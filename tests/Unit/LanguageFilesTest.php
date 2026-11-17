<?php

use Illuminate\Support\Arr;

// T38: the sv and en files are complete. nb is the reference: every locale has the same files, the same keys
// and the same placeholders, and every literal key the PHP code translates exists in all three
// (docs/spec.md 20, AGENTS.md: all user texts in nb, sv and en). Frontend keys: TranslationCoverageTest.

const LANGUAGE_FILE_LOCALES = ['nb', 'sv', 'en'];

/**
 * Every string in the locale, keyed `file.key.sub` for lang/{locale}/*.php and `{locale}.json:key` for the JSON file.
 *
 * @return array<string, string>
 */
function languageStrings(string $locale): array
{
    $root = dirname(__DIR__, 2).'/lang';
    $strings = [];
    foreach (glob("{$root}/{$locale}/*.php") ?: [] as $file) {
        foreach (Arr::dot(require $file) as $key => $value) {
            $strings[basename($file, '.php').'.'.$key] = (string) $value;
        }
    }
    foreach (json_decode((string) file_get_contents("{$root}/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR) as $key => $value) {
        $strings["json:{$key}"] = (string) $value;
    }

    return $strings;
}

/**
 * The placeholders in a string, lower-cased, since :Attribute and :ATTRIBUTE are case variants of :attribute.
 *
 * @return list<string>
 */
function languagePlaceholders(string $value): array
{
    preg_match_all('/(?<![\w:]):([A-Za-z_]+)/', $value, $matches);
    $names = array_values(array_unique(array_map(strtolower(...), $matches[1])));
    sort($names);

    return $names;
}

/**
 * The literal keys the PHP code passes to __(), trans() and trans_choice(). A key ending in a dot is a
 * prefix the code completes at runtime, such as alerts.integrations.{type}.
 *
 * @return list<string>
 */
function backendTranslationKeys(): array
{
    $root = dirname(__DIR__, 2);
    $keys = [];
    foreach (['app', 'routes', 'resources/views', 'config'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/(?<![\\w>:\$])(?:__|trans|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)+)'/", (string) file_get_contents($file->getPathname()), $matches);
            array_push($keys, ...array_map(stripslashes(...), $matches[1]));
        }
    }
    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

function backendKeyExists(string $locale, string $key): bool
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
    if (! is_file("{$root}/{$locale}/{$file}.php")) {
        return false;
    }
    $value = data_get(require "{$root}/{$locale}/{$file}.php", rtrim($path, '.'));

    return str_ends_with($path, '.') ? is_array($value) && $value !== [] : is_string($value) || is_array($value);
}

test('every locale has the same language files', function () {
    $root = dirname(__DIR__, 2).'/lang';
    $files = fn (string $locale): array => array_map(basename(...), glob("{$root}/{$locale}/*.php") ?: []);

    foreach (['sv', 'en'] as $locale) {
        expect($files($locale))->toBe($files('nb'), "Language files in {$locale} differ from nb")
            ->and(is_file("{$root}/{$locale}.json"))->toBeTrue();
    }
});

foreach (['sv', 'en'] as $locale) {
    test("{$locale} has every key in nb and no others", function () use ($locale) {
        $reference = languageStrings('nb');
        $strings = languageStrings($locale);

        expect(array_keys(array_diff_key($reference, $strings)))->toBe([], "Missing in {$locale}: ".implode(', ', array_keys(array_diff_key($reference, $strings))))
            ->and(array_keys(array_diff_key($strings, $reference)))->toBe([], "In {$locale}, not in nb");
    });

    test("{$locale} uses the same placeholders as nb", function () use ($locale) {
        $strings = languageStrings($locale);
        $different = [];
        foreach (languageStrings('nb') as $key => $value) {
            if (array_key_exists($key, $strings) && languagePlaceholders($strings[$key]) !== languagePlaceholders($value)) {
                $different[] = $key;
            }
        }

        expect($different)->toBe([], "Placeholders in {$locale} differ from nb: ".implode(', ', $different));
    });
}

test('the PHP code uses translation keys', function () {
    expect(backendTranslationKeys())->toContain('billing.csv.engine_cost');
});

foreach (LANGUAGE_FILE_LOCALES as $locale) {
    test("every translation key in the PHP code exists in {$locale}", function () use ($locale) {
        $missing = array_values(array_filter(backendTranslationKeys(), fn (string $key): bool => ! backendKeyExists($locale, $key)));

        expect($missing)->toBe([], "Missing in {$locale}: ".implode(', ', $missing));
    });
}

test('the check finds a missing key, an extra key and a lost placeholder', function () {
    expect(array_keys(array_diff_key(['a.b' => 'x', 'a.c' => 'y'], ['a.b' => 'x'])))->toBe(['a.c'])
        ->and(languagePlaceholders('Det tio fältet: :values.'))->not->toBe(languagePlaceholders(':Attribute-feltet: :values.'))
        ->and(languagePlaceholders(':Attribute og :attribute'))->toBe(['attribute']);
});
