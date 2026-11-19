<script setup>
// Small multiples: one minigraph per engine with a shared y axis, so the panels can be compared
// (docs/ui-forbedring.md 6, U05). Each panel is an EkkoChart; the table toggle and the
// description cover all panels together.
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import ChartTable from './ChartTable.vue';
import EkkoChart from './EkkoChart.vue';
import { bucketMarkers } from './support/data.js';
import {
    axisFormatter,
    changeAt,
    formatChange,
    formatDate,
    formatValue,
    isLowSample,
    tooltipHtml,
} from './support/format.js';
import { firstAndLast, lineSeries, timeTable } from './support/lines.js';
import { markerNotes, markerSeries } from './support/markers.js';
import { paletteColor } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    categories: { type: Array, required: true },
    // [{ key, label, points: [{ value, n, low_sample, change? }] }], one per engine.
    panels: { type: Array, required: true },
    // Markers per panel key: { [panelKey]: [marker] }. Model changes belong to one engine.
    markers: { type: Object, default: () => ({}) },
    until: { type: String, default: null },
    kind: { type: String, default: 'rate' },
    color: { type: String, default: 'own' },
    panelHeight: { type: Number, default: 110 },
});

const emit = defineEmits(['marker', 'select']);

const showTable = ref(false);

// Shared scale: from 0 to the highest value in any panel, rounded up.
const max = computed(() => {
    const values = props.panels.flatMap((panel) => panel.points.map((point) => point?.value ?? 0));
    const top = Math.max(0, ...values);
    if (props.kind === 'rate') {
        return Math.min(1, Math.ceil(top * 10) / 10 || 0.1);
    }

    return top > 0 ? top : 1;
});

function lastValue(panel) {
    const range = firstAndLast(panel.points);

    return range ? formatValue(range.last.point.value, props.kind) : trans('app.charts.empty');
}

function optionFor(panel) {
    return ({ palette }) => {
        const color = paletteColor(palette, props.color);
        const buckets = bucketMarkers(props.markers[panel.key] ?? [], props.categories, props.until);
        const series = lineSeries({ id: `p-${panel.key}`, name: panel.label, color, points: panel.points, width: 1.5 });
        if (buckets.size > 0) {
            series.push(markerSeries(palette, buckets));
        }

        return {
            grid: { left: 4, right: 8, top: 8, bottom: 4, containLabel: true },
            tooltip: {
                trigger: 'axis',
                confine: true,
                formatter: (params) => {
                    const index = (Array.isArray(params) ? params[0] : params)?.dataIndex ?? 0;
                    const point = panel.points[index];
                    const category = props.categories[index];

                    return tooltipHtml({
                        title: formatDate(category),
                        rows: [
                            {
                                label: panel.label,
                                color,
                                value: point?.value ?? null,
                                change: changeAt(panel.points, index),
                                n: point?.n,
                                lowSample: isLowSample(point),
                                kind: props.kind,
                            },
                        ],
                        notes: markerNotes(palette, buckets, category),
                    });
                },
            },
            xAxis: {
                type: 'category',
                boundaryGap: false,
                data: props.categories,
                axisLabel: { show: false },
            },
            yAxis: {
                type: 'value',
                min: 0,
                max: max.value,
                splitNumber: 2,
                axisLabel: { formatter: axisFormatter(props.kind), fontSize: 10 },
            },
            series,
        };
    };
}

const table = computed(() => timeTable(props.categories, props.panels, props.kind, trans('app.charts.period')));

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    const parts = props.panels
        .map((panel) => {
            const range = firstAndLast(panel.points);
            if (!range) {
                return `${panel.label} ${trans('app.charts.empty')}`;
            }

            return `${panel.label} ${formatValue(range.last.point.value, props.kind)} (${formatChange(range.last.point.value - range.first.point.value, props.kind)})`;
        })
        .join(', ');

    return trans('app.charts.describe.multiples', { label: props.label, parts });
});
</script>

<template>
    <figure class="m-0" :aria-label="label">
        <div class="mb-2 flex justify-end">
            <button
                type="button"
                class="rounded-md px-2 py-1 text-xs text-shell-muted hover:bg-shell-surface hover:text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :aria-pressed="showTable ? 'true' : 'false'"
                data-testid="chart-table-toggle"
                @click="showTable = !showTable"
            >
                {{ showTable ? $t('app.charts.show_chart') : $t('app.charts.show_table') }}
            </button>
        </div>
        <p class="sr-only">{{ summary }}</p>
        <div v-show="!showTable" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="panel in panels" :key="panel.key">
                <div class="flex items-baseline justify-between gap-2 text-xs">
                    <span class="font-medium">{{ panel.label }}</span>
                    <span class="tabular-nums text-shell-muted">{{ lastValue(panel) }}</span>
                </div>
                <EkkoChart
                    compact
                    :label="panel.label"
                    :height="panelHeight"
                    :option="optionFor(panel)"
                    @marker="emit('marker', $event)"
                    @select="emit('select', { panel: panel.key, dataIndex: $event.dataIndex })"
                />
            </div>
        </div>
        <ChartTable v-if="showTable" :caption="label" :columns="table.columns" :rows="table.rows" />
    </figure>
</template>
