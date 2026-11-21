import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { marketFromUrl } from '@/Components/Workspace/workspaceNav.js';

// The last `workspace` prop per tenant. The prop is deferred, so each visit renders before it arrives;
// until then the sidebar keeps what it showed, as long as it describes the same tenant and brand.
const last = ref(null);

/**
 * The shell's scope (docs/ux-arbeidsflate.md 4.1): the brand from the {brand} route parameter, the
 * market from ?market=<id> and the shared `workspace` prop.
 */
export function useWorkspace() {
    const page = usePage();
    const tenantKey = computed(
        () => `${page.props.auth?.user?.current_team_id ?? ''}:${page.props.actingAs?.tenant ?? ''}`,
    );

    watch(
        () => page.props.workspace,
        (value) => {
            if (value) {
                last.value = { tenant: tenantKey.value, value };
            }
        },
        { immediate: true },
    );

    // Ziggy reads the address bar; page.url makes the computed follow each visit.
    const brandId = computed(() => {
        void page.url;
        const value = route().params?.brand;

        return value !== undefined && /^\d+$/.test(String(value)) ? Number(value) : null;
    });

    const workspace = computed(() => {
        const fresh = page.props.workspace;
        if (fresh !== undefined) {
            return fresh;
        }
        const stale = last.value?.tenant === tenantKey.value ? last.value.value : null;
        if (!stale || (stale.brand?.id ?? null) === brandId.value) {
            return stale;
        }
        const brand = brandId.value === null ? null : (stale.brands.find((item) => item.id === brandId.value) ?? null);

        return {
            ...stale,
            brand,
            markets: [],
            market_id: null,
            counts: { ...stale.counts, actions_new: 0, entities_pending: 0, integrations_failing: 0 },
        };
    });

    const markets = computed(() => workspace.value?.markets ?? []);
    const marketId = computed(() => marketFromUrl(page.url, markets.value));

    /** Ziggy's route().current, re-evaluated on every visit. */
    const current = (name) => {
        void page.url;

        return route().current(name);
    };

    return {
        workspace,
        brandId,
        markets,
        marketId,
        current,
        loading: computed(() => page.props.workspace === undefined),
    };
}
