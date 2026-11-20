<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    engines: { type: Array, required: true },
    brand: { type: Object, default: null },
    maxCore: { type: Number, required: true },
    clickhouseEndpoints: { type: Array, default: () => [] },
});

const field = 'rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text';
const intents = ['category', 'comparison', 'brand', 'problem', 'local'];
const stages = ['awareness', 'consideration', 'decision'];
const frequencies = ['daily', 'weekly', 'monthly', 'off'];
const step = ref('basis');
const analysis = ref(null);
const error = ref('');
const estimate = ref(null);
const estimateError = ref('');
const estimating = ref(false);
const estimatedPayload = ref('');
let estimateRequest = 0;
let poll = null;

const basis = ref({
    name: props.brand?.name ?? '',
    primary_domain: props.brand?.primary_domain ?? '',
    country: props.brand ? '' : 'NO',
    language: props.brand ? '' : 'nb',
});
const basisFields = computed(() =>
    props.brand ? ['country', 'language'] : ['name', 'primary_domain', 'country', 'language'],
);
const draft = ref(emptyDraft());
const setup = ref(props.engines.map((engine) => ({ ...engine, enabled: true })));

const form = useForm({
    name: '',
    primary_domain: '',
    country: 'NO',
    language: 'nb',
    name_variants: [],
    competitors: [],
    topics: [],
    prompts: [],
    facts: [],
    engines: [],
    clickhouse_host: '',
    clickhouse_database: '',
    clickhouse_table: '',
    clickhouse_user: '',
    clickhouse_password: '',
    ga4_property_id: '',
    ga4_client_email: '',
    ga4_private_key: '',
});

const coreCount = computed(() => draft.value.prompts.filter((prompt) => prompt.priority === 'core').length);

function emptyDraft() {
    return { industry: '', category: '', name_variants: [], competitors: [], topics: [], prompts: [], facts: [] };
}

function stop() {
    if (poll !== null) {
        clearTimeout(poll);
        poll = null;
    }
}

onBeforeUnmount(stop);

async function start() {
    error.value = '';
    step.value = 'wait';
    try {
        const response = await window.axios.post(
            props.brand ? route('brands.market-proposals.store', props.brand.id) : route('onboarding.analyses.store'),
            basis.value,
        );
        analysis.value = response.data;
        if (response.data.status === 'ready') {
            fill(response.data);
            return;
        }
        if (response.data.status === 'confirmed') {
            fail({ error: { message: trans('onboarding.errors.not_ready') } });
            return;
        }
        if (response.data.status === 'failed') {
            fail(response.data);
            return;
        }
        poll = setTimeout(() => refresh(response.data.id), 1000);
    } catch (caught) {
        fail(caught.response?.data);
    }
}

async function refresh(id) {
    try {
        const response = await window.axios.get(route('onboarding.analyses.show', id));
        analysis.value = response.data;
        if (response.data.status === 'ready') {
            fill(response.data);
            return;
        }
        if (response.data.status === 'confirmed') {
            fail({ error: { message: trans('onboarding.errors.not_ready') } });
            return;
        }
        if (response.data.status === 'failed') {
            fail(response.data);
            return;
        }
        poll = setTimeout(() => refresh(id), 1500);
    } catch (caught) {
        fail(caught.response?.data);
    }
}

function fill(payload) {
    const proposal = payload.proposal ?? emptyDraft();
    basis.value.country = payload.input.country;
    basis.value.language = payload.input.language;
    draft.value = {
        industry: proposal.industry ?? '',
        category: proposal.category ?? '',
        name_variants: [...(proposal.name_variants ?? [])],
        competitors: (proposal.competitors ?? []).map((row) => ({ ...row })),
        topics: (props.brand?.topics ?? proposal.topics ?? []).map((row) => ({ ...row })),
        prompts: (proposal.prompts ?? []).map((row) => ({ ...row })),
        // Proposed facts are only saved when the user approves them (docs/spec.md 11.6).
        facts: (proposal.facts ?? []).map((text) => ({ text, approved: false })),
    };
    step.value = 'edit';
}

function fail(payload) {
    stop();
    error.value = failureMessage(payload);
    step.value = 'basis';
}

