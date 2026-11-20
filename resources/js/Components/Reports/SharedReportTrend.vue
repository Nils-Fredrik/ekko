<script setup>
import { computed, ref } from 'vue';
import { markerText } from '@/Components/Charts/support/markers.js';
import TrendLineChart from '@/Components/Charts/TrendLineChart.vue';

const props = defineProps({
    label: { type: String, required: true },
    brand: { type: String, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    trend: { type: Array, required: true },
    markers: { type: Array, default: () => [] },
});

// Keep missing days empty rather than drawing a line across data that did not exist.
const categories = computed(() => {
    const start = Date.parse(`${props.from}T00:00:00Z`);
    const end = Date.parse(`${props.to}T00:00:00Z`);
    if (!Number.isFinite(start) || !Number.isFinite(end) || end < start || end - start > 366 * 86400000) return [];
    const result = [];
    for (let day = start; day <= end; day += 86400000) result.push(new Date(day).toISOString().slice(0, 10));
    return result;
});
const points = computed(() => {
    const data = new Map(props.trend.map((item) => [item.period, item.visibility]));
    return categories.value.map((day) => data.get(day) ?? { value: null, n: 0, low_sample: true });
});
const series = computed(() => [{ key: 'own', label: props.brand, own: true, points: points.value }]);
const selected = ref(null);
const selectedMarker = ref(null);
function select(event) {
    if (event?.key !== 'own') return;
    const index = categories.value.indexOf(event.category);
    if (index < 0 || points.value[index]?.value == null) return;
    selected.value = { date: categories.value[index], ...points.value[index] };
}
const selectedValue = computed(() => {
    if (!selected.value) return '';
    return new Intl.NumberFormat(document.documentElement.lang || 'nb', {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(selected.value.value);
});
function formatPoint(value) {
    return new Intl.NumberFormat(document.documentElement.lang || 'nb', {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(value);
}
</script>

<template>
    <section aria-labelledby="shared-trend-title" data-testid="shared-report-trend">
        <h2 id="shared-trend-title">{{ label }}</h2>
        <TrendLineChart
            v-if="categories.length && trend.length"
            :label="label"
            :categories="categories"
            :series="series"
            :legend="false"
            :markers="markers"
            :until="to"
            @marker="selectedMarker = $event"
            @select="select"
        />
        <details v-if="categories.length && trend.length">
            <summary>{{ $t('reports.share.points') }}</summary>
            <ul>
                <li v-for="(point, index) in points" :key="categories[index]">
                    <button
                        v-if="point.value != null"
                        type="button"
                        class="underline"
                        @click="select({ key: 'own', category: categories[index] })"
                    >
                        {{ categories[index] }} · {{ formatPoint(point.value) }}
                    </button>
                </li>
            </ul>
        </details>
        <p v-if="selectedMarker" role="status" data-testid="shared-marker-selection">
            {{ markerText(selectedMarker) }}
        </p>
        <p v-if="selected" role="status" data-testid="shared-trend-selection">
            {{ selected.date }} · {{ selectedValue }} · {{ $t('app.dashboard.n', { n: selected.n }) }}
        </p>
    </section>
</template>
