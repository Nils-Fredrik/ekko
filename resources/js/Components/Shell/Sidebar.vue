<script setup>
// The sidebar (docs/ux-arbeidsflate.md 4.1, sketch 1): tenant, search, portfolio and inbox, the brand and
// market in scope, the brand's pages in Analyse, Arbeid and Oppsett, usage and the user menu.
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { trans } from 'laravel-vue-i18n';
import { ChevronDownIcon, MagnifyingGlassIcon } from '@heroicons/vue/20/solid';
import { urlWithMarket } from '@/Components/paletteActions.js';
import { globalLinks, marketFromUrl, scopeKey, sidebarGroups } from '@/Components/Shell/sidebarNav.js';
import { useLocale } from '@/Composables/useLocale';
import { useWorkspace } from '@/Composables/useWorkspace';
import { formatCurrency, formatDate, formatNumber, formatPercent } from '@/format';
import { applyTheme, currentTheme } from '@/theme';

const emit = defineEmits(['search']);
const page = usePage();
// ⌘K on Apple devices, Ctrl+K elsewhere. Both work everywhere (CommandPalette).
const shortcut =
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '')
        ? '⌘K'
        : 'Ctrl+K';
const { workspace, brandId } = useWorkspace();
const { locale, locales, setLocale } = useLocale();

const user = computed(() => page.props.auth.user);
const tenantId = computed(() => user.value?.current_team_id ?? null);
const initials = computed(() =>
    (user.value?.name || '?')
        .split(' ')
        .map((part) => part.charAt(0))
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);
const current = (name) => route().current(name);
const to = (name, params) => route(name, params);

const counts = computed(() => workspace.value?.counts ?? null);
const markets = computed(() => workspace.value?.markets ?? []);
const marketId = computed(() => marketFromUrl(page.url, markets.value));
const market = computed(() => markets.value.find((item) => item.id === marketId.value) ?? null);
const brand = computed(() => workspace.value?.brand ?? null);
const brands = computed(() => workspace.value?.brands ?? []);

const links = computed(() =>
    globalLinks({ canViewPartner: Boolean(page.props.canViewPartner), counts: counts.value, current, to }),
);
const groups = computed(() =>
    sidebarGroups({
        brandId: brandId.value,
        marketId: marketId.value,
        analysisQuery: page.props.analysisFilters?.query ?? null,
        counts: counts.value,
        current,
        to,
        has: (name) => route().has(name),
    }),
);

const usage = computed(() => workspace.value?.usage ?? null);
const usageLine = computed(() => {
    const value = usage.value;
    if (!value) {
        return '';
    }
    const cost = formatCurrency(value.cost_usd, 'USD');

    return value.quota
        ? trans('workspace.shell.usage_quota', {
              calls: formatNumber(value.calls),
              quota: formatNumber(value.quota),
              cost,
          })
        : trans('workspace.shell.usage_calls', { calls: formatNumber(value.calls), cost });
});

function readScope(id) {
    try {
        const value = localStorage.getItem(scopeKey(tenantId.value, id));

        return value && /^\d+$/.test(value) ? Number(value) : null;
    } catch {
        return null;
    }
}

function rememberMarket(id) {
    try {
        localStorage.setItem(scopeKey(tenantId.value, brandId.value), id === null ? 'all' : String(id));
    } catch {
        // Private mode can refuse storage. The URL still holds the market.
    }
}

/** The same page for another brand when the route only takes {brand}, otherwise its overview. */
function brandHref(id) {
    const name = route().current();
    const params = Object.keys(route().params ?? {});
    const same = name && params.length === 1 && params[0] === 'brand' && name.startsWith('brands.');
    const stored = readScope(id);

    return route(same ? name : 'brands.show', { brand: id, ...(stored === null ? {} : { market: stored }) });
}

function marketLabel(item) {
    return item.is_active ? item.label : trans('workspace.shell.inactive', { market: item.label });
}

const theme = ref(currentTheme());
const themes = [
    { code: 'dark', label: () => trans('app.nav.themes.dark') },
    { code: 'light', label: () => trans('app.nav.themes.light') },
    { code: 'system', label: () => trans('app.nav.themes.system') },
];

function setTheme(option) {
    theme.value = option;
    applyTheme(option);
    window.dispatchEvent(new CustomEvent('ekko:theme', { detail: option }));
}

function switchToTeam(team) {
    router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });
}

function logout() {
    router.post(route('logout'));
}

const linkClass = (active) =>
    active ? 'bg-row-hover font-medium text-shell-fg' : 'text-shell-muted hover:bg-row-hover hover:text-shell-text';
