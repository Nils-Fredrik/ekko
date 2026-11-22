<script setup>
import DataNote from '@/Components/Brand/DataNote.vue';
import HealthHeader from '@/Components/Brand/HealthHeader.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import { crossFilter } from '@/Components/Brand/filters.js';
import DivergingBarChart from '@/Components/Charts/DivergingBarChart.vue';
import SmallMultiplesChart from '@/Components/Charts/SmallMultiplesChart.vue';
import StackedAreaChart from '@/Components/Charts/StackedAreaChart.vue';
import ChartLegend from '@/Components/Charts/ChartLegend.vue';
import TrendLineChart from '@/Components/Charts/TrendLineChart.vue';
import { limitCompetitors, rankedCompetitorKeys } from '@/Components/Charts/support/competitors.js';
import { alignComparison, periodBuckets } from '@/Components/Charts/support/data.js';
import { formatValue } from '@/Components/Charts/support/format.js';
import { pickLeadingEngine } from './leadingEngine.js';
import InsightLine from '@/Components/Dashboard/InsightLine.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import MetricValue from '@/Components/Dashboard/MetricValue.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps({
    health: { type: Object, default: null },
    canCreateMarket: { type: Boolean, default: false },
    hasPitchReport: { type: Boolean, default: false },
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    analysisFilters: { type: Object, required: true },
    options: { type: Object, required: true },
    summary: { type: Object, required: true },
    previous: { type: Object, required: true },
    series: { type: Array, required: true },
    voice: { type: Array, required: true },
    engines: { type: Array, required: true },
    modelChanges: { type: Array, required: true },
    insight: { type: Object, default: null },
    topActions: { type: Array, required: true },
    chartData: { type: Object, default: null },
});

const primaryCards = [
    ['visibility', 'visibility', 'rate'],
    ['share_of_voice', 'share_of_voice', 'rate'],
    ['average_position', 'average_position', 'number', false],
    ['citation_rate', 'citation_rate', 'rate'],
];
const secondaryCards = [
    ['recommendation_rate', 'recommendation_rate', 'rate'],
    ['first_place_rate', 'first_place_rate', 'rate'],
    ['sentiment', 'sentiment', 'number'],
];

function selectFilter(key, event) {
    if (event?.key === 'others') {
        return;
    }
    const value = event?.key === 'brand' ? null : event?.key;
    if (value !== undefined) crossFilter(route('brands.show', props.brand.id), props.analysisFilters.query, key, value);
}

function sparkline(key) {
    return (props.chartData?.sparkline ?? []).map((row) => {
        const metric = row.metrics?.[key] ?? {};

        return {
            x: row.period,
            value: metric.value ?? null,
            n: metric.n ?? 0,
            low_sample: Boolean(metric.low_sample),
        };
    });
}

function point(metric) {
    return {
        value: metric?.value ?? null,
        n: metric?.n ?? 0,
        low_sample: Boolean(metric?.low_sample),
    };
}

const grain = computed(() => props.period.grain ?? 'day');

const categories = computed(() =>
    props.chartData ? periodBuckets(props.period.from, props.period.to, grain.value) : [],
);

function aligned(rows, pick) {
    const byPeriod = new Map((rows ?? []).map((row) => [row.period, pick(row)]));

    return categories.value.map((period) => point(byPeriod.get(period)));
}

function modelMarker(change) {
    return {
        key: `model:${change.engine_key}:${change.changed_on}`,
        date: change.changed_on,
        kind: 'model',
        label: trans('app.dashboard.model_change', {
            engine: change.engine,
            from_model: change.from_model,
            to_model: change.to_model,
        }),
    };
}

const engineName = computed(() => {
    const names = new Map(props.engines.map((item) => [item.key, item.name]));
    for (const item of props.options.engines ?? []) {
        names.set(item.key, item.name);
    }

    return (key) => names.get(key) ?? key;
});

const leadingEngine = computed(() => pickLeadingEngine(props.engines));

