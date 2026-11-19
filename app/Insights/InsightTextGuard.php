<?php

namespace App\Insights;

/**
 * Checks the model's JSON before it is stored (docs/spec.md 14.2, ADR 0038). The text is a template: every figure
 * is a {placeholder} from the list the model was given, and the code fills it in. The text is refused when it is
 * not the expected shape, too long, uses a placeholder it was not given, or contains a digit outside a name the
 * model was given (a brand, an engine, a competitor, a prompt, a model), written in full.
 */
final class InsightTextGuard
{
    /**
     * @param  list<string>  $placeholders
     * @param  list<string>  $names
     */
    public function parse(string $json, array $placeholders, array $names, int $maxLength): ?string
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_string($data['text'] ?? null)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $data['text']));
        if ($text === '' || mb_strlen($text) > $maxLength) {
            return null;
        }

        // The names are taken out first, longest first, so a placeholder-looking name cannot hide anything.
        $rest = $text;
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($names as $name) {
            if (mb_strlen(trim($name)) >= 3) {
                $rest = str_ireplace(trim($name), ' ', $rest);
            }
        }

        preg_match_all('/\{([a-z0-9_]+)\}/', $rest, $found);
        if (array_diff($found[1], $placeholders) !== []) {
            return null;
        }
        $rest = (string) preg_replace('/\{[a-z0-9_]+\}/', ' ', $rest);

        // Any digit or brace left, also next to letters ("5x", "Q4"), is a figure the model wrote.
        if (preg_match('/[\p{N}{}]/u', $rest) === 1) {
            return null;
        }

        return $text;
    }
}
