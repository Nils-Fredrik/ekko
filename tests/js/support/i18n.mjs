// Loads Ekko's PHP language files into laravel-vue-i18n, so tests/js can use the real texts.
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { I18n } from 'laravel-vue-i18n';

const root = fileURLToPath(new URL('../../..', import.meta.url));

function flatten(value, prefix = '', into = {}) {
    for (const [key, entry] of Object.entries(value)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (entry && typeof entry === 'object') {
            flatten(entry, path, into);
        } else {
            into[path] = entry;
        }
    }

    return into;
}

/** The given language files for one locale, flattened the way the Vite plugin does. */
export function messages(locale, files = ['workspace']) {
    const script = `$out = []; foreach (json_decode($argv[2]) as $f) { $out[$f] = require $argv[1].'/lang/'.$argv[3].'/'.$f.'.php'; } echo json_encode($out);`;

    return flatten(
        JSON.parse(execFileSync('php', ['-r', script, root, JSON.stringify(files), locale], { encoding: 'utf8' })),
    );
}

const cache = {};

/** Switches laravel-vue-i18n to the locale with the given files loaded. */
export function useLanguage(locale, files) {
    cache[locale] ??= messages(locale, files);
    I18n.getSharedInstance({ lang: locale, fallbackLang: locale, resolve: () => cache[locale] }, true).loadLanguage(
        locale,
    );
}
