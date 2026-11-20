<script setup>
// Stacked area over time (docs/ui-forbedring.md 6): share of voice as 100 % stacked
// (normalized, U05) and fetches per AI robot as counts (U09). Buckets with low n get a
// muted band and are marked in the tooltip and the table.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { bucketMarkers, hasIsolatedPoint, isLowSample } from './support/data.js';
import { axisFormatter, formatDate, formatValue, tooltipHtml } from './support/format.js';
import { timeTable } from './support/lines.js';
import { markerNotes, markerSeries } from './support/markers.js';
import { seriesColor } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    categories: { type: Array, required: true },
    // [{ key, label, own?, colorIndex?, points: [{ value, n, low_sample }] }]
    series: { type: Array, required: true },
    // Show each bucket as 100 %: values are divided by the bucket total.
    normalized: { type: Boolean, default: false },
    kind: { type: String, default: 'count' },
    markers: { type: Array, default: () => [] },
    until: { type: String, default: null },
    height: { type: Number, default: 240 },
});

const emit = defineEmits(['marker']);

const valueKind = computed(() => (props.normalized ? 'rate' : props.kind));
const buckets = computed(() => bucketMarkers(props.markers, props.categories, props.until));

const totals = computed(() =>
    props.categories.map((_, index) =>
        props.series.reduce((sum, item) => sum + (Number(item.points[index]?.value) || 0), 0),
    ),
);

function shown(item, index) {
    const value = item.points[index]?.value;
    if (value === null || value === undefined) {
        return null;
    }
    if (!props.normalized) {
        return value;
    }

    return totals.value[index] > 0 ? value / totals.value[index] : null;
}

// Stretches touching a low-n bucket, as [from, to] category pairs. A single bucket on its own
// (one category) is widened to its neighbours, so the band is visible.
const lowBands = computed(() => {
    const low = props.categories.map((_, index) => props.series.some((item) => isLowSample(item.points[index])));
    const last = props.categories.length - 1;
    const bands = [];
    let start = null;
    low.forEach((isLow, index) => {
        if (isLow && start === null) {
            start = Math.max(index - 1, 0);
        }
        if (start !== null && (!low[index + 1] || index === last)) {
            if (!isLow) {
                return;
            }
            bands.push([props.categories[start], props.categories[Math.min(index + 1, last)]]);
            start = null;
        }
    });

    return bands;
});

const colorFor = (palette) => (item, index) => seriesColor(palette, item, props.series[0]?.own ? index - 1 : index);

function option({ palette }) {
    const color = colorFor(palette);
    const series = props.series.map((item, index) => {
        const data = props.categories.map((_, bucket) => shown(item, bucket));

        return {
            id: `s-${item.key}`,
            type: 'line',
            name: item.label,
            stack: 'total',
            // A single drawn point (a one-day period) has no line or area, so its symbol carries it.
            showSymbol: hasIsolatedPoint(data),
            connectNulls: false,
            emphasis: { focus: 'series' },
            lineStyle: { width: 1, color: color(item, index) },
            itemStyle: { color: color(item, index) },
            areaStyle: { color: color(item, index), opacity: item.own ? 0.9 : 0.75 },
            data,
            markArea:
                index === 0 && lowBands.value.length > 0
                    ? {
                          silent: true,
                          itemStyle: { color: palette.shell, opacity: 0.45 },
                          data: lowBands.value.map(([from, to]) => [{ xAxis: from }, { xAxis: to }]),
                      }
                    : undefined,
        };
    });
    if (buckets.value.size > 0) {
        series.push(markerSeries(palette, buckets.value));
    }

    return {
        grid: { left: 8, right: 16, top: 16, bottom: 36, containLabel: true },
        legend: { bottom: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8 },
        tooltip: {
            trigger: 'axis',
            confine: true,
            formatter: (params) => {
                const index = (Array.isArray(params) ? params[0] : params)?.dataIndex ?? 0;
                const rows = props.series.map((item, seriesIndex) => {
                    const point = item.points[index];

                    return {
                        label: item.label,
                        color: color(item, seriesIndex),
                        value: shown(item, index),
                        n: point?.n,
                        lowSample: isLowSample(point),
                        kind: valueKind.value,
                    };
                });
                const category = props.categories[index];

                return tooltipHtml({
                    title: formatDate(category),
                    rows,
                    notes: markerNotes(palette, buckets.value, category),
                });
            },
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: props.categories,
            axisLabel: { formatter: formatDate, hideOverlap: true },
        },
        yAxis: {
            type: 'value',
            min: 0,
            max: props.normalized ? 1 : undefined,
            splitNumber: 4,
            interval: props.normalized ? 0.25 : undefined,
            axisLabel: { formatter: axisFormatter(valueKind.value) },
        },
        series,
    };
}

const table = computed(() =>
    timeTable(
        props.categories,
        props.series.map((item) => ({
            ...item,
            points: item.points.map((point, index) => ({ ...point, value: shown(item, index) })),
        })),
        valueKind.value,
        trans('app.charts.period'),
    ),
);

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    const last = props.categories.length - 1;
    if (last < 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }
    const parts = props.series
        .map((item) => `${item.label} ${formatValue(shown(item, last), valueKind.value)}`)
        .join(', ');

    return trans('app.charts.describe.stacked', { date: formatDate(props.categories[last]), parts });
});
</script>

<template>
    <EkkoChart
        :label="label"
        :description="summary"
        :table="table"
        :height="height"
        :option="option"
        @marker="emit('marker', $event)"
    />
</template>
