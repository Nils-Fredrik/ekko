<?php

namespace App\ActionEngine;

use App\ActionEngine\Writing\ActionText;

/**
 * Checks the model's JSON before it is stored (docs/spec.md 11.3: figures are never written by the model). The
 * text is refused when it is not the expected shape, too long, or contains a number outside the names the model
 * was given (a domain, a brand, a topic, a prompt), written in full. A refused text leaves the template text in place.
 */
final class ActionTextGuard
{
    private const NUMBER = '/(?<![\p{L}\p{N}])\p{N}+(?:[.,:]\p{N}+)*(?![\p{L}\p{N}])/u';

    /**
     * @param  list<string>  $names
     */
    public function parse(string $json, array $names): ?ActionText
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_string($data['title'] ?? null) || ! is_string($data['description'] ?? null) || ! is_array($data['steps'] ?? null)) {
            return null;
        }

        $title = $this->clean($data['title']);
        $description = $this->clean($data['description']);
        $steps = [];
        foreach ($data['steps'] as $step) {
            if (! is_string($step)) {
                return null;
            }
            // A leading "1." or "2)" is numbering, not a figure.
            $step = $this->clean((string) preg_replace('/^\s*(?:\p{N}+[.)]|[-•*])\s+/u', '', $step));
            if ($step !== '') {
                $steps[] = $step;
            }
        }

        if ($title === '' || $description === '' || count($steps) < 1 || count($steps) > 6
            || mb_strlen($title) > ActionPromptBuilder::MAX_TITLE
            || mb_strlen($description) > ActionPromptBuilder::MAX_DESCRIPTION
            || array_filter($steps, fn (string $s): bool => mb_strlen($s) > ActionPromptBuilder::MAX_STEP) !== []) {
            return null;
        }

        foreach ([$title, $description, ...$steps] as $text) {
            if (! $this->onlyNamedNumbers($text, $names)) {
                return null;
            }
        }

        return new ActionText($title, $description, $steps);
    }

    /**
     * @param  list<string>  $names
     */
    private function onlyNamedNumbers(string $text, array $names): bool
    {
        // Only a number inside a name the model was given, written as that name, is allowed: the names are taken
        // out of the text first, longest first, and no number may be left.
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($names as $name) {
            if (mb_strlen(trim($name)) >= 3) {
                $text = str_ireplace(trim($name), ' ', $text);
            }
        }

        return preg_match(self::NUMBER, $text) !== 1;
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