function addPrompt() {
    draft.value.prompts.push({
        text: '',
        cross_market_key: draft.value.topics[0]?.cross_market_key ?? '',
        intent: 'category',
        funnel_stage: 'awareness',
        priority: 'standard',
    });
}

function failureMessage(payload) {
    return (
        Object.values(payload?.errors ?? {})
            .flat()
            .join(' ') ||
        payload?.error?.message ||
        trans('onboarding.ui.failed')
    );
}

// The draft index of each fact sent with confirm, so an error on facts.N lands on the right row.
const sentFacts = ref([]);

function factError(index) {
    const sent = sentFacts.value.indexOf(index);

    return sent === -1 ? '' : form.errors[`facts.${sent}`];
}

function addFact() {
    draft.value.facts.push({ text: '', approved: true });
}

function addTopic() {
    draft.value.topics.push({ name: '', cross_market_key: `topic_${crypto.randomUUID()}` });
}

function removeTopic(index) {
    const [removed] = draft.value.topics.splice(index, 1);
    for (const prompt of draft.value.prompts) {
        if (prompt.cross_market_key === removed.cross_market_key) {
            prompt.cross_market_key = draft.value.topics[0]?.cross_market_key ?? '';
        }
    }
}

const estimatePayload = computed(() => ({
    engines: setup.value
        .filter((engine) => engine.enabled)
        .map((engine) => ({
            engine_key: engine.engine_key,
            frequency: engine.frequency,
            prompt_scope: 'all',
            repetitions: Number(engine.repetitions),
        })),
    prompt_count: draft.value.prompts.length,
    core_prompt_count: coreCount.value,
}));
const noActiveEngines = computed(() => !estimatePayload.value.engines.some((engine) => engine.frequency !== 'off'));
const payloadKey = computed(() => JSON.stringify(estimatePayload.value));
const estimateCurrent = computed(() => !estimating.value && estimatedPayload.value === payloadKey.value);
watch([payloadKey, step], () => {
    if (['engines', 'integrations'].includes(step.value) && !estimateCurrent.value) loadEstimate();
});

async function loadEstimate() {
    const request = ++estimateRequest;
    const key = payloadKey.value;
    estimating.value = true;
    estimateError.value = '';
    estimate.value = null;
    estimatedPayload.value = '';
    try {
        const result =
            estimatePayload.value.engines.length === 0
                ? { total_cost_usd: '0.00', issues: [] }
                : (await window.axios.post(route('onboarding.cost-estimate'), estimatePayload.value)).data;
        if (request === estimateRequest && key === payloadKey.value) {
            estimate.value = result;
            estimatedPayload.value = key;
        }
    } catch (caught) {
        if (request === estimateRequest) estimateError.value = failureMessage(caught.response?.data);
    } finally {
        if (request === estimateRequest) estimating.value = false;
    }
}

