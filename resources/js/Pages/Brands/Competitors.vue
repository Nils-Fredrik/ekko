<script setup>
// Competitors (docs/ui-forbedring.md 7.4, U07): the rank of the brand and its active competitors over time, a
// table with visibility, change, rank and sentiment per brand, the sentiment distribution, a link to the unknown
// brands that wait for a decision (Setup › Competitors and brands, U22), and the competitors' aliases and domains.
// A competitor's color is its fixed place among the active competitors (`color_index`), the same as on the
// dashboard and the sources page.
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import DivergingBarChart from '@/Components/Charts/DivergingBarChart.vue';
import RankChart from '@/Components/Charts/RankChart.vue';
import { periodBuckets } from '@/Components/Charts/support/data.js';
import { formatChange, formatValue, isLowSample } from '@/Components/Charts/support/format.js';
import EmptyState from '@/Components/UI/EmptyState.vue';
import WorkspaceHeader from '@/Components/Shell/WorkspaceHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatNumber } from '@/format';
import { Link, router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    analysisFilters: { type: Object, required: true },
    markets: { type: Array, required: true },
    competitors: { type: Array, required: true },
    pendingEntities: { type: Number, required: true },
    canManage: { type: Boolean, required: true },
    chartData: { type: Object, default: null },
});

const TOP = 6;
const CATEGORIES = 6;

const deleting = ref(false);

const setupHref = computed(() =>
    route('brands.setup.competitors', {
        brand: props.brand.id,
        tab: 'pending',
        ...(props.filters.market ? { market: props.filters.market } : {}),
    }),
);

const selected = computed(() => (props.filters.competitor ? String(props.filters.competitor) : null));

// The competitor filter narrows the comparison to one competitor; the brand stays as the reference (7.8).
function shownKey(key) {
    return selected.value === null || key === 'brand' || String(key) === selected.value;
}

const shownCompetitors = computed(() => props.competitors.filter((item) => shownKey(String(item.id))));

const colorIndex = computed(
    () =>
        new Map(
            props.competitors
                .filter((item) => Number.isInteger(item.color_index))
                .map((item) => [String(item.id), item.color_index]),
        ),
);

function swatch(key) {
    if (key === 'brand') {
        return { backgroundColor: 'var(--data-own)' };
    }
    const index = colorIndex.value.get(String(key));

    return {
        backgroundColor: Number.isInteger(index)
            ? `var(--data-cat-${(index % CATEGORIES) + 1})`
            : 'var(--data-neutral)',
    };
}

function brandName(key, name) {
    return key === 'brand' ? props.brand.name : (name ?? String(key));
}

const grain = computed(() => props.chartData?.grain ?? 'day');

const categories = computed(() =>
    props.chartData ? periodBuckets(props.period.from, props.period.to, grain.value) : [],
);

const tableRows = computed(() => (props.chartData?.table ?? []).filter((row) => shownKey(String(row.key))));

// The six best placed in the period (the table is sorted by rank), and always the brand itself.
const rankSeries = computed(() => {
    if (categories.value.length === 0) {
        return [];
    }
    const byKey = new Map();
    for (const row of props.chartData?.ranking ?? []) {
        for (const entity of row.entities ?? []) {
            if (!byKey.has(entity.id)) {
                byKey.set(entity.id, { name: entity.name, points: new Map() });
            }
            byKey.get(entity.id).points.set(row.period, {
                rank: entity.rank,
                n: entity.visibility?.n ?? 0,
                low_sample: Boolean(entity.visibility?.low_sample),
            });
        }
    }
    const keys = tableRows.value.slice(0, TOP).map((row) => String(row.key));
    const ordered = ['brand', ...keys.filter((key) => key !== 'brand')];

    return ordered
        .filter((key) => byKey.has(key))
        .map((key) => {
            const entity = byKey.get(key);
            const own = key === 'brand';

            return {
                key,
                label: brandName(key, entity.name),
                own: own || undefined,
                colorIndex: own ? undefined : (colorIndex.value.get(key) ?? 0),
                points: categories.value.map((period) => entity.points.get(period) ?? { rank: null, n: 0 }),
            };
        });
});

const hasRanking = computed(() => rankSeries.value.some((item) => item.points.some((point) => point.rank != null)));

// TOP limits how many series are drawn, not the ranks shown: the brand, a selected competitor or a series that
// falls below sixth place on a day keeps its line, so the axis reaches the lowest rank among the drawn series.
const rankRange = computed(() =>
    Math.max(TOP, ...rankSeries.value.flatMap((item) => item.points.map((point) => point.rank ?? 0))),
);

const sentimentRows = computed(() =>
    tableRows.value
        .filter((row) => row.sentiment?.n > 0)
        .map((row) => ({
            key: row.own ? 'own' : `competitor:${row.key}`,
            label: brandName(row.key, row.name),
            negative: row.sentiment.negative ?? 0,
            neutral: row.sentiment.neutral ?? 0,
            positive: row.sentiment.positive ?? 0,
            n: row.sentiment.n,
            low_sample: row.sentiment.low_sample,
        })),
);

