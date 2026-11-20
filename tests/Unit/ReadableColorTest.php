<?php

use App\Reports\ReadableColor;

test('a brand color that is already dark enough is left unchanged', function () {
    expect(ReadableColor::against('#112233', '#ffffff'))->toBe('#112233');
});

test('primary is darkened until white text clears AA', function () {
    $safe = ReadableColor::against('#e84a38', '#ffffff');

    expect(ReadableColor::contrast('#ffffff', '#e84a38'))->toBeLessThan(4.5)
        ->and(ReadableColor::contrast('#ffffff', $safe))->toBeGreaterThanOrEqual(ReadableColor::MINIMUM);
});

test('the dark text tokens in the theme clear AA on the dark shell', function () {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    preg_match('/--color-success-text:\s*(#[0-9a-f]{6})/', $css, $success);
    preg_match('/--color-error-text:\s*(#[0-9a-f]{6})/', $css, $error);

    expect($success[1] ?? null)->not->toBeNull()
        ->and($error[1] ?? null)->not->toBeNull()
        ->and(ReadableColor::contrast($success[1], '#000000'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast($success[1], '#15100f'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast($error[1], '#000000'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast($error[1], '#15100f'))->toBeGreaterThanOrEqual(4.5);
});

test('theme text tokens clear AA except the registered light success gap', function () {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    $token = function (string $name) use ($css): string {
        preg_match('/'.$name.':\s*(#[0-9a-f]{6})/', $css, $match);

        return $match[1];
    };

    $success = $token('--color-success');

    expect(ReadableColor::contrast('#ffffff', $token('--color-primary-deep')))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast($token('--color-primary-deep'), '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast($token('--color-primary-deep'), '#f5ede4'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast($success, '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast($success, '#f5ede4'))->toBeLessThan(4.5)
        ->and(ReadableColor::contrast($token('--color-error'), '#ffffff'))->toBeGreaterThanOrEqual(4.5);
});
