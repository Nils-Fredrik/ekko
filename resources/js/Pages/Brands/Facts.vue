<script setup>
import BrandNav from '@/Components/Brand/BrandNav.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    facts: { type: Array, required: true },
    maxFacts: { type: Number, required: true },
    maxLength: { type: Number, required: true },
    verdict: { type: String, required: true },
    verdicts: { type: Array, required: true },
    claims: { type: Array, required: true },
    claimLimit: { type: Number, default: 100 },
    canManage: { type: Boolean, required: true },
});

const field = 'rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text';
const verdictTone = { supported: 'text-success', contradicted: 'text-error', unrelated: 'text-shell-muted' };

const active = computed(() => props.facts.filter((fact) => fact.is_active));
const inactive = computed(() => props.facts.filter((fact) => !fact.is_active));

const adding = useForm({ text: '' });
const changing = useForm({ text: '', is_active: true });
const editing = ref(null);
const busy = ref(null);

function add() {
    adding.post(route('brands.facts.store', props.brand.id), {
        preserveScroll: true,
        onSuccess: () => adding.reset('text'),
    });
}

function edit(fact) {
    changing.clearErrors();
    changing.text = fact.text;
    editing.value = fact.id;
}

function save(fact) {
    changing
        .transform((data) => ({ text: data.text }))
        .patch(route('brands.facts.update', [props.brand.id, fact.id]), {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = null;
            },
        });
}

