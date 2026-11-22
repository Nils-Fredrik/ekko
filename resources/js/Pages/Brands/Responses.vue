<script setup>
// The answers behind the sentiment on the competitors page (U28): the brand's or one competitor's mentions with a
// negative, neutral or positive sentiment in the page's period and filters. The counts on the tabs are the same
// mentions the competitors table counts as n.
import { formatDateTime, formatDecimal, formatNumber } from '@/format';
import AnswerText from '@/Components/Brand/AnswerText.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    analysisFilters: { type: Object, required: true },
    entity: { type: Object, required: true },
    entities: { type: Array, required: true },
    sentiment: { type: String, default: null },
    counts: { type: Object, required: true },
    answers: { type: Array, required: true },
    pagination: { type: Object, required: true },
});

const SENTIMENTS = ['negative', 'neutral', 'positive'];

const params = computed(() => ({
    entity: props.entity.key === 'brand' ? undefined : props.entity.key,
    sentiment: props.sentiment ?? undefined,
}));

function url(changes = {}) {
    const query = { ...props.analysisFilters.query, ...params.value, ...changes };
    for (const key of Object.keys(query)) {
        if (query[key] === undefined || query[key] === null || query[key] === '') {
            delete query[key];
        }
    }

    return route('brands.responses.index', { brand: props.brand.id, ...query });
}

const tabs = computed(() => [
    { key: null, label: 'app.responses.all', count: props.counts.total },
    ...SENTIMENTS.map((key) => ({ key, label: `app.charts.sentiment.${key}`, count: props.counts[key] })),
]);

const competitorsUrl = computed(() =>
    route('brands.competitors.index', { brand: props.brand.id, ...props.analysisFilters.query }),
);

function selectEntity(event) {
    router.get(url({ entity: event.target.value === 'brand' ? undefined : event.target.value, page: undefined }));
}

// The excerpt as answer segments, with the mention highlighted as on the prompt page. An ellipsis marks where
// the excerpt cuts the answer.
function excerptSegments(excerpt) {
    return [
        { text: (excerpt.cut_start ? '… ' : '') + excerpt.before, kind: 'plain' },
        { text: excerpt.match, kind: props.entity.own ? 'brand' : 'competitor' },
        { text: excerpt.after + (excerpt.cut_end ? ' …' : ''), kind: 'plain' },
    ].filter((part) => part.text !== '');
}

function score(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return formatDecimal(value, 1, { signDisplay: 'exceptZero' });
}

function scoreClass(value) {
    if (value < 0) {
        return 'bg-error/10 text-error';
    }
    if (value > 0) {
        return 'bg-success/10 text-success';
    }

    return 'bg-shell-surface text-shell-muted';
}
</script>

<template>
    <AppLayout :title="$t('app.responses.title', { name: entity.name })">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <PeriodFilters :brand-id="brand.id" route-name="brands.responses.index" :params="params" />

                <section class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium">{{ $t('app.responses.title', { name: entity.name }) }}</h3>
                            <p class="mt-1 max-w-3xl text-sm text-shell-muted">{{ $t('app.responses.explain') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <label class="flex items-center gap-2">
                                <span class="text-shell-muted">{{ $t('app.responses.entity') }}</span>
                                <select
                                    class="rounded-lg border-line bg-shell-surface py-1 text-sm text-shell-text"
                                    :value="entity.key"
                                    data-testid="responses-entity"
                                    @change="selectEntity"
                                >
                                    <option v-for="item in entities" :key="item.key" :value="item.key">
                                        {{ item.own ? $t('app.responses.own', { name: item.name }) : item.name }}
                                    </option>
                                </select>
                            </label>
                            <Link :href="competitorsUrl" class="text-primary no-underline">
                                {{ $t('app.responses.back') }}
                            </Link>
                        </div>
                    </div>

                    <nav
                        class="mt-4 flex flex-wrap gap-2"
                        :aria-label="$t('app.competitors.sentiment')"
                        data-testid="responses-tabs"
                    >
                        <Link
                            v-for="tab in tabs"
                            :key="tab.key ?? 'all'"
                            :href="url({ sentiment: tab.key ?? undefined, page: undefined })"
                            class="rounded-full border px-3 py-1 text-sm no-underline"
                            :class="
                                (sentiment ?? null) === tab.key
                                    ? 'border-primary bg-primary/10 text-shell-text'
                                    : 'border-line text-shell-muted hover:text-shell-text'
                            "
                            :aria-current="(sentiment ?? null) === tab.key ? 'page' : undefined"
                            :data-sentiment="tab.key ?? 'all'"
                        >
                            {{ $t(tab.label) }}
                            <span class="ms-1 tabular-nums">{{ formatNumber(tab.count) }}</span>
                        </Link>
                    </nav>
                </section>

                <EmptyState
                    v-if="answers.length === 0"
                    :title="$t('app.responses.empty_title')"
                    :body="$t('app.responses.empty_body')"
                />

                <article
                    v-for="answer in answers"
                    :key="answer.id"
                    class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                    data-testid="responses-answer"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <Link
                            v-if="answer.prompt"
                            :href="answer.prompt.url"
                            class="font-medium text-shell-text no-underline hover:text-primary"
                        >
                            {{ answer.prompt.text }}
                        </Link>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium tabular-nums"
                            :class="scoreClass(answer.sentiment)"
                            :title="$t('app.responses.score_hint')"
                        >
                            {{ $t('app.responses.score', { score: score(answer.sentiment) }) }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-shell-muted">
                        {{ answer.engine }} · {{ formatDateTime(answer.collected_at) }}
                        <span v-if="answer.recommended">· {{ $t('app.responses.recommended') }}</span>
                    </p>

                    <AnswerText
                        class="mt-3"
                        :segments="excerptSegments(answer.excerpt)"
                        data-testid="responses-excerpt"
                    />

                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-primary">{{ $t('app.responses.full') }}</summary>
                        <div class="mt-3">
                            <AnswerText :segments="answer.segments" />
                            <a
                                v-if="answer.raw"
                                :href="answer.raw"
                                class="mt-3 inline-block text-xs text-primary no-underline"
                            >
                                {{ $t('app.library.raw') }}
                            </a>
                        </div>
                    </details>
                </article>

                <nav
                    v-if="pagination.last > 1"
                    class="flex items-center justify-between gap-3 text-sm"
                    :aria-label="$t('app.responses.pages')"
                >
                    <Link
                        v-if="pagination.page > 1"
                        :href="url({ page: pagination.page - 1 })"
                        class="text-primary no-underline"
                    >
                        {{ $t('app.responses.previous') }}
                    </Link>
                    <span v-else></span>
                    <span class="text-shell-muted">
                        {{
                            $t('app.responses.showing', {
                                from: formatNumber(pagination.from),
                                to: formatNumber(pagination.to),
                                total: formatNumber(pagination.total),
                            })
                        }}
                    </span>
                    <Link
                        v-if="pagination.page < pagination.last"
                        :href="url({ page: pagination.page + 1 })"
                        class="text-primary no-underline"
                    >
                        {{ $t('app.responses.next') }}
                    </Link>
                    <span v-else></span>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
