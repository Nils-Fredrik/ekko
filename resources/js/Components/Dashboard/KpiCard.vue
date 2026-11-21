<script setup>
// KPI card with a minigraph, change and n (docs/ui-forbedring.md 4.3 and 6, U04).
import { formatNumber } from '@/format';
import SparklineChart from '@/Components/Charts/SparklineChart.vue';
import { formatChange, formatN, formatValue, isMissing } from '@/Components/Charts/support/format.js';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    metric: { type: Object, required: true },
    previous: { type: Object, default: null },
    points: { type: Array, default: () => [] },
    kind: { type: String, default: 'rate' },
    size: { type: String, default: 'primary' },
    framed: { type: Boolean, default: true },
    // Average position improves when the number falls. The sign stays true; colour and spoken tone follow this.
    higherIsBetter: { type: Boolean, default: true },
});

const delta = computed(() => {
    if (isMissing(props.metric?.value) || isMissing(props.previous?.value)) {
        return null;
    }

    return props.metric.value - props.previous.value;
});

const direction = computed(() => {
    if (delta.value === null || delta.value === 0) {
        return 'flat';
    }

    return delta.value > 0 ? 'up' : 'down';
});

const improved = computed(() => {
    if (delta.value === null || delta.value === 0) {
        return null;
    }

    return props.higherIsBetter ? delta.value > 0 : delta.value < 0;
});

const changeStyle = computed(() => {
    if (improved.value === null) {
        return { color: 'var(--data-neutral)' };
    }

    return { color: improved.value ? 'var(--data-positive)' : 'var(--data-negative)' };
});

const spoken = computed(() => {
    if (delta.value === null) {
        return '';
    }
    if (delta.value === 0) {
        return trans('app.dashboard.change_flat');
    }

    const amount = formatNumber(Math.abs(props.kind === 'rate' ? delta.value * 100 : delta.value), {
        maximumFractionDigits: props.kind === 'count' ? 0 : 1,
    });
    const change = props.kind === 'rate' ? trans('app.dashboard.change_points_spoken', { change: amount }) : amount;
    const key = props.higherIsBetter
        ? direction.value === 'up'
            ? 'app.dashboard.change_up'
            : 'app.dashboard.change_down'
        : improved.value
          ? 'app.dashboard.change_better'
          : 'app.dashboard.change_worse';

    return trans(key, { change });
});

const hasSparkline = computed(() => props.points.some((point) => !isMissing(point?.value)));
</script>

<template>
    <article
        data-testid="kpi-card"
        :data-size="size"
        :class="framed ? 'rounded-2xl border border-line bg-shell-panel p-4' : ''"
    >
        <h3 class="text-xs text-shell-muted">{{ label }}</h3>
        <p
            class="mt-1 font-medium tracking-tight tabular-nums"
            :class="[
                size === 'primary' ? 'text-3xl' : 'text-2xl',
                metric.low_sample ? 'text-shell-muted' : 'text-shell-text',
            ]"
        >
            {{ formatValue(metric.value, kind) }}
        </p>
        <p class="mt-1 flex flex-wrap items-baseline gap-x-2 text-xs text-shell-muted">
            <span>{{ formatN(metric.n) }}</span>
            <span v-if="metric.low_sample">{{ $t('app.dashboard.low_sample') }}</span>
            <span v-if="spoken" class="sr-only">{{ spoken }}</span>
            <span v-if="delta !== null" class="inline-flex items-center gap-1" :style="changeStyle" aria-hidden="true">
                <svg v-if="direction === 'up'" viewBox="0 0 12 12" class="size-3" aria-hidden="true">
                    <path d="M6 1.5 L11 9.5 H1 Z" fill="currentColor" />
                </svg>
                <svg v-else-if="direction === 'down'" viewBox="0 0 12 12" class="size-3" aria-hidden="true">
                    <path d="M6 10.5 L1 2.5 H11 Z" fill="currentColor" />
                </svg>
                {{ formatChange(delta, kind) }}
            </span>
        </p>
        <SparklineChart
            v-if="hasSparkline"
            class="mt-3"
            :label="label"
            :points="points"
            :kind="kind"
            :height="size === 'primary' ? 56 : 36"
        />
    </article>
</template>
