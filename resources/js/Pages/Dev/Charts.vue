<script setup>
// Chart examples (U02): every chart type with fixed test data, in both themes. The route exists
// only in local and testing (routes/dev.php). The numbers are generated, not customer data.
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DivergingBarChart from '@/Components/Charts/DivergingBarChart.vue';
import DotRowChart from '@/Components/Charts/DotRowChart.vue';
import FunnelChart from '@/Components/Charts/FunnelChart.vue';
import GapBarChart from '@/Components/Charts/GapBarChart.vue';
import HeatmapChart from '@/Components/Charts/HeatmapChart.vue';
import RankChart from '@/Components/Charts/RankChart.vue';
import RankedBarChart from '@/Components/Charts/RankedBarChart.vue';
import SmallMultiplesChart from '@/Components/Charts/SmallMultiplesChart.vue';
import SparklineChart from '@/Components/Charts/SparklineChart.vue';
import StackedAreaChart from '@/Components/Charts/StackedAreaChart.vue';
import TreemapChart from '@/Components/Charts/TreemapChart.vue';
import TrendLineChart from '@/Components/Charts/TrendLineChart.vue';

// Deterministic numbers, so the page and the browser test look the same on every run.
function random(seed) {
    let state = seed;

    return () => {
        state = (state * 1664525 + 1013904223) % 4294967296;

        return state / 4294967296;
    };
}

function days(count, end = '2026-11-18') {
    const last = new Date(`${end}T00:00:00Z`);

    return Array.from({ length: count }, (_, index) => {
        const day = new Date(last);
        day.setUTCDate(last.getUTCDate() - (count - 1 - index));

        return day.toISOString().slice(0, 10);
    });
}

function walk(seed, count, start, spread, lowEvery = 0) {
    const next = random(seed);
    let value = start;

    return Array.from({ length: count }, (_, index) => {
        value = Math.min(0.95, Math.max(0.02, value + (next() - 0.5) * spread));
        const n = lowEvery && index % lowEvery === lowEvery - 1 ? 6 : 20 + Math.round(next() * 40);

        return { value: Math.round(value * 1000) / 1000, n, low_sample: n < 10 };
    });
}

const t = (key, replace) => trans(`app.charts.examples.${key}`, replace);
const letters = ['A', 'B', 'C', 'D', 'E'];
// Texts are computed, so they follow the language once it has loaded.
const engines = computed(() =>
    ['chatgpt_ui', 'gemini_ui', 'google_aio', 'perplexity_api', 'claude_sonnet'].map((key) => ({
        key,
        label: trans(`app.engines.${key}`),
    })),
);

const categories = days(28);
const selected = ref('');

const sparkline = walk(3, 28, 0.42, 0.08, 9).map((point, index) => ({ ...point, x: categories[index] }));

const trendSeries = computed(() => [
    { key: 'own', label: t('own'), own: true, points: walk(5, 28, 0.45, 0.07, 11) },
    ...letters.slice(0, 4).map((letter, index) => ({
        key: `c-${letter}`,
        label: t('competitor', { letter }),
        colorIndex: index,
        points: walk(11 + index, 28, 0.2 + index * 0.08, 0.06),
    })),
]);
const previous = computed(() => ({ label: trans('app.charts.previous_period'), points: walk(17, 28, 0.38, 0.06) }));
const markers = computed(() => [
    { key: 'm1', date: categories[8], kind: 'model', label: engines.value[0].label, detail: t('model_change') },
    { key: 'a1', date: categories[19], kind: 'action', label: t('action') },
]);

const shareSeries = computed(() => [
    { key: 'own', label: t('own'), own: true, points: walk(21, 28, 0.35, 0.05, 13) },
    ...letters.slice(0, 3).map((letter, index) => ({
        key: `c-${letter}`,
        label: t('competitor', { letter }),
        colorIndex: index,
        points: walk(23 + index, 28, 0.2, 0.05),
    })),
]);

const panels = computed(() =>
    engines.value.map((engine, index) => ({
        key: engine.key,
        label: engine.label,
        points: walk(31 + index, 28, 0.3 + index * 0.08, 0.08, 7 + index),
    })),
);
const panelMarkers = computed(() => ({ [engines.value[0].key]: [markers.value[0]] }));

