<script setup>
// KPI minigraph: the last 28 days of one metric, without axes (docs/ui-forbedring.md 6, U04).
// Low-n stretches are dashed. The text description carries first value, last value and change.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { changeAt, formatChange, formatDate, formatValue, isLowSample, tooltipHtml } from './support/format.js';
import { firstAndLast, lineSeries } from './support/lines.js';
import { paletteColor } from './support/palette.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ x: 'YYYY-MM-DD', value, n, low_sample, change? }]
    points: { type: Array, required: true },
    kind: { type: String, default: 'rate' },
    // Palette key: 'own', 'cat-1' … 'cat-6'.
    color: { type: String, default: 'own' },
    height: { type: Number, default: 40 },
    // Fixed scale (rates 0–1) or the data range (better for small movements).
    fixedScale: { type: Boolean, default: false },
});

function option({ palette }) {
    const color = paletteColor(palette, props.color);

    return {
        grid: { left: 2, right: 2, top: 4, bottom: 4 },
        tooltip: {
            trigger: 'axis',
            confine: true,
            axisPointer: { type: 'line' },
            formatter: (params) => {
                const index = (Array.isArray(params) ? params[0] : params)?.dataIndex ?? 0;
                const point = props.points[index];

                return tooltipHtml({
                    title: formatDate(point?.x),
                    rows: [
                        {
                            label: props.label,
                            color,
                            value: point?.value ?? null,
                            change: changeAt(props.points, index),
                            n: point?.n,
                            lowSample: isLowSample(point),
                            kind: props.kind,
                        },
                    ],
                });
            },
        },
        xAxis: { type: 'category', show: false, boundaryGap: false, data: props.points.map((point) => point.x) },
        yAxis: {
            type: 'value',
            show: false,
            min: props.fixedScale ? 0 : 'dataMin',
            max: props.fixedScale && props.kind === 'rate' ? 1 : 'dataMax',
        },
        series: lineSeries({
            id: 'sparkline',
            name: props.label,
            color,
            points: props.points,
            width: 1.5,
            extra: { areaStyle: { color, opacity: 0.12 } },
        }),
    };
}

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    const range = firstAndLast(props.points);
    if (!range) {
        return trans('app.charts.describe.empty', { label: props.label });
    }

    return trans('app.charts.describe.sparkline', {
        label: props.label,
        from: formatValue(range.first.point.value, props.kind),
        to: formatValue(range.last.point.value, props.kind),
        change: formatChange(range.last.point.value - range.first.point.value, props.kind),
        days: props.points.length,
    });
});
</script>

<template>
    <EkkoChart compact :label="label" :description="summary" :height="height" :option="option" />
</template>
