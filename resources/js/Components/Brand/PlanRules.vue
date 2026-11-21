<script setup>
import { rulesToRows, rowsToRules, allowsFrequency } from '@/measurementPlan';
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: Array, required: true },
    catalog: { type: Array, required: true },
    frequencies: { type: Array, required: true },
    maxRepetitions: { type: Number, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
const rows = ref(rulesToRows(props.modelValue, props.catalog, props.maxRepetitions));
const field =
    'w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text disabled:opacity-50';

watch(
    () => JSON.stringify(props.modelValue),
    () => {
        if (JSON.stringify(rowsToRules(rows.value, props.maxRepetitions)) === JSON.stringify(props.modelValue)) {
            return;
        }
        rows.value = rulesToRows(props.modelValue, props.catalog, props.maxRepetitions);
    },
);

function commit() {
    const next = rowsToRules(rows.value, props.maxRepetitions);
    if (JSON.stringify(next) === JSON.stringify(props.modelValue)) {
        return;
    }
    emit('update:modelValue', next);
}

function onScope(row) {
    if (row.prompt_scope === 'core') {
        row.core_frequency = '';
    }
    commit();
}

function repetitionChoices() {
    return Array.from({ length: Math.max(1, props.maxRepetitions) }, (_, index) => index + 1);
}
</script>

<template>
    <div class="space-y-4">
        <fieldset v-for="row in rows" :key="row.engine_key" class="space-y-2 border-t border-line pt-3">
            <legend class="text-sm font-medium">{{ row.name }}</legend>
            <p v-if="!row.selectable" class="text-sm text-shell-muted">{{ $t('app.plan.unavailable') }}</p>
            <p v-else-if="row.frequency !== 'off' && !row.has_price" class="text-sm" role="alert">
                {{ $t('app.plan.missing_price') }}
            </p>
            <div class="grid gap-2 sm:grid-cols-2">
                <label class="block text-sm">
                    {{ $t('app.plan.frequency', { engine: row.name }) }}
                    <select
                        v-model="row.frequency"
                        class="mt-1"
                        :class="field"
                        :disabled="disabled || !row.selectable"
                        @change="commit"
                    >
                        <option
                            v-for="frequency in frequencies"
                            :key="frequency"
                            :value="frequency"
                            :disabled="!allowsFrequency(frequency, row.max_frequency)"
                        >
                            {{ $t(`onboarding.frequencies.${frequency}`) }}
                        </option>
                    </select>
                </label>
                <label class="block text-sm">
                    {{ $t('app.plan.scope', { engine: row.name }) }}
                    <select
                        v-model="row.prompt_scope"
                        class="mt-1"
                        :class="field"
                        :disabled="disabled || !row.selectable || row.frequency === 'off'"
                        @change="onScope(row)"
                    >
                        <option value="all">{{ $t('app.workspace.scope_all') }}</option>
                        <option value="core">{{ $t('app.workspace.scope_core') }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    {{ $t('app.plan.repetitions', { engine: row.name }) }}
                    <select
                        v-model.number="row.repetitions"
                        class="mt-1"
                        :class="field"
                        :disabled="disabled || !row.selectable || row.frequency === 'off'"
                        @change="commit"
                    >
                        <option v-for="count in repetitionChoices()" :key="count" :value="count">{{ count }}</option>
                    </select>
                </label>
                <label v-if="row.prompt_scope === 'all' && row.frequency !== 'off'" class="block text-sm">
                    {{ $t('app.plan.core_frequency', { engine: row.name }) }}
                    <select
                        v-model="row.core_frequency"
                        class="mt-1"
                        :class="field"
                        :disabled="disabled || !row.selectable"
                        @change="commit"
                    >
                        <option value="">{{ $t('app.plan.core_frequency_none') }}</option>
                        <option
                            v-for="frequency in frequencies"
                            :key="frequency"
                            :value="frequency"
                            :disabled="!allowsFrequency(frequency, row.max_frequency)"
                        >
                            {{ $t(`onboarding.frequencies.${frequency}`) }}
                        </option>
                    </select>
                </label>
                <label v-if="row.core_frequency && row.prompt_scope === 'all'" class="block text-sm">
                    {{ $t('app.plan.core_repetitions', { engine: row.name }) }}
                    <select
                        v-model.number="row.core_repetitions"
                        class="mt-1"
                        :class="field"
                        :disabled="disabled || !row.selectable"
                        @change="commit"
                    >
                        <option v-for="count in repetitionChoices()" :key="count" :value="count">{{ count }}</option>
                    </select>
                </label>
            </div>
        </fieldset>
    </div>
</template>
