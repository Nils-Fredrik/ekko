<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    metric: { type: Object, required: true },
    kind: { type: String, default: 'rate' },
    previous: { type: Object, default: null },
});

const locale = computed(() => usePage().props.locale || 'nb');

const text = computed(() => {
    if (props.metric.value === null || props.metric.value === undefined) {
        return null;
    }

    if (props.kind === 'rate') {
        return new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 1 }).format(
            props.metric.value,
        );
    }

    return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(props.metric.value);
});

const points = computed(() => {
    if (props.kind !== 'rate' || props.previous?.value == null || props.metric.value == null) {
        return null;
    }

    return (props.metric.value - props.previous.value) * 100;
});

const change = computed(() => {
    if (points.value === null) {
        return null;
    }

    return new Intl.NumberFormat(locale.value, {
        maximumFractionDigits: 1,
        signDisplay: 'exceptZero',
    }).format(points.value);
});

const changeClass = computed(() => {
    if (points.value === null || points.value === 0) {
        return 'text-shell-muted';
    }

    return points.value > 0 ? 'text-success' : 'text-error';
});
</script>

<template>
    <span
        :class="metric.low_sample ? 'text-shell-muted' : 'text-shell-text'"
        :title="metric.low_sample ? $t('app.dashboard.low_sample') : undefined"
    >
        <span class="text-2xl font-medium tracking-tight">{{ text ?? $t('app.dashboard.empty') }}</span>
        <span class="ms-2 text-xs text-shell-muted">{{ $t('app.dashboard.n', { n: metric.n }) }}</span>
        <span v-if="change" class="ms-2 text-xs" :class="changeClass">
            {{ $t('app.dashboard.change_points', { change }) }}
        </span>
    </span>
</template>
