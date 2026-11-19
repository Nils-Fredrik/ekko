import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import { applyTheme, currentTheme, watchSystemTheme } from './theme';

// i18n is created once, from the first page. Later visits (login, language switch)
// only change the shared locale prop, so keep the document language and the
// Vue translations in step with that prop whenever it changes.
let appliedLocale = null;

function applyLocale(locale) {
    if (typeof locale !== 'string' || locale === '' || locale === appliedLocale) {
        return;
    }

    appliedLocale = locale;
    document.documentElement.lang = locale;
    loadLanguageAsync(locale);
}

router.on('success', (event) => {
    applyLocale(event.detail.page.props.locale);
});

const appName = import.meta.env.VITE_APP_NAME || 'Ekko';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        appliedLocale = typeof props.initialPage.props.locale === 'string' ? props.initialPage.props.locale : 'nb';

        const themePref = { current: currentTheme() };
        applyTheme(themePref.current);
        watchSystemTheme(() => themePref.current);
        window.addEventListener('ekko:theme', (event) => {
            themePref.current = event.detail;
        });

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18nVue, {
                // The server decides the language (user preference, otherwise the app default).
                lang: props.initialPage.props.locale,
                fallbackLang: 'nb',
                resolve: async (lang) => {
                    const langs = import.meta.glob('../../lang/*.json');
                    return await langs[`../../lang/${lang}.json`]();
                },
            })
            .mount(el);
    },
    progress: {
        color: getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim() || '#e84a38',
    },
});
