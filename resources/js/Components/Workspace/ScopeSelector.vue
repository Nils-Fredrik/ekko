<script setup>
import { computed, watch } from 'vue';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { ChevronDownIcon } from '@heroicons/vue/20/solid';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { urlWithMarket } from '@/Components/paletteActions.js';
import { useWorkspace } from '@/Composables/useWorkspace';
import { brandSwitchTarget, readScope, writeScope } from './workspaceNav.js';

const page = usePage();
const { workspace, brandId, markets, marketId, loading } = useWorkspace();

const tenantId = computed(() => page.props.auth?.user?.current_team_id ?? 0);
const brands = computed(() => workspace.value?.brands ?? []);
const brandName = computed(() => workspace.value?.brand?.name ?? null);
const marketLabel = computed(() => {
    const market = markets.value.find((item) => item.id === marketId.value);

    return market ? market.label : trans('workspace.shell.all_markets');
});

const storage = () => (typeof window === 'undefined' ? null : window.localStorage);

// The last market of each brand, used when the user switches brand here (docs/ux-arbeidsflate.md 4.1).
watch(
    [brandId, marketId],
    ([brand, market]) => {
        if (brand !== null && market !== null) {
            writeScope(storage(), tenantId.value, brand, market);
        }
    },
    { immediate: true },
);

function chooseBrand(id) {
    const target = brandSwitchTarget(route().current(), readScope(storage(), tenantId.value, id));
    router.visit(route(target.name, { brand: id, ...target.query }));
}

function chooseMarket(id) {
    if (brandId.value !== null) {
        writeScope(storage(), tenantId.value, brandId.value, id);
    }
    router.visit(urlWithMarket(page.url, id));
}

const itemClass = (active) =>
    [
        'flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-start text-[13px] no-underline',
        active ? 'bg-row-hover text-shell-fg' : 'text-shell-text',
    ].join(' ');
const panelClass =
    'absolute inset-x-0 top-full z-40 mt-1 max-h-80 overflow-y-auto rounded-xl border border-line bg-shell-surface p-1 shadow-lg focus:outline-none';
</script>

<template>
    <div
        class="flex flex-col gap-0.5 rounded-xl border border-line bg-shell-surface p-1.5"
        role="group"
        :aria-label="$t('workspace.shell.scope')"
        data-testid="scope-selector"
    >
        <Menu as="div" class="relative">
            <MenuButton
                class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-start text-[13.5px] font-medium text-shell-fg hover:bg-row-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                data-testid="scope-brand"
            >
                <span class="min-w-0">
                    <span class="block text-[11px] font-normal text-shell-muted">
                        {{ $t('workspace.shell.brand') }}
                    </span>
                    <span v-if="brandName" class="block truncate">{{ brandName }}</span>
                    <span
                        v-else-if="brandId !== null && loading"
                        class="ekko-skeleton mt-1 block h-3.5 w-24 rounded"
                    ></span>
                    <span v-else class="block truncate">{{ $t('workspace.shell.choose_brand') }}</span>
                </span>
                <ChevronDownIcon class="size-4 flex-none text-shell-muted" aria-hidden="true" />
            </MenuButton>
            <MenuItems :class="panelClass">
                <MenuItem v-for="item in brands" :key="item.id" v-slot="{ active }">
                    <button type="button" :class="itemClass(active)" @click="chooseBrand(item.id)">
                        <span class="truncate">{{ item.name }}</span>
                        <span v-if="item.id === brandId" class="h-1.5 w-1.5 flex-none rounded-full bg-primary">
                            <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
                        </span>
                    </button>
                </MenuItem>
                <div v-if="brands.length" class="my-1 border-t border-line-soft" />
                <MenuItem v-slot="{ active }">
                    <Link :href="route('brands.index')" :class="itemClass(active)">
                        {{ $t('workspace.shell.all_brands') }}
                    </Link>
                </MenuItem>
                <MenuItem v-if="page.props.canOnboard" v-slot="{ active }">
                    <Link :href="route('onboarding.wizard')" :class="itemClass(active)">
                        {{ $t('workspace.shell.new_brand') }}
                    </Link>
                </MenuItem>
            </MenuItems>
        </Menu>

        <template v-if="brandId !== null">
            <div class="mx-1.5 h-px bg-line-soft" aria-hidden="true"></div>
            <Menu as="div" class="relative">
                <MenuButton
                    class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-start text-[13.5px] text-shell-fg hover:bg-row-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    data-testid="scope-market"
                >
                    <span class="min-w-0">
                        <span class="block text-[11px] text-shell-muted">{{ $t('workspace.shell.market') }}</span>
                        <span class="block truncate">{{ marketLabel }}</span>
                    </span>
                    <ChevronDownIcon class="size-4 flex-none text-shell-muted" aria-hidden="true" />
                </MenuButton>
                <MenuItems :class="panelClass">
                    <MenuItem v-slot="{ active }">
                        <button type="button" :class="itemClass(active)" @click="chooseMarket(null)">
                            <span>{{ $t('workspace.shell.all_markets') }}</span>
                            <span v-if="marketId === null" class="h-1.5 w-1.5 flex-none rounded-full bg-primary">
                                <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
                            </span>
                        </button>
                    </MenuItem>
                    <MenuItem v-for="market in markets" :key="market.id" v-slot="{ active }">
                        <button type="button" :class="itemClass(active)" @click="chooseMarket(market.id)">
                            <span class="truncate" :class="market.is_active ? '' : 'text-shell-muted'">
                                {{
                                    market.is_active
                                        ? market.label
                                        : $t('workspace.shell.inactive_market', { market: market.label })
                                }}
                            </span>
                            <span v-if="market.id === marketId" class="h-1.5 w-1.5 flex-none rounded-full bg-primary">
                                <span class="sr-only">{{ $t('workspace.palette.current') }}</span>
                            </span>
                        </button>
                    </MenuItem>
                </MenuItems>
            </Menu>
        </template>
    </div>
</template>
