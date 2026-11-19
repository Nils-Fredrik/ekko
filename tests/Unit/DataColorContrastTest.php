<?php

// U01: data colors contrast at least 3:1 with both theme backgrounds, and components
// do not hardcode white surfaces or ink text (docs/ui-forbedring.md 4.1–4.2).

/**
 * @return array<string, string>
 */
function themeColorVariables(string $css, string $header): array
{
    $start = strpos($css, $header);
    expect($start)->not->toBeFalse();
    $open = strpos($css, '{', $start);
    $close = strpos($css, "\n}", $open);
    $block = substr($css, $open + 1, $close - $open - 1);
    preg_match_all('/(--[\w-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $block, $matches, PREG_SET_ORDER);
    $colors = [];
    foreach ($matches as $match) {
        $colors[$match[1]] = strtolower($match[2]);
    }

    return $colors;
}

function relativeLuminance(string $hex): float
{
    $channels = array_map(function (string $pair): float {
        $value = hexdec($pair) / 255;

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, str_split(substr($hex, 1), 2));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrastRatio(string $foreground, string $background): float
{
    $lighter = max(relativeLuminance($foreground), relativeLuminance($background));
    $darker = min(relativeLuminance($foreground), relativeLuminance($background));

    return ($lighter + 0.05) / ($darker + 0.05);
}

test('data colors contrast with both theme backgrounds', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    $dark = themeColorVariables($css, '@theme');
    $light = array_merge($dark, themeColorVariables($css, ":root[data-theme='light']"));
    $marks = [
        '--data-own',
        '--data-cat-1',
        '--data-cat-2',
        '--data-cat-3',
        '--data-cat-4',
        '--data-cat-5',
        '--data-cat-6',
        '--data-positive',
        '--data-negative',
        '--data-neutral',
        '--data-marker-model',
        '--data-marker-action',
    ];

    foreach (['dark' => $dark, 'light' => $light] as $theme => $colors) {
        $backgrounds = [$colors['--color-shell'], $colors['--color-shell-surface']];
        foreach ($marks as $mark) {
            foreach ($backgrounds as $background) {
                expect(contrastRatio($colors[$mark], $background))->toBeGreaterThanOrEqual(3, "{$mark} on {$background} in {$theme}");
            }
        }
        expect($colors['--data-seq-0'])->toBe($colors['--color-shell-surface'])
            ->and($colors['--data-seq-6'])->toBe($colors['--data-own']);
        foreach (['--color-gray-600', '--color-gray-700', '--color-gray-900'] as $text) {
            foreach ($backgrounds as $background) {
                expect(contrastRatio($colors[$text], $background))->toBeGreaterThanOrEqual(3, "{$text} on {$background} in {$theme}");
            }
        }
    }

    expect(relativeLuminance($dark['--color-gray-100']))->toBeLessThan(0.05)
        ->and(relativeLuminance($light['--color-gray-100']))->toBeGreaterThan(0.7)
        ->and($dark['--color-qr-quiet'])->toBe('#ffffff')
        ->and($light['--color-qr-quiet'])->toBe('#ffffff');
});

test('components do not hardcode white surfaces or ink text', function () {
    $root = dirname(__DIR__, 2).'/resources/js';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $offenders = [];
    foreach ($files as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'js'], true)) {
            continue;
        }
        $text = (string) file_get_contents($file->getPathname());
        if (preg_match('/\b(?:bg-white|text-ink(?:-soft)?|bg-light-beige|border-ink)\b/', $text) === 1) {
            $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
