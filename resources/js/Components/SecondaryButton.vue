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
        class="inline-flex items-center justify-center rounded-full border border-ink/20 bg-white px-4 py-2 text-sm font-medium text-ink hover:bg-light-beige focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/60 disabled:opacity-50"
    >
        <slot />
    </button>
</template>
