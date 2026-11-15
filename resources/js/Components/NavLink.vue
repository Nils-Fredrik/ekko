<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: String,
    active: Boolean,
});

const classes = computed(() => {
    return props.active
        ? 'inline-flex items-center border-b-2 border-primary px-1 pt-1 text-sm leading-5 font-medium text-shell-fg focus:outline-none'
        : 'inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm leading-5 font-medium text-shell-text/70 hover:border-shell-fg/30 hover:text-shell-fg focus:outline-none';
});
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
