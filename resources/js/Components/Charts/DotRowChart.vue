<script setup>
// Dot row: one dot per day for the last 14 days, showing how stable the answers are
// (docs/ui-forbedring.md 6, U06). The dot is colored on the sequential scale by the day's value;
// a day without answers is an empty ring, a low-n day a muted dashed ring.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { formatDate, formatValue, isLowSample, tooltipHtml } from './support/format.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ x: 'YYYY-MM-DD', value (0–1) | null, n, low_sample }]
    points: { type: Array, required: true },
    kind: { type: String, default: 'rate' },
    height: { type: Number, default: 20 },
});

function step(palette, value) {
    // Steps 2–6: step 0 and 1 are too close to the surface to read as a dot.
    const index = 2 + Math.round(Math.min(Math.max(value, 0), 1) * 4);

    return palette.sequential[index];
}

function option({ palette }) {
    return {
        grid: { left: 6, right: 6, top: 2, bottom: 2 },
        tooltip: {
            trigger: 'item',
            confine: true,
            formatter: (params) => {
                const point = props.points[params.dataIndex];

                return tooltipHtml({
                    title: formatDate(point?.x),
                    rows: [
                        {
                            label: props.label,
                            value: point?.value ?? null,
                            n: point?.n,
                            lowSample: isLowSample(point),
                            kind: props.kind,
                        },
                    ],
                });
            },
        },
        xAxis: { type: 'category', show: false, data: props.points.map((point) => point.x) },
        yAxis: { type: 'value', show: false, min: 0, max: 1 },
        series: [
            {
                id: 'dots',
                type: 'scatter',
                symbolSize: Math.max(6, props.height - 8),
                data: props.points.map((point) => {
                    const missing = point?.value === null || point?.value === undefined;
                    const low = isLowSample(point);

                    return {
                        value: 0.5,
                        itemStyle:
                            missing || low
                                ? {
                                      color: low && !missing ? step(palette, point.value) : 'transparent',
                                      opacity: low ? 0.55 : 1,
                                      borderColor: palette.muted,
                                      borderWidth: 1,
                                      borderType: low ? 'dashed' : 'solid',
                                  }
                                : { color: step(palette, point.value) },
                    };
                }),
            },
        ],
    };
}

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    const measured = props.points.filter((point) => point?.value !== null && point?.value !== undefined);
    if (measured.length === 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }
    const values = measured.map((point) => point.value);

    return trans('app.charts.describe.dots', {
        label: props.label,
        days: props.points.length,
        measured: measured.length,
        min: formatValue(Math.min(...values), props.kind),
        max: formatValue(Math.max(...values), props.kind),
    });
});
</script>

<template>
    <EkkoChart compact :label="label" :description="summary" :height="height" :option="option" />
</template>
