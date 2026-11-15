<script setup>
import { Chart } from 'chart.js/auto';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    points: { type: Array, required: true },
});

const canvas = ref(null);
let chart;

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
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
                    borderColor: token('--color-primary'),
                    backgroundColor: token('--color-primary'),
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0,
                    spanGaps: false,
                },
            ],
        },
        options: {
            animation: false,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: {
                x: {
                    grid: { color: token('--color-line-soft') },
                    ticks: { color: token('--color-ink-soft'), maxTicksLimit: 6 },
                },
                y: {
                    min: 0,
                    max: 1,
                    grid: { color: token('--color-line') },
                    ticks: {
                        color: token('--color-ink-soft'),
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
}

onMounted(render);
watch(() => props.points, render, { deep: true });
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <canvas ref="canvas" class="h-56 w-full"></canvas>
</template>
