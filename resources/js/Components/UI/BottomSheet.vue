<!--
  Bottom sheet for small screens: a shell surface that slides up, with a title and a close button.
  Closes with the button, Escape, or a press outside. The page behind is locked while it is open.
  A fixed footer can sit in #footer.
-->
<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline';
import { useOverlay } from '@/Composables/useOverlay';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
});
const emit = defineEmits(['close']);

useOverlay(
    () => props.show,
    () => emit('close'),
);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-50 bg-black/60" @click="emit('close')"></div>
        </Transition>
        <Transition
            enter-active-class="transition-transform duration-200 ease-out"
            enter-from-class="translate-y-full"
            leave-active-class="transition-transform duration-150 ease-in"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="show"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                class="fixed inset-x-0 bottom-0 z-50 flex max-h-[85vh] flex-col rounded-t-2xl bg-shell-surface text-shell-text shadow-pop"
            >
                <div class="flex items-center justify-between gap-3 pt-2 pr-2 pb-1 pl-5">
                    <h2 class="text-[15px] font-semibold">{{ title }}</h2>
                    <button
                        type="button"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-full text-shell-muted hover:bg-shell-text/10 hover:text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/60"
                        :aria-label="$t('app.common.close')"
                        @click="emit('close')"
                    >
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
                <div
                    class="flex-1 overflow-y-auto px-5 text-sm"
                    :class="$slots.footer ? 'pb-4' : 'pb-[max(1rem,env(safe-area-inset-bottom))]'"
                >
                    <slot></slot>
                </div>
                <div
                    v-if="$slots.footer"
                    class="flex items-center justify-end gap-2 border-t border-line-soft px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
                >
                    <slot name="footer"></slot>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
