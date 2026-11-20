<?php

use App\Reports\ReadableColor;

test('a brand color that is already dark enough is left unchanged', function () {
    expect(ReadableColor::against('#112233', '#ffffff'))->toBe('#112233');
});

test('primary is darkened until white text and colored text both clear AA', function () {
    $safe = ReadableColor::against('#e84a38', '#ffffff');

    expect(ReadableColor::contrast('#ffffff', '#e84a38'))->toBeLessThan(4.5)
        ->and(ReadableColor::contrast($safe, '#ffffff'))->toBeGreaterThanOrEqual(ReadableColor::MINIMUM)
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

test('the text colors used for the shell clear AA on both themes', function () {
    expect(ReadableColor::contrast('#ffffff', '#c73823'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#c73823', '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#c73823', '#f5ede4'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#5d8a6a', '#000000'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#5d8a6a', '#15100f'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#bd6861', '#000000'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#bd6861', '#15100f'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#4a7c59', '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#a8382f', '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::contrast('#78716c', '#ffffff'))->toBeGreaterThanOrEqual(4.5);
});