const competitorIndex = computed(() => {
    const ids = [];
    for (const row of props.chartData?.competitors ?? []) {
        for (const entity of row.entities ?? []) {
            if (entity.id !== 'brand' && !ids.includes(entity.id)) {
                ids.push(entity.id);
            }
        }
    }

    return new Map(ids.map((id, index) => [id, index]));
});

const shareItems = computed(() => {
    if (categories.value.length === 0) {
        return [];
    }
    const groups = new Map();
    const add = (id, label, own, period, metric) => {
        if (!groups.has(id)) {
            groups.set(id, { label, own, points: new Map() });
        }
        groups.get(id).points.set(period, metric);
    };
    for (const row of props.chartData?.series ?? []) {
        add('brand', props.brand.name, true, row.period, row.metrics?.share_of_voice);
    }
    for (const row of props.chartData?.competitors ?? []) {
        for (const entity of row.entities ?? []) {
            if (entity.id === 'brand') {
                continue;
            }
            add(entity.id, entity.name ?? entity.id, false, row.period, entity.share_of_voice);
        }
    }

    return [...groups.entries()].map(([key, group]) => ({
        key,
        label: group.label,
        own: group.own || undefined,
        colorIndex: group.own ? undefined : (competitorIndex.value.get(key) ?? 0),
        points: categories.value.map((period) => point(group.points.get(period))),
    }));
});

const competitorOrder = computed(() => rankedCompetitorKeys(shareItems.value));

const trendSeries = computed(() => {
    if (categories.value.length === 0) {
        return [];
    }
    const own = {
        key: 'brand',
        label: props.brand.name,
        own: true,
        points: aligned(props.chartData?.series, (row) => row.metrics?.visibility),
    };
    const byId = new Map();
    for (const row of props.chartData?.competitors ?? []) {
        for (const entity of row.entities ?? []) {
            if (entity.id === 'brand') {
                continue;
            }
            if (!byId.has(entity.id)) {
                byId.set(entity.id, { label: entity.name ?? entity.id, points: new Map() });
            }
            byId.get(entity.id).points.set(row.period, entity.visibility);
        }
    }
    const competitors = [...byId.entries()]
        .filter(([id]) => !props.filters.competitor || String(id) === String(props.filters.competitor))
        .map(([id, entity]) => ({
            key: id,
            label: entity.label,
            colorIndex: competitorIndex.value.get(id) ?? 0,
            points: categories.value.map((period) => point(entity.points.get(period))),
        }));

    return limitCompetitors([own, ...competitors], {
        order: competitorOrder.value,
        otherLabel: (count) => trans('app.dashboard.others', { count }),
        reduce: 'mean',
    });
});

const previousTrend = computed(() => {
    const buckets = periodBuckets(props.period.previous_from, props.period.previous_to, grain.value);
    const byPeriod = new Map(
        (props.chartData?.previous ?? []).map((row) => [row.period, point(row.metrics?.visibility)]),
    );
    const values = buckets.map((bucket) => byPeriod.get(bucket) ?? point(null));

    return {
        label: trans(props.period.comparison === 'year' ? 'filters.year' : 'app.charts.previous_period'),
        points: alignComparison(categories.value, buckets, values, {
            comparison: props.period.comparison,
            grain: grain.value,
            missing: point(null),
        }),
    };
});

const trendLegend = computed(() => [
    ...trendSeries.value,
    {
        key: 'previous',
        label: previousTrend.value.label,
        own: true,
        dashed: true,
        points: previousTrend.value.points,
    },
]);

const markers = computed(() => [...props.modelChanges.map(modelMarker), ...(props.chartData?.action_markers ?? [])]);

const panels = computed(() => {
    const rows = props.chartData?.engines ?? [];
    const keys = [...new Set(rows.map((row) => row.engine_key))];
    const periodVisibility = new Map(props.engines.map((item) => [item.key, item.visibility]));

    return keys.map((key) => ({
        key,
        label: engineName.value(key),
        headline: periodVisibility.get(key) ?? null,
        points: aligned(
            rows.filter((row) => row.engine_key === key),
            (row) => row.visibility,
        ),
    }));
});

