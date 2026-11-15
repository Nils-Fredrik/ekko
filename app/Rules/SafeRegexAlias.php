<?php

namespace App\Rules;

use App\Detection\RegexAliasGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An alias with match_mode regex must be a safe pattern (ADR 0010). Apply it only when the mode is regex,
 * for example with Rule::when($mode === 'regex', new SafeRegexAlias).
 */
final class SafeRegexAlias implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = (new RegexAliasGuard)->validate(is_string($value) ? $value : '');

        if ($error !== null) {
            $fail('detection.regex.'.$error)->translate();
        }
    }
}
