<script setup>
// Diverging stacked bar: negative to the left, neutral split around zero, positive to the right
// (docs/ui-forbedring.md 6, sentiment in U05 and U07). Shares are 0–1 per row. Clicking a row
// emits `select` with `{ key }`, the row key.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { selection } from './support/data.js';
import { formatValue, isLowSample, tooltipHtml } from './support/format.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ key, label, negative, neutral, positive, n, low_sample }]
    rows: { type: Array, required: true },
    rowHeight: { type: Number, default: 36 },
});

const emit = defineEmits(['select']);

const height = computed(() => Math.max(props.rows.length, 1) * props.rowHeight + 56);

const parts = [
    { key: 'negative', side: -1, color: 'negative' },
    { key: 'neutral', side: -1, half: true, color: 'neutral' },
    { key: 'neutral', side: 1, half: true, color: 'neutral' },
    { key: 'positive', side: 1, color: 'positive' },
];

function option({ palette }) {
    const extent = Math.max(
        0.5,
        ...props.rows.map((row) => (row.negative ?? 0) + (row.neutral ?? 0) / 2),
        ...props.rows.map((row) => (row.positive ?? 0) + (row.neutral ?? 0) / 2),
    );
    const limit = Math.min(1, Math.ceil(extent * 10) / 10);
    // Draw order: the neutral halves next to zero, then negative and positive outside them.
    const order = [1, 0, 2, 3];

    return {
        grid: { left: 8, right: 16, top: 8, bottom: 36, containLabel: true },
        legend: {
            bottom: 0,
            icon: 'roundRect',
            itemWidth: 12,
            itemHeight: 8,
            data: ['negative', 'neutral', 'positive'].map((key) => trans(`app.charts.sentiment.${key}`)),
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            confine: true,
            formatter: (params) => {
                const row = props.rows[(Array.isArray(params) ? params[0] : params)?.dataIndex ?? 0];

                return tooltipHtml({
                    title: row?.label,
                    rows: ['negative', 'neutral', 'positive'].map((key, index) => ({
                        label: trans(`app.charts.sentiment.${key}`),
                        color: palette[key],
                        value: row?.[key] ?? null,
                        n: index === 2 ? row?.n : undefined,
                        lowSample: index === 2 && isLowSample(row),
                        kind: 'rate',
                    })),
                });
            },
        },
        xAxis: {
            type: 'value',
            min: -limit,
            max: limit,
            splitNumber: 4,
            axisLabel: { formatter: (value) => formatValue(Math.abs(value), 'rate', 0) },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: props.rows.map((row) => row.label),
            axisLine: { show: false },
            axisLabel: { color: palette.text },
        },
        series: order.map((partIndex) => {
            const part = parts[partIndex];

            return {
                id: `${part.key}-${part.side}`,
                type: 'bar',
                name: trans(`app.charts.sentiment.${part.key}`),
                // One stack: ECharts stacks negative and positive values apart, so the row stays on one line.
                stack: 'sentiment',
                barMaxWidth: 22,
                itemStyle: { color: palette[part.color] },
                data: props.rows.map((row) => ({
                    value: part.side * (row[part.key] ?? 0) * (part.half ? 0.5 : 1),
                    itemStyle: isLowSample(row) ? { opacity: 0.5 } : undefined,
                })),
            };
        }),
    };
}

function select(params) {
    const row = props.rows[params.dataIndex];
    if (row) {
        // `row` is the alias from the first version; new pages read `key`.
        emit('select', selection(row.key, { row: row.key }));
    }
}

const table = computed(() => ({
    columns: [
        { key: 'label', label: trans('app.charts.row') },
        { key: 'negative', label: trans('app.charts.sentiment.negative'), numeric: true },
        { key: 'neutral', label: trans('app.charts.sentiment.neutral'), numeric: true },
        { key: 'positive', label: trans('app.charts.sentiment.positive'), numeric: true },
        { key: 'n', label: trans('app.charts.n_column'), numeric: true },
    ],
    rows: props.rows.map((row) => ({
        key: row.key,
        label: row.label,
        negative: formatValue(row.negative, 'rate'),
        neutral: formatValue(row.neutral, 'rate'),
        positive: formatValue(row.positive, 'rate'),
        n: formatValue(row.n, 'count'),
    })),
}));

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    if (props.rows.length === 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }

    return props.rows
        .map((row) =>
            trans('app.charts.describe.sentiment', {
                label: row.label,
                negative: formatValue(row.negative, 'rate'),
                neutral: formatValue(row.neutral, 'rate'),
                positive: formatValue(row.positive, 'rate'),
            }),
        )
        .join(' ');
});
</script>

<template>
    <EkkoChart
        :label="label"
        :description="summary"
        :table="table"
        :height="height"
        :option="option"
        @select="select"
    />
</template>
