<?php

namespace App\Detection;

/**
 * Safety for regex aliases (ADR 0010). A regex alias is tenant input run against text we do not control,
 * so it is checked when it is saved ({@see validate()}) and limited when it runs ({@see matchAll()}).
 *
 * Saved patterns: at most 200 characters, no control characters, valid PCRE, no (?C callouts and (*VERBS,
 * no repeated group that itself repeats (the classic (a+)+), cannot match the empty string, and must not
 * exhaust the backtracking limit on adversarial probe strings.
 *
 * Running patterns: PCRE JIT is off so the backtrack limit always applies, the limit is lowered, matches
 * per alias are capped, and a pattern that hits the limit is skipped instead of failing the detection.
 */
final class RegexAliasGuard
{
    public const MAX_LENGTH = 200;

    public const BACKTRACK_LIMIT = 200_000;

    public const MAX_MATCHES = 200;

    private const DELIMITER = "\x01";

    /** Error codes double as translation keys: lang/{locale}/detection.php, regex.<code>. */
    public const ERROR_TOO_LONG = 'too_long';

    public const ERROR_CONTROL = 'control_characters';

    public const ERROR_INVALID = 'invalid';

    public const ERROR_UNSAFE = 'unsafe';

    public const ERROR_EMPTY_MATCH = 'matches_empty';

    public const ERROR_BACKTRACKING = 'backtracking';

    /**
     * @return string|null an error code, or null when the pattern is acceptable
     */
    public function validate(string $pattern): ?string
    {
        if ($pattern === '' || mb_strlen($pattern) > self::MAX_LENGTH) {
            return self::ERROR_TOO_LONG;
        }

        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $pattern) === 1 || ! mb_check_encoding($pattern, 'UTF-8')) {
            return self::ERROR_CONTROL;
        }

        if (preg_match('/\(\?C|\(\*/', $pattern) === 1 || $this->hasNestedQuantifier($pattern)) {
            return self::ERROR_UNSAFE;
        }

        $compiled = $this->compile($pattern);
        if (! $this->compiles($compiled)) {
            return self::ERROR_INVALID;
        }

        if (preg_match($compiled, '') === 1) {
            return self::ERROR_EMPTY_MATCH;
        }

        foreach ($this->probes() as $probe) {
            $this->limited(fn () => preg_match($compiled, $probe));
            if (preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR || preg_last_error() === PREG_JIT_STACKLIMIT_ERROR) {
                return self::ERROR_BACKTRACKING;
            }
        }

        return null;
    }

    /**
     * Runs a saved pattern against text. Returns byte ranges in $subject (zero-length matches skipped).
     * Null means the pattern was invalid or hit the limit, and should be skipped.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    public function matchAll(string $pattern, string $subject): ?array
    {
        if ($this->validatePatternShape($pattern) !== null) {
            return null;
        }

        $compiled = $this->compile($pattern);
        if (! $this->compiles($compiled)) {
            return null;
        }

        $matches = [];
        $result = $this->limited(function () use ($compiled, $subject, &$matches) {
            return preg_match_all($compiled, $subject, $matches, PREG_OFFSET_CAPTURE);
        });

        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        $ranges = [];
        foreach ($matches[0] as [$text, $offset]) {
            if ($text === '') {
                continue;
            }
            $ranges[] = [$offset, $offset + strlen($text)];
            if (count($ranges) >= self::MAX_MATCHES) {
                break;
            }
        }

        return $ranges;
    }

    /**
     * Cheap checks that must hold at run time as well, in case a pattern reached the database
     * without {@see validate()}.
     */
    private function validatePatternShape(string $pattern): ?string
    {
        if ($pattern === '' || mb_strlen($pattern) > self::MAX_LENGTH || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $pattern) === 1) {
            return self::ERROR_TOO_LONG;
        }

        if (preg_match('/\(\?C|\(\*/', $pattern) === 1) {
            return self::ERROR_UNSAFE;
        }

        return null;
    }

    /** A pattern that does not compile makes PHP emit a warning, which must not reach the error handler. */
    private function compiles(string $compiled): bool
    {
        set_error_handler(fn (): bool => true);

        try {
            return preg_match($compiled, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function compile(string $pattern): string
    {
        return self::DELIMITER.$pattern.self::DELIMITER.'iu';
    }

    private function hasNestedQuantifier(string $pattern): bool
    {
        $unbounded = '(?:[+*]|\{\d+,\d*\})';
        $group = '\((?:[^()\\\\]|\\\\.)*'.$unbounded.'(?:[^()\\\\]|\\\\.)*\)';

        return preg_match('/'.$group.$unbounded.'|'.$group.'\{\d/', $pattern) === 1;
    }

    /**
     * @return list<string>
     */
    private function probes(): array
    {
        return [
            str_repeat('a', 4000).'!',
            str_repeat('ab', 2000).'!',
            str_repeat('a ', 2000).'!',
            str_repeat('1', 4000).'x',
        ];
    }

    /**
     * Runs a PCRE call with JIT off and a low backtrack limit, then restores the settings.
     */
    private function limited(callable $callback): mixed
    {
        $jit = ini_set('pcre.jit', '0');
        $limit = ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

        try {
            return $callback();
        } finally {
            if ($jit !== false) {
                ini_set('pcre.jit', $jit);
            }
            if ($limit !== false) {
                ini_set('pcre.backtrack_limit', $limit);
            }
        }
    }
}
