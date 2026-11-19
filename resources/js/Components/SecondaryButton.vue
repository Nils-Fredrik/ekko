<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center justify-center rounded-full border border-shell-text/20 bg-shell-surface px-4 py-2 text-sm font-medium text-shell-text hover:bg-shell-text/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/60 disabled:opacity-50"
    >
        <slot />
    </button>
</template>
