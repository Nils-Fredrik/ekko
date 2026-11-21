<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { formatCurrency, formatMonth, formatNumber, formatPercent } from '@/format';
import { useWorkspace } from '@/Composables/useWorkspace';
import ScopeSelector from './ScopeSelector.vue';
import TenantMenu from './TenantMenu.vue';
import UserMenu from './UserMenu.vue';
import { NAV_GROUPS, countFor, isActive, linkQuery } from './workspaceNav.js';

defineEmits(['search']);

const page = usePage();
const { workspace, brandId, marketId, current } = useWorkspace();

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
const counts = computed(() => workspace.value?.counts ?? null);

const globalLinks = computed(() => {
    const partner = Boolean(page.props.canViewPartner);
    const links = [
        {
            id: 'portfolio',
            href: route(partner ? 'partner.index' : 'dashboard'),
            label: trans('workspace.shell.portfolio'),
            active: current('dashboard') || current('partner.index'),
        },
        {
            id: 'inbox',
            href: route('inbox.index'),
            label: trans('workspace.shell.inbox'),
            active: current('alerts.*'),
            count: Number(counts.value?.inbox ?? 0) || null,
            countLabel: 'workspace.shell.badges.inbox',
            urgent: true,
        },
        {
            id: 'reports',
            href: route('reports.index'),
            label: trans('workspace.shell.reports'),
            active: current('reports.*'),
        },
    ];
    if (partner) {
        links.push({
            id: 'pitches',
            href: route('pitches.index'),
            label: trans('pitch.ui.nav'),
            active: current('pitches.index'),
        });
    }

    return links;
});

const groups = computed(() => {
    if (brandId.value === null) {
        return [];
    }
    const analysisQuery = page.props.analysisFilters?.query ?? null;

    return NAV_GROUPS.map((group) => ({
        id: group.id,
        label: trans(`workspace.shell.groups.${group.id}`),
        items: group.items.map((item) => ({
            id: item.id,
            href: route(item.route, {
                brand: brandId.value,
                ...linkQuery(item, { marketId: marketId.value, analysisQuery }),
            }),
            label: trans(`workspace.shell.links.${item.id}`),
            active: isActive(item, current),
            count: countFor(item, counts.value),
            countLabel: item.count ? `workspace.shell.badges.${item.count}` : null,
        })),
    }));
});

const usage = computed(() => (page.props.canViewUsage ? (workspace.value?.usage ?? null) : null));
const usageWidth = computed(() => `${Math.min(100, Math.max(0, usage.value?.percent ?? 0))}%`);

const linkClass = (active) =>
    [
        'flex items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-[13.5px] no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
        active ? 'bg-row-hover font-medium text-shell-fg' : 'text-shell-muted hover:bg-row-hover hover:text-shell-fg',
    ].join(' ');
</script>

<template>
    <nav
        class="relative flex min-h-full flex-col gap-4 px-3 py-4"
        :aria-label="$t('workspace.shell.main_nav')"
        data-testid="workspace-sidebar"
    >
        <div class="flex items-center justify-between gap-2 px-1.5">
            <Link :href="route('dashboard')" class="flex items-center gap-2 no-underline">
                <span class="flex size-6 flex-none items-center justify-center rounded-[7px] bg-shell-panel">
                    <img src="/images/maksimer-m-square.svg" alt="" class="logo-mark size-[15px]" />
                </span>
                <span class="text-[15px] font-medium tracking-[-0.01em] text-shell-fg">{{ $t('app.name') }}</span>
            </Link>
            <TenantMenu />
        </div>

        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-[10px] border border-line bg-shell-surface px-2.5 py-2 text-[13px] text-shell-muted hover:text-shell-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            aria-keyshortcuts="Meta+K Control+K"
            data-testid="open-palette"
            @click="$emit('search')"
        >
            <span class="flex items-center gap-2">
                <svg
                    aria-hidden="true"
                    width="15"
                    height="15"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
                {{ $t('workspace.shell.search') }}
            </span>
            <kbd class="rounded-[5px] border border-line px-1.5 font-sans text-[11px]">
                {{ isMac ? '⌘K' : 'Ctrl+K' }}
            </kbd>
        </button>

        <ul class="flex flex-col gap-0.5" role="list">
            <li v-for="item in globalLinks" :key="item.id">
                <Link
                    :href="item.href"
                    :class="linkClass(item.active)"
                    :aria-current="item.active ? 'page' : undefined"
                    :data-testid="`nav-${item.id}`"
                >
                    <span>{{ item.label }}</span>
                    <span
                        v-if="item.count"
                        class="rounded-full bg-primary-deep px-[7px] py-px text-[11px] text-white tabular-nums"
                    >
                        <span aria-hidden="true">{{ formatNumber(item.count) }}</span>
                        <span class="sr-only">{{ $t(item.countLabel, { n: formatNumber(item.count) }) }}</span>
                    </span>
                </Link>
            </li>
        </ul>

        <ScopeSelector />

        <section v-for="group in groups" :key="group.id" :aria-labelledby="`nav-group-${group.id}`">
            <h2
                :id="`nav-group-${group.id}`"
                class="px-2.5 pb-1 text-[11px] font-medium tracking-[0.08em] text-shell-muted uppercase"
            >
                {{ group.label }}
            </h2>
            <ul class="flex flex-col gap-0.5" role="list">
                <li v-for="item in group.items" :key="item.id">
                    <Link
                        :href="item.href"
                        :class="linkClass(item.active)"
                        :aria-current="item.active ? 'page' : undefined"
                        :data-testid="`nav-${item.id}`"
                    >
                        <span>{{ item.label }}</span>
                        <span
                            v-if="item.count"
                            class="rounded-full bg-line-soft px-[7px] py-px text-[11px] font-normal text-shell-text tabular-nums"
                        >
                            <span aria-hidden="true">{{ formatNumber(item.count) }}</span>
                            <span class="sr-only">{{ $t(item.countLabel, { n: formatNumber(item.count) }) }}</span>
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <div class="grow"></div>

        <Link
            v-if="usage"
            :href="route('usage.index')"
            class="flex flex-col gap-1.5 rounded-lg px-2.5 py-1 no-underline hover:bg-row-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            data-testid="nav-usage"
        >
            <span class="flex justify-between text-xs text-shell-muted">
                <span>{{ $t('workspace.shell.usage.title', { month: formatMonth(usage.month) }) }}</span>
                <span v-if="usage.percent !== null" class="tabular-nums">{{ formatPercent(usage.percent / 100) }}</span>
            </span>
            <span
                v-if="usage.percent !== null"
                class="h-1 overflow-hidden rounded-full bg-line-soft"
                aria-hidden="true"
            >
                <span class="block h-1 bg-primary" :style="{ width: usageWidth }"></span>
            </span>
            <span class="text-xs text-shell-muted tabular-nums">
                {{
                    usage.quota === null
                        ? $t('workspace.shell.usage.calls_unlimited', {
                              calls: formatNumber(usage.calls),
                              cost: formatCurrency(usage.cost_usd),
                          })
                        : $t('workspace.shell.usage.calls', {
                              calls: formatNumber(usage.calls),
                              quota: formatNumber(usage.quota),
                              cost: formatCurrency(usage.cost_usd),
                          })
                }}
            </span>
        </Link>

        <div class="border-t border-line-soft pt-2">
            <UserMenu />
        </div>
    </nav>
</template>
