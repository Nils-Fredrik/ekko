import { computed, shallowRef, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

// The last `workspace` prop the server sent. The prop is deferred, so each visit renders without it first;
// keeping the last value stops the sidebar from blinking while the new one loads.
const last = shallowRef(null);

/**
 * The shared `workspace` prop (docs/ux-arbeidsflate.md 4.1) for the brand in the URL. While the prop for a new
 * visit loads, the previous one is used, but its brand, markets and counts only when they are for the same brand.
 */
export function useWorkspace() {
    const page = usePage();
    const tenantId = () => page.props.auth?.user?.current_team_id ?? null;

    watch(
        () => page.props.workspace,
        (value) => {
            if (value !== undefined) {
                last.value = { tenant: tenantId(), value };
            }
        },
        { immediate: true },
    );

    const brandId = computed(() => {
        // Ziggy reads the address bar; page.url makes the value follow each visit.
        void page.url;
        const value = route().params?.brand;

        return value === undefined || value === null || !/^\d+$/.test(String(value)) ? null : Number(value);
    });

    const workspace = computed(() => {
        const current = page.props.workspace;
        if (current !== undefined) {
            return current;
        }
        const previous = last.value?.tenant === tenantId() ? last.value.value : null;
        if (!previous) {
            return null;
        }
        if (previous.brand?.id === brandId.value) {
            return previous;
        }
        const brand = previous.brands?.find((item) => item.id === brandId.value) ?? null;

        return {
            ...previous,
            brand,
            markets: [],
            market_id: null,
            counts: { inbox: previous.counts?.inbox ?? 0 },
        };
    });

    return { workspace, brandId, loading: computed(() => page.props.workspace === undefined) };
}
