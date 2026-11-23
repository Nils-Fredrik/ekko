<script setup>
import { usePage } from '@inertiajs/vue3';

defineProps({
    brand: { type: String, default: '' },
    group: { type: String, required: true },
    title: { type: String, required: true },
});

const page = usePage();
</script>

<template>
    <div data-testid="workspace-header" class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs text-shell-muted">
                {{ [brand || page.props.auth?.user?.current_team?.name, group].filter(Boolean).join(' · ') }}
            </p>
            <h1 class="mt-0.5 text-[26px] leading-tight font-medium tracking-tight break-words text-shell-fg">
                {{ title }}
            </h1>
            <slot name="description" />
        </div>
        <slot />
    </div>
</template>
