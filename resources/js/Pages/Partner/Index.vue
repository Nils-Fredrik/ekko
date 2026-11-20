<script setup>
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    period: { type: Object, required: true },
    clients: { type: Array, required: true },
    portfolioGraphs: { type: Array, default: null },
});

function sparkline(clientId) {
    return props.portfolioGraphs?.find((row) => row.id === clientId)?.series ?? [];
}
</script>

<template>
    <AppLayout :title="$t('app.partner.title')">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ $t('app.partner.title') }}</h2>
        </template>
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <p class="text-sm text-shell-muted">{{ $t('app.partner.intro', { days: period.days }) }}</p>
            <EmptyState
                v-if="clients.length === 0"
                class="mt-6"
                :title="$t('app.empty.partner_title')"
                :body="$t('app.empty.partner_body')"
            />
            <div v-else class="mt-6 overflow-hidden rounded-2xl border border-line bg-shell-panel">
                <table class="min-w-full text-sm text-shell-text">
                    <thead class="border-b border-line text-left text-shell-muted">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ $t('app.partner.client') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('app.partner.visibility') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ $t('app.partner.alerts') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="client in clients" :key="client.id" class="border-b border-line last:border-0">
                            <td class="px-4 py-3">{{ client.name }}</td>
                            <td class="min-w-56 px-4 py-3">
                                <KpiCard
                                    size="secondary"
                                    :framed="false"
                                    :label="$t('app.partner.visibility')"
                                    :metric="client.visibility"
                                    :previous="client.previous_visibility"
                                    :points="sparkline(client.id)"
                                />
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ client.alerts }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
