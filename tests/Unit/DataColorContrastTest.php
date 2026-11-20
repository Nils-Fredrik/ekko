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

/**
 * CIE L*a*b* (D65) for an sRGB hex color.
 *
 * @return array{float, float, float}
 */
function hexToLab(string $hex): array
{
    $c = array_map(function (string $pair): float {
        $value = hexdec($pair) / 255;

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, str_split(substr($hex, 1), 2));
    $x = (0.4124564 * $c[0] + 0.3575761 * $c[1] + 0.1804375 * $c[2]) / 0.95047;
    $y = 0.2126729 * $c[0] + 0.7151522 * $c[1] + 0.0721750 * $c[2];
    $z = (0.0193339 * $c[0] + 0.1191920 * $c[1] + 0.9503041 * $c[2]) / 1.08883;
    $f = fn (float $t): float => $t > 216 / 24389 ? $t ** (1 / 3) : (24389 / 27 * $t + 16) / 116;

    return [116 * $f($y) - 16, 500 * ($f($x) - $f($y)), 200 * ($f($y) - $f($z))];
}

/**
 * CIEDE2000 color difference between two L*a*b* colors.
 *
 * @param  array{float, float, float}  $lab1
 * @param  array{float, float, float}  $lab2
 */
function deltaE2000(array $lab1, array $lab2): float
{
    [$l1, $a1, $b1] = $lab1;
    [$l2, $a2, $b2] = $lab2;
    $cBar = (hypot($a1, $b1) + hypot($a2, $b2)) / 2;
    $g = 0.5 * (1 - sqrt($cBar ** 7 / ($cBar ** 7 + 25 ** 7)));
    $a1p = $a1 * (1 + $g);
    $a2p = $a2 * (1 + $g);
    $c1p = hypot($a1p, $b1);
    $c2p = hypot($a2p, $b2);
    $h1p = $c1p == 0.0 ? 0.0 : fmod(rad2deg(atan2($b1, $a1p)) + 360, 360);
    $h2p = $c2p == 0.0 ? 0.0 : fmod(rad2deg(atan2($b2, $a2p)) + 360, 360);
    $dLp = $l2 - $l1;
    $dCp = $c2p - $c1p;
    $dhp = $h2p - $h1p;
    if ($c1p * $c2p == 0.0) {
        $dhp = 0.0;
    } elseif ($dhp > 180) {
        $dhp -= 360;
    } elseif ($dhp < -180) {
        $dhp += 360;
    }
    $dHp = 2 * sqrt($c1p * $c2p) * sin(deg2rad($dhp / 2));
    $lBarP = ($l1 + $l2) / 2;
    $cBarP = ($c1p + $c2p) / 2;
    if ($c1p * $c2p == 0.0) {
        $hBarP = $h1p + $h2p;
    } elseif (abs($h1p - $h2p) <= 180) {
        $hBarP = ($h1p + $h2p) / 2;
    } else {
        $hBarP = ($h1p + $h2p + ($h1p + $h2p < 360 ? 360 : -360)) / 2;
    }
    $t = 1 - 0.17 * cos(deg2rad($hBarP - 30)) + 0.24 * cos(deg2rad(2 * $hBarP))
        + 0.32 * cos(deg2rad(3 * $hBarP + 6)) - 0.20 * cos(deg2rad(4 * $hBarP - 63));
    $dTheta = 30 * exp(-((($hBarP - 275) / 25) ** 2));
    $rc = 2 * sqrt($cBarP ** 7 / ($cBarP ** 7 + 25 ** 7));
    $sl = 1 + 0.015 * ($lBarP - 50) ** 2 / sqrt(20 + ($lBarP - 50) ** 2);
    $sc = 1 + 0.045 * $cBarP;
    $sh = 1 + 0.015 * $cBarP * $t;
    $rt = -sin(deg2rad(2 * $dTheta)) * $rc;

    return sqrt(($dLp / $sl) ** 2 + ($dCp / $sc) ** 2 + ($dHp / $sh) ** 2 + $rt * ($dCp / $sc) * ($dHp / $sh));
}

/**
 * Rule violations for own-vs-competitor (>= 20) and competitor-vs-competitor (>= 10) ΔE00.
 *
 * @param  list<string>  $competitors
 * @return list<string>
 */
function dataColorDistanceViolations(string $own, array $competitors): array
{
    $violations = [];
    foreach ($competitors as $i => $competitor) {
        if (deltaE2000(hexToLab($own), hexToLab($competitor)) < 20) {
            $violations[] = "{$own} vs {$competitor}";
        }
        foreach (array_slice($competitors, $i + 1) as $other) {
            if (deltaE2000(hexToLab($competitor), hexToLab($other)) < 10) {
                $violations[] = "{$competitor} vs {$other}";
            }
        }
    }

    return $violations;
}

test('data colors contrast with both theme backgrounds', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    $root = dirname(__DIR__, 2);
    $dark = array_merge(themeColorVariables($css, '@theme'), themeColorVariables($css, ":root {\n"));
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

    // CI runs npm run build before composer test. A local run needs that build first,
    // because Tailwind can drop these tokens and the source file would not show it.
    $manifest = json_decode((string) file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $pending = ['resources/js/app.js'];
    $visited = [];
    $compiled = '';
    while ($pending !== []) {
        $entry = array_pop($pending);
        if (isset($visited[$entry])) {
            continue;
        }
        $visited[$entry] = true;
        expect($manifest)->toHaveKey($entry);
        foreach ($manifest[$entry]['css'] ?? [] as $cssFile) {
            $path = $root.'/public/build/'.$cssFile;
            expect(is_file($path))->toBeTrue();
            $compiled .= (string) file_get_contents($path);
        }
        array_push($pending, ...($manifest[$entry]['imports'] ?? []));
    }
    expect($compiled)->toContain('--data-seq-1:#301411')
        ->and($compiled)->toContain('--data-seq-5:#c33f30')
        ->and($compiled)->toContain('--data-own:#e84a38')
        ->and($compiled)->toContain('--data-cat-6:#6f8a7e');
});

test('CIEDE2000 matches the Sharma reference data', function () {
    expect(deltaE2000([50.0, 2.6772, -79.7751], [50.0, 0.0, -82.7485]))->toEqualWithDelta(2.0425, 0.0001)
        ->and(deltaE2000([50.0, 2.5, 0.0], [73.0, 25.0, -18.0]))->toEqualWithDelta(27.1492, 0.0001)
        ->and(deltaE2000([2.0776, 0.0795, -1.135], [0.9033, -0.0636, -0.5514]))->toEqualWithDelta(0.9082, 0.0001);
});

test('competitor colors are distinguishable from own brand and each other', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    $dark = array_merge(themeColorVariables($css, '@theme'), themeColorVariables($css, ":root {\n"));
    $light = array_merge($dark, themeColorVariables($css, ":root[data-theme='light']"));

    foreach (['dark' => $dark, 'light' => $light] as $theme => $colors) {
        $competitors = array_map(fn (int $n): string => $colors["--data-cat-{$n}"], range(1, 6));
        expect(dataColorDistanceViolations($colors['--data-own'], $competitors))->toBe([], $theme);
        foreach ([...$competitors, $colors['--data-own']] as $color) {
            foreach (['#000000', '#0b0909', '#ffffff', '#f5ede4'] as $background) {
                expect(contrastRatio($color, $background))->toBeGreaterThanOrEqual(3, "{$color} on {$background}");
            }
        }
    }

    // The palette before U01-F2 broke the rule: cat-1 was only ΔE00 6.8 from own.
    expect(dataColorDistanceViolations('#e84a38', ['#c45c48', '#906056', '#9a6840', '#a35a68', '#7d6248', '#b56a4e']))
        ->not->toBe([]);
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
