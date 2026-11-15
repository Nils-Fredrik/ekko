<script setup>
defineProps({
    segments: { type: Array, required: true },
});

function kindClass(kind) {
    if (kind === 'brand') {
        return 'rounded-sm bg-primary/15 px-0.5 text-ink';
    }
    if (kind === 'competitor') {
        return 'rounded-sm bg-medium-beige/60 px-0.5 text-ink';
    }
    if (kind === 'unknown') {
        return 'underline decoration-warning decoration-2';
    }

    return '';
}
</script>

<template>
    <p class="text-sm whitespace-pre-wrap text-ink">
        <span v-for="(part, index) in segments" :key="index" :class="kindClass(part.kind)">{{ part.text }}</span>
    </p>
</template>
