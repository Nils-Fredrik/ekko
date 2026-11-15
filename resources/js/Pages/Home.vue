<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import MetricValue from '@/Components/Dashboard/MetricValue.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    period: { type: Object, required: true },
    brands: { type: Array, required: true },
    alerts: { type: Array, required: true },
    runs: { type: Array, required: true },
    usage: { type: Object, default: null },
});

function when(value) {
    return new Intl.DateTimeFormat(document.documentElement.lang || 'nb', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
</script>

<template>
    <AppLayout :title="$t('app.dashboard.overview')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">
                {{ $t('app.dashboard.overview') }}
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <p class="text-sm text-shell-text/70">{{ $t('app.dashboard.period_days', { days: period.days }) }}</p>

                <section class="grid gap-4 md:grid-cols-2">
                    <p v-if="brands.length === 0" class="text-sm text-ink-soft">{{ $t('app.dashboard.no_brands') }}</p>
                    <Link
                        v-for="brand in brands"
                        :key="brand.id"
                        :href="route('brands.show', brand.id)"
                        class="rounded-2xl border border-line bg-white p-5 text-ink no-underline"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="text-lg font-medium tracking-tight">{{ brand.name }}</h3>
                            <span class="text-xs text-ink-soft">{{ brand.status }}</span>
                        </div>
                        <p class="mt-1 text-sm text-ink-soft">
                            {{ brand.markets.length ? brand.markets.join(', ') : $t('app.dashboard.no_markets') }}
                        </p>
                        <p class="mt-4 text-xs text-ink-soft">{{ $t('app.dashboard.visibility') }}</p>
                        <MetricValue :metric="brand.visibility" :previous="brand.previous_visibility" />
                    </Link>
                </section>

                <section class="grid gap-4 lg:grid-cols-3">
                    <div class="rounded-2xl border border-line bg-white p-5 text-ink">
                        <h3 class="text-sm font-medium">{{ $t('app.dashboard.alerts') }}</h3>
                        <p v-if="alerts.length === 0" class="mt-3 text-sm text-ink-soft">
                            {{ $t('app.dashboard.no_alerts') }}
                        </p>
                        <ul v-else class="mt-3 flex flex-col gap-2 text-sm">
                            <li v-for="alert in alerts" :key="alert.id" class="border-s-4 border-warning ps-3">
                                {{ $t('app.dashboard.alert', { brand: alert.brand, type: alert.type }) }}
                                <span class="block text-xs text-ink-soft">{{ when(alert.triggered_at) }}</span>
                            </li>
                        </ul>
                    </div>
                    <div class="rounded-2xl border border-line bg-white p-5 text-ink">
                        <h3 class="text-sm font-medium">{{ $t('app.dashboard.runs') }}</h3>
                        <p v-if="runs.length === 0" class="mt-3 text-sm text-ink-soft">
                            {{ $t('app.dashboard.no_runs') }}
                        </p>
                        <ul v-else class="mt-3 flex flex-col gap-2 text-sm">
                            <li v-for="run in runs" :key="run.id">
                                {{ $t('app.dashboard.run', { brand: run.brand, market: run.market }) }}
                                <span class="block text-xs text-ink-soft">{{ when(run.scheduled_for) }}</span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="usage" class="rounded-2xl border border-line bg-white p-5 text-ink">
                        <h3 class="text-sm font-medium">{{ $t('app.dashboard.usage') }}</h3>
                        <p class="mt-3 text-sm">
                            {{
                                usage.quota_calls
                                    ? $t('app.dashboard.usage_calls', { calls: usage.calls, quota: usage.quota_calls })
                                    : $t('app.dashboard.usage_calls_open', { calls: usage.calls })
                            }}
                        </p>
                        <p v-if="usage.budget_usd" class="mt-1 text-sm text-ink-soft">
                            {{ $t('app.dashboard.usage_cost', { cost: usage.cost_usd, budget: usage.budget_usd }) }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
