<script setup>
import PlanPreview from '@/Components/Brand/PlanPreview.vue';
import PlanRules from '@/Components/Brand/PlanRules.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { usePlanEstimate } from '@/Composables/usePlanEstimate';
import { estimateCanConfirm, normalizePlan, rulesLackPrice } from '@/measurementPlan';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    plan: { type: Object, required: true },
    brandId: { type: Number, required: true },
    canManage: { type: Boolean, required: true },
});

const standardRules = ref(
    normalizePlan(props.plan.standard, props.plan.options.engines, props.plan.options.max_repetitions),
);
const markets = ref(
    props.plan.markets.map((market) => ({
        id: market.id,
        country: market.country,
        language: market.language,
        location_label: market.location_label,
        is_active: market.is_active,
        plan_mode: market.plan_mode,
        rules: normalizePlan(market.engines, props.plan.options.engines, props.plan.options.max_repetitions),
    })),
);
const saving = ref(false);
const saveError = ref('');

const payload = computed(() => ({
    standard: { engines: standardRules.value },
    markets: markets.value.map((market) =>
        market.plan_mode === 'own'
            ? { id: market.id, plan_mode: 'own', engines: market.rules }
            : { id: market.id, plan_mode: 'inherit' },
    ),
}));

const { estimate, error, estimating, fresh, load } = usePlanEstimate(
    () => ({ url: route('brands.plan.estimate', props.brandId), body: payload.value }),
    () => props.canManage,
);

const labels = computed(() => Object.fromEntries(markets.value.map((market) => [market.id, marketLabel(market)])));
const missingPrice = computed(() => {
    const rules = [
        ...standardRules.value,
        ...markets.value.filter((market) => market.plan_mode === 'own').flatMap((market) => market.rules),
    ];

    return rulesLackPrice(rules, props.plan.options.engines);
});
const canSave = computed(
    () => props.canManage && !saving.value && fresh.value && !missingPrice.value && estimateCanConfirm(estimate.value),
);
const priceBlocks = computed(
    () =>
        missingPrice.value ||
        (fresh.value && (estimate.value?.issues ?? []).some((issue) => issue.code === 'no_price')),
);

function marketLabel(market) {
    if (market.location_label) {
        return market.location_label;
    }

    return `${market.country} / ${market.language}`;
}

function setMode(market, mode) {
    if (mode === 'own' && market.plan_mode !== 'own') {
        const stored = props.plan.markets.find((item) => item.id === market.id);
        const source = stored?.plan_mode === 'own' ? stored.engines : standardRules.value;
        market.rules = normalizePlan(source, props.plan.options.engines, props.plan.options.max_repetitions);
    }
    market.plan_mode = mode;
}

function save() {
    if (!canSave.value) {
        return;
    }
    saving.value = true;
    saveError.value = '';
    router.put(route('brands.plan.update', props.brandId), payload.value, {
        preserveScroll: true,
        onError: (errors) => {
            saveError.value = Object.values(errors).flat().join(' ');
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

watch(payload, () => load());
load();
</script>

<template>
    <section class="space-y-4 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
        <h3 class="font-medium">{{ $t('app.plan.title') }}</h3>
        <p v-if="!canManage" class="text-sm">{{ $t('app.plan.reader') }}</p>

        <fieldset class="space-y-3" :disabled="!canManage">
            <legend class="text-sm font-medium">{{ $t('app.plan.standard') }}</legend>
            <PlanRules
                v-model="standardRules"
                :catalog="plan.options.engines"
                :frequencies="plan.options.frequencies"
                :max-repetitions="plan.options.max_repetitions"
                :disabled="!canManage"
            />
        </fieldset>

        <fieldset v-for="market in markets" :key="market.id" class="space-y-3 rounded-xl border border-line p-4">
            <legend class="px-1 text-sm font-medium">{{ marketLabel(market) }}</legend>
            <p v-if="!market.is_active" class="text-sm text-shell-muted">{{ $t('app.plan.inactive') }}</p>
            <div class="flex flex-col gap-2 text-sm">
                <label class="flex items-center gap-2">
                    <input
                        type="radio"
                        :name="`measurement-plan-${market.id}`"
                        value="inherit"
                        :checked="market.plan_mode === 'inherit'"
                        :disabled="!canManage"
                        @change="setMode(market, 'inherit')"
                    />
                    {{ $t('app.plan.inherit') }}
                </label>
                <label class="flex items-center gap-2">
                    <input
                        type="radio"
                        :name="`measurement-plan-${market.id}`"
                        value="own"
                        :checked="market.plan_mode === 'own'"
                        :disabled="!canManage"
                        @change="setMode(market, 'own')"
                    />
                    {{ $t('app.plan.own') }}
                </label>
            </div>
            <p v-if="market.plan_mode === 'inherit'" class="text-sm">{{ $t('app.plan.inherits') }}</p>
            <PlanRules
                v-if="market.plan_mode === 'own'"
                :key="`${market.id}-own`"
                v-model="market.rules"
                :catalog="plan.options.engines"
                :frequencies="plan.options.frequencies"
                :max-repetitions="plan.options.max_repetitions"
                :disabled="!canManage"
            />
            <PlanRules
                v-else
                :key="`${market.id}-inherit`"
                :model-value="standardRules"
                :catalog="plan.options.engines"
                :frequencies="plan.options.frequencies"
                :max-repetitions="plan.options.max_repetitions"
                disabled
            />
        </fieldset>

        <PlanPreview
            v-if="canManage"
            :estimate="estimate"
            :estimating="estimating"
            :fresh="fresh"
            :error="error || saveError"
            :blocked="priceBlocks"
            :catalog="plan.options.engines"
            :labels="labels"
        />
        <PrimaryButton v-if="canManage" type="button" :disabled="!canSave" @click="save">
            {{ $t('app.plan.save') }}
        </PrimaryButton>
    </section>
</template>
