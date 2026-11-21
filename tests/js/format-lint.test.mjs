// The ESLint rule ekko/format-through-format-js (docs/ux-arbeidsflate.md 4.2): toFixed(, toLocaleString(
// and Intl. are stopped everywhere in resources/js except format.js, and an exception needs a reason.
import assert from 'node:assert/strict';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';

const root = fileURLToPath(new URL('../..', import.meta.url));
const eslint = new ESLint({ cwd: root });

async function messages(code, file) {
    const [result] = await eslint.lintText(code, { filePath: `${root}/${file}` });

    return result.messages.filter((message) => message.ruleId === 'ekko/format-through-format-js');
}

test('Intl, toFixed and toLocaleString are stopped in scripts', async () => {
    const found = await messages(
        [
            'export const a = new Intl.NumberFormat("nb").format(1);',
            'export const b = (2.5).toFixed(1);',
            'export const c = new Date().toLocaleString();',
            'export const d = new Date().toLocaleDateString();',
            '',
        ].join('\n'),
        'resources/js/Pages/Example.js',
    );
    assert.equal(found.length, 4);
});

test('Intl is also stopped when it is destructured or read from the global object', async () => {
    const found = await messages(
        [
            'const { NumberFormat } = Intl;',
            'export const a = new globalThis.Intl.NumberFormat("nb");',
            'export const b = window["Intl"];',
            'export const c = NumberFormat;',
            '',
        ].join('\n'),
        'resources/js/Pages/Example.js',
    );
    assert.equal(found.length, 3);
});

test('the rule also reads Vue templates', async () => {
    const found = await messages(
        '<script setup>\nconst value = 2;\n</script>\n<template>\n    <p>{{ value.toFixed(1) }}</p>\n</template>\n',
        'resources/js/Pages/Example.vue',
    );
    assert.equal(found.length, 1);
});

test('format.js itself may use Intl', async () => {
    const found = await messages('export const a = new Intl.NumberFormat("nb").format(1);\n', 'resources/js/format.js');
    assert.equal(found.length, 0);
});

test('an exception needs a reason after --', async () => {
    const withoutReason = await messages(
        '// eslint-disable-next-line ekko/format-through-format-js\nexport const a = (2.5).toFixed(1);\n',
        'resources/js/Pages/Example.js',
    );
    assert.equal(withoutReason.length, 1);
    assert.match(withoutReason[0].message, /needs a reason/);

    const withReason = await messages(
        '// eslint-disable-next-line ekko/format-through-format-js -- a CSS length, not a number for people\nexport const a = (2.5).toFixed(1);\n',
        'resources/js/Pages/Example.js',
    );
    assert.equal(withReason.length, 0);
});

test('names that only look alike are allowed', async () => {
    const found = await messages(
        'const Intl2 = {}; export const a = Intl2.x; export const b = { toFixed: 1 }.toFixed; export const c = { Intl: 1 }.Intl2;\n',
        'resources/js/Pages/Example.js',
    );
    assert.equal(found.length, 0);
});
