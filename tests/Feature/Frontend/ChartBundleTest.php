<?php

// U02: ECharts is loaded asynchronously and only on pages with charts (docs/ui-forbedring.md 5 and 9).
// The Vite build must keep the library in one chunk that is reached only through import(),
// never through a static import from the app entry or a page. CI builds the frontend before the tests.

/**
 * @return array<string, array<string, mixed>>
 */
function viteManifest(): array
{
    $path = public_path('build/manifest.json');
    expect(is_file($path))->toBeTrue('Run npm run build before the tests.');

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Chunk files reached from an entry through static imports only.
 *
 * @param  array<string, array<string, mixed>>  $manifest
 * @return list<string>
 */
function staticClosure(array $manifest, string $key): array
{
    $seen = [];
    $queue = [$key];
    while ($queue !== []) {
        $current = array_shift($queue);
        if (isset($seen[$current]) || ! isset($manifest[$current])) {
            continue;
        }
        $seen[$current] = $manifest[$current]['file'];
        foreach ($manifest[$current]['imports'] ?? [] as $import) {
            $queue[] = $import;
        }
    }

    return array_values($seen);
}

const CHART_MODULE = 'resources/js/Components/Charts/support/echarts.js';

test('ECharts is its own chunk, loaded only through a dynamic import', function () {
    $manifest = viteManifest();

    expect($manifest)->toHaveKey(CHART_MODULE);
    expect($manifest[CHART_MODULE]['isDynamicEntry'] ?? false)->toBeTrue();
    expect($manifest[CHART_MODULE]['isEntry'] ?? false)->toBeFalse();

    foreach ($manifest as $key => $chunk) {
        expect($chunk['imports'] ?? [])->not->toContain(CHART_MODULE, "{$key} imports ECharts statically");
    }
});

test('no entry or page loads ECharts code statically', function () {
    $manifest = viteManifest();
    $chartFile = $manifest[CHART_MODULE]['file'];
    $roots = array_keys(array_filter($manifest, fn (array $chunk, string $key) => ($chunk['isEntry'] ?? false) || str_starts_with($key, 'resources/js/Pages/'), ARRAY_FILTER_USE_BOTH));

    expect($roots)->toContain('resources/js/app.js');
    foreach ($roots as $root) {
        foreach (staticClosure($manifest, $root) as $file) {
            expect($file)->not->toBe($chartFile, "{$root} loads ECharts");
            if (str_ends_with($file, '.js')) {
                // ECharts marks its own objects with __ec_ properties; no other chunk may contain them.
                expect((string) file_get_contents(public_path("build/{$file}")))->not->toContain('__ec_', "{$file} (from {$root}) contains ECharts code");
            }
        }
    }
});

test('the chart chunk stays tree-shaken and uses the SVG renderer only', function () {
    $manifest = viteManifest();
    $code = (string) file_get_contents(public_path('build/'.$manifest[CHART_MODULE]['file']));

    // The whole ECharts bundle is about 1 MB minified; the registered parts are about 620 kB.
    // U15 sets the real budget in CI; this guards against importing all of 'echarts'.
    expect(strlen($code))->toBeLessThan(750_000);
    expect($code)->toContain('registerPainter("svg"')->not->toContain('registerPainter("canvas"');
});
