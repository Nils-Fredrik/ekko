<script setup>
import BottomSheet from '@/Components/UI/BottomSheet.vue';
import MetricValue from '@/Components/Dashboard/MetricValue.vue';
import TrendChart from '@/Components/Dashboard/TrendChart.vue';
import VoiceChart from '@/Components/Dashboard/VoiceChart.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    summary: { type: Object, required: true },
    previous: { type: Object, required: true },
    series: { type: Array, required: true },
    voice: { type: Array, required: true },
    engines: { type: Array, required: true },
    modelChanges: { type: Array, required: true },
});

const days = ref(props.period.days);
const market = ref(props.filters.market ?? '');
const engine = ref(props.filters.engine ?? '');
const topic = ref(props.filters.topic ?? '');
const intent = ref(props.filters.intent ?? '');
const filtersOpen = ref(false);
const lengths = [7, 28, 90];

const cards = [
    ['visibility', 'visibility', 'rate'],
    ['recommendation_rate', 'recommendation_rate', 'rate'],
    ['share_of_voice', 'share_of_voice', 'rate'],
    ['average_position', 'average_position', 'number'],
    ['first_place_rate', 'first_place_rate', 'rate'],
    ['citation_rate', 'citation_rate', 'rate'],
    ['sentiment', 'sentiment', 'number'],
];

function apply() {
    const params = {};
    if (days.value) params.days = days.value;
    if (market.value) params.market = market.value;
    if (engine.value) params.engine = engine.value;
    if (topic.value) params.topic = topic.value;
    if (intent.value) params.intent = intent.value;

    filtersOpen.value = false;
    router.get(route('brands.show', props.brand.id), params, { preserveScroll: true, replace: true });
}

