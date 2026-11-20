<script setup>
// Competitors (docs/ui-forbedring.md 7.4, U07): the rank of the brand and its active competitors over time, a
// table with visibility, change, rank and sentiment per brand, the sentiment distribution, suggested competitors
// as cards with «Add», and the competitors' aliases and domains. A competitor's color is its fixed position among
// the active competitors sorted by name (`color_index`), the same as on the dashboard and the sources page.
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import { crossFilter } from '@/Components/Brand/filters.js';
import DivergingBarChart from '@/Components/Charts/DivergingBarChart.vue';
import RankChart from '@/Components/Charts/RankChart.vue';
import { periodBuckets } from '@/Components/Charts/support/data.js';
import { formatChange, formatValue, isLowSample } from '@/Components/Charts/support/format.js';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    analysisFilters: { type: Object, required: true },
    markets: { type: Array, required: true },
    competitors: { type: Array, required: true },
    suggestions: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    chartData: { type: Object, default: null },
});

const TOP = 6;
const CATEGORIES = 6;

const pending = ref([]);
const deleting = ref(false);

const selected = computed(() => (props.filters.competitor ? String(props.filters.competitor) : null));

// The competitor filter narrows the comparison to one competitor; the brand stays as the reference (7.8).
function shownKey(key) {
    return selected.value === null || key === 'brand' || String(key) === selected.value;
}

const shownCompetitors = computed(() => props.competitors.filter((item) => shownKey(String(item.id))));

const colorIndex = computed(
    () =>
        new Map(
            props.competitors
                .filter((item) => Number.isInteger(item.color_index))
                .map((item) => [String(item.id), item.color_index]),
        ),
);

function swatch(key) {
    if (key === 'brand') {
        return { backgroundColor: 'var(--data-own)' };
    }
    const index = colorIndex.value.get(String(key));

    return {
        backgroundColor: Number.isInteger(index)
            ? `var(--data-cat-${(index % CATEGORIES) + 1})`
            : 'var(--data-neutral)',
    };
}

function brandName(key, name) {
    return key === 'brand' ? props.brand.name : (name ?? String(key));
}

const grain = computed(() => props.chartData?.grain ?? 'day');

const categories = computed(() =>
    props.chartData ? periodBuckets(props.period.from, props.period.to, grain.value) : [],
);

const tableRows = computed(() => (props.chartData?.table ?? []).filter((row) => shownKey(String(row.key))));

// The six best placed in the period (the table is sorted by rank), and always the brand itself.
const rankSeries = computed(() => {
    if (categories.value.length === 0) {
        return [];
    }
    const byKey = new Map();
    for (const row of props.chartData?.ranking ?? []) {
        for (const entity of row.entities ?? []) {
            if (!byKey.has(entity.id)) {
                byKey.set(entity.id, { name: entity.name, points: new Map() });
            }
            byKey.get(entity.id).points.set(row.period, {
                rank: entity.rank,
                n: entity.visibility?.n ?? 0,
                low_sample: Boolean(entity.visibility?.low_sample),
            });
        }
    }
    const keys = tableRows.value.slice(0, TOP).map((row) => String(row.key));
    const ordered = ['brand', ...keys.filter((key) => key !== 'brand')];

    return ordered
        .filter((key) => byKey.has(key))
        .map((key) => {
            const entity = byKey.get(key);
            const own = key === 'brand';

            return {
                key,
                label: brandName(key, entity.name),
                own: own || undefined,
                colorIndex: own ? undefined : (colorIndex.value.get(key) ?? 0),
                points: categories.value.map((period) => entity.points.get(period) ?? { rank: null, n: 0 }),
            };
        });
});

const hasRanking = computed(() => rankSeries.value.some((item) => item.points.some((point) => point.rank != null)));

// TOP limits how many series are drawn, not the ranks shown: the brand, a selected competitor or a series that
// falls below sixth place on a day keeps its line, so the axis reaches the lowest rank among the drawn series.
const rankRange = computed(() =>
    Math.max(TOP, ...rankSeries.value.flatMap((item) => item.points.map((point) => point.rank ?? 0))),
);

const sentimentRows = computed(() =>
    tableRows.value
        .filter((row) => row.sentiment?.n > 0)
        .map((row) => ({
            key: row.own ? 'own' : `competitor:${row.key}`,
            label: brandName(row.key, row.name),
            negative: row.sentiment.negative ?? 0,
            neutral: row.sentiment.neutral ?? 0,
            positive: row.sentiment.positive ?? 0,
            n: row.sentiment.n,
            low_sample: row.sentiment.low_sample,
        })),
);

const sentimentComplete = computed(() => props.chartData?.sentiment?.complete !== false);

function selectCompetitor(event) {
    const key = event?.key ?? '';
    const value = key.startsWith('competitor:') ? key.slice('competitor:'.length) : null;
    crossFilter(route('brands.competitors.index', props.brand.id), props.analysisFilters.query, 'competitor', value);
}

