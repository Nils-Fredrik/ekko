<script setup>
// Line chart over time: the own brand highlighted, competitors muted, the previous period
// dashed, and markers for model changes and actions (docs/ui-forbedring.md 6). Also used for
// a single line, such as AI traffic (kind="count").
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import MarkerList from './MarkerList.vue';
import { bucketMarkers, trendSelection, trendSeriesSelection } from './support/data.js';
import { axisFormatter, formatChange, formatDate, formatValue, isLowSample, tooltipHtml } from './support/format.js';
import { firstAndLast, lineSeries, pointRows, timeTable } from './support/lines.js';
import { markerNotes, markerSeries } from './support/markers.js';
import { seriesColor } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // Bucket start dates, 'YYYY-MM-DD', as the metrics API returns them.
    categories: { type: Array, required: true },
    // [{ key, label, own?, colorIndex?, points: [{ value, n, low_sample, change? }] }], points aligned with categories.
    series: { type: Array, required: true },
    // The own brand in the previous period, aligned by position: { label, points }.
    previous: { type: Object, default: null },
    // [{ key, date, kind: 'model' | 'action', label, detail?, href? }]
    markers: { type: Array, default: () => [] },
    // Last day of the period; markers after it are left out.
    until: { type: String, default: null },
    kind: { type: String, default: 'rate' },
    height: { type: Number, default: 240 },
    legend: { type: Boolean, default: true },
    selectSeries: { type: Boolean, default: false },
});

const emit = defineEmits(['marker', 'select']);

const buckets = computed(() => bucketMarkers(props.markers, props.categories, props.until));
const colorFor = (palette) => (item, index) => seriesColor(palette, item, props.series[0]?.own ? index - 1 : index);

function option({ palette }) {
    const color = colorFor(palette);
    const series = props.series.flatMap((item, index) =>
        lineSeries({
            id: `s-${item.key}`,
            name: item.label,
            color: color(item, index),
            points: item.points,
            width: item.own ? 3 : 1.5,
            opacity: item.own || props.series.length === 1 ? 1 : 0.7,
            z: item.own ? 10 : 2,
            extra: { triggerLineEvent: props.selectSeries },
        }),
    );
    if (props.previous) {
        series.push(
            ...lineSeries({
                id: 'previous',
                name: props.previous.label,
                color: palette.own,
                points: props.previous.points,
                width: 1.5,
                opacity: 0.6,
                z: 4,
                dashed: true,
            }),
        );
    }
    if (buckets.value.size > 0) {
        series.push(markerSeries(palette, buckets.value));
    }

    return {
        grid: {
            left: 8,
            right: 16,
            top: 16,
            bottom: props.legend && props.series.length > 1 ? 36 : 8,
            containLabel: true,
        },
        legend:
            props.legend && props.series.length > 1
                ? { bottom: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 4, data: legendNames() }
                : { show: false },
        tooltip: {
            trigger: 'axis',
            confine: true,
            formatter: (params) => tooltip(palette, params),
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
            max: props.kind === 'rate' ? 1 : undefined,
            splitNumber: 4,
            interval: props.kind === 'rate' ? 0.25 : undefined,
            axisLabel: { formatter: axisFormatter(props.kind) },
        },
        series,
    };
}

function select(params) {
    const selected =
        trendSelection(params, props.categories, props.series) ??
        (props.selectSeries ? trendSeriesSelection(params, props.series) : null);
    if (selected) emit('select', selected);
}

function legendNames() {
    const names = props.series.map((item) => item.label);

    return props.previous ? [...names, props.previous.label] : names;
}

function tooltip(palette, params) {
    const index = (Array.isArray(params) ? params[0] : params)?.dataIndex ?? 0;
    const category = props.categories[index];
    const rows = pointRows(props.series, index, props.kind, colorFor(palette));
    if (props.previous) {
        const point = props.previous.points[index];
        rows.push({
            label: props.previous.label,
            color: palette.own,
            value: point?.value ?? null,
            n: point?.n,
            lowSample: isLowSample(point),
            kind: props.kind,
        });
    }

    return tooltipHtml({ title: formatDate(category), rows, notes: markerNotes(palette, buckets.value, category) });
}

const table = computed(() => {
    const series = props.previous ? [...props.series, { key: 'previous', ...props.previous }] : props.series;

    return timeTable(props.categories, series, props.kind, trans('app.charts.period'));
});

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    const main = props.series.find((item) => item.own) ?? props.series[0];
    const range = main ? firstAndLast(main.points) : null;
    if (!range) {
        return trans('app.charts.describe.empty', { label: props.label });
    }
    const markers = [...buckets.value.values()].flat().length;

    return trans('app.charts.describe.trend', {
        series: main.label,
        from: formatValue(range.first.point.value, props.kind),
        to: formatValue(range.last.point.value, props.kind),
        start: formatDate(props.categories[range.first.index]),
        end: formatDate(props.categories[range.last.index]),
        change: formatChange(range.last.point.value - range.first.point.value, props.kind),
        others: props.series.length - 1,
        markers,
    });
});
</script>

<template>
    <div>
        <EkkoChart
            :label="label"
            :description="summary"
            :table="table"
            :height="height"
            :option="option"
            @marker="emit('marker', $event)"
            @select="select"
        />
        <MarkerList :markers="[...buckets.values()].flat()" />
    </div>
</template>
