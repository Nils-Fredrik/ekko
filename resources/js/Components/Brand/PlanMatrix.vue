<script setup>
// Compact plan matrix (docs/ux-arbeidsflate.md 5.4). The estimate waits 300 ms after a change.
import InputError from '@/Components/InputError.vue';
import { usePlanEstimate } from '@/Composables/usePlanEstimate';
import { empty, formatCurrency, formatDate, formatMonth, formatNumber } from '@/format';
import {
    allowsFrequency,
    estimateCanConfirm,
    normalizePlan,
    rowsToRules,
    rulesLackPrice,
    rulesToRows,
} from '@/measurementPlan';
import { INTERVALS, answersFor, cellError, nextDue, rowChanged, samplePromptId } from '@/planMatrix';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

const coreIntervals = INTERVALS.filter((frequency) => frequency !== 'off');

const props = defineProps({
    plan: { type: Object, required: true },
    brandId: { type: Number, required: true },
    canManage: { type: Boolean, required: true },
    prompts: { type: Object, default: () => ({}) },
    marketNames: { type: Map, required: true },
    activity: { type: Object, default: null },
});

const emit = defineEmits(['modes', 'estimate']);
const page = usePage();
const maxRepetitions = computed(() => props.plan.options.max_repetitions ?? 1);
const catalog = computed(() => props.plan.options.engines ?? []);
const standardRows = ref(rulesToRows(props.plan.standard, catalog.value, maxRepetitions.value));
const markets = ref(draftMarkets(props.plan));
const saving = ref(false);
const selected = ref(null);
const matrixId = useId();
const editorHeading = ref(null);
let trigger;
const savedPayload = ref('');
const savedAnswers = ref(null);

const activeMarkets = computed(() => markets.value.filter((market) => market.is_active));
const columnIds = computed(() => [null, ...activeMarkets.value.map((market) => market.id)]);
const payload = computed(() => ({
    standard: { engines: rowsToRules(standardRows.value, maxRepetitions.value) },
    markets: markets.value.map((market) =>
        market.plan_mode === 'own'
            ? { id: market.id, plan_mode: 'own', engines: rowsToRules(market.rules, maxRepetitions.value) }
            : { id: market.id, plan_mode: 'inherit' },
    ),
}));
const dirty = computed(() => JSON.stringify(payload.value) !== savedPayload.value);
const { estimate, error, estimating, fresh, load } = usePlanEstimate(
    () => ({ url: route('brands.plan.estimate', props.brandId), body: payload.value }),
    () => props.canManage,
);
const missingPrice = computed(() =>
    rulesLackPrice(
        [
            ...payload.value.standard.engines,
            ...payload.value.markets.filter((market) => market.plan_mode === 'own').flatMap((market) => market.engines),
        ],
        catalog.value,
    ),
);
const canSave = computed(
    () =>
        props.canManage &&
        dirty.value &&
        !saving.value &&
        fresh.value &&
        !missingPrice.value &&
        estimateCanConfirm(estimate.value),
);
const issues = computed(() => (fresh.value ? (estimate.value?.issues ?? []) : []));
const within = computed(() => fresh.value && issues.value.length === 0 && !missingPrice.value);
const priceBlocks = computed(() => missingPrice.value || issues.value.some((issue) => issue.code === 'no_price'));
const statusMessage = computed(() => {
    if (within.value) {
        return trans('workspace.setup.within');
    }
    if (priceBlocks.value) {
        return trans('app.plan.price_blocks');
    }

    return (
        issues.value[0]?.message ||
        error.value ||
        (estimating.value ? trans('app.plan.updating') : trans('workspace.setup.unsaved'))
    );
});
const delta = computed(() => {
    if (!dirty.value || savedAnswers.value == null || estimate.value?.answers == null || !fresh.value) {
        return null;
    }

    return estimate.value.answers - savedAnswers.value;
});
const month = computed(() => formatMonth());
const fieldErrors = computed(() => page.props.errors ?? {});
const planErrors = computed(() =>
    Object.fromEntries(
        Object.entries(fieldErrors.value).filter(([key]) => key.startsWith('standard.') || key.startsWith('markets.')),
    ),
);
const loosePlanError = computed(() => {
    const entry = Object.entries(planErrors.value).find(
        ([key]) => !/^standard\.engines\.\d+\./.test(key) && !/^markets\.\d+\.engines\.\d+\./.test(key),
    );
    if (!entry) {
        return '';
    }

    return Array.isArray(entry[1]) ? entry[1][0] : entry[1];
});