function confirm() {
    if (!estimateCurrent.value || form.processing) return;
    form.name = basis.value.name;
    form.primary_domain = basis.value.primary_domain;
    form.country = basis.value.country;
    form.language = basis.value.language;
    form.name_variants = draft.value.name_variants.filter((name) => name.trim() !== '');
    form.competitors = draft.value.competitors;
    form.topics = props.brand ? [] : draft.value.topics;
    form.prompts = draft.value.prompts;
    sentFacts.value = props.brand
        ? []
        : draft.value.facts.flatMap((fact, index) => (fact.approved && fact.text.trim() !== '' ? [index] : []));
    form.facts = sentFacts.value.map((index) => draft.value.facts[index].text.trim());
    form.engines = setup.value
        .filter((engine) => engine.enabled)
        .map((engine) => ({
            engine_key: engine.engine_key,
            frequency: engine.frequency,
            repetitions: Number(engine.repetitions),
        }));
    form.post(route('onboarding.analyses.confirm', analysis.value.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="$t(brand ? 'onboarding.ui.market_title' : 'onboarding.ui.title')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">
                {{ $t(brand ? 'onboarding.ui.market_title' : 'onboarding.ui.title') }}
            </h2>
        </template>

        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6">
            <ul
                v-if="Object.keys(form.errors).length"
                role="alert"
                class="rounded-lg border border-red-300 p-4 text-sm text-red-700"
            >
                <li v-for="(message, key) in form.errors" :key="key">{{ message }}</li>
            </ul>
            <form
                v-if="step === 'basis'"
                class="space-y-4 rounded-xl border border-line bg-shell-panel p-5 text-shell-text"
                @submit.prevent="start"
            >
                <p v-if="brand" class="font-medium">{{ brand.name }}</p>
                <label v-if="!brand" class="block text-sm">
                    {{ $t('onboarding.ui.name') }}
                    <input v-model="basis.name" required :class="field" class="mt-1 w-full" />
                </label>
                <label v-if="!brand" class="block text-sm">
                    {{ $t('onboarding.ui.domain') }}
                    <input v-model="basis.primary_domain" required :class="field" class="mt-1 w-full" />
                </label>
                <label class="block text-sm">
                    {{ $t('onboarding.ui.country') }}
                    <input
                        v-model="basis.country"
                        required
                        maxlength="2"
                        :class="field"
                        class="mt-1 w-full uppercase"
                    />
                </label>
                <label class="block text-sm">
                    {{ $t('onboarding.ui.language') }}
                    <input v-model="basis.language" required :class="field" class="mt-1 w-full" />
                </label>
                <InputError :message="error" />
                <PrimaryButton>{{ $t('onboarding.ui.start') }}</PrimaryButton>
            </form>

            <section
                v-else-if="step === 'wait'"
                class="rounded-xl border border-line bg-shell-panel p-5 text-sm text-shell-text"
            >
                {{ $t('onboarding.ui.waiting') }}
            </section>

            <section
                v-else-if="step === 'edit'"
                class="space-y-4 rounded-xl border border-line bg-shell-panel p-5 text-shell-text"
            >
                <label v-for="key in basisFields" :key="key" class="block text-sm">
                    {{ $t(`onboarding.ui.${key === 'primary_domain' ? 'domain' : key}`) }}
                    <input
                        v-model="basis[key]"
                        :disabled="['country', 'language'].includes(key)"
                        :class="field"
                        class="mt-1 w-full"
                    />
                </label>
                <p v-if="draft.industry" class="text-sm">{{ $t('onboarding.ui.industry') }}: {{ draft.industry }}</p>
                <p v-if="draft.category" class="text-sm">{{ draft.category }}</p>
                <div v-if="!brand">
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.variants') }}</h3>
                    <div v-for="(variant, index) in draft.name_variants" :key="index" class="mt-2 flex gap-2">
                        <input
                            v-model="draft.name_variants[index]"
                            :aria-label="$t('onboarding.ui.variants')"
                            :class="field"
                            class="w-full"
                        />
                        <button type="button" class="text-sm" @click="draft.name_variants.splice(index, 1)">
                            {{ $t('onboarding.ui.remove') }}
                        </button>
                    </div>
                    <button type="button" class="mt-2 text-sm" @click="draft.name_variants.push('')">
                        {{ $t('onboarding.ui.add') }}
                    </button>
                </div>
                <div v-if="!brand">
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.competitors') }}</h3>
                    <div
                        v-for="(competitor, index) in draft.competitors"
                        :key="index"
                        class="mt-2 grid gap-2 sm:grid-cols-2"
                    >
                        <input v-model="competitor.name" :aria-label="$t('onboarding.ui.name')" :class="field" />
                        <input v-model="competitor.domain" :aria-label="$t('onboarding.ui.domain')" :class="field" />
                        <button type="button" class="text-sm" @click="draft.competitors.splice(index, 1)">
                            {{ $t('onboarding.ui.remove') }}
                        </button>
                    </div>
                </div>
                <button
                    v-if="!brand"
                    type="button"
                    class="text-sm"
                    @click="draft.competitors.push({ name: '', domain: '' })"
                >
                    {{ $t('onboarding.ui.add_competitor') }}
                </button>
                <div v-if="!brand">
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.topics') }}</h3>
                    <div v-for="(topic, index) in draft.topics" :key="topic.cross_market_key" class="mt-2">
                        <input
                            v-model="topic.name"
                            :aria-label="$t('app.dashboard.topic')"
                            :class="field"
                            class="w-full"
                        />
                        <button type="button" class="text-sm" @click="removeTopic(index)">
                            {{ $t('onboarding.ui.remove') }}
                        </button>
                    </div>
                </div>
                <button v-if="!brand" type="button" class="text-sm" @click="addTopic">
                    {{ $t('onboarding.ui.add_topic') }}
                </button>
                <div v-if="!brand" data-testid="wizard-facts">
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.facts') }}</h3>
                    <p class="mt-1 text-xs text-shell-muted">{{ $t('onboarding.ui.facts_intro') }}</p>
                    <div v-for="(fact, index) in draft.facts" :key="index" class="mt-2">
                        <div class="flex items-center gap-2">
                            <label class="flex shrink-0 items-center gap-2 text-sm">
                                <input v-model="fact.approved" type="checkbox" />
                                {{ $t('onboarding.ui.approve_fact') }}
                            </label>
                            <input
                                v-model="fact.text"
                                :aria-label="$t('onboarding.ui.fact')"
                                maxlength="300"
                                :class="field"
                                class="w-full"
                            />
                            <button type="button" class="text-sm" @click="draft.facts.splice(index, 1)">
                                {{ $t('onboarding.ui.remove') }}
                            </button>
                        </div>
                        <InputError :message="factError(index)" />
                    </div>
                    <button type="button" class="mt-2 text-sm" @click="addFact">
                        {{ $t('onboarding.ui.add_fact') }}
                    </button>
                </div>
                <div>
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.prompts') }}</h3>
                    <div
                        v-for="(prompt, index) in draft.prompts"
                        :key="index"
                        class="mt-3 space-y-2 border-t border-line pt-3"
                    >
                        <textarea
                            v-model="prompt.text"
                            :aria-label="$t('onboarding.ui.prompt')"
                            :class="field"
                            class="w-full"
                            rows="2"
                        />
                        <div class="flex flex-wrap gap-2">
                            <select
                                v-model="prompt.cross_market_key"
                                :aria-label="$t('app.dashboard.topic')"
                                :class="field"
                            >
                                <option
                                    v-for="topic in draft.topics"
                                    :key="topic.cross_market_key"
                                    :value="topic.cross_market_key"
                                >
                                    {{ topic.name }}
                                </option>
                            </select>
                            <select v-model="prompt.intent" :aria-label="$t('app.dashboard.intent')" :class="field">
                                <option v-for="intent in intents" :key="intent" :value="intent">
                                    {{ $t(`app.intents.${intent}`) }}
                                </option>
                            </select>
                            <select v-model="prompt.funnel_stage" :aria-label="$t('app.library.funnel')" :class="field">
                                <option v-for="stage in stages" :key="stage" :value="stage">
                                    {{ $t(`app.funnel_stages.${stage}`) }}
                                </option>
                            </select>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="prompt.priority"
                                    type="checkbox"
                                    true-value="core"
                                    false-value="standard"
                                    :disabled="prompt.priority !== 'core' && coreCount >= maxCore"
                                />
                                {{ $t('onboarding.ui.core') }}
                            </label>
                            <button type="button" class="text-sm" @click="draft.prompts.splice(index, 1)">
                                {{ $t('onboarding.ui.remove') }}
                            </button>
                        </div>
                        <InputError :message="form.errors[`prompts.${index}.text`]" />
                    </div>
                    <button type="button" class="mt-2 text-sm" @click="addPrompt">{{ $t('onboarding.ui.add') }}</button>
                </div>
                <PrimaryButton type="button" @click="step = 'engines'">{{ $t('onboarding.ui.next') }}</PrimaryButton>
            </section>

            <section
                v-else-if="step === 'engines'"
                class="space-y-4 rounded-xl border border-line bg-shell-panel p-5 text-shell-text"
            >
                <h3 class="font-medium">{{ $t('onboarding.ui.engines') }}</h3>
                <div
                    v-for="engine in setup"
                    :key="engine.engine_key"
                    class="grid gap-2 border-t border-line pt-3 sm:grid-cols-4"
                >
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="engine.enabled" type="checkbox" />
                        {{ engine.name }}
                    </label>
                    <select v-model="engine.frequency" :aria-label="$t('app.workspace.frequency')" :class="field">
                        <option
                            v-for="frequency in frequencies.filter(
                                (item) =>
                                    ['off', 'monthly', 'weekly', 'daily'].indexOf(item) <=
                                    ['off', 'monthly', 'weekly', 'daily'].indexOf(engine.max_frequency),
                            )"
                            :key="frequency"
                            :value="frequency"
                        >
                            {{ $t(`onboarding.frequencies.${frequency}`) }}
                        </option>
                    </select>
                    <input
                        v-model.number="engine.repetitions"
                        :aria-label="$t('app.workspace.repetitions')"
                        type="number"
                        min="1"
                        :max="engine.max_repetitions"
                        :class="field"
                    />
                </div>
                <PrimaryButton type="button" @click="loadEstimate">{{ $t('onboarding.ui.estimate') }}</PrimaryButton>
                <p v-if="noActiveEngines" role="status" class="text-sm">{{ $t('onboarding.ui.no_active_engines') }}</p>
                <InputError :message="estimateError" />
                <p v-if="estimating" class="text-sm">{{ $t('onboarding.ui.estimating') }}</p>
                <p v-if="estimate" class="text-sm">
                    {{ $t('onboarding.ui.cost', { amount: estimate.total_cost_usd }) }}
                </p>
                <ul v-if="estimate?.issues?.length" class="list-disc pl-5 text-sm">
                    <li v-for="issue in estimate.issues" :key="issue.code + (issue.engine_key ?? '')">
                        {{ issue.message }}
                    </li>
                </ul>
                <div class="flex gap-3">
                    <button type="button" class="text-sm" @click="step = 'edit'">{{ $t('onboarding.ui.back') }}</button>
                    <PrimaryButton type="button" :disabled="!estimateCurrent" @click="step = 'integrations'">
                        {{ $t('onboarding.ui.next') }}
                    </PrimaryButton>
                </div>
            </section>

            <section v-else class="space-y-4 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('onboarding.ui.integrations') }}</h3>
                <p class="text-sm">{{ $t('onboarding.ui.integrations_later') }}</p>
                <template v-if="clickhouseEndpoints.length > 0">
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_host') }}
                        <select
                            v-model="form.clickhouse_host"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                        >
                            <option value="">{{ $t('app.workspace.clickhouse_none') }}</option>
                            <option v-for="endpoint in clickhouseEndpoints" :key="endpoint" :value="endpoint">
                                {{ endpoint }}
                            </option>
                        </select>
                        <InputError :message="form.errors.clickhouse_host" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_database') }}
                        <input
                            v-model="form.clickhouse_database"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.clickhouse_database" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_table') }}
                        <input
                            v-model="form.clickhouse_table"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.clickhouse_table" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_user') }}
                        <input
                            v-model="form.clickhouse_user"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.clickhouse_user" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_password') }}
                        <input
                            v-model="form.clickhouse_password"
                            type="password"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.clickhouse_password" />
                    </label>
                </template>
                <label class="block text-sm">
                    {{ $t('app.workspace.ga4_property') }}
                    <input
                        v-model="form.ga4_property_id"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.ga4_property_id" />
                </label>
                <label class="block text-sm">
                    {{ $t('app.workspace.ga4_email') }}
                    <input
                        v-model="form.ga4_client_email"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.ga4_client_email" />
                </label>
                <label class="block text-sm">
                    {{ $t('app.workspace.ga4_key') }}
                    <textarea
                        v-model="form.ga4_private_key"
                        rows="3"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.ga4_private_key" />
                </label>
                <p v-if="estimate" class="text-sm">
                    {{ $t('onboarding.ui.cost', { amount: estimate.total_cost_usd }) }}
                </p>
                <p v-if="noActiveEngines" role="status" class="text-sm">{{ $t('onboarding.ui.no_active_engines') }}</p>
                <InputError :message="estimateError" />
                <div class="flex gap-3">
                    <button type="button" class="text-sm" @click="step = 'engines'">
                        {{ $t('onboarding.ui.back') }}
                    </button>
                    <PrimaryButton :disabled="form.processing || !estimateCurrent" @click="confirm">
                        {{ $t(noActiveEngines ? 'onboarding.ui.save_without_run' : 'onboarding.ui.confirm') }}
                    </PrimaryButton>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