function changeClass(change) {
    if (change == null || Math.abs(change) < 0.0005) {
        return 'text-shell-muted';
    }

    return change > 0 ? 'text-success' : 'text-error';
}

function sentimentText(sentiment) {
    return trans('app.competitors.sentiment_split', {
        positive: formatValue(sentiment.positive, 'rate', 0),
        negative: formatValue(sentiment.negative, 'rate', 0),
    });
}

function add(suggestion) {
    if (pending.value.includes(suggestion.name)) {
        return;
    }

    pending.value = [...pending.value, suggestion.name];
    router.post(
        route('brands.competitors.store', props.brand.id),
        { name: suggestion.name },
        {
            preserveScroll: true,
            onFinish: () => {
                pending.value = pending.value.filter((name) => name !== suggestion.name);
            },
        },
    );
}

function remove(competitor) {
    if (deleting.value || !window.confirm(trans('app.library.confirm_delete_competitor', { name: competitor.name }))) {
        return;
    }

    deleting.value = true;
    router.delete(route('brands.competitors.destroy', [props.brand.id, competitor.id]), {
        preserveScroll: true,
        onFinish: () => (deleting.value = false),
    });
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
                <PeriodFilters :brand-id="brand.id" route-name="brands.competitors.index" />

                <EmptyState
                    v-if="competitors.length === 0"
                    :title="$t('app.empty.competitors_title')"
                    :body="$t('app.empty.competitors_body')"
                />

                <div
                    v-else-if="!chartData"
                    class="grid gap-6"
                    aria-busy="true"
                    :aria-label="$t('app.competitors.loading')"
                >
                    <div class="ekko-skeleton h-72 rounded-2xl"></div>
                    <div class="ekko-skeleton h-56 rounded-2xl"></div>
                </div>

                <template v-else>
                    <section
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                        data-testid="competitor-ranking"
                    >
                        <h3 class="text-sm font-medium">{{ $t('app.competitors.ranking') }}</h3>
                        <p class="mt-1 text-xs text-shell-muted">
                            {{ $t(grain === 'week' ? 'app.competitors.ranking_week' : 'app.competitors.ranking_day') }}
                        </p>
                        <p v-if="!hasRanking" class="mt-4 text-sm text-shell-muted">
                            {{ $t('app.competitors.no_ranking') }}
                        </p>
                        <RankChart
                            v-else
                            class="mt-4"
                            :label="$t('app.competitors.ranking')"
                            :categories="categories"
                            :series="rankSeries"
                            :top="rankRange"
                            :height="280"
                        />
                    </section>

                    <section
                        class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text"
                        data-testid="competitor-table"
                    >
                        <h3 class="px-4 pt-4 text-sm font-medium">{{ $t('app.competitors.table') }}</h3>
                        <table class="mt-2 w-full text-left text-sm">
                            <thead class="border-b border-line text-xs text-shell-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-medium">
                                        {{ $t('app.competitors.brand') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right font-medium">
                                        {{ $t('app.competitors.visibility') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right font-medium"
                                        :title="$t('app.competitors.change_hint')"
                                    >
                                        {{ $t('app.competitors.change') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right font-medium">
                                        {{ $t('app.competitors.rank') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 font-medium">
                                        {{ $t('app.competitors.sentiment') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in tableRows"
                                    :key="row.key"
                                    class="border-b border-line-soft last:border-0 hover:bg-row-hover"
                                    :data-competitor="row.key"
                                >
                                    <th scope="row" class="px-4 py-3 font-medium">
                                        <span class="flex items-center gap-2">
                                            <span
                                                class="size-2.5 shrink-0 rounded-sm"
                                                :style="swatch(row.key)"
                                                data-testid="competitor-swatch"
                                            ></span>
                                            <span :class="row.own ? 'font-semibold' : ''">
                                                {{ brandName(row.key, row.name) }}
                                            </span>
                                            <span v-if="row.own" class="text-xs font-normal text-shell-muted">
                                                ({{ $t('app.competitors.own') }})
                                            </span>
                                        </span>
                                    </th>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                        :class="isLowSample(row.visibility) ? 'text-shell-muted' : ''"
                                        :title="
                                            isLowSample(row.visibility) ? $t('app.dashboard.low_sample') : undefined
                                        "
                                    >
                                        {{ formatValue(row.visibility.value, 'rate') }}
                                        <span class="ms-1 text-xs text-shell-muted">
                                            {{ $t('app.charts.n', { n: row.visibility.n }) }}
                                        </span>
                                        <span v-if="isLowSample(row.visibility)" class="ms-1 text-xs text-shell-muted">
                                            · {{ $t('app.charts.low_sample') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums" :class="changeClass(row.change)">
                                        {{ formatChange(row.change, 'rate') }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        {{ row.rank ?? $t('app.charts.empty') }}
                                        <span
                                            v-if="row.previous_rank != null && row.previous_rank !== row.rank"
                                            class="ms-1 text-xs text-shell-muted"
                                        >
                                            {{ $t('app.competitors.previous_rank', { rank: row.previous_rank }) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        <span v-if="!row.sentiment" class="text-shell-muted">
                                            {{ $t('app.charts.empty') }}
                                        </span>
                                        <span v-else-if="row.sentiment.n === 0" class="text-shell-muted">
                                            {{ $t('app.competitors.no_sentiment') }}
                                        </span>
                                        <span v-else :class="row.sentiment.low_sample ? 'text-shell-muted' : ''">
                                            {{ sentimentText(row.sentiment) }}
                                            <span class="text-shell-muted">
                                                · {{ $t('app.charts.n', { n: row.sentiment.n }) }}
                                            </span>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <section
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                        data-testid="competitor-sentiment"
                    >
                        <h3 class="text-sm font-medium">{{ $t('app.competitors.sentiment') }}</h3>
                        <p v-if="!sentimentComplete" class="mt-3 text-sm text-shell-muted">
                            {{ $t('app.competitors.sentiment_missing') }}
                        </p>
                        <p v-else-if="sentimentRows.length === 0" class="mt-3 text-sm text-shell-muted">
                            {{ $t('app.dashboard.no_sentiment') }}
                        </p>
                        <DivergingBarChart
                            v-else
                            class="mt-2"
                            :label="$t('app.competitors.sentiment')"
                            :rows="sentimentRows"
                            @select="selectCompetitor"
                        />
                    </section>
                </template>

                <section
                    class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                    data-testid="competitor-suggestions"
                >
                    <h3 class="text-sm font-medium">{{ $t('app.library.suggestions') }}</h3>
                    <p class="mt-1 text-xs text-shell-muted">{{ $t('app.competitors.suggestions_hint') }}</p>
                    <EmptyState
                        v-if="suggestions.length === 0"
                        class="mt-3"
                        :title="$t('app.empty.suggestions_title')"
                        :body="$t('app.empty.suggestions_body')"
                    />
                    <ul v-else class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <li
                            v-for="suggestion in suggestions"
                            :key="suggestion.name"
                            class="flex items-center justify-between gap-3 rounded-xl border border-line bg-shell-surface p-4"
                            data-testid="competitor-suggestion"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ suggestion.name }}</span>
                                <span class="text-xs text-shell-muted">
                                    {{ $t('app.library.suggestion_count', { count: suggestion.responses }) }}
                                </span>
                            </span>
                            <button
                                v-if="canManage"
                                type="button"
                                class="shrink-0 rounded-full bg-primary px-3 py-1 text-xs font-medium text-white hover:bg-primary-deep disabled:opacity-40"
                                :aria-label="`${$t('app.library.add_competitor')}: ${suggestion.name}`"
                                :disabled="pending.includes(suggestion.name)"
                                @click="add(suggestion)"
                            >
                                {{ $t('app.competitors.add') }}
                            </button>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="competitors.length"
                    class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text"
                    data-testid="competitor-setup"
                >
                    <h3 class="px-4 pt-4 text-sm font-medium">{{ $t('app.competitors.setup') }}</h3>
                    <table class="mt-2 w-full text-left text-sm">
                        <thead class="border-b border-line text-xs text-shell-muted">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.competitors') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.aliases') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.domains') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ $t('app.library.active') }}</th>
                                <th v-if="canManage" scope="col" class="px-4 py-3">
                                    <span class="sr-only">{{ $t('actions.delete') }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="competitor in shownCompetitors"
                                :key="competitor.id"
                                class="border-b border-line-soft last:border-0 hover:bg-row-hover"
                            >
                                <td class="px-4 py-3 font-medium">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="size-2.5 shrink-0 rounded-sm"
                                            :style="swatch(String(competitor.id))"
                                        ></span>
                                        {{ competitor.name }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-shell-muted">{{ competitor.aliases.join(', ') }}</td>
                                <td class="px-4 py-3 text-shell-muted">{{ competitor.domains.join(', ') }}</td>
                                <td
                                    class="px-4 py-3"
                                    :class="competitor.is_active ? 'text-success' : 'text-shell-muted'"
                                >
                                    {{ competitor.is_active ? $t('app.library.active') : $t('app.library.inactive') }}
                                </td>
                                <td v-if="canManage" class="px-4 py-3 text-end">
                                    <button
                                        type="button"
                                        class="rounded-lg px-2 py-1 text-error hover:underline disabled:opacity-50"
                                        :aria-label="$t('actions.named.delete', { name: competitor.name })"
                                        :disabled="deleting"
                                        @click="remove(competitor)"
                                    >
                                        {{ $t('actions.delete') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
