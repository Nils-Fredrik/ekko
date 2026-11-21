<?php

namespace App\Detection;

use Normalizer;

/**
 * The comparable key of a brand name (docs/ux-arbeidsflate.md 6.3, ADR 0043). Variants that only differ in case,
 * width, punctuation, a parenthesis or a legal suffix share one key, so «Netthavn AS», «netthavn» and
 * «Netthavn.» are one unknown brand in the queue and in one decision.
 *
 * 1. Unicode NFKC and lower case.
 * 2. Text in parentheses is removed.
 * 3. A legal suffix as its own word at the end is removed.
 * 4. Punctuation and symbols (® ™ & +) become a space, and spaces are collapsed.
 * 5. The text is trimmed.
 *
 * Step 3 runs after step 4, so a suffix followed by punctuation is removed as well.
 */
final class EntityName
{
    /** Legal suffixes removed as the last word. */
    public const LEGAL_SUFFIXES = ['as', 'asa', 'ab', 'aps', 'oy', 'gmbh', 'ltd', 'inc', 'llc', 'bv'];

    /** The longest key, the width of entity_decisions.name_key. */
    public const MAX_LENGTH = 200;

    public static function key(string $name): string
    {
        $text = Normalizer::normalize($name, Normalizer::FORM_KC);
        $text = mb_strtolower($text === false ? $name : $text);
        $text = (string) preg_replace('/\([^()]*\)/u', ' ', $text);
        $text = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        // The suffix is removed after punctuation, so «Netthavn AS.» and «Netthavn, AS» lose it too. Only once, and
        // never the whole name, so a brand called «AS» keeps its key.
        $suffixes = implode('|', self::LEGAL_SUFFIXES);
        $without = trim((string) preg_replace('/\s(?:'.$suffixes.')$/u', '', $text));
        if ($without !== '') {
            $text = $without;
        }

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
