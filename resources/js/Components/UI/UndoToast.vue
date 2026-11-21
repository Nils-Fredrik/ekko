<script setup>
// The message area for useUndoToast (docs/ux-arbeidsflate.md 4.5). The live region is always in the
// page, so screen readers announce each new message.
import { XMarkIcon } from '@heroicons/vue/20/solid';
import { useUndoToast } from '@/Composables/useUndoToast';

const toast = useUndoToast();
</script>

<template>
    <div aria-live="polite" class="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex justify-center px-4">
        <div
            v-if="toast.current.value"
            :key="toast.current.value.id"
            class="pointer-events-auto flex max-w-lg items-center gap-4 rounded-xl bg-shell-text px-4 py-2.5 text-sm text-shell shadow-pop"
        >
            <span class="min-w-0 flex-1">{{ toast.current.value.text }}</span>
            <button
                v-if="toast.current.value.undo"
                type="button"
                class="font-medium text-primary-deep hover:underline disabled:opacity-60"
                :disabled="toast.current.value.busy"
                @click="toast.undo()"
            >
                {{ $t('workspace.undo.action') }}
            </button>
            <button
                type="button"
                class="rounded-full p-0.5 opacity-70 hover:opacity-100"
                :aria-label="$t('workspace.undo.dismiss')"
                @click="toast.dismiss()"
            >
                <XMarkIcon class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
