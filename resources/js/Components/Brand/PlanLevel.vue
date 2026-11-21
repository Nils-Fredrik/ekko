<script setup>
defineProps({
    level: { type: Object, required: true },
});
</script>

<template>
    <p class="tabular-nums">{{ $t('app.plan.volume', { count: level.answers }) }}</p>
    <p class="tabular-nums">{{ $t('app.plan.quota_calls', { count: level.quota_calls }) }}</p>
    <p v-if="level.engine_cost_usd != null" class="tabular-nums">
        {{ $t('app.plan.cost_collection', { amount: level.engine_cost_usd }) }}
    </p>
    <p v-if="level.extraction_cost_usd != null" class="tabular-nums">
        {{ $t('app.plan.cost_extraction', { amount: level.extraction_cost_usd }) }}
    </p>
    <p v-if="level.total_cost_usd != null" class="tabular-nums">
        {{ $t('app.plan.cost_total', { amount: level.total_cost_usd }) }}
    </p>
</template>
