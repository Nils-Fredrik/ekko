<?php

namespace App\Detection;

/**
 * Finds known brands and competitors in an answer by their aliases (docs/spec.md 8.1, ADR 0010).
 *
 * - exact_word: the alias as a whole word, case-sensitive
 * - case_insensitive: the alias as a whole word, any case
 * - regex: the pattern as written, case-insensitive, on NFKC text, limited by {@see RegexAliasGuard}
 *
 * A whole word means no letter or digit directly before or after. A trailing possessive -s, 's or ’s is
 * allowed (Volvos, Volvo's), a longer word is not (Volvobil, Volvoer). Hyphen compounds count (Volvo-eier).
 * Where matches overlap, the longest wins ("Telenor Norge" beats "Telenor"). Each entity yields one
 * mention: its first occurrence.
 */
final class EntityMatcher
{
    private const MAX_OCCURRENCES = 5000;

    public function __construct(
        private readonly TextNormalizer $normalizer = new TextNormalizer,
        private readonly RegexAliasGuard $guard = new RegexAliasGuard,
        private readonly ListPositions $lists = new ListPositions,
    ) {}

    /**
     * @param  list<AliasPattern>  $aliases
     * @return list<DetectedMention> ordered by first occurrence
     */
    public function detect(string $answer, array $aliases): array
    {
        if ($aliases === [] || trim($answer) === '') {
            return [];
        }

        $folded = $this->normalizer->normalize($answer);
        $plain = null;
        $found = [];

        foreach ($aliases as $alias) {
            if ($alias->matchMode === AliasPattern::REGEX) {
                $plain ??= $this->normalizer->normalize($answer, foldDiacritics: false);
                $ranges = $this->regexRanges($alias, $plain);
                $text = $plain;
            } else {
                $ranges = $this->wordRanges($alias, $folded);
                $text = $folded;
            }

            foreach ($ranges as [$start, $end]) {
                $found[] = ['alias' => $alias, 'start' => $start, 'end' => $end, 'text' => $text];
            }

            if (count($found) > self::MAX_OCCURRENCES) {
                break;
            }
        }

        // Longest first, so that "Telenor Norge" wins over "Telenor" and a start offset breaks ties.
        usort($found, fn (array $a, array $b): int => [$b['end'] - $b['start'], $a['start']] <=> [$a['end'] - $a['start'], $b['start']]);

        $accepted = [];
        foreach ($found as $candidate) {
            foreach ($accepted as $other) {
                if ($candidate['start'] < $other['end'] && $other['start'] < $candidate['end']) {
                    continue 2;
                }
            }
            $accepted[] = $candidate;
        }

        usort($accepted, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $items = $this->lists->items($folded->original);
        $byEntity = [];
        foreach ($accepted as $occurrence) {
            $key = $occurrence['alias']->entityKey();
            $byEntity[$key] ??= ['first' => $occurrence, 'position' => null];

            if ($byEntity[$key]['position'] === null) {
                $byEntity[$key]['position'] = $this->lists->positionAt($items, $occurrence['start']);
            }
        }

        $mentions = [];
        foreach ($byEntity as $entry) {
            $first = $entry['first'];
            /** @var AliasPattern $alias */
            $alias = $first['alias'];
            /** @var NormalizedText $text */
            $text = $first['text'];

            $mentions[] = new DetectedMention(
                $alias->entityType,
                $alias->entityId,
                mb_substr($text->originalSlice($first['start'], $first['end']), 0, 255),
                $first['start'],
                $entry['position'],
            );
        }

        return $mentions;
    }

    /**
     * @return list<array{0: int, 1: int}> original code point ranges
     */
    private function wordRanges(AliasPattern $alias, NormalizedText $text): array
    {
        $needle = $this->normalizer->normalize($alias->alias);
        if ($needle->isEmpty() || trim($needle->cased) === '') {
            return [];
        }

        $caseSensitive = $alias->matchMode === AliasPattern::EXACT_WORD;
        $haystack = $caseSensitive ? $text->cased : $text->lower;
        $word = trim($caseSensitive ? $needle->cased : $needle->lower);

        $pattern = '~(?<![\\p{L}\\p{N}])('.preg_quote($word, '~').")(?:'?s)?(?![\\p{L}\\p{N}])~u";
        if (preg_match_all($pattern, $haystack, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $ranges = [];
        foreach ($matches[1] as [$matched, $byteOffset]) {
            $range = $text->originalRange($byteOffset, $byteOffset + strlen($matched));
            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    /**
     * @return list<array{0: int, 1: int}> original code point ranges
     */
    private function regexRanges(AliasPattern $alias, NormalizedText $text): array
    {
        $byteRanges = $this->guard->matchAll($alias->alias, $text->cased);
        if ($byteRanges === null) {
            return [];
        }

        $ranges = [];
        foreach ($byteRanges as [$start, $end]) {
            $range = $text->originalRange($start, $end);
            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }
}
