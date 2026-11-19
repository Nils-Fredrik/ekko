<script setup>
// Source gap: sources where competitors are cited and the own brand is not, as paired bars
// (own vs competitors), largest gap first (docs/ui-forbedring.md 6, U08). Clicking a row emits
// `select` with `{ key }`, the row key, so the page can open the matching action (T43).
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { selection } from './support/data.js';
import { axisFormatter, formatChange, isLowSample, tooltipHtml } from './support/format.js';
import { tableValue } from './support/lines.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ key, label, own: { value, n, low_sample }, competitors: { value, n, low_sample } }]
    rows: { type: Array, required: true },
    kind: { type: String, default: 'rate' },
    rowHeight: { type: Number, default: 40 },
});

const emit = defineEmits(['select']);

const gap = (row) => (row.competitors?.value ?? 0) - (row.own?.value ?? 0);
const ordered = computed(() => [...props.rows].sort((a, b) => gap(b) - gap(a)));
const height = computed(() => Math.max(ordered.value.length, 1) * props.rowHeight + 56);

function option({ palette }) {
    const sides = [
        { key: 'own', label: trans('app.charts.gap.own'), color: palette.own },
        { key: 'competitors', label: trans('app.charts.gap.competitors'), color: palette.categories[1] },
    ];

    return {
        grid: { left: 8, right: 48, top: 8, bottom: 36, containLabel: true },
        legend: { bottom: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8 },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            confine: true,
            formatter: (params) => {
                const row = ordered.value[(Array.isArray(params) ? params[0] : params)?.dataIndex ?? 0];

                return tooltipHtml({
                    title: row?.label,
                    rows: sides.map((side) => ({
                        label: side.label,
                        color: side.color,
                        value: row?.[side.key]?.value ?? null,
                        n: row?.[side.key]?.n,
                        lowSample: isLowSample(row?.[side.key]),
                        kind: props.kind,
                    })),
                    notes: [
                        { text: trans('app.charts.gap.difference', { gap: formatChange(gap(row ?? {}), props.kind) }) },
                    ],
                });
            },
        },
        xAxis: { type: 'value', min: 0, splitNumber: 4, axisLabel: { formatter: axisFormatter(props.kind) } },
        yAxis: {
            type: 'category',
            inverse: true,
            data: ordered.value.map((row) => row.label),
            axisLine: { show: false },
            axisLabel: { color: palette.text },
        },
        series: sides.map((side) => ({
            id: `gap-${side.key}`,
            type: 'bar',
            name: side.label,
            barMaxWidth: 12,
            barGap: '20%',
            itemStyle: { color: side.color },
            data: ordered.value.map((row) => ({
                value: row[side.key]?.value ?? null,
                itemStyle: isLowSample(row[side.key]) ? { opacity: 0.5 } : undefined,
            })),
        })),
    };
}

function select(params) {
    const row = ordered.value[params.dataIndex];
    if (row) {
        emit('select', selection(row.key));
    }
}

const table = computed(() => ({
    columns: [
        { key: 'label', label: props.label },
        { key: 'own', label: trans('app.charts.gap.own'), numeric: true },
        { key: 'competitors', label: trans('app.charts.gap.competitors'), numeric: true },
        { key: 'gap', label: trans('app.charts.gap.column'), numeric: true },
    ],
    rows: ordered.value.map((row) => ({
        key: row.key,
        label: row.label,
        own: tableValue(row.own, props.kind),
        competitors: tableValue(row.competitors, props.kind),
        gap: formatChange(gap(row), props.kind),
    })),
}));

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    if (ordered.value.length === 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }
    const top = ordered.value
        .slice(0, 3)
        .map((row) => `${row.label} (${formatChange(gap(row), props.kind)})`)
        .join(', ');

    return trans('app.charts.describe.gap', { count: ordered.value.length, top });
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
