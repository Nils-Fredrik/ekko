<script setup>
import { Chart } from 'chart.js/auto';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { chartAnimation, chartIntroPending, markChartIntro } from '@/chartMotion';
import { onThemeChange } from '@/theme';

const props = defineProps({
    points: { type: Array, required: true },
});

const canvas = ref(null);
let chart;
let stopTheme;

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function colorFor(point) {
    const color = token('--data-own');

    return point?.visibility.low_sample ? `${color}59` : color;
}

function render() {
    if (!canvas.value) {
        return;
    }

    chart?.destroy();
    chart = new Chart(canvas.value, {
        type: 'line',
        data: {
            labels: props.points.map((point) => point.period),
            datasets: [
                {
                    data: props.points.map((point) => point.visibility.value),
                    borderColor: token('--data-own'),
                    backgroundColor: token('--data-own'),
                    borderWidth: 2,
                    pointRadius: props.points.map((point) => (point.visibility.low_sample ? 3 : 0)),
                    pointBackgroundColor: props.points.map((point) => colorFor(point)),
                    segment: {
                        borderColor: (context) => colorFor(props.points[context.p0DataIndex]),
                    },
                    tension: 0,
                    spanGaps: false,
                },
            ],
        },
        options: {
            animation: chartAnimation(chartIntroPending('trend')),
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    displayColors: false,
                    callbacks: {
                        label(context) {
                            const point = props.points[context.dataIndex];
                            const value = point?.visibility.value;
                            const formatted =
                                value === null || value === undefined
                                    ? trans('app.dashboard.empty')
                                    : new Intl.NumberFormat(document.documentElement.lang || 'nb', {
                                          style: 'percent',
                                          maximumFractionDigits: 1,
                                      }).format(value);

                            return `${formatted} · ${trans('app.dashboard.n', { n: point?.visibility.n ?? 0 })}`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    grid: { color: token('--color-line-soft') },
                    ticks: { color: token('--color-shell-muted'), maxTicksLimit: 6 },
                },
                y: {
                    min: 0,
                    max: 1,
                    grid: { color: token('--color-line') },
                    ticks: {
                        color: token('--color-shell-muted'),
                        callback: (value) =>
                            new Intl.NumberFormat(document.documentElement.lang || 'nb', {
                                style: 'percent',
                                maximumFractionDigits: 0,
                            }).format(value),
                    },
                },
            },
        },
    });
    markChartIntro('trend');
}

onMounted(() => {
    render();
    stopTheme = onThemeChange(render);
});
watch(() => props.points, render, { deep: true });
onBeforeUnmount(() => {
    stopTheme?.();
    chart?.destroy();
});
</script>

<template>
    <canvas ref="canvas" class="h-56 w-full"></canvas>
</template>
