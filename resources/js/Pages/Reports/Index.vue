<script setup>
import { formatDate, formatDateTime } from '@/format';
import EmptyState from '@/Components/UI/EmptyState.vue';
import WorkspaceHeader from '@/Components/Shell/WorkspaceHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    reports: { type: Array, required: true },
});
</script>

<template>
    <AppLayout :title="$t('app.nav.reports')">
        <template #header>
            <WorkspaceHeader :group="$t('workspace.shell.work')" :title="$t('app.nav.reports')" />
        </template>

        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6">
            <section class="space-y-3 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('app.workspace.history') }}</h3>
                <EmptyState
                    v-if="reports.length === 0"
                    :title="$t('app.empty.reports_title')"
                    :body="$t('app.empty.reports_body')"
                />
                <article
                    v-for="report in reports"
                    :key="report.id"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <p>
                        {{ report.brand }} ·
                        {{
                            $t('app.workspace.period_range', {
                                start: formatDate(report.period_start),
                                end: formatDate(report.period_end),
                            })
                        }}
                    </p>
                    <span class="flex items-center gap-3">
                        <Link :href="route('reports.show', report.id)" class="text-sm text-primary no-underline">
                            {{ $t('app.workspace.view') }}
                        </Link>
                        <a v-if="report.downloadable" :href="route('reports.download', report.id)" class="text-sm">
                            {{ $t('app.workspace.download') }}
                        </a>
                        <span v-else class="text-shell-muted">
                            {{ report.sent_at ? formatDateTime(report.sent_at) : $t('app.workspace.not_sent') }}
                        </span>
                    </span>
                </article>
            </section>
        </div>
    </AppLayout>
</template>
