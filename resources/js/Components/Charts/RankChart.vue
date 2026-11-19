<script setup>
// Rank chart (bump chart): who is ranked highest over time, top 6 (docs/ui-forbedring.md 6, U07).
// Rank 1 is at the top. Pass colorIndex on each competitor so it keeps its dashboard color.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { formatDate, formatN, isLowSample, tooltipHtml } from './support/format.js';
import { lineSeries } from './support/lines.js';
import { seriesColor } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    categories: { type: Array, required: true },
    // [{ key, label, own?, colorIndex?, points: [{ rank, value?, n, low_sample }] }]
    series: { type: Array, required: true },
    top: { type: Number, default: 6 },
    height: { type: Number, default: 260 },
});

const colorFor = (palette) => (item, index) => seriesColor(palette, item, props.series[0]?.own ? index - 1 : index);

function rankPoints(item) {
    return item.points.map((point) => ({
        ...point,
        value: point?.rank != null && point.rank <= props.top ? point.rank : null,
    }));
}

function option({ palette }) {
    const color = colorFor(palette);
    const series = props.series.flatMap((item, index) =>
        lineSeries({
            id: `r-${item.key}`,
            name: item.label,
            color: color(item, index),
            points: rankPoints(item),
            width: item.own ? 3 : 2,
            z: item.own ? 10 : 2,
            extra: { showSymbol: true, symbolSize: item.own ? 9 : 7 },
        }).map((line) =>
            line.id.endsWith('-low')
                ? line
                : { ...line, endLabel: { show: true, formatter: item.label, color: palette.text, fontSize: 11 } },
        ),
    );

    return {
        grid: { left: 8, right: 96, top: 12, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'axis',
            confine: true,
            formatter: (params) => {
                const index = (Array.isArray(params) ? params[0] : params)?.dataIndex ?? 0;
                const rows = props.series
                    .map((item, seriesIndex) => ({ item, seriesIndex, point: item.points[index] }))
                    .filter(({ point }) => point?.rank != null)
                    .sort((a, b) => a.point.rank - b.point.rank)
                    .map(({ item, seriesIndex, point }) => ({
                        label: item.label,
                        color: color(item, seriesIndex),
                        text: trans('app.charts.rank', { rank: point.rank }),
                        n: point.n,
                        lowSample: isLowSample(point),
                    }));

                return tooltipHtml({ title: formatDate(props.categories[index]), rows });
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
            inverse: true,
            min: 1,
            max: props.top,
            interval: 1,
            axisLabel: { formatter: (value) => trans('app.charts.rank', { rank: value }) },
        },
        series,
    };
}

const table = computed(() => ({
    columns: [
        { key: 'period', label: trans('app.charts.period') },
        ...props.series.map((item) => ({ key: `s-${item.key}`, label: item.label, numeric: true })),
    ],
    rows: props.categories.map((category, index) => {
        const row = { key: category, period: formatDate(category) };
        props.series.forEach((item) => {
            const point = item.points[index];
            row[`s-${item.key}`] =
                point?.rank == null
                    ? trans('app.charts.empty')
                    : Number.isFinite(point.n)
                      ? `${point.rank} (${formatN(point.n)}${isLowSample(point) ? `, ${trans('app.charts.low_sample')}` : ''})`
                      : String(point.rank);
        });

        return row;
    }),
}));

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    const last = props.categories.length - 1;
    const order = props.series
        .filter((item) => item.points[last]?.rank != null)
        .sort((a, b) => a.points[last].rank - b.points[last].rank)
        .map((item) => item.label);
    if (order.length === 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }

    return trans('app.charts.describe.rank', { date: formatDate(props.categories[last]), order: order.join(', ') });
});
</script>

<template>
    <EkkoChart :label="label" :description="summary" :table="table" :height="height" :option="option" />
</template>
