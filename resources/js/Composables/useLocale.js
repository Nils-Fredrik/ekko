import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';

const BCP47 = { nb: 'nb-NO', sv: 'sv-SE', en: 'en-GB' };

export function useLocale() {
    const page = usePage();
    const locale = computed(() => page.props.locale ?? 'nb');
    const intlLocale = computed(() => BCP47[locale.value] ?? 'nb-NO');

    const setLocale = (code) =>
        router.put(
            route('locale.update'),
            { locale: code },
            {
                preserveScroll: true,
                onSuccess: () => loadLanguageAsync(code),
            },
        );

    return {
        locale,
        intlLocale,
        locales: computed(() => page.props.locales ?? []),
        setLocale,
    };
}