function cellMessage(engineKey, marketId) {
    if (marketId == null) {
        return cellError(
            planErrors.value,
            rowsToRules(standardRows.value, maxRepetitions.value),
            'standard.engines',
            engineKey,
        );
    }
    const index = markets.value.findIndex((item) => item.id === marketId);
    const market = markets.value[index];
    if (!market || market.plan_mode !== 'own') {
        return '';
    }

    return cellError(
        planErrors.value,
        rowsToRules(market.rules, maxRepetitions.value),
        `markets.${index}.engines`,
        engineKey,
    );
}
function marketById(marketId) {
    return markets.value.find((market) => market.id === marketId) ?? null;
}

const editing = computed(() => {
    if (!selected.value) {
        return null;
    }
    const market = selected.value.marketId == null ? null : marketById(selected.value.marketId);
    if (market && (market.plan_mode !== 'own' || !market.is_active)) {
        return null;
    }
    const rows = market ? market.rules : standardRows.value;
    const row = rows?.find((item) => item.engine_key === selected.value.engineKey);

    return row ? { ...selected.value, row, market } : null;
});

let timer = 0;
let stopBefore = () => {};

function draftMarkets(plan) {
    return plan.markets.map((market) => ({
        id: market.id,
        is_active: market.is_active,
        plan_mode: market.plan_mode,
        rules: rulesToRows(market.engines, plan.options.engines, plan.options.max_repetitions),
    }));
}

function remember() {
    savedPayload.value = JSON.stringify(payload.value);
}

function publishModes() {
    emit('modes', Object.fromEntries(markets.value.map((market) => [market.id, market.plan_mode])));
}

function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => load(), 300);
}

function rowsFor(marketId) {
    if (marketId == null) {
        return standardRows.value;
    }
    const market = markets.value.find((item) => item.id === marketId);
    if (!market) {
        return [];
    }

    return market.plan_mode === 'own' ? market.rules : standardRows.value;
}

function cellAvailable(engineKey, marketId) {
    const row = rowsFor(marketId).find((item) => item.engine_key === engineKey);

    return row?.selectable && (marketId == null || marketById(marketId)?.plan_mode === 'own');
}

async function openCell(engineKey, marketId, button) {
    if (!props.canManage || !cellAvailable(engineKey, marketId)) return;
    trigger = button;
    selected.value = { engineKey, marketId };
    await nextTick();
    editorHeading.value?.focus();
}

function closeEditor() {
    selected.value = null;
    trigger?.focus();
}

function setMode(market, mode) {
    if (!market || !props.canManage) {
        return;
    }
    if (mode === 'own' && market.plan_mode !== 'own') {
        const stored = props.plan.markets.find((item) => item.id === market.id);
        const source =
            stored?.plan_mode === 'own' ? stored.engines : rowsToRules(standardRows.value, maxRepetitions.value);
        market.rules = rulesToRows(
            normalizePlan(source, catalog.value, maxRepetitions.value),
            catalog.value,
            maxRepetitions.value,
        );
    }
    market.plan_mode = mode;
    if (mode !== 'own' && selected.value?.marketId === market.id) {
        selected.value = null;
    }
    publishModes();
}

function setFrequency(frequency) {
    const row = editing.value?.row;
    if (!row || !allowsFrequency(frequency, row.max_frequency)) {
        return;
    }
    row.frequency = frequency;
    if (row.core_frequency && !allowsFrequency(row.core_frequency, row.max_frequency)) {
        row.core_frequency = '';
    }
}

function setCoreFrequency(frequency) {
    const row = editing.value?.row;
    if (!row || frequency === 'off' || !allowsFrequency(frequency, row.max_frequency)) {
        return;
    }
    row.core_frequency = frequency;
}

function discard() {
    standardRows.value = rulesToRows(props.plan.standard, catalog.value, maxRepetitions.value);
    markets.value = draftMarkets(props.plan);
    selected.value = null;
    remember();
    publishModes();
}

