<script setup>
// <EkkoChart>: every chart goes through this component (docs/ui-forbedring.md 5). It owns
// loading (ECharts is fetched with import() the first time a chart is near the viewport),
// the theme (built from the tokens and replaced when data-theme changes, without a reload),
// motion (300 ms on first draw, none on later updates or with prefers-reduced-motion),
// the table view, the text description for screen readers and the click events.
//
// The chart type components build the ECharts option in `option(context)`. The context has the
// current `palette` (support/palette.js) and the chart `width`; the option is rebuilt when either
// changes or when the reactive data the function reads changes.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useId, watch } from 'vue';
import { onThemeChange } from '@/theme';
import ChartTable from './ChartTable.vue';
import { buildTheme, readPalette } from './support/palette.js';

const props = defineProps({
    option: { type: Function, required: true },
    // Accessible name of the chart, and the table caption.
    label: { type: String, required: true },
    // What the chart shows, in words, for screen readers (aria-describedby).
    description: { type: String, default: '' },
    // { columns: [{ key, label, numeric? }], rows: [{ key, ... }] } for the table view.
    table: { type: Object, default: null },
    height: { type: Number, default: 224 },
    // Minigraphs (sparkline, dot row) have no table toggle; their description carries the numbers.
    compact: { type: Boolean, default: false },
    // Start loading only when the chart is close to the viewport (long tables of minigraphs).
    lazy: { type: Boolean, default: true },
});

const emit = defineEmits(['select', 'marker']);

const root = ref(null);
const canvas = ref(null);
const palette = shallowRef(null);
const width = ref(0);
const ready = ref(false);
const showTable = ref(false);
const descriptionId = `ekko-chart-${useId()}`;

let chart = null;
let drawn = false;
let disposed = false;
let stopTheme = null;
let resizeObserver = null;
let visibilityObserver = null;

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

const built = computed(() => (palette.value ? props.option({ palette: palette.value, width: width.value }) : null));

function draw(option) {
    if (!chart || !option) {
        return;
    }
    const first = !drawn;
    drawn = true;
    chart.setOption(
        {
            animation: first && !reducedMotion(),
            animationDuration: 300,
            animationDurationUpdate: 0,
            ...option,
        },
        { notMerge: true },
    );
}

async function load() {
    if (chart || disposed) {
        return;
    }
    const { init } = await import('./support/echarts.js');
    if (disposed || !canvas.value) {
        return;
    }
    palette.value = readPalette();
    width.value = canvas.value.clientWidth;
    chart = init(canvas.value, buildTheme(palette.value), { renderer: 'svg' });
    chart.on('click', (params) => {
        if (params.componentType === 'markLine' && params.data?.marker) {
            emit('marker', params.data.marker);

            return;
        }
        emit('select', params);
    });
    draw(built.value);
    ready.value = true;

    resizeObserver = new ResizeObserver(() => {
        if (!canvas.value || canvas.value.clientWidth === 0) {
            return;
        }
        width.value = canvas.value.clientWidth;
        chart?.resize();
    });
    resizeObserver.observe(canvas.value);
}

watch(built, (option) => draw(option));

watch(showTable, async (table) => {
    if (!table) {
        await nextTick();
        chart?.resize();
    }
});

onMounted(() => {
    stopTheme = onThemeChange(() => {
        if (!chart) {
            return;
        }
        palette.value = readPalette();
        chart.setTheme(buildTheme(palette.value));
    });

    if (!props.lazy || typeof IntersectionObserver === 'undefined') {
        load();

        return;
    }
    visibilityObserver = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                visibilityObserver?.disconnect();
                visibilityObserver = null;
                load();
            }
        },
        { rootMargin: '200px' },
    );
    visibilityObserver.observe(root.value);
});

onBeforeUnmount(() => {
    disposed = true;
    stopTheme?.();
    visibilityObserver?.disconnect();
    resizeObserver?.disconnect();
    chart?.dispose();
    chart = null;
});

defineExpose({ showTable });
</script>

<template>
    <figure ref="root" class="ekko-chart m-0" :data-chart-ready="ready ? 'true' : 'false'">
        <div v-if="table && !compact" class="mb-2 flex justify-end">
            <button
                type="button"
                class="rounded-md px-2 py-1 text-xs text-shell-muted hover:bg-shell-surface hover:text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :aria-pressed="showTable ? 'true' : 'false'"
                data-testid="chart-table-toggle"
                @click="showTable = !showTable"
            >
                {{ showTable ? $t('app.charts.show_chart') : $t('app.charts.show_table') }}
            </button>
        </div>
        <div
            v-show="!showTable"
            ref="canvas"
            role="img"
            :aria-label="label"
            :aria-describedby="description ? descriptionId : undefined"
            class="relative w-full"
            :class="ready ? '' : 'animate-pulse rounded-md bg-shell-surface'"
            :style="{ height: `${height}px` }"
        ></div>
        <p v-if="description" :id="descriptionId" class="sr-only">{{ description }}</p>
        <ChartTable v-if="table && showTable" :caption="label" :columns="table.columns" :rows="table.rows" />
    </figure>
</template>
