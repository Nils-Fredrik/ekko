<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    engines: { type: Array, required: true },
});

const field = 'rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink';
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

const basis = ref({ name: '', primary_domain: '', country: 'NO', language: 'nb' });
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
    engines: [],
});

const coreCount = computed(() => draft.value.prompts.filter((prompt) => prompt.priority === 'core').length);

function emptyDraft() {
    return { industry: '', category: '', name_variants: [], competitors: [], topics: [], prompts: [] };
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
        const response = await window.axios.post(route('onboarding.analyses.store'), basis.value);
        analysis.value = response.data;
        if (response.data.status === 'ready') {
            fill(response.data);
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
    draft.value = {
        industry: proposal.industry ?? '',
        category: proposal.category ?? '',
        name_variants: [...(proposal.name_variants ?? [])],
        competitors: (proposal.competitors ?? []).map((row) => ({ ...row })),
        topics: (proposal.topics ?? []).map((row) => ({ ...row })),
        prompts: (proposal.prompts ?? []).map((row) => ({ ...row })),
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
    form.topics = draft.value.topics;
    form.prompts = draft.value.prompts;
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
    <AppLayout :title="$t('onboarding.ui.title')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">
                {{ $t('onboarding.ui.title') }}
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
                class="space-y-4 rounded-xl border border-line bg-white p-5 text-ink"
                @submit.prevent="start"
            >
                <label class="block text-sm">
                    {{ $t('onboarding.ui.name') }}
                    <input v-model="basis.name" required :class="field" class="mt-1 w-full" />
                </label>
                <label class="block text-sm">
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

            <section v-else-if="step === 'wait'" class="rounded-xl border border-line bg-white p-5 text-sm text-ink">
                {{ $t('onboarding.ui.waiting') }}
            </section>

            <section v-else-if="step === 'edit'" class="space-y-4 rounded-xl border border-line bg-white p-5 text-ink">
                <label class="block text-sm" v-for="key in ['name', 'primary_domain', 'country', 'language']" :key="key">
                    {{ $t(`onboarding.ui.${key === 'primary_domain' ? 'domain' : key}`) }}
                    <input v-model="basis[key]" :class="field" class="mt-1 w-full" />
                </label>
                <p v-if="draft.industry" class="text-sm">{{ $t('onboarding.ui.industry') }}: {{ draft.industry }}</p>
                <p v-if="draft.category" class="text-sm">{{ draft.category }}</p>
                <div>
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.variants') }}</h3>
                    <div v-for="(variant, index) in draft.name_variants" :key="index" class="mt-2 flex gap-2">
                        <input v-model="draft.name_variants[index]" :class="field" class="w-full" />
                        <button type="button" class="text-sm" @click="draft.name_variants.splice(index, 1)">
                            {{ $t('onboarding.ui.remove') }}
                        </button>
                    </div>
                    <button type="button" class="mt-2 text-sm" @click="draft.name_variants.push('')">
                        {{ $t('onboarding.ui.add') }}
                    </button>
                </div>
                <div>
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.competitors') }}</h3>
                    <div
                        v-for="(competitor, index) in draft.competitors"
                        :key="index"
                        class="mt-2 grid gap-2 sm:grid-cols-2"
                    >
                        <input v-model="competitor.name" :class="field" />
                        <input v-model="competitor.domain" :class="field" />
                        <button type="button" class="text-sm" @click="draft.competitors.splice(index, 1)">
                            {{ $t('onboarding.ui.remove') }}
                        </button>
                    </div>
                </div>
                <button type="button" class="text-sm" @click="draft.competitors.push({ name: '', domain: '' })">
                    {{ $t('onboarding.ui.add_competitor') }}
                </button>
                <div>
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.topics') }}</h3>
                    <div v-for="(topic, index) in draft.topics" :key="topic.cross_market_key" class="mt-2">
                        <input v-model="topic.name" :class="field" class="w-full" />
                        <button type="button" class="text-sm" @click="removeTopic(index)">
                            {{ $t('onboarding.ui.remove') }}
                        </button>
                    </div>
                </div>
                <button type="button" class="text-sm" @click="addTopic">{{ $t('onboarding.ui.add_topic') }}</button>
                <div>
                    <h3 class="text-sm font-medium">{{ $t('onboarding.ui.prompts') }}</h3>
                    <div
                        v-for="(prompt, index) in draft.prompts"
                        :key="index"
                        class="mt-3 space-y-2 border-t border-line pt-3"
                    >
                        <textarea v-model="prompt.text" :class="field" class="w-full" rows="2" />
                        <div class="flex flex-wrap gap-2">
                            <select v-model="prompt.cross_market_key" :class="field">
                                <option
                                    v-for="topic in draft.topics"
                                    :key="topic.cross_market_key"
                                    :value="topic.cross_market_key"
                                >
                                    {{ topic.name }}
                                </option>
                            </select>
                            <select v-model="prompt.intent" :class="field">
                                <option v-for="intent in intents" :key="intent" :value="intent">
                                    {{ $t(`app.intents.${intent}`) }}
                                </option>
                            </select>
                            <select v-model="prompt.funnel_stage" :class="field">
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
                class="space-y-4 rounded-xl border border-line bg-white p-5 text-ink"
            >
                <h3 class="font-medium">{{ $t('onboarding.ui.engines') }}</h3>
                <div
                    v-for="engine in setup"
                    :key="engine.engine_key"
                    class="grid gap-2 border-t border-line pt-3 sm:grid-cols-4"
                >
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="engine.enabled" type="checkbox" />
                        {{ engine.engine_key }}
                    </label>
                    <select v-model="engine.frequency" :class="field">
                        <option v-for="frequency in frequencies" :key="frequency" :value="frequency">
                            {{ $t(`onboarding.frequencies.${frequency}`) }}
                        </option>
                    </select>
                    <input v-model.number="engine.repetitions" type="number" min="1" max="3" :class="field" />
                </div>
                <PrimaryButton type="button" @click="loadEstimate">{{ $t('onboarding.ui.estimate') }}</PrimaryButton>
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

            <section v-else class="space-y-4 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('onboarding.ui.integrations') }}</h3>
                <p class="text-sm">{{ $t('onboarding.ui.integrations_later') }}</p>
                <p v-if="estimate" class="text-sm">
                    {{ $t('onboarding.ui.cost', { amount: estimate.total_cost_usd }) }}
                </p>
                <InputError :message="estimateError" />
                <div class="flex gap-3">
                    <button type="button" class="text-sm" @click="step = 'engines'">
                        {{ $t('onboarding.ui.back') }}
                    </button>
                    <PrimaryButton :disabled="form.processing || !estimateCurrent" @click="confirm">
                        {{ $t('onboarding.ui.confirm') }}
                    </PrimaryButton>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
