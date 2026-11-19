<script setup>
// Ranked horizontal bars colored by group, e.g. sources by ownership: own domain, competitor,
// third party (docs/ui-forbedring.md 6, U08). Clicking a bar emits `select` with `{ key }`, the bar key.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { selection } from './support/data.js';
import { axisFormatter, formatValue, isLowSample, tooltipHtml } from './support/format.js';
import { paletteColor } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ key, label, value, n?, low_sample?, group }]
    bars: { type: Array, required: true },
    // [{ key, label, color: palette key }]. Default: ownership of a source (own, competitor, third_party);
    // a bar with an unknown group falls in the last group.
    groups: { type: Array, default: null },
    kind: { type: String, default: 'rate' },
    // Sort by value, highest first. Turn off when the order is given.
    sort: { type: Boolean, default: true },
    rowHeight: { type: Number, default: 28 },
});

const emit = defineEmits(['select']);

const groupList = computed(
    () =>
        props.groups ?? [
            { key: 'own', label: trans('app.ownership.own'), color: 'own' },
            { key: 'competitor', label: trans('app.ownership.competitor'), color: 'cat-2' },
            { key: 'third_party', label: trans('app.ownership.third_party'), color: 'neutral' },
        ],
);
const ordered = computed(() =>
    props.sort ? [...props.bars].sort((a, b) => (b.value ?? -Infinity) - (a.value ?? -Infinity)) : props.bars,
);
const height = computed(() => Math.max(ordered.value.length, 1) * props.rowHeight + 56);

function groupOf(bar) {
    return groupList.value.find((group) => group.key === bar.group) ?? groupList.value[groupList.value.length - 1];
}

function option({ palette }) {
    const used = groupList.value.filter((group) => ordered.value.some((bar) => groupOf(bar).key === group.key));

    return {
        grid: { left: 8, right: 48, top: 8, bottom: 36, containLabel: true },
        legend: { bottom: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8, data: used.map((group) => group.label) },
        tooltip: {
            trigger: 'item',
            confine: true,
            formatter: (params) => {
                const bar = ordered.value[params.dataIndex];
                const group = groupOf(bar);

                return tooltipHtml({
                    title: bar.label,
                    rows: [
                        {
                            label: group.label,
                            color: paletteColor(palette, group.color),
                            value: bar.value,
                            change: bar.change ?? null,
                            n: bar.n,
                            lowSample: isLowSample(bar),
                            kind: props.kind,
                        },
                    ],
                });
            },
        },
        xAxis: {
            type: 'value',
            min: 0,
            splitNumber: 4,
            axisLabel: { formatter: axisFormatter(props.kind) },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: ordered.value.map((bar) => bar.label),
            axisLine: { show: false },
            axisLabel: { color: palette.text },
        },
        // One series per group, so the legend names the colors; each bar sits in its own group's series.
        series: used.map((group) => ({
            id: `g-${group.key}`,
            type: 'bar',
            name: group.label,
            stack: 'bars',
            barMaxWidth: 18,
            itemStyle: { color: paletteColor(palette, group.color) },
            label: {
                show: true,
                position: 'right',
                color: palette.muted,
                formatter: (params) => (params.value == null ? '' : formatValue(params.value, props.kind)),
            },
            data: ordered.value.map((bar) =>
                groupOf(bar).key === group.key
                    ? { value: bar.value, itemStyle: isLowSample(bar) ? { opacity: 0.5 } : undefined }
                    : null,
            ),
        })),
    };
}

function select(params) {
    const bar = ordered.value[params.dataIndex];
    if (bar) {
        emit('select', selection(bar.key));
    }
}

const table = computed(() => ({
    columns: [
        { key: 'label', label: props.label },
        { key: 'group', label: trans('app.charts.group') },
        { key: 'value', label: trans('app.charts.value'), numeric: true },
        { key: 'n', label: trans('app.charts.n_column'), numeric: true },
    ],
    rows: ordered.value.map((bar) => ({
        key: bar.key,
        label: bar.label,
        group: groupOf(bar).label,
        value: formatValue(bar.value, props.kind),
        n: formatValue(bar.n ?? null, 'count'),
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
        .map((bar) => `${bar.label} ${formatValue(bar.value, props.kind)} (${groupOf(bar).label})`)
        .join(', ');

    return trans('app.charts.describe.ranked', { count: ordered.value.length, top });
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
