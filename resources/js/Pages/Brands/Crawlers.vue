<script setup>
// AI robots and traffic (docs/ui-forbedring.md 7.6, U09). The answer first: the three funnel figures against the
// comparison period. Then the funnel «fetched by AI → cited → visits» with how each step is measured, fetches per AI
// robot as a stacked area, AI-referred sessions as a line and the most fetched pages. The funnel steps are measured
// separately in different units, so no step is shown as a share of the previous one.
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import FunnelChart from '@/Components/Charts/FunnelChart.vue';
import StackedAreaChart from '@/Components/Charts/StackedAreaChart.vue';
import TrendLineChart from '@/Components/Charts/TrendLineChart.vue';
import { alignComparison, periodBuckets } from '@/Components/Charts/support/data.js';
import { formatValue } from '@/Components/Charts/support/format.js';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { trans } from 'laravel-vue-i18n';
import { robotSpan } from './robotBuckets.js';
import { computed } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    bots: { type: Array, required: true },
    traffic: { type: Array, required: true },
    funnel: { type: Object, required: true },
    connections: { type: Object, required: true },
    botLabels: { type: Object, default: () => ({}) },
    chartData: { type: Object, default: null },
});

// Robots with their own color; with more robots, the smallest share the last one, so each color stays distinct.
const OWN_COLORS = 6;
const LOW_SAMPLE_N = 10;
const STEPS = ['fetched', 'cited', 'visits'];

const grain = computed(() => props.period.grain ?? 'day');
const categories = computed(() => periodBuckets(props.period.from, props.period.to, grain.value));
const settingsHref = computed(() => route('brands.settings', props.brand.id));
const pages = computed(() => props.chartData?.pages ?? []);
// A source counts as connected when it is set up or has delivered figures for the period.
const hasBotLogs = computed(() => props.connections.bot_logs || props.bots.length > 0);
const hasGa4 = computed(() => props.connections.ga4 || props.traffic.length > 0);

function botLabel(key) {
    return props.botLabels[key] ?? key;
}

// A count with its sample: hits and sessions are their own n.
function point(value) {
    if (value === null || value === undefined) {
        return { value: null, n: 0, low_sample: false };
    }

    return { value, n: value, low_sample: value < LOW_SAMPLE_N };
}

// Robots ordered by hits in the period (the order of `bots`).
const shownBots = computed(() => {
    const keys = props.bots.map((row) => row.bot);

    return keys.length <= OWN_COLORS ? keys : keys.slice(0, OWN_COLORS - 1);
});

const botSeries = computed(() => {
    const rows = props.chartData?.bots ?? [];
    if (rows.length === 0) {
        return [];
    }
    const byBucket = new Map();
    for (const row of rows) {
        if (!byBucket.has(row.period)) {
            byBucket.set(row.period, new Map());
        }
        byBucket.get(row.period).set(row.bot, row.hits);
    }
    const own = new Set(shownBots.value);
    // Buckets inside the logged span count missing robots as zero; see robotBuckets.js.
    const span = robotSpan(categories.value, byBucket);
    const values = (pick) =>
        categories.value.map((bucket, index) =>
            span && index >= span.from && index <= span.to
                ? point(pick(byBucket.get(bucket) ?? new Map()))
                : point(null),
        );
    const series = shownBots.value.map((bot, index) => ({
        key: bot,
        label: botLabel(bot),
        colorIndex: index,
        points: values((hits) => hits.get(bot) ?? 0),
    }));
    if (props.bots.length > shownBots.value.length) {
        series.push({
            key: 'other',
            label: trans('app.crawlers.other_bots'),
            colorIndex: OWN_COLORS - 1,
            points: values((hits) => [...hits].reduce((sum, [bot, value]) => (own.has(bot) ? sum : sum + value), 0)),
        });
    }

    return series;
});

function sessionsByBucket(rows) {
    const totals = new Map();
    for (const row of rows ?? []) {
        totals.set(row.period, (totals.get(row.period) ?? 0) + row.sessions);
    }

    return totals;
}

const trafficSeries = computed(() => {
    const rows = props.chartData?.traffic ?? [];
    if (rows.length === 0) {
        return [];
    }
    const totals = sessionsByBucket(rows);

    return [
        {
            key: 'sessions',
            label: trans('app.crawlers.traffic_series'),
            own: true,
            points: categories.value.map((bucket) => point(totals.get(bucket) ?? null)),
        },
    ];
});

