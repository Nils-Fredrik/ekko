<script setup>
import PlanLevel from '@/Components/Brand/PlanLevel.vue';
import { computed } from 'vue';

const props = defineProps({
    estimate: { type: Object, default: null },
    estimating: { type: Boolean, default: false },
    fresh: { type: Boolean, default: false },
    error: { type: String, default: '' },
    blocked: { type: Boolean, default: false },
    catalog: { type: Array, default: () => [] },
    labels: { type: Object, default: () => ({}) },
    onlyMarket: { type: Boolean, default: false },
    marketId: { type: [Number, null], default: null },
});

const visibleMarkets = computed(() => {
    const markets = props.estimate?.markets ?? [];
    if (!props.onlyMarket) {
        return markets;
    }

    return markets.filter((market) => market.id === props.marketId);
});

const visibleIssues = computed(() => {
    const issues = props.estimate?.issues ?? [];
    if (!props.onlyMarket) {
        return issues;
    }

    return issues.filter((issue) => issue.market_id === undefined || issue.market_id === props.marketId);
});

function rulesFor(market, engineKey) {
    return (market.rules ?? []).filter((rule) => rule.engine_key === engineKey);
}

function engineName(key) {
    return props.catalog.find((engine) => engine.engine_key === key)?.name ?? key;
}

function marketName(market) {
    if (market.id === null || market.id === undefined) {
        return null;
    }

    return props.labels[market.id] ?? null;
}
</script>

<template>
    <section
        class="space-y-3 rounded-xl border border-line bg-shell-surface p-4 text-sm text-shell-text"
        aria-live="polite"
    >
        <h4 class="font-medium">{{ $t('app.plan.preview') }}</h4>
        <p v-if="estimating" role="status">{{ $t('app.plan.updating') }}</p>
        <p v-else-if="estimate && !fresh" role="status">{{ $t('app.plan.stale') }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="blocked" role="alert">{{ $t('app.plan.price_blocks') }}</p>
        <template v-if="estimate">
            <div v-if="!onlyMarket" class="space-y-1">
                <p class="font-medium">{{ $t('app.plan.brand_total') }}</p>
                <PlanLevel :level="estimate" />
            </div>
            <p v-if="estimate.quota?.remaining != null" class="tabular-nums">
                {{ $t('app.plan.quota_remaining', { count: estimate.quota.remaining }) }}
            </p>
            <p v-else>{{ $t('app.plan.quota_none') }}</p>
            <div v-for="market in visibleMarkets" :key="market.id ?? 'new'" class="space-y-2 border-t border-line pt-3">
                <p class="font-medium">
                    {{ market.id == null ? $t('app.plan.new_market') : marketName(market) }}
                    ·
                    {{ $t(market.plan_mode === 'own' ? 'app.plan.own' : 'app.plan.inherits') }}
                </p>
                <p v-if="market.is_active === false">{{ $t('app.plan.inactive') }}</p>
                <PlanLevel :level="market" />
                <div v-for="engine in market.engines" :key="engine.engine_key" class="space-y-1 pl-3">
                    <p class="font-medium">{{ engineName(engine.engine_key) }}</p>
                    <PlanLevel :level="engine" />
                    <ul class="space-y-1">
                        <li
                            v-for="(rule, index) in rulesFor(market, engine.engine_key)"
                            :key="`${rule.engine_key}-${rule.prompt_scope}-${index}`"
                        >
                            {{ $t(`onboarding.frequencies.${rule.frequency}`) }}
                            ·
                            {{
                                $t(
                                    rule.prompt_scope === 'core'
                                        ? 'app.workspace.scope_core'
                                        : 'app.workspace.scope_all',
                                )
                            }}
                            <template v-if="rule.next_due_on">
                                · {{ $t('app.plan.next_due', { date: rule.next_due_on }) }}
                            </template>
                            <template v-if="rule.frequency !== 'off' && rule.usage_per_answer">
                                <span class="block">
                                    {{
                                        $t('app.plan.search_assumption', { count: rule.usage_per_answer.search_calls })
                                    }}
                                </span>
                                <span class="block">
                                    {{
                                        $t('app.plan.token_assumption', {
                                            input: rule.usage_per_answer.input_tokens,
                                            output: rule.usage_per_answer.output_tokens,
                                        })
                                    }}
                                </span>
                            </template>
                        </li>
                    </ul>
                </div>
            </div>
            <ul v-if="visibleIssues.length" class="list-disc space-y-1 pl-5">
                <li v-for="(issue, index) in visibleIssues" :key="`${issue.code}-${issue.market_id ?? ''}-${index}`">
                    {{ issue.message }}
                </li>
            </ul>
        </template>
    </section>
</template>
