<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    month: { type: String, required: true },
    usage: { type: Object, required: true },
    engines: { type: Array, required: true },
});

function amount(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    return new Intl.NumberFormat(document.documentElement.lang || 'nb', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value));
}
</script>

<template>
    <AppLayout :title="$t('app.nav.usage')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ $t('app.nav.usage') }}</h2>
        </template>

        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6">
            <section class="space-y-2 rounded-xl border border-line bg-white p-5 text-ink">
                <p class="text-sm text-ink-soft">{{ month }}</p>
                <p>
                    {{ $t('app.workspace.calls') }}: {{ usage.calls }} /
                    {{ usage.quota_calls ?? $t('app.workspace.unlimited') }}
                </p>
                <p>
                    {{ $t('app.workspace.platform_cost') }}: {{ amount(usage.platform_cost) }} /
                    {{ amount(usage.budget_usd) ?? $t('app.workspace.unlimited') }}
                </p>
                <p>
                    {{ $t('app.workspace.byok_cost') }}: {{ amount(usage.byok_cost) }} /
                    {{ amount(usage.byok_budget_usd) ?? $t('app.workspace.unlimited') }}
                </p>
                <p>{{ $t('app.workspace.usage_status') }}: {{ $t(`app.workspace.status_${usage.status}`) }}</p>
            </section>

            <section class="space-y-2 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.by_engine') }}</h3>
                <p v-if="engines.length === 0">{{ $t('app.workspace.no_usage') }}</p>
                <p v-for="engine in engines" :key="engine.name">
                    {{ engine.name }}: {{ engine.calls }} · {{ amount(engine.cost) }}
                </p>
            </section>
        </div>
    </AppLayout>
</template>
