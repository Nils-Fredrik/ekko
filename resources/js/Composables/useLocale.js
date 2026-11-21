import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { intlLocale as toIntlLocale } from '@/format';

export function useLocale() {
    const page = usePage();
    const locale = computed(() => page.props.locale ?? 'nb');
    const intlLocale = computed(() => toIntlLocale(locale.value));

    const setLocale = (code) =>
        router.put(
            route('locale.update'),
            { locale: code },
            {
                preserveScroll: true,
            },
        );

    return {
        locale,
        intlLocale,
        locales: computed(() => page.props.locales ?? []),
        setLocale,
    };
}
