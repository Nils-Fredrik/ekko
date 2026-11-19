<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    body: { type: String, required: true },
    href: { type: String, default: '' },
    link: { type: String, default: '' },
});
</script>

<template>
    <div class="rounded-2xl border border-dashed border-line bg-shell-panel px-5 py-6">
        <p class="text-sm font-medium text-shell-text">{{ title }}</p>
        <p class="mt-1 text-sm text-shell-muted">{{ body }}</p>
        <Link v-if="href && link" :href="href" class="mt-3 inline-block text-sm text-primary no-underline">
            {{ link }}
        </Link>
    </div>
</template>
