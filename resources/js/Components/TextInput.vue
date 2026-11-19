<script setup>
import { onMounted, ref } from 'vue';

defineProps({
    modelValue: String,
});

defineEmits(['update:modelValue']);

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        class="rounded-md border border-line bg-shell-surface text-shell-text shadow-sm focus:border-primary focus:ring-primary/30"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
