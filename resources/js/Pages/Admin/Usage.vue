<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
defineProps({ anthropic: { type: Object, required: true } });
function usd(value) {
    return new Intl.NumberFormat(document.documentElement.lang || 'nb', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value));
}
</script>

<template>
    <AppLayout :title="$t('anthropic_budget.title')">
        <template #header>
            <h2 class="text-xl font-medium text-shell-fg">{{ $t('anthropic_budget.title') }}</h2>
        </template>
        <main class="mx-auto max-w-4xl space-y-6 p-6">
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
                    class="space-y-3 rounded-xl border border-line bg-white p-5 text-ink"
                >
                    <p>{{ $t('anthropic_budget.forecast', { amount: usd(anthropic.forecast.forecast_usd) }) }}</p>
                    <p>{{ $t('anthropic_budget.buffer', { amount: usd(anthropic.forecast.buffer_usd) }) }}</p>
                    <p>{{ $t('anthropic_budget.held', { amount: usd(anthropic.forecast.held_usd) }) }}</p>
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
