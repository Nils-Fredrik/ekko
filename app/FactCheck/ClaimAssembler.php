<?php

namespace App\FactCheck;

use App\Models\Claim;

/**
 * Cleans the claims the model reported (docs/spec.md 11.6, ADR 0036). The provider cannot enforce more than the
 * shape, so what the model says is checked here: the fact must be one of the brand's active facts, the quote must
 * stand in the answer (a claim the model made up, or a verdict on text that is not there, is dropped, as with
 * citations in T39-F10), the text is cut, the same claim on the same fact is kept once and at most
 * ekko.facts.max_claims_per_response are kept. Source URLs only belong to a contradicted claim.
 */
final class ClaimAssembler
{
    public const MAX_TEXT = 500;

    public const MAX_QUOTE = 300;

    /**
     * @param  list<ModelClaim>  $claims
     * @param  list<int>  $factIds  the active facts of the brand
     * @return list<ModelClaim>
     */
    public function assemble(string $answerText, array $claims, array $factIds): array
    {
        if ($factIds === []) {
            return [];
        }
        $max = (int) config('ekko.facts.max_claims_per_response');
        $haystack = self::normalize($answerText);
        $allowed = array_fill_keys($factIds, true);

        $kept = [];
        $seen = [];
        foreach ($claims as $claim) {
            if (count($kept) >= $max) {
                break;
            }
            if ($claim->factId === null || ! isset($allowed[$claim->factId]) || ! in_array($claim->verdict, Claim::VERDICTS, true)) {
                continue;
            }
            $quote = self::normalize($claim->quote);
            if ($quote === '' || ! str_contains($haystack, $quote)) {
                continue;
            }
            $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $claim->text)), 0, self::MAX_TEXT);
            $key = $claim->factId.'|'.mb_strtolower($text);
            if ($text === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = new ModelClaim(
                $claim->factId,
                $text,
                mb_substr(trim((string) preg_replace('/\s+/u', ' ', $claim->quote)), 0, self::MAX_QUOTE),
                $claim->verdict,
                $claim->verdict === Claim::CONTRADICTED ? array_values(array_unique($claim->sourceUrls)) : [],
            );
        }

        return $kept;
    }

    /** Lower case with collapsed whitespace and quotes, so a quote still matches when the model changed the spacing. */
    private static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', mb_strtolower(mb_scrub($text)));

        return trim(str_replace(['“', '”', '„', '«', '»', '’', '‘'], ['"', '"', '"', '"', '"', "'", "'"], $text));
    }
}