const sentimentComplete = computed(() => props.chartData?.sentiment?.complete !== false);

// The answers behind a sentiment figure (U28): the brand or one competitor, optionally one sentiment group,
// in the page's period and filters. The competitor filter only narrows this page, so it is not passed on.
function responsesUrl(key, sentiment = null) {
    const query = { ...props.analysisFilters.query };
    delete query.competitor;
    const entity = key === 'brand' || key === 'own' ? undefined : String(key).replace(/^competitor:/, '');

    return route('brands.responses.index', {
        brand: props.brand.id,
        ...query,
        ...(entity ? { entity } : {}),
        ...(sentiment ? { sentiment } : {}),
    });
}

function openResponses(event) {
    if (event?.key) {
        router.visit(responsesUrl(event.key, event.sentiment ?? null));
    }
}

function changeClass(change) {
    if (change == null || Math.abs(change) < 0.0005) {
        return 'text-shell-muted';
    }

    return change > 0 ? 'text-success' : 'text-error';
}

const SENTIMENTS = ['positive', 'neutral', 'negative'];

function sentimentShare(sentiment, key) {
    return trans(`app.competitors.sentiment_${key}`, { share: formatValue(sentiment[key], 'rate', 0) });
}

function remove(competitor) {
    if (deleting.value || !window.confirm(trans('app.library.confirm_delete_competitor', { name: competitor.name }))) {
        return;
    }

    deleting.value = true;
    router.delete(route('brands.competitors.destroy', [props.brand.id, competitor.id]), {
        preserveScroll: true,
        onFinish: () => (deleting.value = false),
    });
}
</script>