function save() {
    if (!canSave.value) {
        return;
    }
    const submitted = JSON.parse(JSON.stringify(payload.value));
    saving.value = true;
    router.put(route('brands.plan.update', props.brandId), submitted, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            saving.value = false;
        },
        onSuccess: () => {
            savedPayload.value = JSON.stringify(submitted);
            savedAnswers.value = estimate.value?.answers ?? savedAnswers.value;
        },
    });
}

function cellAnswers(engineKey, marketId) {
    return answersFor(estimate.value, marketId, engineKey);
}

function changed(engineKey, market) {
    if (!market || market.plan_mode !== 'own') {
        return false;
    }
    const standard = standardRows.value.find((row) => row.engine_key === engineKey);
    const row = market.rules.find((item) => item.engine_key === engineKey);

    return rowChanged(standard, row);
}

onMounted(() => {
    remember();
    publishModes();
    if (props.canManage) {
        load();
    }
    stopBefore = router.on('before', (event) => {
        if (!dirty.value || event.detail.visit.method !== 'get' || event.detail.visit.deferredProps) {
            return;
        }
        if (!window.confirm(trans('workspace.setup.leave'))) {
            event.preventDefault();
        }
    });
});

watch(payload, () => {
    if (props.canManage) {
        schedule();
    }
});
watch(estimate, (value) => emit('estimate', value));
watch([fresh, dirty, estimate], () => {
    if (fresh.value && !dirty.value && estimate.value) {
        savedAnswers.value = estimate.value.answers;
    }
});
watch(
    () => props.plan,
    (plan) => {
        if (!dirty.value) {
            standardRows.value = rulesToRows(plan.standard, catalog.value, maxRepetitions.value);
            markets.value = draftMarkets(plan);
            remember();
            publishModes();
        }
    },
);
function syncActivity(nextActive) {
    for (const incoming of props.plan.markets) {
        const current = markets.value.find((market) => market.id === incoming.id);
        const active = nextActive?.id === incoming.id ? nextActive.is_active : incoming.is_active;
        if (current) {
            current.is_active = active;
            continue;
        }
        markets.value.push({
            id: incoming.id,
            is_active: active,
            plan_mode: incoming.plan_mode,
            rules: rulesToRows(incoming.engines, props.plan.options.engines, props.plan.options.max_repetitions),
        });
    }
    if (selected.value?.marketId != null && !marketById(selected.value.marketId)?.is_active) {
        selected.value = null;
    }
    if (props.canManage) {
        load();
    }
}

watch(
    () => props.plan.markets.map((market) => `${market.id}:${market.is_active ? 1 : 0}`).join('|'),
    (next, previous) => {
        if (next !== previous) {
            syncActivity();
        }
    },
);
watch(
    () => props.activity?.token ?? 0,
    (token) => {
        if (token) {
            syncActivity(props.activity);
        }
    },
);

onBeforeUnmount(() => {
    window.clearTimeout(timer);
    stopBefore();
    window.onbeforeunload = null;
});

watch(dirty, (value) => {
    window.onbeforeunload = value ? () => trans('workspace.setup.leave') : null;
});
</script>

