<?php

// Every literal translation key used in the frontend ($t('…'), trans('…')) must exist in nb, sv and en,
// either in lang/{locale}.json or as file.key in lang/{locale}/*.php (AGENTS.md: no hardcoded user texts).

/**
 * @return list<string>
 */
function frontendTranslationKeys(): array
{
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js'));
    $keys = [];

    foreach ($files as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'js'], true)) {
            continue;
        }
        preg_match_all("/(?:\\\$t|\\btrans)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[1] as $key) {
            $keys[] = stripslashes($key);
        }
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

function translationExists(string $locale, string $key): bool
{
    $root = dirname(__DIR__, 2).'/lang';
    $json = json_decode((string) file_get_contents("{$root}/{$locale}.json"), true);
    if (is_array($json) && array_key_exists($key, $json)) {
        return true;
    }

    if (! str_contains($key, '.')) {
        return false;
    }

    [$file, $path] = explode('.', $key, 2);
    $phpFile = "{$root}/{$locale}/{$file}.php";
    if (! is_file($phpFile)) {
        return false;
    }

    $value = data_get(require $phpFile, $path);

    return is_string($value);
}

test('the frontend uses translation keys', function () {
    expect(frontendTranslationKeys())->not->toBeEmpty();
});

foreach (['nb', 'sv', 'en'] as $locale) {
    test("every frontend translation key exists in {$locale}", function () use ($locale) {
        $missing = array_values(array_filter(
            frontendTranslationKeys(),
            fn (string $key) => ! translationExists($locale, $key),
        ));

        expect($missing)->toBe([], "Missing in {$locale}: ".implode(', ', $missing));
    });
}