<template>
    <AppLayout :title="$t('workspace.shell.competitors')">
        <template #header>
            <WorkspaceHeader
                :brand="brand.name"
                :group="$t('workspace.shell.analysis')"
                :title="$t('workspace.shell.competitors')"
            />
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <PeriodFilters :brand-id="brand.id" route-name="brands.competitors.index" />

                <EmptyState
                    v-if="competitors.length === 0"
                    :title="$t('app.empty.competitors_title')"
                    :body="$t('app.empty.competitors_body')"
                />

                <div
                    v-else-if="!chartData"
                    class="grid gap-6"
                    aria-busy="true"
                    :aria-label="$t('app.competitors.loading')"
                >
                    <div class="ekko-skeleton h-72 rounded-2xl"></div>
                    <div class="ekko-skeleton h-56 rounded-2xl"></div>
                </div>

                <template v-else>
                    <section
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                        data-testid="competitor-ranking"
                    >
                        <h3 class="text-sm font-medium">{{ $t('app.competitors.ranking') }}</h3>
                        <p class="mt-1 text-xs text-shell-muted">
                            {{ $t(grain === 'week' ? 'app.competitors.ranking_week' : 'app.competitors.ranking_day') }}
                        </p>
                        <p v-if="!hasRanking" class="mt-4 text-sm text-shell-muted">
                            {{ $t('app.competitors.no_ranking') }}
                        </p>
                        <RankChart
                            v-else
                            class="mt-4"
                            :label="$t('app.competitors.ranking')"
                            :categories="categories"
                            :series="rankSeries"
                            :top="rankRange"
                            :height="280"
                        />
                    </section>

                    <section
                        class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text"
                        data-testid="competitor-table"
                    >
                        <h3 class="px-4 pt-4 text-sm font-medium">{{ $t('app.competitors.table') }}</h3>
                        <table class="mt-2 w-full text-left text-sm">
                            <thead class="border-b border-line text-xs text-shell-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-medium">
                                        {{ $t('app.competitors.brand') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right font-medium">
                                        {{ $t('app.competitors.visibility') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right font-medium"
                                        :title="$t('app.competitors.change_hint')"
                                    >
                                        {{ $t('app.competitors.change') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right font-medium">
                                        {{ $t('app.competitors.rank') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 font-medium">
                                        {{ $t('app.competitors.sentiment') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in tableRows"
                                    :key="row.key"
                                    class="border-b border-line-soft last:border-0 hover:bg-row-hover"
                                    :data-competitor="row.key"
                                >
                                    <th scope="row" class="px-4 py-3 font-medium">
                                        <span class="flex items-center gap-2">
                                            <span
                                                class="size-2.5 shrink-0 rounded-sm"
                                                :style="swatch(row.key)"
                                                data-testid="competitor-swatch"
                                            ></span>
                                            <span :class="row.own ? 'font-semibold' : ''">
                                                {{ brandName(row.key, row.name) }}
                                            </span>
                                            <span v-if="row.own" class="text-xs font-normal text-shell-muted">
                                                ({{ $t('app.competitors.own') }})
                                            </span>
                                        </span>
                                    </th>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                        :class="isLowSample(row.visibility) ? 'text-shell-muted' : ''"
                                        :title="
                                            isLowSample(row.visibility) ? $t('app.dashboard.low_sample') : undefined
                                        "
                                    >
                                        {{ formatValue(row.visibility.value, 'rate') }}
                                        <span class="ms-1 text-xs text-shell-muted">
                                            {{ $t('app.charts.n', { n: row.visibility.n }) }}
                                        </span>
                                        <span v-if="isLowSample(row.visibility)" class="ms-1 text-xs text-shell-muted">
                                            · {{ $t('app.charts.low_sample') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums" :class="changeClass(row.change)">
                                        {{ formatChange(row.change, 'rate') }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        {{ row.rank ?? $t('app.charts.empty') }}
                                        <span
                                            v-if="row.previous_rank != null && row.previous_rank !== row.rank"
                                            class="ms-1 text-xs text-shell-muted"
                                        >
                                            {{ $t('app.competitors.previous_rank', { rank: row.previous_rank }) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        <span v-if="!row.sentiment" class="text-shell-muted">
                                            {{ $t('app.charts.empty') }}
                                        </span>
                                        <span v-else-if="row.sentiment.n === 0" class="text-shell-muted">
                                            {{ $t('app.competitors.no_sentiment') }}
                                        </span>
                                        <span
                                            v-else
                                            class="flex flex-wrap items-center gap-x-2"
                                            :class="row.sentiment.low_sample ? 'text-shell-muted' : ''"
                                            data-testid="competitor-sentiment-links"
                                        >
                                            <Link
                                                v-for="key in SENTIMENTS"
                                                :key="key"
                                                :href="responsesUrl(row.key, key)"
                                                class="no-underline hover:text-primary"
                                                :class="
                                                    row.sentiment.low_sample ? 'text-shell-muted' : 'text-shell-text'
                                                "
                                                :data-sentiment="key"
                                            >
                                                {{ sentimentShare(row.sentiment, key) }}
                                            </Link>
                                            <Link
                                                :href="responsesUrl(row.key)"
                                                class="text-shell-muted no-underline hover:text-primary"
                                            >
                                                · {{ $t('app.charts.n', { n: row.sentiment.n }) }}
                                            </Link>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <section
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                        data-testid="competitor-sentiment"
                    >
                        <h3 class="text-sm font-medium">{{ $t('app.competitors.sentiment') }}</h3>
                        <p v-if="!sentimentComplete" class="mt-3 text-sm text-shell-muted">
                            {{ $t('app.competitors.sentiment_missing') }}
                        </p>
                        <p v-else-if="sentimentRows.length === 0" class="mt-3 text-sm text-shell-muted">
                            {{ $t('app.dashboard.no_sentiment') }}
                        </p>
                        <DivergingBarChart
                            v-else
                            class="mt-2"
                            :label="$t('app.competitors.sentiment')"
                            :rows="sentimentRows"
                            @select="openResponses"
                        />
                    </section>
                </template>

                <p
                    v-if="pendingEntities > 0"
                    class="rounded-2xl border border-line bg-shell-panel px-5 py-3 text-sm text-shell-text"
                    data-testid="pending-entities"
                >
                    <Link class="text-shell-accent underline" :href="setupHref">
                        {{
                            transChoice('app.competitors.pending_entities', pendingEntities, {
                                n: formatNumber(pendingEntities),
                            })
                        }}
                    </Link>
                </p>

                <section
                    v-if="competitors.length"
                    class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text"
                    data-testid="competitor-setup"
                >
                    <h3 class="px-4 pt-4 text-sm font-medium">{{ $t('app.competitors.setup') }}</h3>
                    <table class="mt-2 w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-shell-muted">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.competitors') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.aliases') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.domains') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.active') }}</th>
                                <th v-if="canManage" scope="col" class="px-4 py-3">
                                    <span class="sr-only">{{ $t('actions.delete') }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="competitor in shownCompetitors"
                                :key="competitor.id"
                                class="border-b border-line-soft last:border-0 hover:bg-row-hover"
                            >
                                <td class="px-4 py-3 font-medium">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="size-2.5 shrink-0 rounded-sm"
                                            :style="swatch(String(competitor.id))"
                                        ></span>
                                        {{ competitor.name }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-shell-muted">{{ competitor.aliases.join(', ') }}</td>
                                <td class="px-4 py-3 text-shell-muted">{{ competitor.domains.join(', ') }}</td>
                                <td
                                    class="px-4 py-3"
                                    :class="competitor.is_active ? 'text-success' : 'text-shell-muted'"
                                >
                                    {{ competitor.is_active ? $t('app.library.active') : $t('app.library.inactive') }}
                                </td>
                                <td v-if="canManage" class="px-4 py-3 text-end">
                                    <button
                                        type="button"
                                        class="rounded-lg px-2 py-1 text-error hover:underline disabled:opacity-50"
                                        :aria-label="$t('actions.named.delete', { name: competitor.name })"
                                        :disabled="deleting"
                                        @click="remove(competitor)"
                                    >
                                        {{ $t('actions.delete') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
