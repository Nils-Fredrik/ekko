<script setup>
// The partner portfolio (docs/ui-forbedring.md 7.7, U10): one row per client with visibility and minigraph,
// change, an alert dot with text and open actions, sorted by the largest drop first. A row opens the client's
// dashboard. The names come first; the figures are one deferred prop with a skeleton until they arrive.
import SparklineChart from '@/Components/Charts/SparklineChart.vue';
import { formatChange, formatN, formatValue, isMissing } from '@/Components/Charts/support/format.js';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps({
    period: { type: Object, required: true },
    clients: { type: Array, required: true },
    portfolioGraphs: { type: Array, default: null },
});

const page = usePage();
const convert = useForm({});

const loaded = computed(() => props.portfolioGraphs !== null);

const rows = computed(() => {
    const graphs = new Map((props.portfolioGraphs ?? []).map((row) => [row.id, row]));

    return props.clients.map((client) => ({ ...client, graph: graphs.get(client.id) ?? null }));
});

// Default: the largest negative change first (docs/ui-forbedring.md 7.7). Rows without a change go last.
const sort = ref({ key: 'change', direction: 'asc' });

const columns = [
    { key: 'name', label: 'app.partner.client' },
    { key: 'visibility', label: 'app.partner.visibility' },
    { key: 'change', label: 'app.partner.change' },
    { key: 'alerts', label: 'app.partner.alerts' },
    { key: 'actions', label: 'app.partner.open_actions' },
];

function sortValue(row, key) {
    switch (key) {
        case 'name':
            return row.name;
        case 'visibility':
            return row.graph?.visibility?.value ?? null;
        case 'change':
            return row.graph?.change ?? null;
        case 'alerts':
            return row.graph?.open_alerts ?? null;
        default:
            return row.graph?.open_actions ?? null;
    }
}

const sorted = computed(() => {
    const { key, direction } = sort.value;
    const factor = direction === 'asc' ? 1 : -1;
    const collator = new Intl.Collator(document.documentElement.lang || 'nb');

    return [...rows.value].sort((a, b) => {
        const left = sortValue(a, key);
        const right = sortValue(b, key);
        if (isMissing(left) || isMissing(right)) {
            return isMissing(left) === isMissing(right) ? collator.compare(a.name, b.name) : isMissing(left) ? 1 : -1;
        }
        const order = typeof left === 'string' ? collator.compare(left, right) : left - right;

        return order === 0 ? collator.compare(a.name, b.name) : order * factor;
    });
});

function sortBy(key) {
    sort.value =
        sort.value.key === key
            ? { key, direction: sort.value.direction === 'asc' ? 'desc' : 'asc' }
            : { key, direction: key === 'name' || key === 'change' ? 'asc' : 'desc' };
}

function ariaSort(key) {
    if (sort.value.key !== key) {
        return 'none';
    }

    return sort.value.direction === 'asc' ? 'ascending' : 'descending';
}

// A click anywhere on the row opens the client, except on its own controls (convert, chart table view).
function rowClick(event, client) {
    if (event.target.closest('button, a, input, [role="button"]')) {
        return;
    }
    openClient(client);
}

function openClient(client) {
    router.put(route('current-team.update'), { team_id: client.id }, { preserveState: false });
}

function isPitch(client) {
    return page.props.pitchProjects?.includes(client.id) ?? false;
}

function changeTone(change) {
    if (isMissing(change) || change === 0) {
        return { color: 'var(--data-neutral)' };
    }

    return { color: change > 0 ? 'var(--data-positive)' : 'var(--data-negative)' };
}

function spokenChange(change) {
    if (isMissing(change)) {
        return trans('app.partner.no_change');
    }
    if (change === 0) {
        return trans('app.dashboard.change_flat');
    }
    const amount = new Intl.NumberFormat(document.documentElement.lang || 'nb', { maximumFractionDigits: 1 }).format(
        Math.abs(change * 100),
    );

    return trans(change > 0 ? 'app.dashboard.change_up' : 'app.dashboard.change_down', {
        change: trans('app.dashboard.change_points_spoken', { change: amount }),
    });
}
</script>

