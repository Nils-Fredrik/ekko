<?php

use App\Detection\RegexAliasGuard;

it('accepts ordinary patterns', function (string $pattern) {
    expect((new RegexAliasGuard)->validate($pattern))->toBeNull();
})->with([
    'literal' => ['fjordkaffe'],
    'optional space' => ['fjord\s?kaffe'],
    'alternation' => ['(?:as|ab)\b'],
    'unicode' => ['bj[øö]rn\b'],
    'bounded repeat' => ['a{2,5}b'],
    'repeated simple group' => ['(?:ab)+c'],
]);

it('rejects unsafe or broken patterns with an error code', function (string $pattern, string $error) {
    expect((new RegexAliasGuard)->validate($pattern))->toBe($error);
})->with([
    'empty' => ['', 'empty'],
    'blank' => ['   ', 'empty'],
    'too long' => [str_repeat('a', 201), 'too_long'],
    'control character' => ["a\x01b", 'control_characters'],
    'null byte' => ["a\x00b", 'control_characters'],
    'invalid utf-8' => ["a\xC3\x28", 'control_characters'],
    'unclosed group' => ['(abc', 'invalid'],
    'bad escape in class' => ['[z-a]', 'invalid'],
    'nested plus' => ['(a+)+$', 'unsafe'],
    'nested star' => ['(a*)*b', 'unsafe'],
    'nested with class' => ['(\w+\s?)*x', 'unsafe'],
    'nested open range' => ['(a{2,})+', 'unsafe'],
    'nested then bounded' => ['(a+){2,5}', 'unsafe'],
    'callout' => ['(?C1)a', 'unsafe'],
    'backtracking verb' => ['(*COMMIT)a', 'unsafe'],
    'matches empty' => ['a*', 'matches_empty'],
    'matches empty alternation' => ['a|', 'matches_empty'],
    'overlapping alternation' => ['(?:a|aa)+$', 'backtracking'],
]);

it('limits a pattern at run time and returns null instead of throwing', function () {
    $guard = new RegexAliasGuard;

    expect($guard->matchAll('(?:a|aa)+$', str_repeat('a', 5000).'!'))->toBeNull()
        ->and($guard->matchAll('(unclosed', 'abc'))->toBeNull()
        ->and($guard->matchAll("a\x01", 'abc'))->toBeNull()
        ->and($guard->matchAll('(?C1)a', 'abc'))->toBeNull();
});

it('returns byte ranges, skips empty matches and caps the number of matches', function () {
    $guard = new RegexAliasGuard;

    expect($guard->matchAll('b+', 'abbcb'))->toBe([[1, 3], [4, 5]])
        ->and($guard->matchAll('x*', 'abc'))->toBe([])
        ->and($guard->matchAll('a', str_repeat('a ', 1000)))->toHaveCount(RegexAliasGuard::MAX_MATCHES);
});

it('restores PCRE settings after running', function () {
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');

    (new RegexAliasGuard)->matchAll('a', 'a');

    expect(ini_get('pcre.jit'))->toBe($jit)->and(ini_get('pcre.backtrack_limit'))->toBe($limit);
});

it('runs a normal pattern over a long answer within the limit', function () {
    $text = str_repeat('Dette er en lang tekst om kaffe, biler og Fjord Kaffe. ', 500);

    expect((new RegexAliasGuard)->matchAll('fjord\s?kaffe', $text))->toHaveCount(RegexAliasGuard::MAX_MATCHES);
});