function when(value) {
    return new Intl.DateTimeFormat(document.documentElement.lang || 'nb', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}

function shareText(share) {
    if (share === null || share === undefined) {
        return null;
    }

    return new Intl.NumberFormat(document.documentElement.lang || 'nb', {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(share);
}
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <div class="hidden flex-wrap items-end gap-3 sm:flex">
                    <label class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.period') }}
                        <select
                            v-model="days"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                            @change="apply"
                        >
                            <option v-for="length in lengths" :key="length" :value="length">
                                {{ $t('app.dashboard.period_days', { days: length }) }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.market') }}
                        <select
                            v-model="market"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                            @change="apply"
                        >
                            <option value="">{{ $t('app.dashboard.all_markets') }}</option>
                            <option v-for="item in options.markets" :key="item.id" :value="item.id">
                                {{ item.label }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.engine') }}
                        <select
                            v-model="engine"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                            @change="apply"
                        >
                            <option value="">{{ $t('app.dashboard.all_engines') }}</option>
                            <option v-for="item in options.engines" :key="item.key" :value="item.key">
                                {{ item.name }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.topic') }}
                        <select
                            v-model="topic"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                            @change="apply"
                        >
                            <option value="">{{ $t('app.dashboard.all_topics') }}</option>
                            <option v-for="item in options.topics" :key="item.id" :value="item.id">
                                {{ item.name }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-shell-text/70">
                        {{ $t('app.dashboard.intent') }}
                        <select
                            v-model="intent"
                            class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink"
                            @change="apply"
                        >
                            <option value="">{{ $t('app.dashboard.all_intents') }}</option>
                            <option v-for="item in options.intents" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </select>
                    </label>
                </div>

                <button
                    type="button"
                    class="rounded-lg border border-line bg-white px-4 py-2 text-sm text-ink sm:hidden"
                    @click="filtersOpen = true"
                >
                    {{ $t('app.dashboard.open_filters') }}
                </button>

                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="[key, label, kind] in cards"
                        :key="key"
                        class="rounded-2xl border border-line bg-white p-4"
                    >
                        <p class="text-xs text-ink-soft">{{ $t(`app.dashboard.${label}`) }}</p>
                        <MetricValue class="mt-2 block" :metric="summary[key]" :kind="kind" :previous="previous[key]" />
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-white p-5 text-ink">
                    <h3 class="text-sm font-medium">{{ $t('app.dashboard.trend') }}</h3>
                    <p v-if="series.length === 0" class="mt-4 text-sm text-ink-soft">
                        {{ $t('app.dashboard.no_trend') }}
                    </p>
                    <TrendChart v-else class="mt-4" :points="series" />
                </section>

                <section class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-2xl border border-line bg-white p-5 text-ink">
                        <h3 class="text-sm font-medium">{{ $t('app.dashboard.voice') }}</h3>
                        <p v-if="voice.every((part) => !part.mentions)" class="mt-4 text-sm text-ink-soft">
                            {{ $t('app.dashboard.no_voice') }}
                        </p>
                        <template v-else>
                            <VoiceChart class="mt-4" :parts="voice" />
                            <ul class="mt-3 flex flex-col gap-1 text-xs text-ink-soft">
                                <li v-for="part in voice" :key="part.id" :class="part.low_sample ? 'opacity-40' : ''">
                                    {{ part.name }} · {{ shareText(part.share) ?? $t('app.dashboard.empty') }} ·
                                    {{ $t('app.dashboard.n', { n: part.n }) }}
                                </li>
                            </ul>
                        </template>
                    </div>
                    <div class="rounded-2xl border border-line bg-white p-5 text-ink">
                        <h3 class="text-sm font-medium">{{ $t('app.dashboard.engines') }}</h3>
                        <p v-if="engines.length === 0" class="mt-4 text-sm text-ink-soft">
                            {{ $t('app.dashboard.no_engines') }}
                        </p>
                        <ul v-else class="mt-4 flex flex-col gap-3">
                            <li v-for="item in engines" :key="item.key">
                                <p class="text-sm">{{ item.name }}</p>
                                <MetricValue :metric="item.visibility" />
                            </li>
                        </ul>
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-white p-5 text-ink">
                    <h3 class="text-sm font-medium">{{ $t('app.dashboard.model_changes') }}</h3>
                    <p v-if="modelChanges.length === 0" class="mt-3 text-sm text-ink-soft">
                        {{ $t('app.dashboard.no_model_changes') }}
                    </p>
                    <ul v-else class="mt-3 flex flex-col gap-2 text-sm">
                        <li v-for="change in modelChanges" :key="`${change.engine_key}-${change.changed_on}`">
                            {{
                                $t('app.dashboard.model_change', {
                                    engine: change.engine,
                                    from_model: change.from_model,
                                    to_model: change.to_model,
                                })
                            }}
                            <span class="block text-xs text-ink-soft">{{ when(change.changed_on) }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <BottomSheet :show="filtersOpen" :title="$t('app.dashboard.filters')" @close="filtersOpen = false">
            <div class="flex flex-col gap-3 py-2">
                <label class="flex flex-col gap-1 text-xs text-ink-soft">
                    {{ $t('app.dashboard.period') }}
                    <select v-model="days" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option v-for="length in lengths" :key="length" :value="length">
                            {{ $t('app.dashboard.period_days', { days: length }) }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs text-ink-soft">
                    {{ $t('app.dashboard.market') }}
                    <select v-model="market" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('app.dashboard.all_markets') }}</option>
                        <option v-for="item in options.markets" :key="item.id" :value="item.id">
                            {{ item.label }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs text-ink-soft">
                    {{ $t('app.dashboard.engine') }}
                    <select v-model="engine" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('app.dashboard.all_engines') }}</option>
                        <option v-for="item in options.engines" :key="item.key" :value="item.key">
                            {{ item.name }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs text-ink-soft">
                    {{ $t('app.dashboard.topic') }}
                    <select v-model="topic" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('app.dashboard.all_topics') }}</option>
                        <option v-for="item in options.topics" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs text-ink-soft">
                    {{ $t('app.dashboard.intent') }}
                    <select v-model="intent" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ $t('app.dashboard.all_intents') }}</option>
                        <option v-for="item in options.intents" :key="item.value" :value="item.value">
                            {{ item.label }}
                        </option>
                    </select>
                </label>
                <button
                    type="button"
                    class="rounded-full bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-deep"
                    @click="apply"
                >
                    {{ $t('app.dashboard.apply') }}
                </button>
            </div>
        </BottomSheet>
    </AppLayout>
</template>
