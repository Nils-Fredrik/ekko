<script setup>
import { Chart } from 'chart.js/auto';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const palette = [
    '--color-primary',
    '--color-dark-beige',
    '--color-medium-beige',
    '--color-ink-soft',
    '--color-accent',
    '--color-secondary',
];

const props = defineProps({
    parts: { type: Array, required: true },
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
        type: 'bar',
        data: {
            labels: props.parts.map((part) => part.name),
            datasets: [
                {
                    data: props.parts.map((part) => part.share),
                    backgroundColor: props.parts.map((part, index) => {
                        const color = token(palette[index % palette.length]);

                        return part.low_sample ? `${color}59` : color;
                    }),
                    borderWidth: 0,
                    maxBarThickness: 28,
                },
            ],
        },
        options: {
            animation: false,
            indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: {
                x: {
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
                y: { grid: { display: false }, ticks: { color: token('--color-ink') } },
            },
        },
    });
}

onMounted(render);
watch(() => props.parts, render, { deep: true });
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <canvas ref="canvas" class="w-full" :style="{ height: `${Math.max(parts.length, 1) * 48}px` }"></canvas>
</template>
