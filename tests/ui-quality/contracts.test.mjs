import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { staticFiles, measureBuild, violations } from '../../scripts/ui-quality/budget.mjs';
import { pages, inactivePages } from '../Browser/visual/pages.mjs';

function vuePages(dir, prefix = '') {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
        entry.isDirectory()
            ? entry.name === 'Partials'
                ? []
                : vuePages(join(dir, entry.name), prefix + entry.name + '/')
            : entry.name.endsWith('.vue')
              ? [prefix + entry.name.slice(0, -4)]
              : [],
    );
}
test('every Inertia page has a visual and axe scenario or an explicit inactive-route reason', () => {
    const actual = vuePages('resources/js/Pages').sort();
    const covered = [
        ...new Set(
            pages
                .map((row) => row[1])
                .filter(Boolean)
                .concat(Object.keys(inactivePages)),
        ),
    ].sort();
    assert.deepEqual(covered, actual);
    assert.equal(new Set(pages.map((row) => row[0])).size, pages.length);
    for (const reason of Object.values(inactivePages)) assert.ok(reason.length > 30);
    assert.match(readFileSync('config/jetstream.php', 'utf8'), /\/\/ Features::termsAndPrivacyPolicy\(\)/);
});
test('budget gate fails on every exceeded limit and on incomplete measurements', () => {
    const limits = { entryGzip: 20, pageInitialGzip: 30, chartGzip: 50, totalJsGzip: 90 };
    assert.deepEqual(violations(limits, limits), []);
    for (const key of Object.keys(limits))
        assert.equal(violations({ ...limits, [key]: limits[key] + 1 }, limits).length, 1);
    assert.throws(() => violations({}, limits));
});
test('static closures count shared imports once and reject eager chart imports', () => {
    const chart = 'resources/js/Components/Charts/support/echarts.js';
    const manifest = {
        'resources/js/app.js': { file: 'app.js', imports: ['shared'] },
        shared: { file: 'shared.js' },
        'resources/js/Pages/Home.vue': { file: 'home.js', imports: ['shared'] },
        'lang/php_en.json': { file: 'php-en.js' },
        'lang/en.json': { file: 'en.js' },
        [chart]: { file: 'chart.js', isDynamicEntry: true },
    };
    assert.deepEqual(staticFiles(manifest, 'resources/js/app.js'), ['app.js', 'shared.js']);
    const result = measureBuild(manifest, (file) => Buffer.from(file.repeat(10)));
    assert.ok(result.pageInitialGzip > result.entryGzip);
    const singleLocale = measureBuild(
        Object.fromEntries(Object.entries(manifest).filter(([key]) => key !== 'lang/php_en.json')),
        (file) => Buffer.from(file.repeat(10)),
    );
    assert.ok(result.entryGzip > singleLocale.entryGzip, 'Both PHP and JSON translations count toward initial load');
    manifest['resources/js/Pages/Home.vue'].imports.push(chart);
    assert.throws(() => measureBuild(manifest, (file) => Buffer.from(file)), /eagerly/);
    assert.throws(() => staticFiles(manifest, 'missing'), /Missing/);
});