const panelMarkers = computed(() => {
    const grouped = {};
    for (const panel of panels.value) {
        grouped[panel.key] = [...(props.chartData?.action_markers ?? [])];
    }
    for (const change of props.modelChanges) {
        grouped[change.engine_key] ??= [];
        grouped[change.engine_key].push(modelMarker(change));
    }

    return grouped;
});

const shareSeries = computed(() =>
    limitCompetitors(shareItems.value, {
        order: competitorOrder.value,
        otherLabel: (count) => trans('app.dashboard.others', { count }),
    }),
);

const sentimentRows = computed(() => {
    const sentiment = props.chartData?.sentiment;
    if (!sentiment?.complete || !Array.isArray(sentiment.rows)) {
        return [];
    }
    const names = new Map([['own', props.brand.name]]);
    for (const row of props.chartData?.competitors ?? []) {
        for (const entity of row.entities ?? []) {
            if (entity.id !== 'brand' && entity.name) {
                names.set(`competitor:${entity.id}`, entity.name);
            }
        }
    }

    return sentiment.rows
        .filter((row) => (row.n || row.negative + row.neutral + row.positive) > 0)
        .map((row) => {
            const n = row.n || row.negative + row.neutral + row.positive;
            const share = (count) => (n ? count / n : 0);

            return {
                key: row.key,
                label: names.get(row.key) ?? row.key,
                negative: share(row.negative),
                neutral: share(row.neutral),
                positive: share(row.positive),
                n,
                low_sample: row.low_sample,
            };
        });
});

