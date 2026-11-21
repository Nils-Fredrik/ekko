<script setup>
// The overview (docs/ui-forbedring.md 7.1, U10): the insight line first, one card per brand with minigraph,
// change and open actions, «Hva har skjedd» as one timeline, and usage as progress against the quota.
import { formatDate, formatDateTime } from '@/format';
import InsightLine from '@/Components/Dashboard/InsightLine.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const page = usePage();

const props = defineProps({
    period: { type: Object, required: true },
    brands: { type: Array, required: true },
    timeline: { type: Array, required: true },
    runs: { type: Array, required: true },
    usage: { type: Object, default: null },
    botLogUploadsUrl: { type: String, default: null },
    chartData: { type: Array, default: null },
    insight: { type: Object, default: null },
});

function brandData(brandId) {
    return props.chartData?.find((row) => row.brand_id === brandId) ?? null;
}

function sparkline(brandId) {
    return (brandData(brandId)?.series ?? []).map((row) => ({
        x: row.period,
        value: row.visibility?.value ?? null,
        n: row.visibility?.n ?? 0,
        low_sample: Boolean(row.visibility?.low_sample),
    }));
}

function when(value, dateOnly = false) {
    return dateOnly ? formatDate(value) : formatDateTime(value);
}

function eventText(item) {
    const params = {
        alert: { brand: item.brand, type: item.title },
        model_change: { engine: item.title, change: item.detail },
        action_done: { brand: item.brand, title: item.title },
        competitor: { brand: item.brand, name: item.title },
    }[item.kind];

    return trans(`app.timeline.${item.kind}`, params ?? {});
}

// The fill colour of a progress bar follows the usage status; the status is also written out in words.
const statusBar = { ok: 'bg-primary', warning: 'bg-warning', capped: 'bg-error' };

function share(used, limit) {
    const total = Number(limit);
    if (!total) {
        return null;
    }

    return Math.min(100, Math.max(0, (Number(used) / total) * 100));
}

const quotaShare = computed(() => (props.usage ? share(props.usage.calls, props.usage.quota_calls) : null));
const budgetShare = computed(() => (props.usage ? share(props.usage.cost_usd, props.usage.budget_usd) : null));
</script>

