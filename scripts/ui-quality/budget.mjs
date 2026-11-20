import { readFileSync, writeFileSync, mkdirSync, appendFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { gzipSync } from 'node:zlib';

export function staticFiles(manifest, root, seen = new Set()) {
    if (seen.has(root)) return [];
    seen.add(root);
    const chunk = manifest[root];
    if (!chunk) throw new Error(`Missing manifest entry: ${root}`);
    return [chunk.file, ...(chunk.imports ?? []).flatMap((key) => staticFiles(manifest, key, seen))];
}
export function measureBuild(manifest, read) {
    const size = (files) =>
        [...new Set(files)]
            .filter((file) => file.endsWith('.js'))
            .reduce((sum, file) => sum + gzipSync(read(file), { level: 9 }).length, 0);
    const app = staticFiles(manifest, 'resources/js/app.js');
    const chart = 'resources/js/Components/Charts/support/echarts.js';
    if (!manifest[chart]?.isDynamicEntry) throw new Error('ECharts must remain a dynamic entry');
    const languages = Object.keys(manifest).filter((key) => /^lang\/.*\.json$/.test(key));
    if (!languages.length) throw new Error('No translation chunks found');
    // laravel-vue-i18n requests both the JSON locale and the generated PHP locale.
    const locales = [...new Set(languages.map((key) => key.match(/^lang\/(?:php_)?(.+)\.json$/)[1]))];
    const withLocale = (files) =>
        Math.max(
            ...locales.map((locale) =>
                size([
                    ...files,
                    ...languages
                        .filter((key) => key === `lang/${locale}.json` || key === `lang/php_${locale}.json`)
                        .flatMap((key) => staticFiles(manifest, key)),
                ]),
            ),
        );
    const pages = Object.keys(manifest).filter(
        (key) => key.startsWith('resources/js/Pages/') && !key.includes('/Partials/'),
    );
    if (!pages.length) throw new Error('No page chunks found');
    const initial = pages
        .map((key) => {
            const files = [...new Set([...app, ...staticFiles(manifest, key)])];
            if (files.includes(manifest[chart].file)) throw new Error(`ECharts is loaded eagerly by ${key}`);
            return { page: key, bytes: withLocale(files) };
        })
        .sort((a, b) => b.bytes - a.bytes);
    return {
        entryGzip: withLocale(app),
        pageInitialGzip: initial[0].bytes,
        chartGzip: size(staticFiles(manifest, chart)),
        totalJsGzip: size(Object.values(manifest).map((chunk) => chunk.file)),
        pages: initial,
    };
}
export function violations(measured, limits) {
    const keys = ['entryGzip', 'pageInitialGzip', 'chartGzip', 'totalJsGzip'];
    return keys
        .filter((key) => {
            if (!Number.isFinite(limits[key]) || limits[key] <= 0 || !Number.isFinite(measured[key]))
                throw new Error(`Missing or invalid budget: ${key}`);
            return measured[key] > limits[key];
        })
        .map((key) => `${key}: ${measured[key]} bytes > ${limits[key]} bytes`);
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
    const limits = JSON.parse(readFileSync('tests/Browser/visual/budgets.json', 'utf8'));
    const measured = measureBuild(manifest, (file) => readFileSync(resolve('public/build', file)));
    const failures = violations(measured, limits);
    mkdirSync('ui-quality-results', { recursive: true });
    writeFileSync('ui-quality-results/bundle-budget.json', JSON.stringify({ measured, limits, failures }, null, 2));
    const lines = [
        '### JavaScript budget (gzip level 9)',
        '',
        '| Measurement | Bytes | Limit |',
        '|---|---:|---:|',
        ...Object.entries(limits).map(([key, limit]) => `| ${key} | ${measured[key]} | ${limit} |`),
    ];
    console.log(lines.join('\n'));
    if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, lines.join('\n') + '\n');
    if (failures.length) {
        console.error(failures.join('\n'));
        process.exitCode = 1;
    }
}