const menuItemClass = (active) =>
    `flex w-full items-center justify-between gap-3 rounded-lg px-3 py-1.5 text-start text-[13px] no-underline ${
        active ? 'bg-row-hover text-shell-fg' : 'text-shell-text'
    }`;
const menuPanel =
    'absolute inset-x-0 z-50 flex max-h-[60vh] flex-col overflow-y-auto rounded-xl border border-line bg-shell-surface p-1 shadow-lg focus:outline-none';
const menuHeading = 'px-3 pt-2 pb-1 text-[11px] font-medium tracking-[0.08em] text-shell-muted uppercase';
</script>

<template>
    <nav
        :aria-label="$t('workspace.shell.main_nav')"
        class="relative flex min-h-full flex-col gap-3.5 px-3 py-4 text-sm"
        data-testid="sidebar"
    >
        <div class="relative flex items-center justify-between gap-2 px-1.5">
            <Link :href="route('dashboard')" class="flex items-center gap-2 no-underline">
                <span class="flex size-6 flex-none items-center justify-center rounded-[7px] bg-shell-panel">
                    <img src="/images/maksimer-m-square.svg" alt="" class="logo-mark size-[15px]" />
                </span>
                <span class="text-[15px] font-medium tracking-[-0.01em] text-shell-fg">{{ $t('app.name') }}</span>
            </Link>

            <Menu v-if="$page.props.jetstream.hasTeamFeatures && user.current_team" as="div" class="static min-w-0">
                <MenuButton
                    class="flex max-w-36 items-center gap-1 rounded-full border border-line-soft px-2.5 py-0.5 text-xs text-shell-muted hover:text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :aria-label="`${$t('workspace.shell.tenant_menu')}: ${user.current_team.name}`"
                    data-testid="tenant-menu"
                >
                    <span class="truncate">{{ user.current_team.name }}</span>
                    <ChevronDownIcon class="size-3 flex-none" aria-hidden="true" />
                </MenuButton>
                <MenuItems :class="[menuPanel, 'top-full mt-1']">
                    <template v-if="user.all_teams.length > 1 && !page.props.actingAs">
                        <div :class="menuHeading">{{ $t('Switch Teams') }}</div>
                        <MenuItem v-for="team in user.all_teams" :key="team.id" v-slot="{ active }">
                            <button type="button" :class="menuItemClass(active)" @click="switchToTeam(team)">
                                <span class="truncate">{{ team.name }}</span>
                                <span
                                    v-if="team.id == user.current_team_id"
                                    class="size-1.5 flex-none rounded-full bg-primary"
                                ></span>
                            </button>
                        </MenuItem>
                        <div class="my-1 border-t border-line-soft" />
                    </template>
                    <MenuItem v-slot="{ active }">
                        <Link :href="route('teams.show', user.current_team)" :class="menuItemClass(active)">
                            {{ $t('Team Settings') }}
                        </Link>
                    </MenuItem>
                    <MenuItem v-if="$page.props.jetstream.canCreateTeams" v-slot="{ active }">
                        <Link :href="route('teams.create')" :class="menuItemClass(active)">
                            {{ $t('Create New Team') }}
                        </Link>
                    </MenuItem>
                </MenuItems>
            </Menu>
        </div>

        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-[10px] border border-line bg-shell-surface px-2.5 py-2 text-[13px] text-shell-muted hover:text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :aria-keyshortcuts="shortcut === '⌘K' ? 'Meta+K' : 'Control+K'"
            data-testid="open-palette"
            @click="emit('search')"
        >
            <span class="flex min-w-0 items-center gap-2">
                <MagnifyingGlassIcon class="size-[15px] flex-none" aria-hidden="true" />
                <span class="truncate">{{ $t('workspace.shell.search') }}</span>
            </span>
            <kbd class="flex-none rounded-[5px] border border-line px-1.5 font-sans text-[11px]">{{ shortcut }}</kbd>
        </button>

        <ul class="flex flex-col gap-0.5">
            <li v-for="item in links" :key="item.id">
                <Link
                    :href="item.href"
                    class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-[5px] text-[13.5px] no-underline"
                    :class="linkClass(item.active)"
                    :aria-current="item.active ? 'page' : undefined"
                >
                    <span>{{ $t(item.label) }}</span>
                    <span
                        v-if="item.badge > 0"
                        class="rounded-full bg-primary-deep px-[7px] py-px text-[11px] text-white tabular-nums"
                        :data-testid="`badge-${item.id}`"
                    >
                        {{ formatNumber(item.badge) }}
                    </span>
                </Link>
            </li>
        </ul>

        <div
            class="relative flex flex-col gap-0.5 rounded-xl border border-line bg-shell-surface p-1.5"
            role="group"
            :aria-label="$t('workspace.shell.scope')"
        >
            <Menu as="div" class="static">
                <MenuButton
                    class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-start text-[13.5px] font-medium text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    data-testid="brand-picker"
                >
                    <span class="min-w-0">
                        <span class="block text-[11px] font-normal text-shell-muted">
                            {{ $t('workspace.shell.brand') }}
                        </span>
                        <span class="block truncate">
                            {{ brandId ? (brand?.name ?? '') : $t('workspace.shell.choose_brand') }}
                        </span>
                    </span>
                    <ChevronDownIcon class="size-3.5 flex-none text-shell-muted" aria-hidden="true" />
                </MenuButton>
                <MenuItems :class="[menuPanel, 'top-full mt-1']">
                    <MenuItem v-for="item in brands" :key="item.id" v-slot="{ active }">
                        <Link :href="brandHref(item.id)" :class="menuItemClass(active)">
                            <span class="truncate">{{ item.name }}</span>
                            <span
                                v-if="item.id === brandId"
                                class="size-1.5 flex-none rounded-full bg-primary"
                                :aria-label="$t('workspace.palette.current')"
                            ></span>
                        </Link>
                    </MenuItem>
                    <template v-if="page.props.canOnboard">
                        <div v-if="brands.length > 0" class="my-1 border-t border-line-soft" />
                        <MenuItem v-slot="{ active }">
                            <Link :href="route('onboarding.wizard')" :class="menuItemClass(active)">
                                {{ $t('workspace.shell.new_brand') }}
                            </Link>
                        </MenuItem>
                    </template>
                </MenuItems>
            </Menu>

            <template v-if="brandId">
                <div class="mx-1.5 h-px bg-line-soft"></div>
                <Menu as="div" class="static">
                    <MenuButton
                        class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-start text-[13.5px] text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        data-testid="market-picker"
                    >
                        <span class="min-w-0">
                            <span class="block text-[11px] text-shell-muted">{{ $t('workspace.shell.market') }}</span>
                            <span class="block truncate">
                                {{ market ? marketLabel(market) : $t('workspace.shell.all_markets') }}
                            </span>
                        </span>
                        <ChevronDownIcon class="size-3.5 flex-none text-shell-muted" aria-hidden="true" />
                    </MenuButton>
                    <MenuItems :class="[menuPanel, 'top-full mt-1']">
                        <MenuItem v-slot="{ active }">
                            <Link
                                :href="urlWithMarket(page.url, null)"
                                :class="menuItemClass(active)"
                                @click="rememberMarket(null)"
                            >
                                {{ $t('workspace.shell.all_markets') }}
                                <span
                                    v-if="marketId === null"
                                    class="size-1.5 flex-none rounded-full bg-primary"
                                    :aria-label="$t('workspace.palette.current')"
                                ></span>
                            </Link>
                        </MenuItem>
                        <MenuItem v-for="item in markets" :key="item.id" v-slot="{ active }">
                            <Link
                                :href="urlWithMarket(page.url, item.id)"
                                :class="menuItemClass(active)"
                                @click="rememberMarket(item.id)"
                            >
                                <span class="truncate">{{ marketLabel(item) }}</span>
                                <span
                                    v-if="item.id === marketId"
                                    class="size-1.5 flex-none rounded-full bg-primary"
                                    :aria-label="$t('workspace.palette.current')"
                                ></span>
                            </Link>
                        </MenuItem>
                    </MenuItems>
                </Menu>
            </template>
        </div>

        <div v-for="group in groups" :key="group.id" class="flex flex-col gap-0.5">
            <h2 class="px-2.5 pb-1 text-[11px] font-medium tracking-[0.08em] text-shell-muted uppercase">
                {{ $t(group.label) }}
            </h2>
            <ul class="flex flex-col gap-0.5">
                <li v-for="item in group.items" :key="item.id">
                    <Link
                        :href="item.href"
                        class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-[5px] text-[13.5px] no-underline"
                        :class="linkClass(item.active)"
                        :aria-current="item.active ? 'page' : undefined"
                        :data-testid="`nav-${item.id}`"
                    >
                        <span>{{ $t(item.label) }}</span>
                        <span
                            v-if="item.badge > 0"
                            class="rounded-full bg-line-soft px-[7px] py-px text-[11px] text-shell-text tabular-nums"
                            :data-testid="`badge-${item.id}`"
                        >
                            {{ formatNumber(item.badge) }}
                        </span>
                    </Link>
                </li>
            </ul>
        </div>

        <div class="flex-grow"></div>

        <Link
            v-if="usage"
            :href="route('usage.index')"
            class="flex flex-col gap-1.5 rounded-lg px-2.5 py-1 no-underline hover:bg-row-hover"
            data-testid="sidebar-usage"
        >
            <span class="flex justify-between text-xs text-shell-muted">
                <span>{{ $t('workspace.shell.usage', { month: formatDate(usage.month, { year: false }) }) }}</span>
                <span v-if="usage.percent !== null" class="tabular-nums">{{ formatPercent(usage.percent) }}</span>
            </span>
            <span
                v-if="usage.percent !== null"
                class="h-1 overflow-hidden rounded-full bg-line-soft"
                aria-hidden="true"
            >
                <span
                    class="block h-1 bg-primary"
                    :style="{ width: `${Math.min(100, Math.max(0, usage.percent * 100))}%` }"
                ></span>
            </span>
            <span class="text-xs text-shell-muted tabular-nums">{{ usageLine }}</span>
        </Link>

        <Menu as="div" class="relative border-t border-line-soft pt-2">
            <MenuButton
                class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1 text-start focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                data-testid="account-menu"
                :aria-label="`${$t('workspace.shell.user_menu')}: ${user.name}`"
            >
                <span
                    class="flex size-7 flex-none items-center justify-center rounded-full bg-line-soft text-[11px] font-medium text-shell-text"
                    aria-hidden="true"
                >
                    {{ initials }}
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="truncate text-[13px] text-shell-fg">{{ user.name }}</span>
                    <span v-if="workspace?.role" class="truncate text-xs text-shell-muted">{{ workspace.role }}</span>
                </span>
            </MenuButton>
            <MenuItems :class="[menuPanel, 'bottom-full mb-1']">
                <MenuItem v-slot="{ active }">
                    <Link :href="route('profile.show')" :class="menuItemClass(active)">{{ $t('Profile') }}</Link>
                </MenuItem>
                <MenuItem v-if="$page.props.jetstream.hasApiFeatures" v-slot="{ active }">
                    <Link :href="route('api-tokens.index')" :class="menuItemClass(active)">{{ $t('API Tokens') }}</Link>
                </MenuItem>
                <MenuItem v-slot="{ active }">
                    <Link :href="route('mcp.connections')" :class="menuItemClass(active)">{{ $t('mcp.nav') }}</Link>
                </MenuItem>

                <div class="my-1 border-t border-line-soft" />
                <div :class="menuHeading">{{ $t('app.nav.theme') }}</div>
                <MenuItem v-for="option in themes" :key="option.code" v-slot="{ active }">
                    <button type="button" :class="menuItemClass(active)" @click="setTheme(option.code)">
                        {{ option.label() }}
                        <span v-if="option.code === theme" class="size-1.5 rounded-full bg-primary"></span>
                    </button>
                </MenuItem>

                <div class="my-1 border-t border-line-soft" />
                <div :class="menuHeading">{{ $t('app.nav.language') }}</div>
                <MenuItem v-for="item in locales" :key="item.code" v-slot="{ active }">
                    <button type="button" :class="menuItemClass(active)" @click="setLocale(item.code)">
                        {{ item.label }}
                        <span v-if="item.code === locale" class="size-1.5 rounded-full bg-primary"></span>
                    </button>
                </MenuItem>

                <template v-if="user.is_platform_admin">
                    <div class="my-1 border-t border-line-soft" />
                    <div :class="menuHeading">{{ $t('workspace.shell.platform_admin') }}</div>
                    <MenuItem v-slot="{ active }">
                        <Link :href="route('admin.tenants.index')" :class="menuItemClass(active)">
                            {{ $t('admin.nav.tenants') }}
                        </Link>
                    </MenuItem>
                    <MenuItem v-slot="{ active }">
                        <Link :href="route('admin.plans.index')" :class="menuItemClass(active)">
                            {{ $t('admin.nav.plans') }}
                        </Link>
                    </MenuItem>
                    <MenuItem v-slot="{ active }">
                        <Link :href="route('admin.engines.index')" :class="menuItemClass(active)">
                            {{ $t('admin.nav.engines') }}
                        </Link>
                    </MenuItem>
                    <MenuItem v-slot="{ active }">
                        <Link :href="route('admin.usage')" :class="menuItemClass(active)">
                            {{ $t('admin.nav.usage') }}
                        </Link>
                    </MenuItem>
                    <MenuItem v-slot="{ active }">
                        <Link :href="route('admin.billing.index')" :class="menuItemClass(active)">
                            {{ $t('billing.title') }}
                        </Link>
                    </MenuItem>
                </template>

                <div class="my-1 border-t border-line-soft" />
                <MenuItem v-slot="{ active }">
                    <button type="button" :class="menuItemClass(active)" @click="logout">{{ $t('Log Out') }}</button>
                </MenuItem>
            </MenuItems>
        </Menu>
    </nav>
</template>
