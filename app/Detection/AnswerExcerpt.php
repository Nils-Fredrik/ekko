<?php

namespace App\Detection;

final class AnswerExcerpt
{
    private const EXCERPT_CONTEXT = 80;

    /**
     * The name in the text with up to EXCERPT_CONTEXT characters on each side. The stored offset is used when the
     * name stands there, otherwise the first occurrence without case.
     *
     * @return array{before: string, match: string, after: string}|null
     */
    public static function around(string $text, string $name, ?int $offset): ?array
    {
        $length = mb_strlen($name);
        if ($length === 0) {
            return null;
        }
        if ($offset === null || mb_strtolower(mb_substr($text, $offset, $length)) !== mb_strtolower($name)) {
            $found = mb_stripos($text, $name);
            if ($found === false) {
                return null;
            }
            $offset = $found;
        }
        $start = max(0, $offset - self::EXCERPT_CONTEXT);

        return [
            'before' => ($start > 0 ? '…' : '').mb_substr($text, $start, $offset - $start),
            'match' => mb_substr($text, $offset, $length),
            'after' => mb_substr($text, $offset + $length, self::EXCERPT_CONTEXT).(mb_strlen($text) > $offset + $length + self::EXCERPT_CONTEXT ? '…' : ''),
        ];
    }
}
