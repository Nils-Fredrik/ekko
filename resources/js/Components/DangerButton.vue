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
        class="inline-flex items-center justify-center rounded-full border border-transparent bg-error px-4 py-2 text-sm font-medium text-white hover:bg-secondary focus:outline-none focus-visible:ring-2 focus-visible:ring-error/60"
    >
        <slot />
    </button>
</template>
