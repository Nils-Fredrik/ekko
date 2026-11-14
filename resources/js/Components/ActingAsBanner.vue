<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// Shown while a platform admin acts as a tenant (docs/spec.md 16). Styled in T02.
const actingAs = computed(() => usePage().props.actingAs);

const stop = () => {
    router.delete(route('admin.acting-as.stop'));
};
</script>

<template>
    <div v-if="actingAs" class="bg-red-700 text-white" role="alert" data-testid="acting-as-banner">
        <div class="max-w-7xl mx-auto py-2 px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-4 text-sm">
            <p>
                <span class="font-semibold">{{ $t('app.acting_as.banner', { tenant: actingAs.tenant }) }}</span>
                {{ $t('app.acting_as.reason', { reason: actingAs.reason }) }}
            </p>
            <button type="button" class="rounded-full border border-white/60 px-3 py-1 font-medium" @click="stop">
                {{ $t('app.acting_as.stop') }}
            </button>
        </div>
    </div>
</template>
