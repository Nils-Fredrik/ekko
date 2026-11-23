<script setup>
// Sources (docs/ui-forbedring.md 7.5, U08): the most cited domains colored and labeled by ownership, the source
// gap with a link to the action generated for each gap (T43), and the citations by source type. Clicking a domain
// in a chart or in the list loads the answers that cite it (partial reload of `citing`).
import { formatDateTime } from '@/format';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import GapBarChart from '@/Components/Charts/GapBarChart.vue';
import RankedBarChart from '@/Components/Charts/RankedBarChart.vue';
import TreemapChart from '@/Components/Charts/TreemapChart.vue';
import { formatValue, isLowSample } from '@/Components/Charts/support/format.js';
import EmptyState from '@/Components/UI/EmptyState.vue';
import WorkspaceHeader from '@/Components/Shell/WorkspaceHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    analysisFilters: { type: Object, required: true },
    markets: { type: Array, required: true },
    engines: { type: Array, required: true },
    citing: { type: Object, default: null },
    chartData: { type: Object, default: null },
});

// Fixed palette key per source type, so a type keeps its color when the mix changes.
const TYPE_COLORS = {
    news: 'cat-1',
    review: 'cat-2',
    forum: 'cat-3',
    wiki: 'cat-4',
    marketplace: 'cat-5',
    social: 'cat-6',
    gov: 'seq-4',
    other: 'neutral',
    unknown: 'neutral',
};

const selectedType = ref(null);
const answersPanel = ref(null);
const loadingDomain = ref(null);

const competitors = computed(() => new Map((props.chartData?.competitors ?? []).map((item) => [item.id, item])));
const sources = computed(() => props.chartData?.sources ?? []);
const gaps = computed(() => props.chartData?.gaps ?? []);
const types = computed(() => props.chartData?.types ?? { complete: true, items: [] });

function typeLabel(key) {
    const label = trans(`app.source_types.${key}`);

    return label === `app.source_types.${key}` ? key : label;
}

// The group of a domain: own, one group per active competitor (its fixed color), or third party. A domain of an
// inactive competitor is still a competitor's, without that competitor's color.
function groupKey(source) {
    if (source.ownership === 'competitor') {
        return competitors.value.has(source.competitor_id) ? `competitor:${source.competitor_id}` : 'competitor';
    }

    return source.ownership === 'own' ? 'own' : 'third_party';
}

const groupList = computed(() => {
    const list = [{ key: 'own', label: trans('app.ownership.own'), color: 'own' }];
    for (const competitor of competitors.value.values()) {
        list.push({
            key: `competitor:${competitor.id}`,
            label: trans('app.sources.competitor_owner', { name: competitor.name }),
            color: `cat-${(competitor.color_index % 6) + 1}`,
        });
    }
    list.push({ key: 'competitor', label: trans('app.ownership.competitor'), color: 'cat-2' });
    list.push({ key: 'third_party', label: trans('app.ownership.third_party'), color: 'neutral' });

    return list;
});
const groups = computed(() => new Map(groupList.value.map((group) => [group.key, group])));

const shown = computed(() =>
    selectedType.value === null
        ? sources.value
        : sources.value.filter((source) => source.types.includes(selectedType.value)),
);

const bars = computed(() =>
    shown.value.map((source) => ({
        key: source.domain,
        label: source.domain,
        value: source.share,
        n: source.n,
        low_sample: source.low_sample,
        group: groupKey(source),
    })),
);

const gapRows = computed(() =>
    gaps.value.map((gap) => ({ key: gap.domain, label: gap.domain, own: gap.own, competitors: gap.competitors })),
);

const typeItems = computed(() =>
    types.value.items.map((item) => ({
        key: item.key,
        label: typeLabel(item.key),
        value: item.value,
        color: TYPE_COLORS[item.key] ?? 'neutral',
    })),
);

function swatch(source) {
    const color = groups.value.get(groupKey(source))?.color ?? 'neutral';

    return { backgroundColor: `var(--data-${color})` };
}

function params(domain) {
    const query = { ...props.analysisFilters.query };
    if (props.filters.market) query.market = props.filters.market;
    if (props.filters.engine) query.engine = props.filters.engine;
    if (domain) query.domain = domain;

    return query;
}

function showAnswers(domain) {
    if (!domain) {
        return;
    }
    loadingDomain.value = domain;
    router.get(route('brands.sources', props.brand.id), params(domain), {
        only: ['citing', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            loadingDomain.value = null;
        },
    });
}

