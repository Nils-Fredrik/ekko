<script setup>
import ReportSection from '@/Components/Actions/ReportSection.vue';
import { formatDate, formatValue } from '@/Components/Charts/support/format.js';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    brand: String,
    brandId: Number,
    from: String,
    to: String,
    visibility: Object,
    competitors: Array,
    sources: Array,
    actions: Object,
    reduced_quality: Number,
});
</script>

<template>
    <AppLayout :title="brand">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ brand }} · {{ $t('pitch.report') }}</h2>
        </template>
        <div class="py-8">
            <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-shell-muted">
                    <p>{{ $t('app.workspace.period_range', { start: formatDate(from), end: formatDate(to) }) }}</p>
                    <a :href="route('pitches.report.pdf', brandId)" class="text-primary no-underline">
                        {{ $t('pitch.download_pdf') }}
                    </a>
                </div>
                <p v-if="reduced_quality" class="text-sm text-shell-muted">
                    {{ $t('pitch.reduced_quality', { n: reduced_quality }) }}
                </p>

                <section class="flex flex-col gap-3" data-testid="pitch-visibility">
                    <h3 class="text-base font-medium text-shell-fg">{{ $t('pitch.visibility') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <KpiCard :label="$t('pitch.ui.brand_visibility', { name: brand })" :metric="visibility" />
                        <KpiCard
                            v-for="row in competitors"
                            :key="row.name"
                            size="secondary"
                            :label="row.name"
                            :metric="row.visibility"
                        />
                    </div>
                    <p v-if="competitors.length === 0" class="text-sm text-shell-muted">
                        {{ $t('pitch.ui.no_competitors') }}
                    </p>
                </section>

                <section class="flex flex-col gap-3" data-testid="pitch-sources">
                    <h3 class="text-base font-medium text-shell-fg">{{ $t('pitch.sources') }}</h3>
                    <EmptyState
                        v-if="sources.length === 0"
                        :title="$t('pitch.sources')"
                        :body="$t('pitch.ui.no_sources')"
                    />
                    <div v-else class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-line text-xs text-shell-muted">
                                <tr>
                                    <th class="px-4 py-3 font-medium">{{ $t('pitch.ui.domain') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ $t('pitch.ui.citations') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in sources"
                                    :key="row.domain"
                                    class="border-b border-line-soft last:border-0 hover:bg-row-hover"
                                >
                                    <td class="px-4 py-3">{{ row.domain }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        {{ formatValue(row.citations, 'count') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <ReportSection :actions="actions" />
            </div>
        </div>
    </AppLayout>
</template>