const heatRows = computed(() =>
    Array.from({ length: 9 }, (_, index) => ({
        key: `p${index + 1}`,
        label: t('prompt', { n: index + 1 }),
        group: t('topic', { n: Math.floor(index / 3) + 1 }),
    })),
);
const heatCells = computed(() => {
    const next = random(41);

    return heatRows.value.flatMap((row) =>
        engines.value.map((engine) => {
            const n = Math.round(next() * 30);

            return { row: row.key, column: engine.key, value: Math.round(next() * 100) / 100, n, low_sample: n < 10 };
        }),
    );
});

const dots = (() => {
    const next = random(51);

    return days(14).map((x, index) => ({
        x,
        value: index === 4 ? null : Math.round(next() * 100) / 100,
        n: index === 9 ? 3 : 7,
        low_sample: index === 9,
    }));
})();

// Weekly buckets: the Monday of each of the last eight weeks.
const rankCategories = days(8 * 7, '2026-11-22').filter((_, index) => index % 7 === 0);
const rankSeries = computed(() => {
    const names = [
        { key: 'own', label: t('own'), own: true },
        ...letters.map((letter, index) => ({
            key: `c-${letter}`,
            label: t('competitor', { letter }),
            colorIndex: index,
        })),
    ];
    const next = random(61);
    const ranks = rankCategories.map(() => {
        const order = names.map((_, index) => index);
        for (let index = order.length - 1; index > 0; index--) {
            const swap = Math.floor(next() * (index + 1));
            [order[index], order[swap]] = [order[swap], order[index]];
        }

        return order;
    });

    return names.map((name, nameIndex) => ({
        ...name,
        points: ranks.map((order) => ({ rank: order.indexOf(nameIndex) + 1, n: 30 })),
    }));
});

const sentimentRows = computed(() => [
    { key: 'own', label: t('own'), negative: 0.12, neutral: 0.48, positive: 0.4, n: 84 },
    { key: 'c-A', label: t('competitor', { letter: 'A' }), negative: 0.22, neutral: 0.5, positive: 0.28, n: 61 },
    {
        key: 'c-B',
        label: t('competitor', { letter: 'B' }),
        negative: 0.08,
        neutral: 0.62,
        positive: 0.3,
        n: 7,
        low_sample: true,
    },
]);

const domains = [
    'example.no',
    'store.example',
    'news.example',
    'forum.example',
    'review.example',
    'wiki.example',
    'rival.example',
];
const sourceBars = [
    { key: 'd1', label: domains[0], value: 0.31, n: 120, group: 'own' },
    { key: 'd2', label: domains[6], value: 0.24, n: 120, group: 'competitor' },
    { key: 'd3', label: domains[2], value: 0.19, n: 120, group: 'third_party' },
    { key: 'd4', label: domains[3], value: 0.12, n: 120, group: 'third_party' },
    { key: 'd5', label: domains[4], value: 0.07, n: 8, low_sample: true, group: 'third_party' },
];
const gapRows = [
    { key: 'g1', label: domains[2], own: { value: 0.02, n: 40 }, competitors: { value: 0.21, n: 40 } },
    { key: 'g2', label: domains[3], own: { value: 0, n: 40 }, competitors: { value: 0.14, n: 40 } },
    {
        key: 'g3',
        label: domains[4],
        own: { value: 0.05, n: 9, low_sample: true },
        competitors: { value: 0.11, n: 9, low_sample: true },
    },
];
const treemapItems = computed(() => [
    { key: 'news', label: t('type_news'), value: 140 },
    { key: 'forum', label: t('type_forum'), value: 96 },
    { key: 'review', label: t('type_review'), value: 71 },
    { key: 'shop', label: t('type_shop'), value: 44 },
    { key: 'wiki', label: t('type_wiki'), value: 9, n: 9, low_sample: true },
]);

const robotSeries = computed(() =>
    ['GPTBot', 'ClaudeBot', 'PerplexityBot'].map((label, index) => ({
        key: label,
        label,
        colorIndex: index,
        points: walk(71 + index, 28, 0.4, 0.2).map((point) => ({ ...point, value: Math.round(point.value * 300) })),
    })),
);
const traffic = computed(() => [
    {
        key: 'traffic',
        label: t('traffic'),
        own: true,
        points: walk(81, 28, 0.4, 0.2, 10).map((point) => ({ ...point, value: Math.round(point.value * 90) })),
    },
]);
const funnelSteps = computed(() => [
    { key: 'fetched', label: t('fetched'), value: 4210, description: t('fetched_how') },
    { key: 'cited', label: t('cited'), value: 612, description: t('cited_how') },
    { key: 'visits', label: t('visits'), value: 148, description: t('visits_how') },
]);

function show(value) {
    selected.value = typeof value === 'string' ? value : JSON.stringify(value);
}
</script>

