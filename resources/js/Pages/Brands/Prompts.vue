<script setup>
import { crossFilter } from '@/Components/Brand/filters.js';
import BrandNav from '@/Components/Brand/BrandNav.vue';
import PeriodFilters from '@/Components/Brand/PeriodFilters.vue';
import DotRowChart from '@/Components/Charts/DotRowChart.vue';
import HeatmapChart from '@/Components/Charts/HeatmapChart.vue';
import RankedBarChart from '@/Components/Charts/RankedBarChart.vue';
import SparklineChart from '@/Components/Charts/SparklineChart.vue';
import { periodBuckets } from '@/Components/Charts/support/data.js';
import MetricValue from '@/Components/Dashboard/MetricValue.vue';
import InputError from '@/Components/InputError.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    period: { type: Object, required: true },
    filters: { type: Object, required: true },
    analysisFilters: { type: Object, required: true },
    chartData: { type: Object, default: null },
    markets: { type: Array, required: true },
    prompts: { type: Array, required: true },
    intents: { type: Array, required: true },
    priorities: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    importResult: { type: Object, default: null },
    personalData: { type: Boolean, default: false },
});

function selectTopic(event) {
    if (event?.key)
        crossFilter(route('brands.prompts.index', props.brand.id), props.analysisFilters.query, 'topic', event.key);
}

const view = ref('heatmap');

const heatRows = computed(() =>
    [...props.prompts]
        .sort(
            (left, right) =>
                (left.topic || '\uffff').localeCompare(right.topic || '\uffff') || left.text.localeCompare(right.text),
        )
        .map((prompt) => ({
            key: String(prompt.id),
            label: prompt.text,
            group: prompt.topic || trans('app.library.no_topic'),
        })),
);

const heatColumns = computed(() =>
    (props.chartData?.engines ?? []).map((engine) => ({ key: engine.key, label: engine.name })),
);

const heatCells = computed(() => {
    const ids = new Set(props.prompts.map((prompt) => String(prompt.id)));

    return (props.chartData?.heatmap ?? [])
        .filter((cell) => ids.has(String(cell.prompt_id)))
        .map((cell) => ({
            row: String(cell.prompt_id),
            column: cell.engine_key,
            value: cell.visibility?.value ?? null,
            n: cell.visibility?.n ?? 0,
            low_sample: Boolean(cell.visibility?.low_sample),
        }));
});

