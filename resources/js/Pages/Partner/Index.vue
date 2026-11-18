<script setup>
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    period: { type: Object, required: true },
    clients: { type: Array, required: true },
});

const page = usePage();

function formatRate(metric) {
    if (metric.value === null || metric.value === undefined) {
        return null;
    }

    return new Intl.NumberFormat(page.props.locale || 'nb', {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(metric.value);
}

function formatChange(current, previous) {
    if (
        current.value === null ||
        current.value === undefined ||
        previous.value === null ||
        previous.value === undefined
    ) {
        return null;
    }

    return new Intl.NumberFormat(page.props.locale || 'nb', {
        maximumFractionDigits: 1,
        signDisplay: 'exceptZero',
    }).format((current.value - previous.value) * 100);
}
</script>

<template>
    <AppLayout :title="$t('app.partner.title')">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ $t('app.partner.title') }}</h2>
        </template>
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <p class="text-sm text-ink-soft">{{ $t('app.partner.intro', { days: period.days }) }}</p>
            <p v-if="clients.length === 0" class="mt-6 text-sm text-ink-soft">{{ $t('app.partner.empty') }}</p>
            <div v-else class="mt-6 overflow-hidden rounded-2xl border border-line bg-white">
                <table class="min-w-full text-sm text-ink">
                    <thead class="border-b border-line text-left text-ink-soft">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ $t('app.partner.client') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ $t('app.partner.visibility') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ $t('app.partner.change') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ $t('app.partner.alerts') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="client in clients" :key="client.id" class="border-b border-line last:border-0">
                            <td class="px-4 py-3">{{ client.name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatRate(client.visibility) ?? $t('app.dashboard.empty') }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    formatChange(client.visibility, client.previous_visibility) === null
                                        ? $t('app.dashboard.empty')
                                        : $t('app.dashboard.change_points', {
                                              change: formatChange(client.visibility, client.previous_visibility),
                                          })
                                }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ client.alerts }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