<template>
    <AppLayout :title="$t('app.partner.title')">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ $t('app.partner.title') }}</h2>
        </template>
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <p class="text-sm text-shell-muted">{{ $t('app.partner.intro', { days: period.days }) }}</p>
            <p class="mt-3 text-sm text-shell-muted">
                {{ $t('pitch.ui.partner_hint') }}
                <Link :href="route('pitches.index')" class="text-primary no-underline">
                    {{ $t('pitch.ui.manage') }}
                </Link>
            </p>
            <EmptyState
                v-if="clients.length === 0"
                class="mt-6"
                :title="$t('app.empty.partner_title')"
                :body="$t('app.empty.partner_body')"
            />
            <template v-else>
                <p class="mt-6 text-xs text-shell-muted">{{ $t('app.partner.sorted_hint') }}</p>
                <p v-if="!loaded" class="sr-only" role="status">{{ $t('app.partner.loading') }}</p>
                <div class="mt-2 overflow-x-auto rounded-2xl border border-line bg-shell-panel">
                    <table class="min-w-full text-sm text-shell-text" data-testid="portfolio">
                        <thead class="border-b border-line text-left text-shell-muted">
                            <tr>
                                <th
                                    v-for="column in columns"
                                    :key="column.key"
                                    scope="col"
                                    class="px-4 py-3 font-medium"
                                    :class="column.key === 'alerts' || column.key === 'actions' ? 'text-right' : ''"
                                    :aria-sort="ariaSort(column.key)"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 font-medium"
                                        :aria-label="$t('app.partner.sort_by', { column: $t(column.label) })"
                                        @click="sortBy(column.key)"
                                    >
                                        {{ $t(column.label) }}
                                        <span v-if="sort.key === column.key" aria-hidden="true">
                                            {{ sort.direction === 'asc' ? '↑' : '↓' }}
                                        </span>
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="client in sorted"
                                :key="client.id"
                                class="cursor-pointer border-b border-line last:border-0 hover:bg-shell-surface"
                                data-testid="portfolio-row"
                                :data-client="client.id"
                                @click="rowClick($event, client)"
                            >
                                <th scope="row" class="px-4 py-3 text-left font-medium">
                                    <button
                                        type="button"
                                        class="text-left text-shell-text"
                                        :aria-label="$t('app.partner.open_client', { name: client.name })"
                                        @click="openClient(client)"
                                    >
                                        {{ client.name }}
                                    </button>
                                    <span v-if="isPitch(client)" class="block text-xs font-normal text-shell-muted">
                                        {{ $t('pitch.kind_pitch') }}
                                    </span>
                                    <button
                                        v-if="isPitch(client)"
                                        type="button"
                                        class="text-sm font-normal text-primary"
                                        :disabled="convert.processing"
                                        @click="convert.post(route('pitches.convert', client.id))"
                                    >
                                        {{ $t('pitch.convert') }}
                                    </button>
                                </th>
                                <template v-if="client.graph">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-4">
                                            <div class="min-w-20">
                                                <span
                                                    class="block font-medium tabular-nums"
                                                    :class="
                                                        client.graph.visibility.low_sample ? 'text-shell-muted' : ''
                                                    "
                                                >
                                                    {{ formatValue(client.graph.visibility.value) }}
                                                </span>
                                                <span class="block text-xs text-shell-muted">
                                                    {{ formatN(client.graph.visibility.n) }}
                                                    <template v-if="client.graph.visibility.low_sample">
                                                        · {{ $t('app.dashboard.low_sample') }}
                                                    </template>
                                                </span>
                                            </div>
                                            <SparklineChart
                                                v-if="client.graph.series.some((point) => !isMissing(point.value))"
                                                class="w-32"
                                                :label="$t('app.partner.visibility')"
                                                :points="client.graph.series"
                                                :height="32"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 tabular-nums" data-testid="portfolio-change">
                                        <span class="sr-only">{{ spokenChange(client.graph.change) }}</span>
                                        <span
                                            class="inline-flex items-center gap-1"
                                            :style="changeTone(client.graph.change)"
                                            aria-hidden="true"
                                        >
                                            <svg
                                                v-if="client.graph.change > 0"
                                                viewBox="0 0 12 12"
                                                class="size-3"
                                                aria-hidden="true"
                                            >
                                                <path d="M6 1.5 L11 9.5 H1 Z" fill="currentColor" />
                                            </svg>
                                            <svg
                                                v-else-if="client.graph.change < 0"
                                                viewBox="0 0 12 12"
                                                class="size-3"
                                                aria-hidden="true"
                                            >
                                                <path d="M6 10.5 L1 2.5 H11 Z" fill="currentColor" />
                                            </svg>
                                            {{ formatChange(client.graph.change) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap" data-testid="portfolio-alerts">
                                        <span class="inline-flex items-center gap-2">
                                            <span
                                                class="size-2 rounded-full"
                                                :class="
                                                    client.graph.open_alerts > 0 ? 'bg-warning' : 'bg-shell-surface'
                                                "
                                                aria-hidden="true"
                                            ></span>
                                            {{
                                                client.graph.open_alerts > 0
                                                    ? $t('app.partner.alerts_open', { count: client.graph.open_alerts })
                                                    : $t('app.partner.alerts_none')
                                            }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ client.graph.open_actions }}</td>
                                </template>
                                <template v-else>
                                    <td class="px-4 py-3">
                                        <span class="ekko-skeleton block h-8 w-48 rounded"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="ekko-skeleton block h-4 w-16 rounded"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="ekko-skeleton ms-auto block h-4 w-14 rounded"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="ekko-skeleton ms-auto block h-4 w-8 rounded"></span>
                                    </td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
