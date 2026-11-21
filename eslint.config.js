import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

// Surfaces and ink follow the shell tokens (docs/ui-forbedring.md 4.1). A translucent
// scrim may still use bg-black/…, and text-white stays on primary and error fills.
// The Google sign-in mark keeps its brand colors; they are not UI surfaces.
const allowedHex = new Set(['#ea4335', '#4285f4', '#fbbc05', '#34a853']);
const noHardcodedColor = {
    meta: {
        type: 'problem',
        schema: [],
        messages: {
            banned: 'Use a shell or data token instead of {{value}}.',
        },
    },
    create(context) {
        const utility = 'bg|text|border|fill|stroke|from|to|via|ring|outline|caret|accent|decoration';
        const banned = new RegExp(
            [
                String.raw`\b(?:${utility})-\[(?:#[0-9a-fA-F]{3,8}|(?:rgba?|hsla?|hwb|oklch|oklab)\([^)]*\))\]`,
                String.raw`#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})\b`,
                String.raw`\b(?:fill|stroke)-(?:black|white)\b`,
                String.raw`\b(?:bg-white|text-ink(?:-soft)?|bg-light-beige|border-ink|bg-black(?!\/)|text-black)\b`,
            ].join('|'),
            'gi',
        );

        const check = (node, value) => {
            if (typeof value !== 'string') {
                return;
            }
            banned.lastIndex = 0;
            for (const match of value.matchAll(banned)) {
                if (allowedHex.has(match[0].toLowerCase())) {
                    continue;
                }
                context.report({ node, messageId: 'banned', data: { value: match[0] } });
                return;
            }
        };

        const scriptVisitor = {
            Literal(node) {
                check(node, node.value);
            },
            TemplateElement(node) {
                check(node, node.value.cooked);
            },
        };
        const templateVisitor = {
            ...scriptVisitor,
            VLiteral(node) {
                check(node, node.value);
            },
        };
        const defineTemplateBodyVisitor = context.sourceCode.parserServices?.defineTemplateBodyVisitor;

        return defineTemplateBodyVisitor ? defineTemplateBodyVisitor(templateVisitor, scriptVisitor) : scriptVisitor;
    },
};

// Numbers, money, shares and dates go through resources/js/format.js (docs/ux-arbeidsflate.md 4.2).
// An exception needs a reason: // eslint-disable-next-line ekko/format-through-format-js -- why
const formatThroughFormatJs = {
    meta: {
        type: 'problem',
        schema: [],
        messages: {
            banned: 'Format through resources/js/format.js instead of {{what}}.',
            reason: 'An exception to ekko/format-through-format-js needs a reason after "--".',
        },
    },
    create(context) {
        const methods = new Set(['toFixed', 'toLocaleString', 'toLocaleDateString', 'toLocaleTimeString']);
        const visitor = {
            // Intl as a value anywhere: Intl.X, const { NumberFormat } = Intl, f(Intl).
            Identifier(node) {
                if (node.name !== 'Intl') {
                    return;
                }
                const parent = node.parent;
                const isKey =
                    (parent.type === 'MemberExpression' && parent.property === node && !parent.computed) ||
                    (parent.type === 'Property' && parent.key === node && !parent.computed && parent.value !== node);
                if (!isKey) {
                    context.report({ node, messageId: 'banned', data: { what: 'Intl.' } });
                }
            },
            MemberExpression(node) {
                const name = node.computed ? node.property.value : node.property.name;
                // globalThis.Intl, window.Intl, self['Intl']
                if (name === 'Intl') {
                    context.report({ node, messageId: 'banned', data: { what: 'Intl.' } });
                    return;
                }
                if (methods.has(name) && node.parent.type === 'CallExpression' && node.parent.callee === node) {
                    context.report({ node, messageId: 'banned', data: { what: `${name}(` } });
                }
            },
        };
        const programVisitor = {
            ...visitor,
            Program() {
                for (const comment of context.sourceCode.getAllComments()) {
                    if (
                        /eslint-disable[\w-]*\s[^]*ekko\/format-through-format-js/.test(comment.value) &&
                        !/\s--\s*\S/.test(comment.value)
                    ) {
                        context.report({ loc: comment.loc, messageId: 'reason' });
                    }
                }
            },
        };
        const defineTemplateBodyVisitor = context.sourceCode.parserServices?.defineTemplateBodyVisitor;

        return defineTemplateBodyVisitor ? defineTemplateBodyVisitor(visitor, programVisitor) : programVisitor;
    },
};

export default [
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/ssr/**'] },
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                axios: 'readonly',
                route: 'readonly',
                Ziggy: 'readonly',
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/require-default-prop': 'off',
            // All user texts come from the language files (AGENTS.md): use $t('…') in templates.
            'vue/no-bare-strings-in-template': [
                'error',
                {
                    allowlist: ['-', '·', '•', '/', ',', '.', ':', '(', ')', '&times;', '&nbsp;', '&hellip;'],
                    attributes: {
                        '/.+/': ['title', 'aria-label', 'aria-placeholder', 'aria-roledescription', 'aria-valuetext'],
                        input: ['placeholder'],
                        img: ['alt'],
                        InputLabel: ['value'],
                        Head: ['title'],
                        AppLayout: ['title'],
                    },
                    directives: ['v-text'],
                },
            ],
        },
    },
    prettier,
    {
        files: ['resources/js/**/*.{vue,js}'],
        plugins: {
            ekko: {
                rules: {
                    'no-hardcoded-color': noHardcodedColor,
                    'format-through-format-js': formatThroughFormatJs,
                },
            },
        },
        rules: {
            'ekko/no-hardcoded-color': 'error',
            'ekko/format-through-format-js': 'error',
        },
    },
    {
        files: ['resources/js/format.js'],
        rules: {
            'ekko/format-through-format-js': 'off',
        },
    },
];
