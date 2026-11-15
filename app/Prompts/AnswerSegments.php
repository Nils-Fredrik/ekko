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
            ];
        }

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