const previousTraffic = computed(() => {
    const rows = props.chartData?.previous_traffic ?? [];
    if (rows.length === 0) {
        return null;
    }
    const buckets = periodBuckets(props.period.previous_from, props.period.previous_to, grain.value);
    const totals = sessionsByBucket(rows);

    return {
        label: trans(props.period.comparison === 'year' ? 'filters.year' : 'app.charts.previous_period'),
        points: alignComparison(
            categories.value,
            buckets,
            buckets.map((bucket) => point(totals.get(bucket) ?? null)),
            { comparison: props.period.comparison, grain: grain.value, missing: point(null) },
        ),
    };
});

// Minigraphs for the KPI cards: robot hits and AI sessions per bucket. Cited answers have no series here.
const sparklines = computed(() => {
    const hits = new Map();
    for (const row of props.chartData?.bots ?? []) {
        hits.set(row.period, (hits.get(row.period) ?? 0) + row.hits);
    }
    const sessions = sessionsByBucket(props.chartData?.traffic);
    const points = (totals) =>
        totals.size === 0
            ? []
            : categories.value.map((bucket) => ({ x: bucket, ...point(totals.get(bucket) ?? null) }));

    return { fetched: points(hits), cited: [], visits: points(sessions) };
});

// Why a step has no value, in words, so an unmeasured step is never read as zero.
function missingReason(key) {
    if (key === 'fetched') {
        return hasBotLogs.value ? 'app.crawlers.missing_fetched' : 'app.crawlers.missing_bot_logs';
    }
    if (key === 'visits') {
        return hasGa4.value ? 'app.crawlers.missing_visits' : 'app.crawlers.missing_ga4';
    }

    return 'app.crawlers.missing_cited';
}

function stepDescription(key) {
    const metric = props.funnel.current[key];
    const how = trans(`app.crawlers.${key}_help`, { n: formatValue(metric?.n ?? 0, 'count') });
    const measured = metric?.value !== null && metric?.value !== undefined;

    return measured ? how : `${how} ${trans(missingReason(key))}`;
}

const steps = computed(() =>
    STEPS.map((key) => ({
        key,
        label: trans(`app.crawlers.${key}`),
        value: props.funnel.current[key]?.value ?? null,
        description: stepDescription(key),
    })),
);

