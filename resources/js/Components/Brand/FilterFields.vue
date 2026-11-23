<script setup>
import { periodLengths } from './filters.js';

const props = defineProps({
    modelValue: { type: Object, required: true },
    options: { type: Object, required: true },
    grain: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value });
const fieldClass = 'rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text';
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <label class="grid gap-1 text-sm">
            {{ $t('app.dashboard.period') }}
            <select :value="modelValue.mode" :class="fieldClass" @change="set('mode', $event.target.value)">
                <option v-for="days in periodLengths" :key="days" :value="days">
                    {{ $t('app.dashboard.period_days', { days }) }}
                </option>
                <option value="custom">{{ $t('filters.custom') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-sm">
            {{ $t('filters.comparison') }}
            <select :value="modelValue.comparison" :class="fieldClass" @change="set('comparison', $event.target.value)">
                <option value="previous">{{ $t('filters.previous') }}</option>
                <option value="year">{{ $t('filters.year') }}</option>
            </select>
        </label>
        <template v-if="modelValue.mode === 'custom'">
            <label v-for="key in ['from', 'to']" :key="key" class="grid gap-1 text-sm">
                {{ $t(`filters.${key}`) }}
                <input
                    type="date"
                    required
                    :value="modelValue[key]"
                    :class="fieldClass"
                    @input="set(key, $event.target.value)"
                />
            </label>
        </template>
        <label v-for="(items, key) in options" :key="key" class="grid gap-1 text-sm">
            {{ $t(`filters.${key}`) }}
            <select :value="modelValue[key] ?? ''" :class="fieldClass" @change="set(key, $event.target.value)">
                <option value="">{{ $t('filters.all') }}</option>
                <option v-for="item in items" :key="item.value" :value="item.value">{{ item.label }}</option>
            </select>
        </label>
        <label v-if="grain" class="grid gap-1 text-sm">
            {{ $t('filters.grain') }}
            <select :value="modelValue.grain" :class="fieldClass" @change="set('grain', $event.target.value)">
                <option v-for="key in ['day', 'week', 'month']" :key="key" :value="key">
                    {{ $t(`filters.${key}`) }}
                </option>
            </select>
        </label>
    </div>
</template>

<style scoped>
:global(html[data-theme='dark']) input[type='date'] {
    color-scheme: dark;
}
</style>
