<script setup>
import { crossFilter } from '@/Components/Brand/filters.js';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import DotRowChart from '@/Components/Charts/DotRowChart.vue';
import HeatmapChart from '@/Components/Charts/HeatmapChart.vue';
import RankedBarChart from '@/Components/Charts/RankedBarChart.vue';
import SparklineChart from '@/Components/Charts/SparklineChart.vue';
import { periodBuckets } from '@/Components/Charts/support/data.js';
import { heatmapRows } from '@/Components/Charts/support/heatmapRows.js';
import MetricValue from '@/Components/Dashboard/MetricValue.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    analysisFilters: { type: Object, required: true },
    chartData: { type: Object, default: null },
    markets: { type: Array, required: true },
    prompts: { type: Array, required: true },
});

function selectTopic(event) {
    if (event?.key)
        crossFilter(route('brands.prompts.index', props.brand.id), props.analysisFilters.query, 'topic', event.key);
}

const view = ref('heatmap');

const heatRows = computed(() =>
    heatmapRows(props.prompts, {
        marketSelected: Boolean(props.filters.market),
        unnamed: trans('app.library.unknown_market_group'),
    }),
);

const heatColumns = computed(() =>
    (props.chartData?.engines ?? []).map((engine) => ({ key: engine.key, label: engine.name })),
);

const heatCells = computed(() => {
    const ids = new Set(props.prompts.map((prompt) => String(prompt.id)));

    return (props.chartData?.heatmap ?? [])
        .filter((cell) => ids.has(String(cell.prompt_id)))
        .map((cell) => ({
            row: String(cell.prompt_id),
            column: cell.engine_key,
            value: cell.visibility?.value ?? null,
            n: cell.visibility?.n ?? 0,
            low_sample: Boolean(cell.visibility?.low_sample),
        }));
});