function isoShift(iso, days) {
    const [year, month, day] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

function metricPoint(metric) {
    return {
        value: metric?.value ?? null,
        n: metric?.n ?? 0,
        low_sample: Boolean(metric?.low_sample),
    };
}

function sparkline(promptId) {
    const range = props.chartData?.sparkline;
    if (!range?.from || !range?.to) {
        return [];
    }
    const byDay = new Map(
        (props.chartData?.sparklines ?? [])
            .filter((row) => row.prompt_id === promptId)
            .map((row) => [row.period, row.visibility]),
    );

    return periodBuckets(range.from, range.to).map((day) => ({ x: day, ...metricPoint(byDay.get(day)) }));
}

function dots(promptId) {
    if (!props.period?.to || !props.chartData) {
        return [];
    }
    const byDay = new Map();
    for (const row of props.chartData.stability ?? []) {
        if (row.prompt_id !== promptId) {
            continue;
        }
        const current = byDay.get(row.period) ?? { mentioned: false, n: 0 };
        current.n += row.n ?? 0;
        current.mentioned = current.mentioned || row.value === 1;
        byDay.set(row.period, current);
    }

    return periodBuckets(isoShift(props.period.to, -13), props.period.to).map((day) => {
        const row = byDay.get(day);
        if (!row || row.n === 0) {
            return { x: day, value: null, n: 0, low_sample: true };
        }

        return { x: day, value: row.mentioned ? 1 : 0, n: row.n, low_sample: row.n < 10 };
    });
}

function hasSparkline(promptId) {
    return sparkline(promptId).some((point) => point.value !== null);
}

function openCell(event) {
    if (!event?.key || !event?.column) {
        return;
    }
    router.get(route('brands.prompts.show', [props.brand.id, event.key]), {
        ...props.analysisFilters.query,
        engine: event.column,
    });
}

const editing = ref(null);
const form = useForm({ text: '', intent: '', priority: '', is_active: true });
const toggleError = ref({ id: null, message: '' });

const personal = computed(
    () => /[^\s@]+@[^\s@]+\.[^\s@]+/.test(form.text) || /(?:\+|00)?\d(?:[\s-]?\d){7,}/.test(form.text),
);

function edit(prompt) {
    editing.value = prompt.id;
    form.clearErrors();
    form.text = prompt.text;
    form.intent = prompt.intent;
    form.priority = prompt.priority;
    form.is_active = prompt.is_active;
}

function save(prompt) {
    form.patch(route('brands.prompts.update', [props.brand.id, prompt.id]), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}

function toggle(prompt) {
    toggleError.value = { id: null, message: '' };
    router.patch(
        route('brands.prompts.update', [props.brand.id, prompt.id]),
        { is_active: !prompt.is_active },
        {
            preserveScroll: true,
            onError: (errors) => {
                toggleError.value = { id: prompt.id, message: errors.is_active || errors.text || '' };
            },
        },
    );
}

function importCsv(event) {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }
    router.post(
        route('brands.prompts.import', props.brand.id),
        { file },
        { forceFormData: true, preserveScroll: true },
    );
    event.target.value = '';
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
                <PeriodFilters :brand-id="brand.id" route-name="brands.prompts.index" />

                <RankedBarChart
                    v-if="chartData?.topics?.length"
                    :label="$t('filters.topics_chart')"
                    :bars="chartData.topics"
                    :groups="[{ key: 'own', label: brand.name, color: 'own' }]"
                    kind="rate"
                    @select="selectTopic"
                />

                <div v-if="canManage" class="flex flex-wrap items-center gap-3">
                    <a
                        :href="route('brands.prompts.export', brand.id)"
                        class="rounded-full border border-line px-4 py-2 text-sm no-underline"
                    >
                        {{ $t('app.library.export') }}
                    </a>
                    <label
                        class="rounded-full bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-deep"
                    >
                        {{ $t('app.library.import') }}
                        <input type="file" accept=".csv,text/csv" class="hidden" @change="importCsv" />
                    </label>
                </div>

                <p v-if="importResult" class="text-sm text-shell-text">
                    {{ $t('app.library.imported', { created: importResult.created, skipped: importResult.skipped }) }}
                </p>
                <ul v-if="importResult?.errors?.length" class="text-sm text-error">
                    <li v-for="(error, index) in importResult.errors" :key="index">{{ error }}</li>
                </ul>
                <ul
                    v-if="importResult?.warnings?.length"
                    class="border-s-4 border-warning ps-3 text-sm text-shell-text"
                >
                    <li v-for="(warning, index) in importResult.warnings" :key="index">{{ warning }}</li>
                </ul>
                <p v-if="personalData" class="border-s-4 border-warning ps-3 text-sm text-shell-text">
                    {{ $t('app.library.personal_data') }}
                </p>

                <EmptyState
                    v-if="prompts.length === 0"
                    :title="$t('app.empty.prompts_title')"
                    :body="$t('app.empty.prompts_body')"
                />
                <template v-else>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="rounded-md px-2 py-1 text-xs text-shell-muted hover:bg-shell-surface hover:text-shell-text focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :aria-pressed="view === 'table' ? 'true' : 'false'"
                            @click="view = view === 'heatmap' ? 'table' : 'heatmap'"
                        >
                            {{ view === 'heatmap' ? $t('app.library.show_table') : $t('app.library.show_heatmap') }}
                        </button>
                    </div>
                    <HeatmapChart
                        v-if="view === 'heatmap' && heatCells.length"
                        :label="$t('app.library.heatmap')"
                        :rows="heatRows"
                        :columns="heatColumns"
                        :cells="heatCells"
                        @select="openCell"
                    />
                    <p v-else-if="view === 'heatmap' && chartData" class="text-sm text-shell-muted">
                        {{ $t('app.library.heatmap_empty') }}
                    </p>
                    <p v-else-if="view === 'heatmap'" class="text-sm text-shell-muted">
                        {{ $t('app.library.charts_loading') }}
                    </p>
                    <div
                        v-show="view === 'table'"
                        class="overflow-x-auto rounded-2xl border border-line bg-shell-panel text-shell-text"
                    >
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-line text-xs text-shell-muted">
                                <tr>
                                    <th class="px-4 py-3 font-medium">{{ $t('app.library.text') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('app.dashboard.market') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">
                                        {{ $t('app.dashboard.visibility') }}
                                    </th>
                                    <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.position') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ $t('app.library.stability') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('app.library.active') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="prompt in prompts"
                                    :key="prompt.id"
                                    class="border-b border-line-soft align-top last:border-0 hover:bg-row-hover"
                                >
                                    <td class="px-4 py-3">
                                        <div v-if="editing === prompt.id" class="flex flex-col gap-2">
                                            <textarea
                                                v-model="form.text"
                                                rows="2"
                                                class="w-full rounded-lg border border-line px-3 py-2 text-sm"
                                            />
                                            <InputError :message="form.errors.text" />
                                            <p v-if="personal" class="text-xs text-warning">
                                                {{ $t('app.library.personal_data') }}
                                            </p>
                                            <div class="flex flex-wrap gap-2">
                                                <select
                                                    v-model="form.intent"
                                                    class="rounded-lg border border-line px-2 py-1 text-sm"
                                                >
                                                    <option
                                                        v-for="item in intents"
                                                        :key="item.value"
                                                        :value="item.value"
                                                    >
                                                        {{ item.label }}
                                                    </option>
                                                </select>
                                                <select
                                                    v-model="form.priority"
                                                    class="rounded-lg border border-line px-2 py-1 text-sm"
                                                >
                                                    <option
                                                        v-for="item in priorities"
                                                        :key="item.value"
                                                        :value="item.value"
                                                    >
                                                        {{ item.label }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="flex gap-2">
                                                <button
                                                    type="button"
                                                    class="rounded-full bg-primary px-3 py-1 text-xs font-medium text-white"
                                                    @click="save(prompt)"
                                                >
                                                    {{ $t('app.library.save') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-full border border-line px-3 py-1 text-xs"
                                                    @click="editing = null"
                                                >
                                                    {{ $t('app.library.cancel') }}
                                                </button>
                                            </div>
                                        </div>
                                        <div v-else>
                                            <Link
                                                :href="route('brands.prompts.show', [brand.id, prompt.id])"
                                                class="font-medium text-shell-text no-underline"
                                            >
                                                {{ prompt.text }}
                                            </Link>
                                            <p class="mt-1 text-xs text-shell-muted">
                                                {{ prompt.intent_label }} · {{ prompt.funnel }} ·
                                                {{ prompt.priority_label }}
                                            </p>
                                            <button
                                                v-if="canManage"
                                                type="button"
                                                class="mt-1 text-xs text-primary"
                                                @click="edit(prompt)"
                                            >
                                                {{ $t('app.library.edit') }}
                                            </button>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-shell-muted">{{ prompt.market }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        <MetricValue :metric="prompt.visibility" />
                                        <SparklineChart
                                            v-if="hasSparkline(prompt.id)"
                                            class="ms-auto mt-2 w-24"
                                            :label="prompt.text"
                                            :points="sparkline(prompt.id)"
                                            :height="28"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        <MetricValue :metric="prompt.position" kind="number" />
                                    </td>
                                    <td class="px-4 py-3 text-right text-xs tabular-nums">
                                        <DotRowChart
                                            v-if="dots(prompt.id).length"
                                            class="ms-auto w-36"
                                            :label="$t('app.library.stability')"
                                            :points="dots(prompt.id)"
                                        />
                                        <div
                                            v-for="item in prompt.stability"
                                            :key="item.engine"
                                            class="mt-1 flex items-center justify-end gap-2"
                                        >
                                            <span>{{ item.engine }}</span>
                                            <MetricValue :metric="item.metric" />
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="text-sm"
                                            :class="prompt.is_active ? 'text-success' : 'text-shell-muted'"
                                            @click="toggle(prompt)"
                                        >
                                            {{
                                                prompt.is_active ? $t('app.library.active') : $t('app.library.inactive')
                                            }}
                                        </button>
                                        <span v-else :class="prompt.is_active ? 'text-success' : 'text-shell-muted'">
                                            {{
                                                prompt.is_active ? $t('app.library.active') : $t('app.library.inactive')
                                            }}
                                        </span>
                                        <InputError
                                            v-if="toggleError.id === prompt.id"
                                            :message="toggleError.message"
                                            class="mt-1"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
