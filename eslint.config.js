import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

// Surfaces and ink follow the shell tokens (docs/ui-forbedring.md 4.1). A translucent
// scrim may still use bg-black/…, and text-white stays on primary and error fills.
const noHardcodedColor = {
    meta: {
        type: 'problem',
        schema: [],
        messages: {
            banned: 'Use a shell or data token instead of {{value}}.',
        },
    },
    create(context) {
        const banned =
            /\b(?:bg-white|text-ink(?:-soft)?|bg-light-beige|border-ink|bg-black(?!\/)|text-black)\b|#(?:fff(?:fff)?|000(?:000)?)\b/i;

        const check = (node, value) => {
            if (typeof value !== 'string') {
                return;
            }
            const match = value.match(banned);
            if (match) {
                context.report({ node, messageId: 'banned', data: { value: match[0] } });
            }
        };

        return {
            Literal(node) {
                check(node, node.value);
            },
            TemplateElement(node) {
                check(node, node.value.cooked);
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
                },
            },
        },
        rules: {
            'ekko/no-hardcoded-color': 'error',
        },
    },
];