function openMarker(marker) {
    if (marker?.href) {
        router.visit(marker.href);
    }
}
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
                <Link :href="route('brands.settings', brand.id)" class="text-sm text-shell-fg no-underline">
                    {{ $t('app.nav.settings') }}
                </Link>
            </div>
        </template>

        <div class="py-4">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 sm:px-6 lg:px-8">
                <Link v-if="hasPitchReport" :href="route('pitches.report', brand.id)" class="text-sm text-primary">
                    {{ $t('pitch.report') }}
                </Link>
                <Link
                    v-if="canCreateMarket && !health"
                    :href="route('brands.markets.create', brand.id)"
                    class="text-sm text-shell-text"
                >
                    {{ $t('onboarding.ui.add_market') }}
                </Link>
                <PeriodFilters :brand-id="brand.id" route-name="brands.show" :show-data-note="false" />
                <HealthHeader
                    v-if="health"
                    :health="health"
                    :brand-id="brand.id"
                    :can-create-market="canCreateMarket"
                />
                <DataNote />
                <InsightLine :insight="insight" />

                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="primary-kpis">
                    <KpiCard
                        v-for="[key, label, kind, higherIsBetter] in primaryCards"
                        :key="key"
                        :label="$t(`app.dashboard.${label}`)"
                        :metric="summary[key]"
                        :kind="kind"
                        :higher-is-better="higherIsBetter !== false"
                        :previous="previous[key]"
                        :points="sparkline(key)"
                    />
                </section>
                <p v-if="leadingEngine" class="text-sm text-shell-text" data-testid="leading-engine">
                    {{ $t('app.dashboard.leading_engine', { engine: leadingEngine.name }) }}
                    <span class="ms-2 tabular-nums text-shell-muted">
                        {{ formatValue(leadingEngine.visibility.value, 'rate') }}
                        {{ $t('app.dashboard.n', { n: leadingEngine.visibility.n }) }}
                    </span>
                </p>

                <section>
                    <h3 class="text-sm font-medium text-shell-text">{{ $t('app.dashboard.engines') }}</h3>
                    <EmptyState
                        v-if="engines.length === 0"
                        class="mt-3"
                        :title="$t('app.empty.engines_title')"
                        :body="$t('app.empty.engines_body')"
                    />
                    <SmallMultiplesChart
                        v-else-if="panels.length"
                        class="mt-2"
                        :label="$t('app.dashboard.engines')"
                        :categories="categories"
                        :panels="panels"
                        :markers="panelMarkers"
                        :until="period.to"
                        :panel-height="72"
                        select-series
                        @select="selectFilter('engine', $event)"
                        @marker="openMarker"
                    />
                    <ul v-else class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <li v-for="item in engines" :key="item.key" class="text-sm text-shell-text">
                            <p>{{ item.name }}</p>
                            <MetricValue :metric="item.visibility" />
                        </li>
                    </ul>
                </section>

                <section class="grid gap-3 sm:grid-cols-3">
                    <KpiCard
                        v-for="[key, label, kind] in secondaryCards"
                        :key="key"
                        size="secondary"
                        :label="$t(`app.dashboard.${label}`)"
                        :metric="summary[key]"
                        :kind="kind"
                        :previous="previous[key]"
                        :points="sparkline(key)"
                    />
                </section>

                <section>
                    <h3 class="text-sm font-medium text-shell-text">{{ $t('app.dashboard.trend') }}</h3>
                    <EmptyState
                        v-if="series.length === 0"
                        class="mt-3"
                        :title="$t('app.empty.trend_title')"
                        :body="$t('app.empty.trend_body')"
                        :href="route('brands.settings', brand.id)"
                        :link="$t('app.empty.settings_link')"
                    />
                    <div v-else-if="trendSeries.length" class="mt-2 flex flex-wrap items-start gap-4">
                        <TrendLineChart
                            class="min-w-0 flex-[2_1_16rem]"
                            :legend="false"
                            :label="$t('app.dashboard.trend')"
                            :categories="categories"
                            :series="trendSeries"
                            :previous="previousTrend"
                            :markers="markers"
                            :until="period.to"
                            :height="180"
                            :empty-before="period.measured_from || ''"
                            select-series
                            @select="selectFilter('competitor', $event)"
                            @marker="openMarker"
                        />
                        <ChartLegend :series="trendLegend" />
                    </div>
                </section>

                <section class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <h3 class="text-sm font-medium text-shell-text">{{ $t('app.dashboard.voice') }}</h3>
                        <EmptyState
                            v-if="voice.every((part) => !part.mentions)"
                            class="mt-3"
                            :title="$t('app.empty.voice_title')"
                            :body="$t('app.empty.voice_body')"
                        />
                        <div v-else-if="shareSeries.length" class="mt-2 flex flex-wrap items-start gap-4">
                            <StackedAreaChart
                                class="min-w-0 flex-[2_1_16rem]"
                                normalized
                                :legend="false"
                                :label="$t('app.dashboard.voice')"
                                :categories="categories"
                                :series="shareSeries"
                                :until="period.to"
                                :height="200"
                                :empty-before="period.measured_from || ''"
                            />
                            <ChartLegend :series="shareSeries" />
                        </div>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-shell-text">{{ $t('app.dashboard.sentiment') }}</h3>
                        <p v-if="sentimentRows.length === 0 && chartData" class="mt-3 text-sm text-shell-muted">
                            {{
                                chartData.sentiment?.complete === false
                                    ? $t('app.dashboard.sentiment_unavailable')
                                    : $t('app.dashboard.no_sentiment')
                            }}
                        </p>
                        <DivergingBarChart
                            v-else-if="sentimentRows.length"
                            class="mt-2"
                            :label="$t('app.dashboard.sentiment')"
                            :rows="sentimentRows"
                        />
                    </div>
                </section>

                <section>
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="text-sm font-medium text-shell-text">{{ $t('app.library.actions') }}</h3>
                        <Link :href="route('brands.actions.index', brand.id)" class="text-sm text-shell-accent">
                            {{ $t('app.dashboard.all_actions') }}
                        </Link>
                    </div>
                    <p v-if="topActions.length === 0" class="mt-2 text-sm text-shell-muted">
                        {{ $t('app.dashboard.no_actions') }}
                    </p>
                    <ul v-else class="mt-2 flex flex-col gap-1 text-sm">
                        <li v-for="action in topActions" :key="action.id">
                            <Link
                                :href="route('brands.actions.show', [brand.id, action.id])"
                                class="text-shell-text no-underline"
                            >
                                {{ action.title }}
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