function closeAnswers() {
    router.get(route('brands.sources', props.brand.id), params(null), {
        only: ['citing', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function selectType({ key }) {
    selectedType.value = selectedType.value === key ? null : key;
}

watch(
    () => props.citing?.domain,
    async (domain) => {
        if (domain) {
            await nextTick();
            answersPanel.value?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
            answersPanel.value?.focus?.({ preventScroll: true });
        }
    },
);

function when(value) {
    if (!value) {
        return '';
    }

    return formatDateTime(value);
}

// A rate with its n, and "low n" in words when the sample is small (docs/ui-forbedring.md 5).
function rate(value, item) {
    const parts = [formatValue(value, 'rate'), trans('app.charts.n', { n: item.n })];
    if (isLowSample(item)) {
        parts.push(trans('app.charts.low_sample'));
    }

    return parts.join(' · ');
}
</script>

<template>
    <AppLayout :title="$t('workspace.shell.sources')">
        <template #header>
            <WorkspaceHeader
                :brand="brand.name"
                :group="$t('workspace.shell.analysis')"
                :title="$t('workspace.shell.sources')"
            />
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <PeriodFilters :brand-id="brand.id" route-name="brands.sources" />

                <div v-if="!chartData" class="grid gap-6" aria-busy="true" :aria-label="$t('app.sources.loading')">
                    <div class="ekko-skeleton h-80 rounded-2xl"></div>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="ekko-skeleton h-64 rounded-2xl"></div>
                        <div class="ekko-skeleton h-64 rounded-2xl"></div>
                    </div>
                </div>

                <template v-else>
                    <section
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                        data-testid="sources-ranked"
                    >
                        <h3 class="text-sm font-medium">{{ $t('app.sources.ranked') }}</h3>
                        <p class="mt-1 text-xs text-shell-muted">{{ $t('app.sources.ranked_help') }}</p>
                        <p v-if="selectedType" class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                            <span>{{ $t('app.sources.filtered', { type: typeLabel(selectedType) }) }}</span>
                            <button type="button" class="text-primary" @click="selectedType = null">
                                {{ $t('app.sources.clear_filter') }}
                            </button>
                        </p>
                        <EmptyState
                            v-if="sources.length === 0"
                            class="mt-4"
                            :title="$t('app.empty.sources_title')"
                            :body="$t('app.empty.sources_body')"
                        />
                        <p v-else-if="shown.length === 0" class="mt-4 text-sm text-shell-muted">
                            {{ $t('app.sources.no_filtered') }}
                        </p>
                        <template v-else>
                            <RankedBarChart
                                class="mt-4"
                                :label="$t('app.sources.ranked')"
                                :bars="bars"
                                :groups="groupList"
                                @select="showAnswers($event.key)"
                            />
                            <ol class="mt-4 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                <li
                                    v-for="source in shown"
                                    :key="source.domain"
                                    class="flex items-center justify-between gap-3 border-b border-line-soft py-1.5"
                                >
                                    <button
                                        type="button"
                                        class="flex min-w-0 items-center gap-2 text-left text-shell-text hover:text-primary"
                                        :aria-label="$t('app.sources.show_answers', { domain: source.domain })"
                                        :aria-pressed="citing?.domain === source.domain"
                                        data-testid="source-domain"
                                        @click="showAnswers(source.domain)"
                                    >
                                        <span class="size-2.5 shrink-0 rounded-sm" :style="swatch(source)"></span>
                                        <span class="truncate">{{ source.domain }}</span>
                                    </button>
                                    <span class="flex shrink-0 items-center gap-3 text-xs text-shell-muted">
                                        <span>{{ groups.get(groupKey(source))?.label }}</span>
                                        <span class="text-shell-text tabular-nums">
                                            {{ rate(source.share, source) }}
                                        </span>
                                    </span>
                                </li>
                            </ol>
                        </template>
                    </section>

                    <section
                        v-if="citing"
                        ref="answersPanel"
                        tabindex="-1"
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text outline-none"
                        data-testid="source-answers"
                        :aria-busy="loadingDomain !== null"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-medium">
                                    {{ $t('app.sources.answers_title', { domain: citing.domain }) }}
                                </h3>
                                <p v-if="citing.total" class="mt-1 text-xs text-shell-muted">
                                    {{
                                        $t('app.sources.answers_count', {
                                            shown: citing.answers.length,
                                            total: citing.total,
                                        })
                                    }}
                                </p>
                            </div>
                            <button type="button" class="text-sm text-primary" @click="closeAnswers">
                                {{ $t('app.sources.close') }}
                            </button>
                        </div>
                        <p v-if="citing.answers.length === 0" class="mt-4 text-sm text-shell-muted">
                            {{ $t('app.sources.answers_empty', { domain: citing.domain }) }}
                        </p>
                        <ul v-else class="mt-4 flex flex-col divide-y divide-line-soft">
                            <li v-for="answer in citing.answers" :key="answer.id" class="py-3">
                                <Link
                                    v-if="answer.prompt"
                                    :href="answer.prompt.href"
                                    class="text-sm text-shell-text no-underline hover:text-primary"
                                >
                                    {{ answer.prompt.text }}
                                </Link>
                                <p class="mt-1 flex flex-wrap gap-x-2 text-xs text-shell-muted">
                                    <span>{{ answer.engine }}</span>
                                    <span v-if="answer.market">· {{ answer.market }}</span>
                                    <span>· {{ when(answer.collected_at) }}</span>
                                    <span>
                                        ·
                                        {{
                                            answer.brand_mentioned
                                                ? $t('app.sources.brand_named')
                                                : $t('app.sources.brand_not_named')
                                        }}
                                    </span>
                                </p>
                                <ul v-if="answer.urls.length" class="mt-1 flex flex-col gap-0.5 text-xs">
                                    <li v-for="url in answer.urls" :key="url" class="truncate">
                                        <a
                                            :href="url"
                                            target="_blank"
                                            rel="noopener noreferrer nofollow"
                                            class="text-shell-muted no-underline hover:text-primary"
                                        >
                                            {{ url }}
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </section>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <section
                            class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                            data-testid="sources-gap"
                        >
                            <h3 class="text-sm font-medium">{{ $t('app.sources.gap') }}</h3>
                            <p class="mt-1 text-xs text-shell-muted">{{ $t('app.sources.gap_help') }}</p>
                            <EmptyState
                                v-if="gaps.length === 0"
                                class="mt-4"
                                :title="$t('app.empty.gap_title')"
                                :body="$t('app.empty.gap_body')"
                            />
                            <template v-else>
                                <GapBarChart
                                    class="mt-4"
                                    :label="$t('app.sources.gap')"
                                    :rows="gapRows"
                                    @select="showAnswers($event.key)"
                                />
                                <ul class="mt-4 flex flex-col divide-y divide-line-soft text-sm">
                                    <li
                                        v-for="gap in gaps"
                                        :key="gap.domain"
                                        class="flex flex-wrap items-center justify-between gap-3 py-2"
                                    >
                                        <div class="min-w-0">
                                            <button
                                                type="button"
                                                class="truncate text-left text-shell-text hover:text-primary"
                                                :aria-label="$t('app.sources.show_answers', { domain: gap.domain })"
                                                @click="showAnswers(gap.domain)"
                                            >
                                                {{ gap.domain }}
                                            </button>
                                            <p class="text-xs text-shell-muted">
                                                {{ $t('app.sources.gap_answers', { count: gap.gap_responses }) }} ·
                                                <span class="tabular-nums">{{ rate(gap.gap_rate, gap) }}</span>
                                            </p>
                                        </div>
                                        <Link
                                            v-if="gap.action"
                                            :href="gap.action.href"
                                            class="shrink-0 rounded-lg border border-line px-3 py-1.5 text-xs text-primary no-underline"
                                            data-testid="gap-action"
                                        >
                                            {{ $t('app.sources.open_action') }} ·
                                            {{ $t(`action_items.statuses.${gap.action.status}`) }}
                                        </Link>
                                        <span v-else class="shrink-0 text-xs text-shell-muted">
                                            {{ $t('app.sources.no_action') }}
                                        </span>
                                    </li>
                                </ul>
                            </template>
                        </section>

                        <section
                            class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                            data-testid="sources-types"
                        >
                            <h3 class="text-sm font-medium">{{ $t('app.sources.types') }}</h3>
                            <p class="mt-1 text-xs text-shell-muted">{{ $t('app.sources.types_help') }}</p>
                            <p v-if="!types.complete" class="mt-4 text-sm text-shell-muted">
                                {{ $t('app.sources.types_incomplete') }}
                            </p>
                            <EmptyState
                                v-else-if="typeItems.length === 0"
                                class="mt-4"
                                :title="$t('app.empty.sources_title')"
                                :body="$t('app.empty.sources_body')"
                            />
                            <TreemapChart
                                v-else
                                class="mt-4"
                                :label="$t('app.sources.types')"
                                :items="typeItems"
                                @select="selectType"
                            />
                        </section>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
