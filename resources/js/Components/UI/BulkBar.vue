<script setup>
// The bar for actions on the selected rows (docs/ux-arbeidsflate.md 4.5, sketch 2). It stays under
// the table and follows the page while scrolling. The buttons come in the default slot.
import { XMarkIcon } from '@heroicons/vue/20/solid';
import { transChoice } from 'laravel-vue-i18n';
import { formatNumber } from '@/format';

defineProps({
    count: { type: Number, required: true },
});

const emit = defineEmits(['clear']);
</script>

<template>
    <!-- The live region is always in the page (outside the layout), so the first «1 valgt» is read too. -->
    <Teleport to="body">
        <p class="sr-only" aria-live="polite">
            {{ count > 0 ? transChoice('workspace.bulk.selected', count, { n: formatNumber(count) }) : '' }}
        </p>
    </Teleport>
    <div
        v-if="count > 0"
        role="toolbar"
        :aria-label="$t('workspace.bulk.label')"
        class="sticky bottom-4 z-20 flex flex-wrap items-center gap-3 rounded-[14px] border border-line bg-row-hover px-4 py-2.5 text-sm text-shell-text shadow-pop"
    >
        <span class="font-medium tabular-nums" aria-hidden="true">
            {{ transChoice('workspace.bulk.selected', count, { n: formatNumber(count) }) }}
        </span>
        <div class="flex flex-1 flex-wrap items-center gap-2">
            <slot />
        </div>
        <button
            type="button"
            class="rounded-full p-1 text-shell-muted hover:bg-shell-surface hover:text-shell-text"
            :aria-label="$t('workspace.bulk.clear')"
            @click="emit('clear')"
        >
            <XMarkIcon class="h-4 w-4" aria-hidden="true" />
        </button>
    </div>
</template>
