<?php

// All of Ekko's own language files must have the same keys in nb, sv and en (AGENTS.md: no hardcoded user texts).

/**
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function translationKeys(array $array, string $prefix = ''): array
{
    $keys = [];
    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $keys = is_array($value) ? [...$keys, ...translationKeys($value, $path)] : [...$keys, $path];
    }
    sort($keys);

    return $keys;
}

$ownFiles = ['app.php', 'jetstream.php'];

foreach ($ownFiles as $file) {
    test("lang/*/{$file} has the same keys in nb, sv and en", function () use ($file) {
        $base = translationKeys(require dirname(__DIR__, 2)."/lang/nb/{$file}");

        foreach (['sv', 'en'] as $locale) {
            expect(translationKeys(require dirname(__DIR__, 2)."/lang/{$locale}/{$file}"))
                ->toBe($base, "lang/{$locale}/{$file} differs from lang/nb/{$file}");
        }
    });
}
