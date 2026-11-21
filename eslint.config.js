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
        const defineTemplateBodyVisitor = context.sourceCode.parserServices?.defineTemplateBodyVisitor;

        return defineTemplateBodyVisitor ? defineTemplateBodyVisitor(visitor, visitor) : visitor;
    },
};

// The only allowed exception to ekko/format-through-format-js is
//     // eslint-disable-next-line ekko/format-through-format-js -- why
// (in a Vue template: <!-- eslint-disable-next-line ekko/format-through-format-js -- why -->).
// directiveProblem() decides it. The rule below runs it on script and template comments, and
// tests/js/format-lint.test.mjs runs it on every file in resources/js, because a directive such as
// /* eslint-disable */ on the first line can hide any ESLint report, including this rule's own.
const FORMAT_RULE = 'ekko/format-through-format-js';
const GUARD_RULE = 'ekko/format-exception-needs-reason';

/** null when a comment is fine, otherwise 'config', 'unlimited', 'guard' or 'form'. */
export function directiveProblem(text) {
    // /* eslint ekko/format-through-format-js: "off" */ turns a rule off for the whole file.
    if (/^\s*eslint\s/.test(text) && (text.includes(FORMAT_RULE) || text.includes(GUARD_RULE))) {
        return 'config';
    }
    const directive = /^\s*eslint-disable(-next-line|-line)?(?:\s+([^]*?))?\s*$/.exec(text);
    if (!directive) {
        return null;
    }
    const [rules = '', reason] = (directive[2] ?? '').split(/\s--\s/);
    if (rules.trim() === '') {
        return 'unlimited';
    }
    if (rules.includes(GUARD_RULE)) {
        return 'guard';
    }
    if (rules.includes(FORMAT_RULE) && (directive[1] !== '-next-line' || !reason?.trim())) {
        return 'form';
    }

    return null;
}

const formatExceptionNeedsReason = {
    meta: {
        type: 'problem',
        schema: [],
        messages: {
            form: `Line {{line}}: an exception to ${FORMAT_RULE} must be "eslint-disable-next-line ${FORMAT_RULE} -- reason".`,
            unlimited: `Line {{line}}: a disable comment without rule names also turns off ${FORMAT_RULE}. Name the rules.`,
            guard: `Line {{line}}: ${GUARD_RULE} cannot be turned off.`,
            config: `Line {{line}}: ${FORMAT_RULE} and ${GUARD_RULE} cannot be configured in a comment.`,
        },
    },
    create(context) {
        return {
            Program() {
                const comments = [
                    ...context.sourceCode.getAllComments(),
                    ...(context.sourceCode.ast.templateBody?.comments ?? []),
                ];
                for (const comment of comments) {
                    const problem = directiveProblem(comment.value);
                    if (problem) {
                        context.report({
                            loc: { line: 1, column: 0 },
                            messageId: problem,
                            data: { line: comment.loc.start.line },
                        });
                    }
                }
            },
        };
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
                    'format-exception-needs-reason': formatExceptionNeedsReason,
                },
            },
        },
        rules: {
            'ekko/no-hardcoded-color': 'error',
            'ekko/format-through-format-js': 'error',
            'ekko/format-exception-needs-reason': 'error',
        },
    },
    {
        files: ['resources/js/format.js'],
        rules: {
            'ekko/format-through-format-js': 'off',
        },
    },
];