<template>
    <AppLayout :title="$t('app.dashboard.overview')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">
                {{ $t('app.dashboard.overview') }}
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <InsightLine :insight="insight" />
                <p class="text-sm text-shell-muted">{{ $t('app.dashboard.period_days', { days: period.days }) }}</p>

                <Link v-if="botLogUploadsUrl" :href="botLogUploadsUrl" class="text-sm text-shell-accent underline">
                    {{ $t('bot_logs.title') }}
                </Link>

                <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" :aria-label="$t('app.dashboard.brands')">
                    <EmptyState
                        v-if="brands.length === 0"
                        class="md:col-span-2 xl:col-span-3"
                        :title="$t('app.empty.brands_title')"
                        :body="$t('app.empty.brands_body')"
                        :href="page.props.canOnboard ? route('onboarding.wizard') : ''"
                        :link="page.props.canOnboard ? $t('app.empty.wizard_link') : ''"
                    />
                    <Link
                        v-for="brand in brands"
                        :key="brand.id"
                        :href="route('brands.show', brand.id)"
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text no-underline"
                        data-testid="brand-card"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="text-lg font-medium tracking-tight">{{ brand.name }}</h3>
                            <span class="text-xs text-shell-muted">{{ brand.status }}</span>
                        </div>
                        <p class="mt-1 text-sm text-shell-muted">
                            {{ brand.markets.length ? brand.markets.join(', ') : $t('app.dashboard.no_markets') }}
                        </p>
                        <KpiCard
                            class="mt-4"
                            size="secondary"
                            :framed="false"
                            :label="$t('app.dashboard.visibility')"
                            :metric="brand.visibility"
                            :previous="brand.previous_visibility"
                            :points="sparkline(brand.id)"
                        />
                        <p class="mt-3 border-t border-line pt-3 text-sm text-shell-muted" data-testid="brand-actions">
                            <span v-if="brandData(brand.id)">
                                {{ $t('app.dashboard.open_actions', { count: brandData(brand.id).open_actions }) }}
                            </span>
                            <span v-else class="ekko-skeleton inline-block h-4 w-28 rounded"></span>
                        </p>
                    </Link>
                </section>

                <section class="grid gap-4 lg:grid-cols-3">
                    <div
                        class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text lg:col-span-2"
                        data-testid="timeline"
                    >
                        <h3 class="text-sm font-medium">{{ $t('app.dashboard.what_happened') }}</h3>
                        <EmptyState
                            v-if="timeline.length === 0"
                            class="mt-3"
                            :title="$t('app.timeline.empty_title')"
                            :body="$t('app.timeline.empty_body')"
                        />
                        <ol v-else class="mt-3 flex flex-col">
                            <li
                                v-for="item in timeline"
                                :key="item.key"
                                class="flex gap-3 border-b border-line py-2.5 text-sm last:border-0"
                                :data-kind="item.kind"
                            >
                                <span
                                    class="mt-0.5 h-fit shrink-0 rounded-full border border-line px-2 py-0.5 text-xs text-shell-muted"
                                >
                                    {{ $t(`app.timeline.kinds.${item.kind}`) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <Link v-if="item.href" :href="item.href" class="text-shell-text no-underline">
                                        {{ eventText(item) }}
                                    </Link>
                                    <span v-else>{{ eventText(item) }}</span>
                                    <time class="block text-xs text-shell-muted" :datetime="item.at">
                                        {{ when(item.at, item.date_only) }}
                                    </time>
                                </span>
                            </li>
                        </ol>
                    </div>

                    <div class="flex flex-col gap-4">
                        <div
                            v-if="usage"
                            class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                            data-testid="usage"
                        >
                            <h3 class="text-sm font-medium">{{ $t('app.dashboard.usage') }}</h3>
                            <p class="mt-3 text-sm">
                                {{
                                    usage.quota_calls
                                        ? $t('app.dashboard.usage_calls', {
                                              calls: usage.calls,
                                              quota: usage.quota_calls,
                                          })
                                        : $t('app.dashboard.usage_calls_open', { calls: usage.calls })
                                }}
                            </p>
                            <div
                                v-if="quotaShare !== null"
                                class="mt-2 h-2 overflow-hidden rounded-full bg-shell-surface"
                                role="progressbar"
                                :aria-label="$t('app.dashboard.usage_quota')"
                                aria-valuemin="0"
                                :aria-valuemax="usage.quota_calls"
                                :aria-valuenow="Math.min(usage.calls, usage.quota_calls)"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="statusBar[usage.status] ?? 'bg-primary'"
                                    :style="{ width: `${quotaShare}%` }"
                                ></div>
                            </div>
                            <template v-if="usage.budget_usd">
                                <p class="mt-3 text-sm text-shell-muted">
                                    {{
                                        $t('app.dashboard.usage_cost', {
                                            cost: usage.cost_usd,
                                            budget: usage.budget_usd,
                                        })
                                    }}
                                </p>
                                <div
                                    v-if="budgetShare !== null"
                                    class="mt-2 h-2 overflow-hidden rounded-full bg-shell-surface"
                                    role="progressbar"
                                    :aria-label="$t('app.dashboard.usage_budget')"
                                    aria-valuemin="0"
                                    :aria-valuemax="Number(usage.budget_usd)"
                                    :aria-valuenow="Math.min(Number(usage.cost_usd), Number(usage.budget_usd))"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="statusBar[usage.status] ?? 'bg-primary'"
                                        :style="{ width: `${budgetShare}%` }"
                                    ></div>
                                </div>
                            </template>
                            <p class="mt-3 text-xs text-shell-muted">
                                {{
                                    $t('app.dashboard.usage_status', {
                                        status: $t(`app.workspace.status_${usage.status}`),
                                    })
                                }}
                            </p>
                        </div>

                        <div class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text">
                            <h3 class="text-sm font-medium">{{ $t('app.dashboard.runs') }}</h3>
                            <EmptyState
                                v-if="runs.length === 0"
                                class="mt-3"
                                :title="$t('app.empty.runs_title')"
                                :body="$t('app.empty.runs_body')"
                            />
                            <ul v-else class="mt-3 flex flex-col gap-2 text-sm">
                                <li v-for="run in runs" :key="run.id">
                                    {{ $t('app.dashboard.run', { brand: run.brand, market: run.market }) }}
                                    <span class="block text-xs text-shell-muted">{{ when(run.scheduled_for) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
