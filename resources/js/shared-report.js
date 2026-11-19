import '../css/app.css';
import { createApp } from 'vue';
import { i18nVue } from 'laravel-vue-i18n';
import SharedReportTrend from './Components/Reports/SharedReportTrend.vue';

const root = document.getElementById('shared-report-chart');
const data = document.getElementById('shared-report-chart-data');
if (root && data) {
    const props = JSON.parse(data.textContent);
    createApp(SharedReportTrend, props)
        .use(i18nVue, {
            lang: document.documentElement.lang || 'nb',
            fallbackLang: 'nb',
            resolve: async (lang) => {
                const langs = import.meta.glob('../../lang/*.json');
                return await langs[`../../lang/${lang}.json`]();
            },
        })
        .mount(root);
}
