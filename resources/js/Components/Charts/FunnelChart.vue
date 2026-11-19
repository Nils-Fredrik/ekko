<script setup>
// Funnel «fetched by AI → cited → visits» (docs/ui-forbedring.md 6, U09), as horizontal bars
// with the share of the previous step. Each step explains how it is measured, as visible text
// under the chart.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { formatValue, tooltipHtml } from './support/format.js';
import { paletteColor } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ key, label, value, description? }], in funnel order.
    steps: { type: Array, required: true },
    kind: { type: String, default: 'count' },
    color: { type: String, default: 'own' },
    rowHeight: { type: Number, default: 44 },
});

const height = computed(() => Math.max(props.steps.length, 1) * props.rowHeight + 16);

function conversion(index) {
    const previous = props.steps[index - 1]?.value;
    const value = props.steps[index]?.value;
    if (index === 0 || !previous || value == null) {
        return null;
    }

    return value / previous;
}

function conversionText(index) {
    const rate = conversion(index);

    return rate === null ? '' : trans('app.charts.funnel.of_previous', { rate: formatValue(rate, 'rate') });
}

function option({ palette }) {
    const color = paletteColor(palette, props.color);

    return {
        grid: { left: 8, right: 140, top: 8, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'item',
            confine: true,
            formatter: (params) => {
                const step = props.steps[params.dataIndex];

                return tooltipHtml({
                    title: step?.label,
                    rows: [{ label: trans('app.charts.value'), value: step?.value ?? null, kind: props.kind }],
                    notes: [conversionText(params.dataIndex), step?.description]
                        .filter(Boolean)
                        .map((text) => ({ text })),
                });
            },
        },
        xAxis: { type: 'value', show: false, min: 0 },
        yAxis: {
            type: 'category',
            inverse: true,
            data: props.steps.map((step) => step.label),
            axisLine: { show: false },
            axisLabel: { color: palette.text },
        },
        series: [
            {
                id: 'funnel',
                type: 'bar',
                barMaxWidth: 26,
                data: props.steps.map((step, index) => ({
                    value: step.value,
                    itemStyle: { color, opacity: 1 - index * 0.15 },
                })),
                label: {
                    show: true,
                    position: 'right',
                    color: palette.text,
                    formatter: (params) => {
                        const text = conversionText(params.dataIndex);
                        const value = formatValue(params.value, props.kind);

                        return text ? `${value} · ${text}` : value;
                    },
                },
            },
        ],
    };
}

const table = computed(() => ({
    columns: [
        { key: 'label', label: trans('app.charts.funnel.step') },
        { key: 'value', label: trans('app.charts.value'), numeric: true },
        { key: 'conversion', label: trans('app.charts.funnel.conversion'), numeric: true },
    ],
    rows: props.steps.map((step, index) => ({
        key: step.key,
        label: step.label,
        value: formatValue(step.value, props.kind),
        conversion: formatValue(conversion(index), 'rate'),
    })),
}));

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    if (props.steps.length === 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }

    return props.steps
        .map((step, index) => {
            const text = conversionText(index);

            return `${step.label}: ${formatValue(step.value, props.kind)}${text ? ` (${text})` : ''}.`;
        })
        .join(' ');
});
</script>

<template>
    <div>
        <EkkoChart :label="label" :description="summary" :table="table" :height="height" :option="option" />
        <dl v-if="steps.some((step) => step.description)" class="mt-3 space-y-1 text-xs text-shell-muted">
            <div v-for="step in steps.filter((item) => item.description)" :key="step.key" class="flex gap-2">
                <dt class="font-medium text-shell-text">{{ step.label }}</dt>
                <dd>{{ step.description }}</dd>
            </div>
        </dl>
    </div>
</template>
