<script setup>
// Heatmap: prompts grouped by topic (rows) × engines (columns), colored on the sequential
// scale (docs/ui-forbedring.md 6, U06). Low-n cells are muted with a dashed border. Clicking a
// cell emits `select` with { row, column } keys.
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import EkkoChart from './EkkoChart.vue';
import { formatValue, isLowSample, tooltipHtml } from './support/format.js';
import { tableValue } from './support/lines.js';

const props = defineProps({
    label: { type: String, required: true },
    description: { type: String, default: '' },
    // [{ key, label, group? }], in display order; rows of the same group should be next to each other.
    rows: { type: Array, required: true },
    // [{ key, label }]
    columns: { type: Array, required: true },
    // [{ row, column, value, n, low_sample, change? }]
    cells: { type: Array, required: true },
    kind: { type: String, default: 'rate' },
    // Scale maximum; rates default to 1.
    max: { type: Number, default: null },
    rowHeight: { type: Number, default: 28 },
    // Characters of the row label shown on the axis; the full text is in the tooltip and table.
    labelLength: { type: Number, default: 36 },
});

const emit = defineEmits(['select']);

const cellMap = computed(() => new Map(props.cells.map((cell) => [`${cell.row}|${cell.column}`, cell])));
// The y axis: a heading line for each topic group, then its prompts. Group headings have no cells.
const lines = computed(() => {
    const result = [];
    props.rows.forEach((row, index) => {
        if (row.group && row.group !== props.rows[index - 1]?.group) {
            result.push({ heading: row.group });
        }
        result.push({ row });
    });

    return result;
});
const height = computed(() => Math.max(lines.value.length, 1) * props.rowHeight + 48);
const scaleMax = computed(
    () => props.max ?? (props.kind === 'rate' ? 1 : Math.max(1, ...props.cells.map((cell) => cell.value ?? 0))),
);

function short(text) {
    return text.length > props.labelLength ? `${text.slice(0, props.labelLength - 1)}…` : text;
}

// The sequential tokens are the scale: step 1 for the lowest values, step 6 (--data-own) for the highest.
// Step 0 is the surface itself and marks a cell without a value.
function stepColor(palette, value) {
    if (value === null || value === undefined) {
        return palette.sequential[0];
    }
    const ratio = Math.min(Math.max(value / scaleMax.value, 0), 1);

    return palette.sequential[1 + Math.round(ratio * 5)];
}

function option({ palette, width }) {
    const data = [];
    lines.value.forEach(({ row }, y) => {
        if (!row) {
            return;
        }
        props.columns.forEach((column, x) => {
            const cell = cellMap.value.get(`${row.key}|${column.key}`);
            if (!cell) {
                return;
            }
            const low = isLowSample(cell);
            const color = stepColor(palette, cell.value);
            data.push({
                value: [x, y, cell.value ?? '-'],
                itemStyle: low
                    ? { color, opacity: 0.55, borderColor: palette.muted, borderType: 'dashed', borderWidth: 1 }
                    : { color, borderColor: palette.panel, borderWidth: 2 },
            });
        });
    });

    return {
        grid: { left: 8, right: 8, top: 32, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'item',
            confine: true,
            formatter: (params) => {
                const [x, y] = params.value;
                const row = lines.value[y]?.row;
                const column = props.columns[x];
                const cell = cellMap.value.get(`${row.key}|${column.key}`);

                return tooltipHtml({
                    title: row.group ? `${row.group} · ${row.label}` : row.label,
                    rows: [
                        {
                            label: column.label,
                            value: cell?.value ?? null,
                            change: cell?.change ?? null,
                            n: cell?.n,
                            lowSample: isLowSample(cell),
                            kind: props.kind,
                        },
                    ],
                });
            },
        },
        xAxis: {
            type: 'category',
            position: 'top',
            data: props.columns.map((column) => column.label),
            axisLine: { show: false },
            // Every engine name, shortened to the cell width rather than hidden.
            axisLabel: {
                interval: 0,
                overflow: 'truncate',
                width: Math.max(40, (width - 160) / Math.max(props.columns.length, 1) - 4),
            },
            splitArea: { show: false },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: lines.value.map((line) => (line.row ? short(line.row.label) : line.heading)),
            axisLine: { show: false },
            axisLabel: {
                interval: 0,
                color: palette.text,
                formatter: (value, index) => (lines.value[index]?.heading ? `{group|${value}}` : value),
                rich: { group: { color: palette.muted, fontWeight: 600, fontSize: 11 } },
            },
        },
        series: [
            {
                id: 'heatmap',
                type: 'heatmap',
                data,
                emphasis: { itemStyle: { borderColor: palette.text, borderWidth: 2 } },
            },
        ],
    };
}

function select(params) {
    if (params.seriesType !== 'heatmap' || !Array.isArray(params.value)) {
        return;
    }
    const [x, y] = params.value;
    const row = lines.value[y]?.row;
    if (row) {
        emit('select', { row: row.key, column: props.columns[x]?.key });
    }
}

const table = computed(() => ({
    columns: [
        { key: 'row', label: trans('app.charts.row') },
        ...props.columns.map((column) => ({ key: `c-${column.key}`, label: column.label, numeric: true })),
    ],
    rows: props.rows.map((row) => {
        const line = { key: row.key, row: row.group ? `${row.group} · ${row.label}` : row.label };
        props.columns.forEach((column) => {
            const cell = cellMap.value.get(`${row.key}|${column.key}`);
            line[`c-${column.key}`] = tableValue(cell, props.kind);
        });

        return line;
    }),
}));

const summary = computed(() => {
    if (props.description) {
        return props.description;
    }
    const measured = props.cells.filter((cell) => cell.value !== null && cell.value !== undefined);
    if (measured.length === 0) {
        return trans('app.charts.describe.empty', { label: props.label });
    }
    const best = measured.reduce((top, cell) => (cell.value > top.value ? cell : top));
    const row = props.rows.find((item) => item.key === best.row);
    const column = props.columns.find((item) => item.key === best.column);

    return trans('app.charts.describe.heatmap', {
        rows: props.rows.length,
        columns: props.columns.length,
        best: `${row?.label ?? ''} · ${column?.label ?? ''}`,
        value: formatValue(best.value, props.kind),
    });
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
