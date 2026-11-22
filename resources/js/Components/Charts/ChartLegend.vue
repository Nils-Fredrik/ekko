<script setup>
// A legend beside the chart (flex 1 1 170px) so it never covers the axis. It wraps under the
// chart when the row is narrow (docs/ux-arbeidsflate.md 7).
import { formatValue } from './support/format.js';
import { readPalette, seriesColor } from './support/palette.js';
import { onThemeChange } from '@/theme';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    series: { type: Array, required: true },
    kind: { type: String, default: 'rate' },
});

const palette = ref(null);
let stopTheme = null;

onMounted(() => {
    palette.value = readPalette();
    stopTheme = onThemeChange(() => {
        palette.value = readPalette();
    });
});

onBeforeUnmount(() => stopTheme?.());

function color(item, index) {
    return palette.value ? seriesColor(palette.value, item, index) : 'transparent';
}

function latest(item) {
    for (let index = item.points.length - 1; index >= 0; index -= 1) {
        const value = item.points[index]?.value;
        if (value !== null && value !== undefined) {
            return formatValue(value, props.kind);
        }
    }

    return formatValue(null, props.kind);
}
</script>

<template>
    <ul class="flex min-w-0 flex-col gap-1.5 text-sm text-shell-text" style="flex: 1 1 170px">
        <li v-for="(item, index) in series" :key="item.key" class="flex min-w-0 items-center gap-2">
            <span
                class="h-2.5 w-2.5 shrink-0 rounded-full"
                :class="item.dashed ? 'border border-dashed bg-transparent' : ''"
                :style="item.dashed ? { borderColor: color(item, index) } : { background: color(item, index) }"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1 truncate" :title="item.label">{{ item.label }}</span>
            <span class="shrink-0 tabular-nums text-shell-muted">{{ latest(item) }}</span>
        </li>
    </ul>
</template>