<template>
    <AppLayout :title="$t('app.charts.examples.title')">
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div>
                <h1 class="text-xl font-semibold">{{ $t('app.charts.examples.title') }}</h1>
                <p class="mt-1 text-sm text-shell-muted">{{ $t('app.charts.examples.intro') }}</p>
                <p class="mt-2 text-sm" data-testid="chart-selected" aria-live="polite">
                    {{ selected ? $t('app.charts.examples.selected', { value: selected }) : '' }}
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-lg border border-line bg-shell-panel p-4" data-testid="example-sparkline">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.sparkline') }}</h2>
                    <div class="mt-3 w-48">
                        <SparklineChart :label="$t('app.dashboard.visibility')" :points="sparkline" />
                    </div>
                    <h2 class="mt-6 text-sm font-medium">{{ $t('app.charts.examples.dots') }}</h2>
                    <div class="mt-3 w-64">
                        <DotRowChart :label="$t('app.charts.examples.dots')" :points="dots" />
                    </div>
                </section>

                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.funnel') }}</h2>
                    <FunnelChart class="mt-3" :label="$t('app.charts.examples.funnel')" :steps="funnelSteps" />
                </section>
            </div>

            <section class="rounded-lg border border-line bg-shell-panel p-4" data-testid="example-trend">
                <h2 class="text-sm font-medium">{{ $t('app.charts.examples.trend') }}</h2>
                <TrendLineChart
                    class="mt-3"
                    :label="$t('app.charts.examples.trend')"
                    :categories="categories"
                    :series="trendSeries"
                    :previous="previous"
                    :markers="markers"
                    :until="categories[categories.length - 1]"
                    @marker="show($event.label)"
                />
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.share') }}</h2>
                    <StackedAreaChart
                        class="mt-3"
                        normalized
                        :label="$t('app.charts.examples.share')"
                        :categories="categories"
                        :series="shareSeries"
                    />
                </section>
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.robots') }}</h2>
                    <StackedAreaChart
                        class="mt-3"
                        :label="$t('app.charts.examples.robots')"
                        :categories="categories"
                        :series="robotSeries"
                    />
                </section>
            </div>

            <section class="rounded-lg border border-line bg-shell-panel p-4">
                <h2 class="text-sm font-medium">{{ $t('app.charts.examples.multiples') }}</h2>
                <SmallMultiplesChart
                    class="mt-3"
                    :label="$t('app.charts.examples.multiples')"
                    :categories="categories"
                    :panels="panels"
                    :markers="panelMarkers"
                    @marker="show($event.label)"
                />
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-lg border border-line bg-shell-panel p-4" data-testid="example-heatmap">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.heatmap') }}</h2>
                    <HeatmapChart
                        class="mt-3"
                        :label="$t('app.charts.examples.heatmap')"
                        :rows="heatRows"
                        :columns="engines"
                        :cells="heatCells"
                        @select="show(`${$event.row} · ${$event.column}`)"
                    />
                </section>
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.rank') }}</h2>
                    <RankChart
                        class="mt-3"
                        :label="$t('app.charts.examples.rank')"
                        :categories="rankCategories"
                        :series="rankSeries"
                    />
                </section>
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.sentiment') }}</h2>
                    <DivergingBarChart
                        class="mt-3"
                        :label="$t('app.charts.examples.sentiment')"
                        :rows="sentimentRows"
                        @select="show($event.row)"
                    />
                </section>
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.sources') }}</h2>
                    <RankedBarChart
                        class="mt-3"
                        :label="$t('app.charts.examples.sources')"
                        :bars="sourceBars"
                        @select="show($event.key)"
                    />
                </section>
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.gap') }}</h2>
                    <GapBarChart
                        class="mt-3"
                        :label="$t('app.charts.examples.gap')"
                        :rows="gapRows"
                        @select="show($event.key)"
                    />
                </section>
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.treemap') }}</h2>
                    <TreemapChart
                        class="mt-3"
                        :label="$t('app.charts.examples.treemap')"
                        :items="treemapItems"
                        @select="show($event.key)"
                    />
                </section>
                <section class="rounded-lg border border-line bg-shell-panel p-4">
                    <h2 class="text-sm font-medium">{{ $t('app.charts.examples.traffic') }}</h2>
                    <TrendLineChart
                        class="mt-3"
                        kind="count"
                        :label="$t('app.charts.examples.traffic')"
                        :categories="categories"
                        :series="traffic"
                        :markers="markers"
                    />
                </section>
            </div>
        </div>
    </AppLayout>
</template>
