<script setup>
import ReportSection from '@/Components/Actions/ReportSection.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    brand: { type: String, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    actions: { type: Object, required: true },
    downloadable: { type: Boolean, required: true },
    reportId: { type: Number, required: true },
});
</script>

<template>
    <AppLayout :title="brand">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand }}</h2>
        </template>
        <div class="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-8">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-shell-muted">{{ $t('app.workspace.period_range', { start: from, end: to }) }}</p>
                <a v-if="downloadable" :href="route('reports.download', reportId)" class="text-sm text-primary">
                    {{ $t('app.workspace.download') }}
                </a>
            </div>
            <ReportSection :actions="actions" />
        </div>
    </AppLayout>
</template>