const hasFunnel = computed(() => steps.value.some((step) => step.value !== null));
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <BrandNav :brand-id="brand.id" />
                <PeriodFilters :brand-id="brand.id" route-name="brands.crawlers" />

                <section class="grid gap-3 sm:grid-cols-3" data-testid="crawler-kpis">
                    <KpiCard
                        v-for="key in STEPS"
                        :key="key"
                        :label="$t(`app.crawlers.${key}`)"
                        :metric="funnel.current[key]"
                        :previous="funnel.previous[key]"
                        :points="sparklines[key]"
                        kind="count"
                    />
                </section>

                <section
                    class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                    data-testid="crawler-funnel"
                >
                    <h3 class="text-sm font-medium">{{ $t('app.crawlers.funnel') }}</h3>
                    <p class="mt-1 text-xs text-shell-muted">{{ $t('app.crawlers.funnel_help') }}</p>
                    <FunnelChart
                        v-if="hasFunnel"
                        class="mt-4"
                        :label="$t('app.crawlers.funnel')"
                        :steps="steps"
                        :ratios="false"
                    />
                    <EmptyState
                        v-else
                        class="mt-4"
                        :title="$t('app.crawlers.funnel_empty_title')"
                        :body="$t('app.crawlers.funnel_empty_body')"
                        :href="settingsHref"
                        :link="$t('app.crawlers.settings_link')"
                    />
                </section>

                <div v-if="!chartData" class="grid gap-6" aria-busy="true" :aria-label="$t('app.crawlers.loading')">
                    <div class="ekko-skeleton h-72 rounded-2xl"></div>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="ekko-skeleton h-64 rounded-2xl"></div>
                        <div class="ekko-skeleton h-64 rounded-2xl"></div>
                    </div>
                </div>

                <template v-else>
                    <section
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                        data-testid="crawler-robots"
                    >
                        <h3 class="text-sm font-medium">{{ $t('app.crawlers.robots') }}</h3>
                        <p class="mt-1 text-xs text-shell-muted">{{ $t('app.crawlers.robots_help') }}</p>
                        <EmptyState
                            v-if="botSeries.length === 0 && !hasBotLogs"
                            class="mt-4"
                            :title="$t('app.crawlers.bot_logs_missing_title')"
                            :body="$t('app.crawlers.bot_logs_missing_body')"
                            :href="settingsHref"
                            :link="$t('app.crawlers.settings_link')"
                        />
                        <EmptyState
                            v-else-if="botSeries.length === 0"
                            class="mt-4"
                            :title="$t('app.empty.bots_title')"
                            :body="$t('app.empty.bots_body')"
                        />
                        <StackedAreaChart
                            v-else
                            class="mt-4"
                            :label="$t('app.crawlers.robots')"
                            :categories="categories"
                            :series="botSeries"
                            kind="count"
                            :height="280"
                        />
                    </section>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <section
                            class="min-w-0 rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                            data-testid="crawler-traffic"
                        >
                            <h3 class="text-sm font-medium">{{ $t('app.crawlers.traffic') }}</h3>
                            <p class="mt-1 text-xs text-shell-muted">{{ $t('app.crawlers.traffic_help') }}</p>
                            <EmptyState
                                v-if="trafficSeries.length === 0 && !hasGa4"
                                class="mt-4"
                                :title="$t('app.crawlers.ga4_missing_title')"
                                :body="$t('app.crawlers.ga4_missing_body')"
                                :href="settingsHref"
                                :link="$t('app.crawlers.settings_link')"
                            />
                            <EmptyState
                                v-else-if="trafficSeries.length === 0"
                                class="mt-4"
                                :title="$t('app.empty.traffic_title')"
                                :body="$t('app.empty.traffic_body')"
                            />
                            <template v-else>
                                <TrendLineChart
                                    class="mt-4"
                                    :label="$t('app.crawlers.traffic')"
                                    :categories="categories"
                                    :series="trafficSeries"
                                    :previous="previousTraffic"
                                    kind="count"
                                />
                                <table class="mt-4 w-full text-left text-sm" data-testid="crawler-traffic-sources">
                                    <caption class="sr-only">
                                        {{ $t('app.crawlers.traffic_by_source') }}
                                    </caption>
                                    <thead class="border-b border-line text-xs text-shell-muted">
                                        <tr>
                                            <th class="py-2 pe-3 font-medium">{{ $t('app.library.source') }}</th>
                                            <th class="py-2 pe-3 text-right font-medium">
                                                {{ $t('app.library.sessions') }}
                                            </th>
                                            <th class="py-2 pe-3 text-right font-medium">
                                                {{ $t('app.library.engaged') }}
                                            </th>
                                            <th class="py-2 text-right font-medium">
                                                {{ $t('app.library.conversions') }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in traffic"
                                            :key="row.source"
                                            class="border-b border-line-soft last:border-0"
                                        >
                                            <td class="py-2 pe-3 break-all">{{ row.source }}</td>
                                            <td class="py-2 pe-3 text-right tabular-nums">
                                                {{ formatValue(row.sessions, 'count') }}
                                            </td>
                                            <td class="py-2 pe-3 text-right tabular-nums">
                                                {{ formatValue(row.engaged_sessions, 'count') }}
                                            </td>
                                            <td class="py-2 text-right tabular-nums">
                                                {{ formatValue(row.conversions, 'number') }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </template>
                        </section>

                        <section
                            class="min-w-0 rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                            data-testid="crawler-pages"
                        >
                            <h3 class="text-sm font-medium">{{ $t('app.crawlers.pages') }}</h3>
                            <p class="mt-1 text-xs text-shell-muted">{{ $t('app.crawlers.pages_help') }}</p>
                            <EmptyState
                                v-if="pages.length === 0 && !hasBotLogs"
                                class="mt-4"
                                :title="$t('app.crawlers.bot_logs_missing_title')"
                                :body="$t('app.crawlers.bot_logs_missing_body')"
                                :href="settingsHref"
                                :link="$t('app.crawlers.settings_link')"
                            />
                            <p v-else-if="pages.length === 0" class="mt-4 text-sm text-shell-muted">
                                {{ $t('app.crawlers.pages_empty') }}
                            </p>
                            <table v-else class="mt-4 w-full text-left text-sm">
                                <caption class="sr-only">
                                    {{ $t('app.crawlers.pages') }}
                                </caption>
                                <thead class="border-b border-line text-xs text-shell-muted">
                                    <tr>
                                        <th class="py-2 pe-3 font-medium">{{ $t('app.crawlers.page') }}</th>
                                        <th class="py-2 pe-3 text-right font-medium">{{ $t('app.library.hits') }}</th>
                                        <th class="py-2 text-right font-medium">{{ $t('app.crawlers.bots_count') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="page in pages"
                                        :key="`${page.domain}${page.path}`"
                                        class="border-b border-line-soft last:border-0"
                                        data-testid="crawler-page"
                                    >
                                        <td class="py-2 pe-3 break-all">
                                            <span class="text-shell-muted">{{ page.domain }}</span>
                                            <span>{{ page.path }}</span>
                                        </td>
                                        <td class="py-2 pe-3 text-right tabular-nums">
                                            {{ formatValue(page.hits, 'count') }}
                                        </td>
                                        <td class="py-2 text-right tabular-nums">{{ page.bots }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
