<script setup>
// Treemap: cited sources by source type (docs/ui-forbedring.md 6, U08). Area is the value
// (citations); each type has a fixed palette color. Clicking a tile emits `select` with its key.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { formatValue, isLowSample, tooltipHtml } from './support/format.js';
import { paletteColor, readableOn } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ key, label, value, n?, low_sample?, color?: palette key }]
    items: { type: Array, required: true },
    kind: { type: String, default: 'count' },
    height: { type: Number, default: 260 },
});

const emit = defineEmits(['select']);

const total = computed(() => props.items.reduce((sum, item) => sum + (Number(item.value) || 0), 0));
const share = (item) => (total.value > 0 ? (Number(item.value) || 0) / total.value : null);
const colorKey = (item, index) => item.color ?? `cat-${(index % 6) + 1}`;

function option({ palette }) {
    return {
        tooltip: {
            trigger: 'item',
            confine: true,
            formatter: (params) => {
                const item = props.items.find((entry) => entry.key === params.data?.key);
                if (!item) {
                    return '';
                }

                return tooltipHtml({
                    title: item.label,
                    rows: [
                        {
                            label: trans('app.charts.value'),
                            value: item.value,
                            n: item.n,
                            lowSample: isLowSample(item),
                            kind: props.kind,
                        },
                        { label: trans('app.charts.share'), value: share(item), kind: 'rate' },
                    ],
                });
            },
        },
        series: [
            {
                id: 'treemap',
                type: 'treemap',
                roam: false,
                nodeClick: false,
                breadcrumb: { show: false },
                width: '100%',
                height: '100%',
                top: 0,
                left: 0,
                label: {
                    show: true,
                    formatter: (params) => `${params.name}\n${formatValue(share(params.data) ?? null, 'rate', 0)}`,
                },
                itemStyle: { borderColor: palette.panel, borderWidth: 2, gapWidth: 2 },
                data: props.items.map((item, index) => ({
                    key: item.key,
                    name: item.label,
                    value: item.value,
                    itemStyle: {
                        color: paletteColor(palette, colorKey(item, index)),
                        opacity: isLowSample(item) ? 0.5 : 1,
                    },
                    label: { color: readableOn(palette, paletteColor(palette, colorKey(item, index))) },
                })),
            },
        ],
    };
}

function select(params) {
    if (params.data?.key !== undefined) {
        emit('select', { key: params.data.key });
    }
}

const table = computed(() => ({
    columns: [
        { key: 'label', label: props.label },
        { key: 'value', label: trans('app.charts.value'), numeric: true },
        { key: 'share', label: trans('app.charts.share'), numeric: true },
    ],
    rows: props.items.map((item) => ({
        key: item.key,
        label: item.label,
        value: formatValue(item.value, props.kind),
        share: formatValue(share(item), 'rate'),
    })),
}));

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    if (props.items.length === 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }
    const parts = [...props.items]
        .sort((a, b) => (b.value ?? 0) - (a.value ?? 0))
        .map((item) => `${item.label} ${formatValue(share(item), 'rate', 0)}`)
        .join(', ');

    return trans('app.charts.describe.treemap', { label: props.label, parts });
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
