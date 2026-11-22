<script setup>
import { formatCurrency, formatDate, formatNumber } from '@/format';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    month: { type: String, required: true },
    usage: { type: Object, required: true },
    engines: { type: Array, required: true },
    projectUsage: { type: Array, required: true },
});
</script>

<template>
    <AppLayout :title="$t('app.nav.usage')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ $t('app.nav.usage') }}</h2>
        </template>

        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6">
            <section class="space-y-2 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <p class="text-sm text-shell-muted">{{ formatDate(month) }}</p>
                <p>
                    {{ $t('app.workspace.calls') }}: {{ formatNumber(usage.calls) }} /
                    {{ usage.quota_calls == null ? $t('app.workspace.unlimited') : formatNumber(usage.quota_calls) }}
                </p>
                <p>
                    {{ $t('app.workspace.platform_cost') }}: {{ formatCurrency(usage.platform_cost) }} /
                    {{ usage.budget_usd == null ? $t('app.workspace.unlimited') : formatCurrency(usage.budget_usd) }}
                </p>
                <p>
                    {{ $t('app.workspace.byok_cost') }}: {{ formatCurrency(usage.byok_cost) }} /
                    {{
                        usage.byok_budget_usd == null
                            ? $t('app.workspace.unlimited')
                            : formatCurrency(usage.byok_budget_usd)
                    }}
                </p>
                <p>{{ $t('app.workspace.usage_status') }}: {{ $t(`app.workspace.status_${usage.status}`) }}</p>
            </section>

            <section class="space-y-2 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('pitch.usage_by_kind') }}</h3>
                <p v-for="row in projectUsage" :key="row.kind">
                    {{ $t(`pitch.kind_${row.kind}`) }}: {{ formatNumber(row.calls) }} · {{ formatCurrency(row.cost) }}
                </p>
            </section>

            <section class="space-y-2 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('app.workspace.by_engine') }}</h3>
                <EmptyState
                    v-if="engines.length === 0"
                    :title="$t('app.empty.usage_title')"
                    :body="$t('app.empty.usage_body')"
                />
                <p v-for="engine in engines" :key="engine.name">
                    {{ engine.name }}: {{ formatNumber(engine.calls) }} · {{ formatCurrency(engine.cost) }}
                </p>
            </section>
        </div>
    </AppLayout>
</template>
