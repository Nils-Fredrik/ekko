<?php

namespace App\Prompts;

/**
 * Prompts must not carry personal data (docs/spec.md 16). The editor warns; it does not refuse the text.
 */
final class PersonalData
{
    public static function in(string $text): bool
    {
        return preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $text) === 1
            || preg_match('/(?:\+|00)\d{7,}\b/u', $text) === 1
            || preg_match('/\b\d{8}\b/u', $text) === 1;
    }
}
