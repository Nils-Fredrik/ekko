<?php

namespace App\Prompts;

use App\Detection\EntityMatcher;
use App\Detection\TextNormalizer;
use App\Models\Brand;
use App\Models\Mention;

/**
 * Splits an answer into plain text and mention spans so the prompt page can highlight them
 * (docs/spec.md 14.2). Offsets are Unicode code points from the start of the answer.
 */
final class AnswerSegments
{
    /**
     * Keys are "{entity_type}:{entity_id}:{normalized surface}" for aliases with match_mode
     * case_insensitive. The surface is folded the same way as detection (docs/spec.md 8.1), so
     * Björn and Bjørn share a key. exact_word and unknown mentions stay case-sensitive.
     *
     * @param  iterable<Mention>  $mentions
     * @param  array<string, true>  $caseFold
     * @return list<array{text: string, kind: string}>
     */
    public static function from(string $answer, iterable $mentions, array $caseFold = []): array
    {
        $marks = [];
        $length = mb_strlen($answer);

        foreach ($mentions as $mention) {
            $surface = (string) $mention->surface_name;
            $span = mb_strlen($surface);
            if ($span === 0) {
                continue;
            }

            $fold = self::folds($mention->entity_type, $mention->entity_id, $surface, $caseFold);
            $offset = self::offset($answer, $surface, $mention->first_char_offset, $fold);
            if ($offset === null || $offset < 0 || $offset + $span > $length) {
                continue;
            }

            $marks[] = [
                'offset' => $offset,
                'length' => $span,
                'kind' => self::kind((string) $mention->entity_type),
                'surface' => $surface,
                'fold' => $fold,
                'entity' => self::foldKey($mention->entity_type, $mention->entity_id, $surface) ?? $surface,
            ];
        }

        $marks = self::withLaterOccurrences($answer, $marks);

        usort($marks, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $segments = [];
        $cursor = 0;
        foreach ($marks as $mark) {
            if ($mark['offset'] < $cursor) {
                continue;
            }

            if ($mark['offset'] > $cursor) {
                $segments[] = ['text' => mb_substr($answer, $cursor, $mark['offset'] - $cursor), 'kind' => 'plain'];
            }

            $segments[] = ['text' => mb_substr($answer, $mark['offset'], $mark['length']), 'kind' => $mark['kind']];
            $cursor = $mark['offset'] + $mark['length'];
        }

        if ($cursor < $length) {
            $segments[] = ['text' => mb_substr($answer, $cursor), 'kind' => 'plain'];
        }

        return $segments === [] ? [['text' => $answer, 'kind' => 'plain']] : $segments;
    }

    /**
     * A stored mention is only the first span. Later copies of that same surface are highlighted too,
     * when they are whole words (docs/spec.md 8.1): no letter or digit directly beside the name,
     * except a trailing possessive s. A name inside another word, such as "apple" in "pineapple", is left plain.
     * The spelling must match the stored surface, so exact_word "Apple" does not highlight "apple".
     * A case_insensitive alias for that surface is the exception and is passed in $caseFold.
     * Other aliases of the same entity are not highlighted.
     *
     * @param  list<array{offset: int, length: int, kind: string, surface: string, fold: bool, entity: string}>  $marks
     * @return list<array{offset: int, length: int, kind: string, surface: string, fold: bool, entity: string}>
     */
    private static function withLaterOccurrences(string $answer, array $marks): array
    {
        $seen = [];
        $stored = $marks;
        foreach ($stored as $mark) {
            $key = ($mark['fold'] ? mb_strtolower($mark['surface']) : $mark['surface']).'|'.$mark['kind'].'|'.$mark['entity'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $from = 0;
            while (($found = self::findBounded($answer, $mark['surface'], $from, $mark['fold'])) !== null) {
                if (! self::overlaps($marks, $found, $mark['length'])) {
                    $marks[] = [
                        'offset' => $found,
                        'length' => $mark['length'],
                        'kind' => $mark['kind'],
                        'surface' => mb_substr($answer, $found, $mark['length']),
                        'fold' => $mark['fold'],
                        'entity' => $mark['entity'],
                    ];
                }
                $from = $found + $mark['length'];
            }
        }

        return $marks;
    }

    public static function caseFoldKey(string $entityType, int|string|null $entityId, string $alias): ?string
    {
        return self::foldKey($entityType, $entityId, $alias);
    }

    /**
     * The case_insensitive aliases of the brand and all its competitors, as keys for {@see from()}. Later copies of a
     * mention keep the stored spelling, unless that surface is one of these aliases.
     *
     * @return array<string, true>
     */
    public static function caseFoldFor(Brand $brand): array
    {
        $brand->loadMissing('aliases');
        $keys = [];
        $add = function (string $type, int $id, iterable $aliases) use (&$keys): void {
            foreach ($aliases as $alias) {
                if ($alias->match_mode !== 'case_insensitive') {
                    continue;
                }
                $key = self::foldKey($type, $id, (string) $alias->alias);
                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        };

        $add('brand', $brand->id, $brand->aliases);
        foreach ($brand->competitors()->withTrashed()->with('aliases')->get() as $competitor) {
            $add('competitor', $competitor->id, $competitor->aliases);
        }

        return $keys;
    }

    /**
     * The first copy of $surface that stands as its own word, or null. Case must match unless $fold is set.
     */
    public static function findWord(string $answer, string $surface, bool $fold): ?int
    {
        return self::findBounded($answer, $surface, 0, $fold);
    }

    /**
     * @param  array<string, true>  $caseFold
     */
    private static function folds(mixed $entityType, mixed $entityId, string $surface, array $caseFold): bool
    {
        $key = self::foldKey($entityType, $entityId, $surface);

        return $key !== null && isset($caseFold[$key]);
    }

    /**
     * @return non-empty-string|null
     */
    private static function foldKey(mixed $entityType, mixed $entityId, string $surface): ?string
    {
        if (! is_string($entityType) || $entityType === '' || ! is_numeric($entityId) || $surface === '') {
            return null;
        }

        $normalized = trim((new TextNormalizer)->normalize($surface)->lower);
        if ($normalized === '') {
            return null;
        }

        return $entityType.':'.(int) $entityId.':'.$normalized;
    }

    /**
     * @param  list<array{offset: int, length: int}>  $marks
     */
    private static function overlaps(array $marks, int $offset, int $length): bool
    {
        $end = $offset + $length;
        foreach ($marks as $mark) {
            $markEnd = $mark['offset'] + $mark['length'];
            if ($offset < $markEnd && $mark['offset'] < $end) {
                return true;
            }
        }

        return false;
    }

    private static function offset(string $answer, string $surface, mixed $stored, bool $fold): ?int
    {
        if (is_int($stored) || (is_string($stored) && is_numeric($stored))) {
            return (int) $stored;
        }

        return self::findBounded($answer, $surface, 0, $fold);
    }

    /**
     * The next copy that stands as its own word, or null. Case must match unless $fold is set.
     */
    private static function findBounded(string $answer, string $surface, int $from, bool $fold): ?int
    {
        $length = mb_strlen($surface);
        if ($length === 0) {
            return null;
        }

        while (($found = $fold ? mb_stripos($answer, $surface, $from) : mb_strpos($answer, $surface, $from)) !== false) {
            if (self::bounded($answer, $found, $length)) {
                return $found;
            }
            $from = $found + 1;
        }

        return null;
    }

    /**
     * Same word boundary as {@see EntityMatcher}: no letter or digit on either side.
     * A single trailing possessive s is allowed (Volvos), a longer word is not (Volvoer, pineapple).
     * Case follows the alias mode and is not part of this boundary check.
     */
    private static function bounded(string $answer, int $offset, int $length): bool
    {
        if ($offset > 0 && self::isLetterOrDigit(mb_substr($answer, $offset - 1, 1))) {
            return false;
        }

        $tail = mb_substr($answer, $offset + $length);
        if ($tail === '' || ! self::isLetterOrDigit(mb_substr($tail, 0, 1))) {
            return true;
        }

        return preg_match('/^s(?![\p{L}\p{N}])/iu', $tail) === 1;
    }

    private static function isLetterOrDigit(string $char): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $char) === 1;
    }

    private static function kind(string $entityType): string
    {
        return in_array($entityType, ['brand', 'competitor', 'unknown'], true) ? $entityType : 'unknown';
    }
}