function isoShift(iso, days) {
    const [year, month, day] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

function metricPoint(metric) {
    return {
        value: metric?.value ?? null,
        n: metric?.n ?? 0,
        low_sample: Boolean(metric?.low_sample),
    };
}

const sparklineByPrompt = computed(() => {
    const range = props.chartData?.sparkline;
    const result = new Map();
    if (!range?.from || !range?.to) {
        return result;
    }
    const byPrompt = new Map();
    for (const row of props.chartData?.sparklines ?? []) {
        if (!byPrompt.has(row.prompt_id)) {
            byPrompt.set(row.prompt_id, new Map());
        }
        byPrompt.get(row.prompt_id).set(row.period, row.visibility);
    }
    const days = periodBuckets(range.from, range.to);
    for (const prompt of props.prompts) {
        const byDay = byPrompt.get(prompt.id) ?? new Map();
        result.set(
            prompt.id,
            days.map((day) => ({ x: day, ...metricPoint(byDay.get(day)) })),
        );
    }

    return result;
});

const dotsByPrompt = computed(() => {
    const result = new Map();
    if (!props.period?.to || !props.chartData) {
        return result;
    }
    const byPrompt = new Map();
    for (const row of props.chartData.stability ?? []) {
        if (!byPrompt.has(row.prompt_id)) {
            byPrompt.set(row.prompt_id, new Map());
        }
        const byDay = byPrompt.get(row.prompt_id);
        const current = byDay.get(row.period) ?? { mentioned: false, n: 0 };
        current.n += row.n ?? 0;
        current.mentioned = current.mentioned || row.value === 1;
        byDay.set(row.period, current);
    }
    const days = periodBuckets(isoShift(props.period.to, -13), props.period.to);
    for (const prompt of props.prompts) {
        const byDay = byPrompt.get(prompt.id) ?? new Map();
        result.set(
            prompt.id,
            days.map((day) => {
                const point = byDay.get(day);
                if (!point || point.n === 0) {
                    return { x: day, value: null, n: 0, low_sample: true };
                }

                return { x: day, value: point.mentioned ? 1 : 0, n: point.n, low_sample: point.n < 10 };
            }),
        );
    }

    return result;
});

function sparkline(promptId) {
    return sparklineByPrompt.value.get(promptId) ?? [];
}

function dots(promptId) {
    return dotsByPrompt.value.get(promptId) ?? [];
}

function hasSparkline(promptId) {
    return sparkline(promptId).some((point) => point.value !== null);
}

function openCell(event) {
    if (!event?.key || !event?.column) {
        return;
    }
    router.get(route('brands.prompts.show', [props.brand.id, event.key]), {
        ...props.analysisFilters.query,
        engine: event.column,
    });
}
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <PeriodFilters :brand-id="brand.id" route-name="brands.prompts.index" />

                <RankedBarChart
                    v-if="chartData?.topics?.length"
                    :label="$t('filters.topics_chart')"
                    :bars="chartData.topics"
                    :groups="[{ key: 'own', label: brand.name, color: 'own' }]"
                    kind="rate"
                    @select="selectTopic"
                />

                <Link
                    :href="
                        route('brands.setup.prompts', {
                            brand: brand.id,
                            ...(filters.market ? { market: filters.market } : {}),
                        })
                    "
                    class="text-sm text-primary no-underline"
                >
                    {{ $t('app.library.edit_in_setup') }}
                </Link>

                <EmptyState
                    v-if="prompts.length === 0"
                    :title="$t('app.empty.prompts_title')"
                    :body="$t('app.empty.prompts_body')"
                />
                <template v-else>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="rounded-md px-2 py-1 text-xs text-shell-muted hover:bg-shell-surface hover:text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :aria-pressed="view === 'table' ? 'true' : 'false'"
                            @click="view = view === 'heatmap' ? 'table' : 'heatmap'"
                        >
                            {{ view === 'heatmap' ? $t('app.library.show_table') : $t('app.library.show_heatmap') }}
                        </button>
                    </div>
                    <HeatmapChart
                        v-if="view === 'heatmap' && chartData && heatRows.length"
                        :label="$t('app.library.heatmap')"
                        :rows="heatRows"
                        :columns="heatColumns"
                        :cells="heatCells"
                        :row-height="48"
                        :label-lines="2"
                        :label-length="28"
                        @select="openCell"
                    />
                    <p v-else-if="view === 'heatmap' && chartData" class="text-sm text-shell-muted">
                        {{ $t('app.library.heatmap_empty') }}
                    </p>
                    <p v-else-if="view === 'heatmap'" class="text-sm text-shell-muted">
                        {{ $t('app.library.charts_loading') }}
                    </p>
                    <div
                        v-if="view === 'table'"
                        class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text"
                    >
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-line text-xs text-shell-muted">
                                <tr>
                                    <th class="px-4 py-3 font-medium">{{ $t('app.library.text') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('app.dashboard.market') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">
                                        {{ $t('app.dashboard.visibility') }}
                                    </th>
                                    <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.position') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.stability') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('app.library.active') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="prompt in prompts"
                                    :key="prompt.id"
                                    class="border-b border-line-soft align-top last:border-0 hover:bg-row-hover"
                                >
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="route('brands.prompts.show', [brand.id, prompt.id])"
                                            class="font-medium text-shell-text no-underline"
                                        >
                                            {{ prompt.text }}
                                        </Link>
                                        <p class="mt-1 text-xs text-shell-muted">
                                            {{ prompt.intent_label }} · {{ prompt.funnel }} ·
                                            {{ prompt.priority_label }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-shell-muted">{{ prompt.market }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        <MetricValue :metric="prompt.visibility" />
                                        <SparklineChart
                                            v-if="hasSparkline(prompt.id)"
                                            class="ms-auto mt-2 w-24"
                                            :label="prompt.text"
                                            :points="sparkline(prompt.id)"
                                            :height="28"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        <MetricValue :metric="prompt.position" kind="number" />
                                    </td>
                                    <td class="px-4 py-3 text-right text-xs tabular-nums">
                                        <DotRowChart
                                            v-if="dots(prompt.id).length"
                                            class="ms-auto w-36"
                                            :label="$t('app.library.stability')"
                                            :points="dots(prompt.id)"
                                        />
                                        <div
                                            v-for="item in prompt.stability"
                                            :key="item.engine"
                                            class="mt-1 flex items-center justify-end gap-2"
                                        >
                                            <span>{{ item.engine }}</span>
                                            <MetricValue :metric="item.metric" />
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span :class="prompt.is_active ? 'text-success' : 'text-shell-muted'">
                                            {{
                                                prompt.is_active ? $t('app.library.active') : $t('app.library.inactive')
                                            }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
