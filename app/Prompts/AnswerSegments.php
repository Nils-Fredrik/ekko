<?php

namespace App\Prompts;

use App\Models\Mention;

/**
 * Splits an answer into plain text and mention spans so the prompt page can highlight them
 * (docs/spec.md 14.2). Offsets are Unicode code points from the start of the answer.
 */
final class AnswerSegments
{
    /**
     * @param  iterable<Mention>  $mentions
     * @return list<array{text: string, kind: string}>
     */
    public static function from(string $answer, iterable $mentions): array
    {
        $marks = [];
        $length = mb_strlen($answer);

        foreach ($mentions as $mention) {
            $surface = (string) $mention->surface_name;
            $span = mb_strlen($surface);
            if ($span === 0) {
                continue;
            }

            $offset = self::offset($answer, $surface, $mention->first_char_offset);
            if ($offset === null || $offset < 0 || $offset + $span > $length) {
                continue;
            }

            $marks[] = [
                'offset' => $offset,
                'length' => $span,
                'kind' => self::kind((string) $mention->entity_type),
                'surface' => $surface,
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
     * A stored mention is only the first span. Later copies of the same surface are highlighted too,
     * unless they overlap a span already kept.
     *
     * @param  list<array{offset: int, length: int, kind: string, surface: string}>  $marks
     * @return list<array{offset: int, length: int, kind: string, surface: string}>
     */
    private static function withLaterOccurrences(string $answer, array $marks): array
    {
        $seen = [];
        $stored = $marks;
        foreach ($stored as $mark) {
            $key = mb_strtolower($mark['surface']).'|'.$mark['kind'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $from = 0;
            while (($found = mb_stripos($answer, $mark['surface'], $from)) !== false) {
                if (! self::overlaps($marks, $found, $mark['length'])) {
                    $marks[] = [
                        'offset' => $found,
                        'length' => $mark['length'],
                        'kind' => $mark['kind'],
                        'surface' => mb_substr($answer, $found, $mark['length']),
                    ];
                }
                $from = $found + $mark['length'];
            }
        }

        return $marks;
    }

    /**
     * @param  list<array{offset: int, length: int, kind: string, surface: string}>  $marks
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

    private static function offset(string $answer, string $surface, mixed $stored): ?int
    {
        if (is_int($stored) || (is_string($stored) && is_numeric($stored))) {
            return (int) $stored;
        }

        $found = mb_stripos($answer, $surface);

        return $found === false ? null : $found;
    }

    private static function kind(string $entityType): string
    {
        return in_array($entityType, ['brand', 'competitor', 'unknown'], true) ? $entityType : 'unknown';
    }
}
