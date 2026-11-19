<script setup>
import { Chart } from 'chart.js/auto';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { chartAnimation } from '@/chartMotion';
import { onThemeChange } from '@/theme';

const competitors = ['--data-cat-1', '--data-cat-2', '--data-cat-3', '--data-cat-4', '--data-cat-5', '--data-cat-6'];

const props = defineProps({
    parts: { type: Array, required: true },
});

const canvas = ref(null);
let chart;
let stopTheme;
let firstView = true;

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function render() {
    if (!canvas.value) {
        return;
    }

    chart?.destroy();
    chart = new Chart(canvas.value, {
        type: 'bar',
        data: {
            labels: props.parts.map((part) => part.name),
            datasets: [
                {
                    data: props.parts.map((part) => part.share),
                    backgroundColor: props.parts.map((part, index) => {
                        const name = index === 0 ? '--data-own' : competitors[(index - 1) % competitors.length];
                        const color = token(name);

                        return part.low_sample ? `${color}59` : color;
                    }),
                    borderWidth: 0,
                    maxBarThickness: 28,
                },
            ],
        },
        options: {
            animation: chartAnimation(firstView),
            indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: {
                x: {
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
                y: { grid: { display: false }, ticks: { color: token('--color-shell-text') } },
            },
        },
    });
    firstView = false;
}

onMounted(() => {
    render();
    stopTheme = onThemeChange(render);
});
watch(() => props.parts, render, { deep: true });
onBeforeUnmount(() => {
    stopTheme?.();
    chart?.destroy();
});
</script>

<template>
    <canvas ref="canvas" class="w-full" :style="{ height: `${Math.max(parts.length, 1) * 48}px` }"></canvas>
</template>
