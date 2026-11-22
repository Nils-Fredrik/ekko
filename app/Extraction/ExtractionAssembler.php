<?php

namespace App\Extraction;

use App\Detection\AliasPattern;
use App\Detection\DetectedMention;
use App\Detection\EntityMatcher;

/**
 * Combines the deterministic and the model stage by the rules in docs/spec.md 8.2 and ADR 0011:
 *
 * - Deterministic matches win on whether a known brand or competitor is mentioned. A known entity the model
 *   reports but the deterministic stage did not find is dropped; one the model leaves out is kept.
 * - The model enriches the known mentions with is_recommended and sentiment. Offset and list position of a
 *   known mention always come from the deterministic stage.
 * - The model's quote for the sentiment is kept only when the sentiment is not 0 and the quote stands word for word
 *   in the answer (U29), so the reason shown to the user is always the engine's own words.
 * - The model adds mentions of unknown companies. An "unknown" the alias search recognises as a known
 *   entity is dropped, one whose name is not in the answer text is dropped (T39-F10), and the same name is
 *   kept once.
 * - When the model stage failed (null), the deterministic mentions are kept as they are.
 */
final class ExtractionAssembler
{
    private const MAX_UNKNOWN = 20;

    public function __construct(private readonly EntityMatcher $matcher = new EntityMatcher) {}

    /**
     * @param  list<DetectedMention>  $deterministic
     * @param  list<AliasPattern>  $aliases
     */
    public function assemble(string $answerText, array $deterministic, ?ExtractionResult $model, array $aliases): AssembledExtraction
    {
        $enrichment = [];
        $unknown = [];

        foreach ($model->mentions ?? [] as $mention) {
            if ($mention->entityType === 'unknown') {
                $unknown[] = $mention;

                continue;
            }
            $key = $mention->entityKey();
            if ($key !== null && ! isset($enrichment[$key])) {
                // The first report of an entity is used; a repeat adds a recommendation but never removes one.
                $enrichment[$key] = $mention;
            } elseif ($key !== null && $mention->isRecommended && ! $enrichment[$key]->isRecommended) {
                $enrichment[$key] = new ModelMention(
                    $enrichment[$key]->surfaceName, $enrichment[$key]->entityType, $enrichment[$key]->entityId,
                    $enrichment[$key]->listPosition, true, $enrichment[$key]->sentiment, $enrichment[$key]->evidence,
                    $enrichment[$key]->sentimentQuote,
                );
            }
        }

        $assembled = [];
        foreach ($deterministic as $mention) {
            $reported = $enrichment[$mention->entityType.':'.$mention->entityId] ?? null;
            $assembled[] = new AssembledMention(
                $mention->entityType,
                $mention->entityId,
                $mention->surfaceName,
                $mention->firstCharOffset,
                $mention->listPosition,
                $reported->isRecommended ?? false,
                $reported?->sentiment,
                'deterministic',
                $reported === null ? null : self::quote($answerText, $reported),
            );
        }

        $seen = [];
        $extra = [];
        foreach ($unknown as $mention) {
            $name = mb_strtolower($mention->surfaceName);
            $offset = $mention->surfaceName === '' ? false : mb_stripos($answerText, $mention->surfaceName);
            if ($offset === false || isset($seen[$name]) || $this->matcher->detect($mention->surfaceName, $aliases) !== []) {
                continue;
            }
            $seen[$name] = true;
            $extra[] = new AssembledMention(
                'unknown', null, $mention->surfaceName, $offset,
                $mention->listPosition, $mention->isRecommended, $mention->sentiment, 'llm',
                self::quote($answerText, $mention),
            );
        }
        usort($extra, fn (AssembledMention $a, AssembledMention $b): int => $a->firstCharOffset <=> $b->firstCharOffset);

        return new AssembledExtraction(
            [...$assembled, ...array_slice($extra, 0, self::MAX_UNKNOWN)],
            $model->citations ?? [],
            $model?->answerType,
            $model === null,
        );
    }

    /**
     * The quote for the sentiment when it is word for word in the answer, otherwise null. Quotation marks the model
     * put around the quote are not part of it, and neither is the escaping of &, < and > the model saw the answer with
     * (ExtractionPromptBuilder). A neutral mention has no reason to show.
     */
    private static function quote(string $answerText, ModelMention $mention): ?string
    {
        $quote = str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], $mention->sentimentQuote);
        $quote = (string) preg_replace('/^[\s"\'«»“”‘’„]+|[\s"\'«»“”‘’„]+$/u', '', $quote);
        // Neutral as stored: the writer rounds the sentiment to three decimals.
        if (round($mention->sentiment, 3) == 0.0 || $quote === '' || mb_strpos($answerText, $quote) === false) {
            return null;
        }

        return $quote;
    }
}
