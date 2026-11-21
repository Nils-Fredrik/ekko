<script setup>
// «Lagrer…», «Lagret» with a check mark, or the error (docs/ux-arbeidsflate.md 4.5). useAutosave
// returns the state to idle two seconds after a save, which hides «Lagret».
import { CheckIcon } from '@heroicons/vue/20/solid';

defineProps({
    state: { type: String, default: 'idle' },
    error: { type: String, default: '' },
});
</script>

<template>
    <span class="inline-flex min-h-5 items-center gap-1 text-xs" aria-live="polite">
        <span v-if="state === 'saving'" class="text-shell-muted">{{ $t('workspace.save.saving') }}</span>
        <span v-else-if="state === 'saved'" class="inline-flex items-center gap-1 text-success-text">
            <CheckIcon class="h-3.5 w-3.5" aria-hidden="true" />
            {{ $t('workspace.save.saved') }}
        </span>
        <span v-else-if="state === 'error'" class="text-error-text">{{ error || $t('workspace.save.failed') }}</span>
    </span>
</template>
