import { computed } from 'vue';
import { useLocale } from '@/Composables/useLocale';

export function useDateTime() {
    const { intlLocale } = useLocale();
    const formatter = computed(
        () =>
            new Intl.DateTimeFormat(intlLocale.value, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }),
    );
    return { dateTime: (value) => (value ? formatter.value.format(new Date(value)) : '') };
}
