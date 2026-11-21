<script setup>
import { formatDecimal } from '@/format';
import AdminNav from '@/Components/AdminNav.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
defineProps({
    anthropic: { type: Object, required: true },
    month: String,
    tenants: Array,
    engines: Array,
});
function usd(value) {
    // No cost yet is 0,00 here, as before format.js.
    return formatDecimal(value ?? 0, 2);
}
</script>

<template>
    <AppLayout :title="$t('anthropic_budget.title')">
        <template #header>
            <h2 class="text-xl font-medium text-shell-fg">{{ $t('anthropic_budget.title') }}</h2>
        </template>
        <main class="mx-auto max-w-4xl space-y-6 p-6">
            <AdminNav />
            <section class="rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('admin.usage.tenants') }}</h3>
                <p class="mt-1 text-sm text-shell-muted">{{ $t('admin.usage.month', { month }) }}</p>
                <EmptyState
                    v-if="!tenants.length"
                    class="mt-3"
                    :title="$t('app.empty.usage_tenants_title')"
                    :body="$t('app.empty.usage_tenants_body')"
                />
                <table v-else class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs text-shell-muted">
                        <tr>
                            <th class="p-2">{{ $t('admin.tenants.name') }}</th>
                            <th class="p-2 text-right">{{ $t('admin.usage.calls') }}</th>
                            <th class="p-2 text-right">{{ $t('admin.usage.cost') }}</th>
                            <th class="p-2 text-right">{{ $t('admin.usage.byok') }}</th>
                            <th class="p-2 text-right">{{ $t('admin.usage.platform_paid') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tenant in tenants" :key="tenant.id" class="border-t border-line-soft">
                            <td class="p-2">{{ tenant.name }}</td>
                            <td class="p-2 text-right tabular-nums">{{ tenant.calls }}</td>
                            <td class="p-2 text-right tabular-nums">{{ usd(tenant.cost_usd) }}</td>
                            <td class="p-2 text-right tabular-nums">{{ usd(tenant.byok_cost_usd) }}</td>
                            <td class="p-2 text-right tabular-nums">
                                {{
                                    $t('admin.usage.platform_paid_cell', {
                                        cost: usd(tenant.platform_paid_cost_usd),
                                        calls: tenant.platform_paid_calls,
                                    })
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="tenants.length" class="mt-2 text-xs text-shell-muted">
                    {{ $t('admin.usage.platform_paid_hint') }}
                </p>
                <h3 class="mt-6 font-medium">{{ $t('admin.usage.engines') }}</h3>
                <EmptyState
                    v-if="!engines.length"
                    class="mt-3"
                    :title="$t('app.empty.usage_engines_title')"
                    :body="$t('app.empty.usage_engines_body')"
                />
                <table v-else class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs text-shell-muted">
                        <tr>
                            <th class="p-2">{{ $t('admin.usage.engine') }}</th>
                            <th class="p-2 text-right">{{ $t('admin.usage.calls') }}</th>
                            <th class="p-2 text-right">{{ $t('admin.usage.cost') }}</th>
                            <th class="p-2 text-right">{{ $t('admin.usage.byok') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="engine in engines"
                            :key="engine.engine_key ?? 'unassigned'"
                            class="border-t border-line-soft"
                        >
                            <td class="p-2">{{ engine.engine_key ?? $t('admin.usage.unassigned') }}</td>
                            <td class="p-2 text-right tabular-nums">{{ engine.calls }}</td>
                            <td class="p-2 text-right tabular-nums">{{ usd(engine.cost_usd) }}</td>
                            <td class="p-2 text-right tabular-nums">{{ usd(engine.byok_cost_usd) }}</td>
                        </tr>
                    </tbody>
                </table>
                <a :href="route('admin.billing.index', { month })" class="mt-4 inline-block text-sm text-primary">
                    {{ $t('admin.usage.export') }}
                </a>
            </section>
            <p v-if="!anthropic.configured">{{ $t('anthropic_budget.unconfigured') }}</p>
            <template v-else>
                <p>{{ $t('anthropic_budget.period', { start: anthropic.start, end: anthropic.end }) }}</p>
                <p class="text-2xl font-medium">
                    {{
                        $t('anthropic_budget.spent', { spent: usd(anthropic.spent_usd), pool: usd(anthropic.pool_usd) })
                    }}
                </p>
                <p role="status">
                    {{
                        $t(
                            anthropic.exhausted
                                ? 'anthropic_budget.exhausted'
                                : anthropic.reduced
                                  ? 'anthropic_budget.reduced'
                                  : 'anthropic_budget.normal',
                        )
                    }}
                </p>
                <section
                    v-if="anthropic.forecast"
                    class="space-y-3 rounded-xl border border-line bg-shell-panel p-5 text-shell-text"
                >
                    <p>{{ $t('anthropic_budget.forecast', { amount: usd(anthropic.forecast.forecast_usd) }) }}</p>
                    <p>{{ $t('anthropic_budget.buffer', { amount: usd(anthropic.forecast.buffer_usd) }) }}</p>
                    <p>{{ $t('anthropic_budget.held', { amount: usd(anthropic.forecast.held_usd) }) }}</p>
                    <p v-if="anthropic.forecast.platform_paid_usd !== undefined">
                        {{
                            $t('anthropic_budget.platform_paid', { amount: usd(anthropic.forecast.platform_paid_usd) })
                        }}
                    </p>
                    <h3 class="font-medium">{{ $t('anthropic_budget.additions') }}</h3>
                    <p v-if="Object.keys(anthropic.forecast.additions).length === 0">
                        {{ $t('anthropic_budget.none') }}
                    </p>
                    <p v-for="(count, kind) in anthropic.forecast.additions" :key="kind">
                        {{ $t(`anthropic_budget.${kind}`) }}: {{ count }}
                    </p>
                </section>
                <p v-else>{{ $t('anthropic_budget.no_forecast') }}</p>
            </template>
        </main>
    </AppLayout>
</template>