<template>
    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <p v-if="!canManage" class="text-sm text-shell-muted lg:col-span-2">{{ $t('app.plan.reader') }}</p>
        <div data-testid="plan-matrix" class="overflow-x-auto rounded-xl border border-line">
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr class="border-b border-line text-left text-shell-muted">
                        <th class="px-2 py-1 font-medium">{{ $t('workspace.setup.engine') }}</th>
                        <th :id="`${matrixId}-standard`" scope="col" class="px-2 py-1 font-medium">
                            {{ $t('workspace.setup.standard') }}
                        </th>
                        <th
                            v-for="market in activeMarkets"
                            :key="market.id"
                            class="px-2 py-1 font-medium"
                            data-testid="plan-column"
                        >
                            <span :id="`${matrixId}-${market.id}`" class="block text-shell-fg">
                                {{ marketNames.get(market.id) }}
                            </span>
                            <span class="mt-1 inline-flex rounded-full border border-line p-0.5">
                                <button
                                    type="button"
                                    class="rounded-full px-2 py-0.5"
                                    :class="
                                        market.plan_mode !== 'own'
                                            ? 'bg-shell-surface text-shell-fg'
                                            : 'text-shell-muted'
                                    "
                                    :aria-pressed="market.plan_mode !== 'own'"
                                    :disabled="!canManage"
                                    @click="setMode(market, 'inherit')"
                                >
                                    {{ $t('workspace.setup.inherit') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-full px-2 py-0.5"
                                    :class="
                                        market.plan_mode === 'own'
                                            ? 'bg-shell-surface text-shell-fg'
                                            : 'text-shell-muted'
                                    "
                                    :aria-pressed="market.plan_mode === 'own'"
                                    :disabled="!canManage"
                                    data-testid="own-choices"
                                    @click="setMode(market, 'own')"
                                >
                                    {{ $t('workspace.setup.own') }}
                                </button>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="engine in catalog"
                        :key="engine.engine_key"
                        data-testid="engine-row"
                        class="border-t border-line"
                    >
                        <th
                            :id="`${matrixId}-${engine.engine_key}`"
                            scope="row"
                            class="px-2 py-1 text-left font-medium text-shell-fg"
                        >
                            {{ engine.name }}
                            <span v-if="!engine.selectable" class="block font-normal text-shell-muted">
                                {{ $t('workspace.setup.requires_package') }}
                            </span>
                        </th>
                        <td v-for="marketId in columnIds" :key="`${engine.engine_key}-${marketId}`" class="p-1">
                            <button
                                type="button"
                                class="w-full rounded-lg px-2 py-1 text-left leading-tight"
                                :class="[
                                    !cellAvailable(engine.engine_key, marketId)
                                        ? 'bg-shell-surface text-shell-muted'
                                        : 'bg-shell-panel text-shell-fg',
                                    selected?.engineKey === engine.engine_key && selected?.marketId === marketId
                                        ? 'ring-2 ring-primary ring-inset'
                                        : '',
                                ]"
                                :disabled="!canManage || !cellAvailable(engine.engine_key, marketId)"
                                :aria-labelledby="`${matrixId}-${engine.engine_key} ${matrixId}-${marketId ?? 'standard'} ${matrixId}-${engine.engine_key}-${marketId ?? 'standard'}`"
                                :aria-expanded="
                                    canManage && cellAvailable(engine.engine_key, marketId)
                                        ? selected?.engineKey === engine.engine_key && selected?.marketId === marketId
                                        : undefined
                                "
                                :data-testid="`cell-${engine.engine_key}-${marketId ?? 'standard'}`"
                                @click="openCell(engine.engine_key, marketId, $event.currentTarget)"
                            >
                                <span :id="`${matrixId}-${engine.engine_key}-${marketId ?? 'standard'}`">
                                    <template
                                        v-if="
                                            !rowsFor(marketId).find((row) => row.engine_key === engine.engine_key)
                                                ?.selectable
                                        "
                                    >
                                        {{ $t('workspace.setup.not_in_package') }}
                                    </template>
                                    <template
                                        v-else-if="
                                            rowsFor(marketId).find((row) => row.engine_key === engine.engine_key)
                                                ?.frequency === 'off'
                                        "
                                    >
                                        {{ $t('workspace.setup.off') }}
                                    </template>
                                    <template v-else>
                                        <span class="block">
                                            {{
                                                $t('workspace.setup.cell_main', {
                                                    interval: $t(
                                                        `onboarding.frequencies.${rowsFor(marketId).find((row) => row.engine_key === engine.engine_key).frequency}`,
                                                    ),
                                                    scope: $t(
                                                        rowsFor(marketId).find(
                                                            (row) => row.engine_key === engine.engine_key,
                                                        ).prompt_scope === 'core'
                                                            ? 'workspace.setup.scope_core'
                                                            : 'workspace.setup.scope_all',
                                                    ),
                                                })
                                            }}
                                        </span>
                                        <span class="block text-shell-muted">
                                            {{
                                                rowsFor(marketId).find((row) => row.engine_key === engine.engine_key)
                                                    .core_frequency
                                                    ? $t('workspace.setup.cell_core', {
                                                          interval: $t(
                                                              `onboarding.frequencies.${rowsFor(marketId).find((row) => row.engine_key === engine.engine_key).core_frequency}`,
                                                          ),
                                                          count: rowsFor(marketId).find(
                                                              (row) => row.engine_key === engine.engine_key,
                                                          ).core_repetitions,
                                                      })
                                                    : $t('workspace.setup.cell_repetitions', {
                                                          count: rowsFor(marketId).find(
                                                              (row) => row.engine_key === engine.engine_key,
                                                          ).repetitions,
                                                      })
                                            }}
                                        </span>
                                        <span v-if="cellAnswers(engine.engine_key, marketId) != null" class="block">
                                            {{
                                                $t('workspace.setup.cell_answers', {
                                                    count: formatNumber(cellAnswers(engine.engine_key, marketId)),
                                                })
                                            }}
                                            <template v-if="marketId != null">
                                                {{
                                                    $t(
                                                        marketById(marketId)?.plan_mode !== 'own' ||
                                                            !changed(engine.engine_key, marketById(marketId))
                                                            ? 'workspace.setup.inherited_suffix'
                                                            : 'workspace.setup.changed_suffix',
                                                    )
                                                }}
                                            </template>
                                        </span>
                                    </template>
                                </span>
                            </button>
                            <InputError
                                v-if="selected?.engineKey !== engine.engine_key || selected?.marketId !== marketId"
                                :message="cellMessage(engine.engine_key, marketId)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
            <InputError class="px-2 py-1" :message="loosePlanError" />
        </div>

        <section
            v-if="editing"
            class="rounded-xl border border-line bg-shell-panel p-3 text-sm text-shell-text"
            :aria-labelledby="`${matrixId}-editor`"
            @keydown.esc.stop.prevent="closeEditor"
        >
            <h3 :id="`${matrixId}-editor`" ref="editorHeading" tabindex="-1" class="font-medium">
                {{
                    $t('workspace.setup.editing', {
                        engine: editing.row.name,
                        market:
                            editing.marketId == null
                                ? $t('workspace.setup.standard')
                                : marketNames.get(editing.marketId),
                    })
                }}
            </h3>
            <InputError class="mt-2" :message="cellMessage(editing.engineKey, editing.marketId)" />
            <p class="mt-2 text-xs text-shell-muted">{{ $t('workspace.setup.interval') }}</p>
            <div class="mt-1 flex flex-wrap gap-1">
                <button
                    v-for="frequency in INTERVALS"
                    :key="frequency"
                    type="button"
                    class="rounded-full border px-2 py-0.5 text-xs"
                    :class="
                        editing.row.frequency === frequency
                            ? 'border-primary text-shell-fg'
                            : 'border-line text-shell-muted'
                    "
                    :disabled="!allowsFrequency(frequency, editing.row.max_frequency)"
                    :aria-pressed="editing.row.frequency === frequency"
                    @click="setFrequency(frequency)"
                >
                    {{ $t(`onboarding.frequencies.${frequency}`) }}
                </button>
            </div>
            <p v-if="editing.row.max_frequency !== 'daily'" class="mt-1 text-xs text-shell-muted">
                {{
                    $t('workspace.setup.frequency_blocked', {
                        choice: $t('onboarding.frequencies.daily'),
                        cap: $t(`onboarding.frequencies.${editing.row.max_frequency}`),
                    })
                }}
            </p>
            <label class="mt-3 block text-xs">
                {{ $t('workspace.setup.scope') }}
                <select
                    v-model="editing.row.prompt_scope"
                    class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1 text-sm"
                >
                    <option value="all">{{ $t('workspace.setup.scope_all') }}</option>
                    <option value="core">{{ $t('workspace.setup.scope_core') }}</option>
                </select>
            </label>
            <label class="mt-3 block text-xs">
                {{ $t('workspace.setup.repetitions') }}
                <input
                    v-model.number="editing.row.repetitions"
                    type="number"
                    class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1 text-sm"
                    :min="1"
                    :max="maxRepetitions"
                />
            </label>
            <div v-if="editing.row.prompt_scope === 'all'" class="mt-3">
                <button
                    v-if="!editing.row.core_frequency"
                    type="button"
                    class="text-xs"
                    @click="
                        editing.row.core_repetitions = editing.row.repetitions || 1;
                        editing.row.core_frequency = editing.row.frequency === 'off' ? 'weekly' : editing.row.frequency;
                    "
                >
                    {{ $t('workspace.setup.add_core') }}
                </button>
                <div v-else class="text-xs">
                    <p>{{ $t('app.plan.core_frequency', { engine: editing.row.name }) }}</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <button
                            v-for="frequency in coreIntervals"
                            :key="frequency"
                            type="button"
                            class="rounded-full border px-2 py-0.5 text-xs"
                            :class="
                                editing.row.core_frequency === frequency
                                    ? 'border-primary text-shell-fg'
                                    : 'border-line text-shell-muted'
                            "
                            :disabled="!allowsFrequency(frequency, editing.row.max_frequency)"
                            :aria-pressed="editing.row.core_frequency === frequency"
                            @click="setCoreFrequency(frequency)"
                        >
                            {{ $t(`onboarding.frequencies.${frequency}`) }}
                        </button>
                    </div>
                    <label class="mt-3 block">
                        {{ $t('app.plan.core_repetitions', { engine: editing.row.name }) }}
                        <input
                            v-model.number="editing.row.core_repetitions"
                            type="number"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1 text-sm"
                            :min="1"
                            :max="maxRepetitions"
                        />
                    </label>
                    <button type="button" class="mt-2" @click="editing.row.core_frequency = ''">
                        {{ $t('workspace.setup.remove_core') }}
                    </button>
                </div>
            </div>
            <p
                v-if="editing.marketId != null && cellAnswers(editing.engineKey, editing.marketId) != null"
                class="mt-3 text-xs"
            >
                {{
                    $t('workspace.setup.engine_month', {
                        market: marketNames.get(editing.marketId),
                        count: formatNumber(cellAnswers(editing.engineKey, editing.marketId)),
                    })
                }}
            </p>
            <p
                v-if="editing.marketId != null && nextDue(estimate, editing.marketId, editing.engineKey)"
                class="text-xs text-shell-muted"
            >
                {{
                    $t('workspace.setup.next_due', {
                        date: formatDate(nextDue(estimate, editing.marketId, editing.engineKey)),
                    })
                }}
                <template v-if="prompts[samplePromptId(estimate, editing.marketId, editing.engineKey)]">
                    {{
                        $t('workspace.setup.example', {
                            prompt: prompts[samplePromptId(estimate, editing.marketId, editing.engineKey)],
                        })
                    }}
                </template>
            </p>
        </section>
    </div>

    <div
        v-if="canManage"
        data-testid="save-bar"
        class="sticky bottom-0 z-10 mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line bg-shell-surface px-3 py-2 text-xs"
    >
        <span
            v-if="dirty"
            class="inline-block h-2 w-2 rounded-full bg-warning"
            role="img"
            :aria-label="$t('workspace.setup.unsaved')"
        />
        <span data-testid="estimate-answers">
            {{ $t('workspace.setup.answers_per_month', { count: formatNumber(estimate?.answers ?? 0) }) }}
            <template v-if="delta">{{ $t('workspace.setup.delta', { count: formatNumber(delta) }) }}</template>
        </span>
        <span>{{ $t('workspace.setup.quota_calls', { count: formatNumber(estimate?.quota_calls ?? 0) }) }}</span>
        <span>
            {{
                $t('workspace.setup.cost_month', {
                    amount: estimate?.total_cost_usd == null ? empty() : formatCurrency(estimate.total_cost_usd),
                })
            }}
        </span>
        <span>
            {{
                $t('workspace.setup.quota_left', {
                    count: formatNumber(estimate?.quota?.remaining ?? 0),
                    month,
                })
            }}
        </span>
        <span :class="within ? 'text-success' : 'text-warning'">{{ statusMessage }}</span>
        <span class="ml-auto flex gap-2">
            <button v-if="dirty" type="button" class="rounded-lg border border-line px-3 py-1" @click="discard">
                {{ $t('workspace.setup.discard') }}
            </button>
            <button
                v-if="dirty"
                type="button"
                class="rounded-lg bg-primary-deep px-3 py-1 text-white"
                :disabled="!canSave"
                @click="save"
            >
                {{ $t('workspace.setup.save_plan') }}
            </button>
        </span>
    </div>
</template>