function setActive(fact, value) {
    busy.value = fact.id;
    router.patch(
        route('brands.facts.update', [props.brand.id, fact.id]),
        { is_active: value },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function remove(fact) {
    if (!window.confirm(trans('fact_check.ui.confirm_delete'))) {
        return;
    }
    busy.value = fact.id;
    router.delete(route('brands.facts.destroy', [props.brand.id, fact.id]), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}

function filter(verdict) {
    router.get(
        route('brands.facts.index', props.brand.id),
        { verdict },
        { preserveScroll: true, preserveState: true, only: ['claims', 'verdict'] },
    );
}

function date(value) {
    return value
        ? new Intl.DateTimeFormat(document.documentElement.lang || 'nb', { dateStyle: 'medium' }).format(
              new Date(value),
          )
        : '';
}
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <BrandNav :brand-id="brand.id" />

                <section class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-base font-medium text-shell-fg">{{ $t('fact_check.ui.title') }}</h3>
                        <span class="text-xs text-shell-muted tabular-nums">
                            {{ $t('fact_check.ui.count', { count: facts.length, max: maxFacts }) }}
                        </span>
                    </div>
                    <p class="mt-2 max-w-3xl text-sm text-shell-muted">{{ $t('fact_check.ui.intro') }}</p>

                    <form v-if="canManage" class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="add">
                        <label for="new-fact" class="sr-only">{{ $t('fact_check.ui.add_label') }}</label>
                        <input
                            id="new-fact"
                            v-model="adding.text"
                            :aria-label="$t('fact_check.ui.add_label')"
                            :placeholder="$t('fact_check.ui.add_placeholder')"
                            :maxlength="maxLength"
                            required
                            :class="field"
                            class="w-full"
                        />
                        <PrimaryButton :disabled="adding.processing || facts.length >= maxFacts">
                            {{ $t('fact_check.ui.add') }}
                        </PrimaryButton>
                    </form>
                    <InputError class="mt-2" :message="adding.errors.text" />
                    <p v-if="!canManage" class="mt-3 text-xs text-shell-muted">{{ $t('fact_check.ui.readonly') }}</p>
                </section>

                <EmptyState
                    v-if="facts.length === 0"
                    :title="$t('fact_check.ui.empty_title')"
                    :body="$t('fact_check.ui.empty_body')"
                />

                <section
                    v-if="inactive.length"
                    class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                    data-testid="pending-facts"
                >
                    <h3 class="text-sm font-medium text-shell-fg">{{ $t('fact_check.ui.pending_title') }}</h3>
                    <p class="mt-1 text-sm text-shell-muted">{{ $t('fact_check.ui.pending_body') }}</p>
                    <ul class="mt-3 flex flex-col divide-y divide-line-soft">
                        <li
                            v-for="fact in inactive"
                            :key="fact.id"
                            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <span class="text-sm">{{ fact.text }}</span>
                            <span v-if="canManage" class="flex shrink-0 gap-3">
                                <button
                                    type="button"
                                    class="rounded-full bg-primary px-3 py-1 text-xs font-medium text-white hover:bg-primary-deep disabled:opacity-40"
                                    :disabled="busy === fact.id"
                                    @click="setActive(fact, true)"
                                >
                                    {{ $t('fact_check.ui.approve') }}
                                </button>
                                <button
                                    type="button"
                                    class="text-xs text-shell-muted hover:text-shell-text"
                                    :disabled="busy === fact.id"
                                    @click="remove(fact)"
                                >
                                    {{ $t('fact_check.ui.delete') }}
                                </button>
                            </span>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="active.length"
                    class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text"
                >
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-shell-muted">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ $t('fact_check.ui.fact') }}</th>
                                <th class="px-4 py-3 font-medium">{{ $t('fact_check.ui.claims') }}</th>
                                <th v-if="canManage" class="px-4 py-3">
                                    <span class="sr-only">{{ $t('fact_check.ui.edit') }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="fact in active"
                                :key="fact.id"
                                class="border-b border-line-soft align-top last:border-0 hover:bg-row-hover"
                            >
                                <td class="px-4 py-3">
                                    <form
                                        v-if="editing === fact.id"
                                        class="flex flex-col gap-2"
                                        @submit.prevent="save(fact)"
                                    >
                                        <input
                                            v-model="changing.text"
                                            :aria-label="$t('fact_check.ui.text_label')"
                                            :maxlength="maxLength"
                                            required
                                            :class="field"
                                            class="w-full"
                                        />
                                        <InputError :message="changing.errors.text" />
                                        <p class="text-xs text-shell-muted">{{ $t('fact_check.ui.change_note') }}</p>
                                        <span class="flex gap-3">
                                            <PrimaryButton :disabled="changing.processing">
                                                {{ $t('fact_check.ui.save') }}
                                            </PrimaryButton>
                                            <button type="button" class="text-sm" @click="editing = null">
                                                {{ $t('fact_check.ui.cancel') }}
                                            </button>
                                        </span>
                                    </form>
                                    <span v-else>{{ fact.text }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs whitespace-nowrap text-shell-muted tabular-nums">
                                    {{ $t('fact_check.ui.claim_counts', fact.claims) }}
                                </td>
                                <td v-if="canManage" class="px-4 py-3 text-right whitespace-nowrap">
                                    <span v-if="editing !== fact.id" class="inline-flex gap-3 text-xs">
                                        <button type="button" class="text-primary" @click="edit(fact)">
                                            {{ $t('fact_check.ui.edit') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="text-shell-muted hover:text-shell-text"
                                            :disabled="busy === fact.id"
                                            @click="setActive(fact, false)"
                                        >
                                            {{ $t('fact_check.ui.deactivate') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="text-shell-muted hover:text-shell-text"
                                            :disabled="busy === fact.id"
                                            @click="remove(fact)"
                                        >
                                            {{ $t('fact_check.ui.delete') }}
                                        </button>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="flex flex-col gap-3 text-shell-text">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-medium text-shell-fg">{{ $t('fact_check.ui.claims') }}</h3>
                            <p class="text-sm text-shell-muted">{{ $t('fact_check.ui.claims_intro') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2" role="group" :aria-label="$t('fact_check.ui.claims')">
                            <button
                                v-for="option in verdicts"
                                :key="option"
                                type="button"
                                class="rounded-full px-3 py-1 text-sm"
                                :class="
                                    option === verdict ? 'bg-primary text-white' : 'bg-shell-surface text-shell-text/70'
                                "
                                :aria-pressed="option === verdict"
                                @click="filter(option)"
                            >
                                {{ $t(`fact_check.ui.verdicts.${option}`) }}
                            </button>
                        </div>
                    </div>

                    <EmptyState
                        v-if="claims.length === 0"
                        :title="$t('fact_check.ui.claims_empty_title')"
                        :body="$t('fact_check.ui.claims_empty_body')"
                    />
                    <ul v-else class="flex flex-col gap-3" data-testid="claims">
                        <li
                            v-for="claim in claims"
                            :key="claim.id"
                            class="rounded-2xl border border-line bg-shell-panel p-5"
                        >
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-shell-muted">
                                <span class="font-medium" :class="verdictTone[claim.verdict]">
                                    {{ $t(`fact_check.ui.verdicts.${claim.verdict}`) }}
                                </span>
                                <span v-if="claim.engine">{{ claim.engine }}</span>
                                <span v-if="claim.collected_at">{{ date(claim.collected_at) }}</span>
                                <Link
                                    v-if="claim.prompt_id"
                                    :href="route('brands.prompts.show', [brand.id, claim.prompt_id])"
                                    class="text-primary no-underline"
                                >
                                    {{ $t('fact_check.ui.open_prompt') }}
                                </Link>
                            </div>
                            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-[8rem_1fr]">
                                <dt class="text-shell-muted">{{ $t('fact_check.ui.fact') }}</dt>
                                <dd>{{ claim.fact }}</dd>
                                <dt class="text-shell-muted">{{ $t('fact_check.ui.claim') }}</dt>
                                <dd>{{ claim.text }}</dd>
                                <template v-if="claim.quote">
                                    <dt class="text-shell-muted">{{ $t('fact_check.ui.quote') }}</dt>
                                    <dd>
                                        <blockquote class="border-l-2 border-line pl-3 text-shell-muted italic">
                                            {{ claim.quote }}
                                        </blockquote>
                                    </dd>
                                </template>
                                <template v-if="claim.verdict === 'contradicted'">
                                    <dt class="text-shell-muted">{{ $t('fact_check.ui.sources') }}</dt>
                                    <dd>
                                        <ul v-if="claim.sources.length" class="flex flex-col gap-1">
                                            <li v-for="source in claim.sources" :key="source.id" class="break-all">
                                                <a
                                                    v-if="/^https?:\/\//i.test(source.url)"
                                                    :href="source.url"
                                                    target="_blank"
                                                    rel="noopener noreferrer nofollow"
                                                    class="text-primary no-underline"
                                                >
                                                    {{ source.url }}
                                                </a>
                                                <span v-else>{{ source.url }}</span>
                                                <span
                                                    v-if="source.ownership === 'own'"
                                                    class="ml-2 text-xs text-shell-muted"
                                                >
                                                    {{ $t('fact_check.ui.own_source') }}
                                                </span>
                                            </li>
                                        </ul>
                                        <span v-else class="text-shell-muted">
                                            {{ $t('fact_check.ui.no_sources') }}
                                        </span>
                                    </dd>
                                </template>
                            </dl>
                        </li>
                    </ul>
                    <p v-if="claims.length >= claimLimit" class="text-xs text-shell-muted">
                        {{ $t('fact_check.ui.claims_limit', { count: claimLimit }) }}
                    </p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
