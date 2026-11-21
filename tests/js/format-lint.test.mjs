// The ESLint rule ekko/format-through-format-js (docs/ux-arbeidsflate.md 4.2): toFixed(, toLocaleString(
// and Intl. are stopped everywhere in resources/js except format.js, and an exception needs a reason.
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';
import { directiveProblem } from '../../eslint.config.js';

const root = fileURLToPath(new URL('../..', import.meta.url));
const eslint = new ESLint({ cwd: root });

async function messages(code, file) {
    const [result] = await eslint.lintText(code, { filePath: `${root}/${file}` });

    return result.messages.filter((message) =>
        ['ekko/format-through-format-js', 'ekko/format-exception-needs-reason'].includes(message.ruleId),
    );
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
    assert.match(withoutReason[0].message, /-- reason/);

    const withReason = await messages(
        '// eslint-disable-next-line ekko/format-through-format-js -- a CSS length, not a number for people\nexport const a = (2.5).toFixed(1);\n',
        'resources/js/Pages/Example.js',
    );
    assert.equal(withReason.length, 0);
});

test('an exception on the same line or as a block is not allowed, with or without a reason', async () => {
    for (const code of [
        'export const a = (2.5).toFixed(1); // eslint-disable-line ekko/format-through-format-js\n',
        'export const a = (2.5).toFixed(1); // eslint-disable-line ekko/format-through-format-js -- why\n',
        '/* eslint-disable ekko/format-through-format-js */\nexport const a = (2.5).toFixed(1);\n',
        '\n/* eslint-disable ekko/format-through-format-js -- why */\nexport const a = (2.5).toFixed(1);\n',
    ]) {
        const found = await messages(code, 'resources/js/Pages/Example.js');
        assert.equal(found.length, 1, code);
        assert.equal(found[0].ruleId, 'ekko/format-exception-needs-reason');
    }
});

test('a disable comment without rule names is not allowed', async () => {
    const found = await messages(
        '\n/* eslint-disable */\nexport const a = (2.5).toFixed(1);\n',
        'resources/js/Pages/Example.js',
    );
    assert.equal(found.length, 1);
    assert.match(found[0].message, /Line 2/);
});

test('an exception in a Vue template needs the same form and reason', async () => {
    const template = (comment) =>
        `<script setup>\nconst value = 2;\n</script>\n<template>\n    ${comment}\n    <p>{{ value.toFixed(1) }}</p>\n</template>\n`;

    for (const comment of [
        '<!-- eslint-disable-next-line ekko/format-through-format-js -->',
        '<!-- eslint-disable -->',
        '<!-- eslint-disable ekko/format-through-format-js -- why -->',
    ]) {
        const found = await messages(template(comment), 'resources/js/Pages/Example.vue');
        assert.ok(
            found.some((message) => message.ruleId === 'ekko/format-exception-needs-reason'),
            comment,
        );
    }

    const allowed = await messages(
        template('<!-- eslint-disable-next-line ekko/format-through-format-js -- a CSS length -->'),
        'resources/js/Pages/Example.vue',
    );
    assert.equal(allowed.length, 0);
});

// Every comment form in a .js or .vue file, with its text. Only comments with eslint-disable matter.
function directives(source) {
    const pattern = /\/\/([^\n]*)|\/\*([^]*?)\*\/|<!--([^]*?)-->/g;

    return [...source.matchAll(pattern)]
        .map((match) => match[1] ?? match[2] ?? match[3])
        .filter((text) => /\beslint[\s-]/.test(text));
}

test('a rule configuration comment cannot turn the format rule or its guard off', async () => {
    const formatOff = '/* eslint ekko/format-through-format-js: "off" */';
    const found = await messages(
        `\n${formatOff}\nexport const a = (2.5).toFixed(1);\n`,
        'resources/js/Pages/Example.js',
    );
    assert.ok(found.some((message) => message.ruleId === 'ekko/format-exception-needs-reason'));
    assert.equal(directiveProblem(formatOff.slice(2, -2)), 'config');
    // Turning the guard itself off hides its ESLint report too; the file scan below catches it.
    const guardOff = '/* eslint ekko/format-exception-needs-reason: "off", ekko/format-through-format-js: "off" */';
    assert.equal(directiveProblem(guardOff.slice(2, -2)), 'config');
    const template = await messages(
        '<script setup>\nconst value = 2;\n</script>\n<template>\n    <!-- eslint ekko/format-through-format-js: "off" -->\n    <p>{{ value.toFixed(1) }}</p>\n</template>\n',
        'resources/js/Pages/Example.vue',
    );
    assert.ok(template.some((message) => message.ruleId === 'ekko/format-exception-needs-reason'));
    assert.equal(directiveProblem(' eslint vue/no-v-html: "off" '), null);
    assert.deepEqual(
        directives('/* eslint ekko/format-through-format-js: "off" */\nexport const a = 1;\n').map(directiveProblem),
        ['config'],
    );
});

test('directiveProblem allows only a next-line exception with a reason', () => {
    assert.equal(directiveProblem(' eslint-disable-next-line ekko/format-through-format-js -- why '), null);
    assert.equal(directiveProblem(' eslint-disable-next-line vue/no-v-html '), null);
    assert.equal(directiveProblem(' eslint-disable-next-line ekko/format-through-format-js'), 'form');
    assert.equal(directiveProblem(' eslint-disable-line ekko/format-through-format-js -- why'), 'form');
    assert.equal(directiveProblem(' eslint-disable '), 'unlimited');
    assert.equal(
        directiveProblem(' eslint-disable ekko/format-exception-needs-reason, ekko/format-through-format-js '),
        'guard',
    );
    // A directive on the very first line hides every ESLint report, so this scan is what catches it.
    assert.deepEqual(directives('/* eslint-disable */\nexport const a = (2.5).toFixed(1);\n').map(directiveProblem), [
        'unlimited',
    ]);
});

test('no file in resources/js turns the format rule off without a next-line reason', () => {
    const base = fileURLToPath(new URL('../../resources/js', import.meta.url));
    const files = [];
    const walk = (dir) => {
        for (const name of readdirSync(dir)) {
            const path = join(dir, name);
            if (statSync(path).isDirectory()) {
                walk(path);
            } else if (/\.(js|vue)$/.test(name)) {
                files.push(path);
            }
        }
    };
    walk(base);
    assert.ok(files.length > 50);

    const problems = files.flatMap((file) =>
        directives(readFileSync(file, 'utf8'))
            .map((text) => [relative(base, file), directiveProblem(text), text.trim()])
            .filter(([, problem]) => problem),
    );
    assert.deepEqual(problems, []);
});

test('names that only look alike are allowed', async () => {
    const found = await messages(
        'const Intl2 = {}; export const a = Intl2.x; export const b = { toFixed: 1 }.toFixed; export const c = { Intl: 1 }.Intl2;\n',
        'resources/js/Pages/Example.js',
    );
    assert.equal(found.length, 0);
});
