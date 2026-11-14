import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

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
];
