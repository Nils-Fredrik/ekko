<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline';
import { formatDate } from '@/format';
import DataNote from '@/Components/Brand/DataNote.vue';
import BottomSheet from '@/Components/UI/BottomSheet.vue';
import FilterFields from './FilterFields.vue';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useId, watch } from 'vue';
import { periodLengths, visitAnalysis } from './filters.js';

const props = defineProps({
    brandId: { type: Number, required: true },
    routeName: { type: String, required: true },
    params: { type: Object, default: () => ({}) },
    showDataNote: { type: Boolean, default: true },
});
const page = usePage();
const config = computed(() => page.props.analysisFilters ?? { query: {}, options: {}, views: [] });
const period = computed(() => page.props.period ?? {});
const periodLabel = computed(() => trans('app.workspace.period_range', {
    start: formatDate(period.value.from),
    end: formatDate(period.value.to),
}));
const open = ref(false);
const mobileFormId = useId();
const busy = ref(false);
const error = ref('');
const name = ref('');
const selectedView = ref('');
const draft = ref({});
watch(
    () => config.value.query,
    (query) => {
        draft.value = {
            ...query,
            mode: query.from ? 'custom' : String(query.days ?? 28),
            from: period.value.from,
            to: period.value.to,
            comparison: query.comparison ?? 'previous',
            grain: query.grain ?? 'day',
        };
    },
    { immediate: true },
);
const grain = computed(() => props.routeName === 'brands.show');
const market = computed(() =>
    config.value.options.market?.find((item) => String(item.value) === String(config.value.query.market)),
);

function changePeriod(days) {
    const query = { ...config.value.query, days: Number(days) };
    delete query.from;
    delete query.to;
    visit(query);
}

const chips = computed(() =>
    Object.entries(config.value.options).flatMap(([key, items]) => {
        const selected = items.find((item) => String(item.value) === String(config.value.query[key]));
        return key !== 'market' && selected ? [{ key, label: selected.label }] : [];
    }),
);
function visit(query) {
    error.value = '';
    visitAnalysis(route(props.routeName, { brand: props.brandId, ...props.params }), query, {
        onError: (errors) => {
            error.value = Object.values(errors)[0] ?? '';
        },
    });
}
function apply(close = () => {}) {
    const query = { comparison: draft.value.comparison };
    if (grain.value) query.grain = draft.value.grain;
    if (draft.value.mode === 'custom') {
        query.from = draft.value.from;
        query.to = draft.value.to;
    } else query.days = Number(draft.value.mode);
    for (const key of Object.keys(config.value.options)) {
        if (draft.value[key] !== '' && draft.value[key] != null) query[key] = draft.value[key];
    }
    open.value = false;
    close();
    visit(query);
}
function remove(key) {
    const query = { ...config.value.query };
    delete query[key];
    visit(query);
}
function loadView() {
    const view = config.value.views.find((item) => String(item.id) === selectedView.value);
    if (view) visit(view.filters);
}
async function save() {
    busy.value = true;
    error.value = '';
    const path = window.location.pathname;
    try {
        const { data: view } = await axios.post(route('brands.views.store', props.brandId), {
            name: name.value,
            page: props.routeName,
            filters: config.value.query,
        });
        if (window.location.pathname !== path) return;
        name.value = '';
        router.replaceProp('analysisFilters.views', (views) =>
            [...views.filter((item) => item.id !== view.id), view].sort((a, b) =>
                a.name.localeCompare(b.name, page.props.locale),
            ),
        );
    } catch (failure) {
        error.value = failure.response?.data?.message ?? trans('filters.failed');
    } finally {
        busy.value = false;
    }
}
async function removeView() {
    if (!selectedView.value) return;
    busy.value = true;
    error.value = '';
    const path = window.location.pathname;
    const id = selectedView.value;
    try {
        await axios.delete(route('brands.views.destroy', [props.brandId, id]));
        if (window.location.pathname !== path) return;
        selectedView.value = '';
        router.replaceProp('analysisFilters.views', (views) => views.filter((view) => String(view.id) !== id));
    } catch {
        error.value = trans('filters.failed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section
        class="relative flex flex-col gap-3"
        :aria-label="$t('app.dashboard.filters')"
        data-testid="analysis-filters"
    >
        <div class="flex flex-wrap items-center gap-2">
            <div
                v-if="config.options.market"
                class="inline-flex max-w-full items-center rounded-full border border-line bg-shell-panel text-sm"
            >
                <select
                    :aria-label="$t('workspace.palette.switch_market')"
                    :value="config.query.market ?? ''"
                    class="min-w-0 max-w-full rounded-full border-0 bg-transparent py-1.5 ps-3 pe-8 text-sm text-shell-fg"
                    @change="
                        $event.target.value
                            ? visit({ ...config.query, market: Number($event.target.value) })
                            : remove('market')
                    "
                >
                    <option value="">{{ $t('workspace.shell.all_markets') }}</option>
                    <option v-for="item in config.options.market" :key="item.value" :value="item.value">
                        {{ $t('filters.market_chip', { market: item.label }) }}
                    </option>
                </select>
                <button
                    v-if="market"
                    type="button"
                    class="rounded-full p-1 me-1"
                    :aria-label="$t('filters.remove', { name: market.label })"
                    @click="remove('market')"
                >
                    <XMarkIcon class="h-3.5 w-3.5" />
                </button>
            </div>
            <select
                :aria-label="$t('filters.quick_period')"
                :title="periodLabel"
                :value="config.query.from ? 'custom' : String(config.query.days ?? period.days)"
                class="max-w-full rounded-full border-line bg-shell-panel py-1.5 ps-3 pe-8 text-sm text-shell-fg"
                @change="changePeriod($event.target.value)"
            >
                <option v-if="config.query.from" value="custom">
                    {{ periodLabel }}
                </option>
                <option v-for="days in periodLengths" :key="days" :value="String(days)">
                    {{ $t('app.dashboard.period_days', { days }) }}
                </option>
            </select>
            <span
                class="text-xs text-shell-muted"
                :title="$t('app.workspace.period_range', { start: period.previous_from, end: period.previous_to })"
            >
                {{
                    config.query.from
                        ? $t('filters.compare_range', { start: period.previous_from, end: period.previous_to })
                        : config.query.comparison === 'year'
                          ? $t('filters.compare_year')
                          : $t('filters.compare_days', { days: config.query.days ?? period.days })
                }}
            </span>
            <button
                v-for="chip in chips"
                :key="chip.key"
                type="button"
                class="rounded-full border border-line bg-shell-panel px-3 py-1 text-sm"
                :aria-label="$t('filters.remove', { name: chip.label })"
                @click="remove(chip.key)"
            >
                {{ chip.label }}
                <XMarkIcon class="ms-1 inline h-3 w-3" aria-hidden="true" />
            </button>
            <Popover v-slot="{ close }" class="hidden sm:block">
                <PopoverButton class="rounded-full border border-line px-4 py-2 text-sm">
                    {{ $t('filters.edit') }}
                </PopoverButton>
                <PopoverPanel
                    focus
                    class="absolute left-0 top-full z-30 mt-2 w-[32rem] max-w-[90vw] rounded-xl border border-line bg-shell-surface p-4 shadow-pop"
                >
                    <form class="space-y-4" @submit.prevent="apply(close)">
                        <FilterFields v-model="draft" :options="config.options" :grain="grain" />
                        <button type="submit" class="rounded-full bg-primary px-4 py-2 text-sm text-white">
                            {{ $t('app.dashboard.apply') }}
                        </button>
                    </form>
                </PopoverPanel>
            </Popover>
            <button
                type="button"
                class="rounded-full border border-line px-4 py-2 text-sm sm:hidden"
                @click="open = true"
            >
                {{ $t('filters.edit') }}
            </button>
            <details class="ms-auto text-sm">
                <summary class="cursor-pointer rounded-full border border-line px-3 py-1.5">
                    {{ $t('filters.saved') }}
                </summary>
                <div
                    class="absolute end-0 z-20 mt-2 flex w-max max-w-full flex-wrap items-end gap-2 rounded-xl border border-line bg-shell-surface p-3 shadow-pop"
                >
                    <label v-if="config.views.length" class="grid gap-1">
                        {{ $t('filters.saved') }}
                        <select
                            v-model="selectedView"
                            class="rounded-lg border-line bg-shell-surface"
                            @change="loadView"
                        >
                            <option value="">{{ $t('filters.choose') }}</option>
                            <option v-for="view in config.views" :key="view.id" :value="String(view.id)">
                                {{ view.name }}
                            </option>
                        </select>
                    </label>
                    <button
                        v-if="selectedView"
                        type="button"
                        :disabled="busy"
                        class="rounded-full border border-line px-3 py-2"
                        @click="removeView"
                    >
                        {{ $t('filters.delete') }}
                    </button>
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="save">
                        <label class="grid gap-1">
                            {{ $t('filters.name') }}
                            <input
                                v-model="name"
                                required
                                maxlength="80"
                                class="rounded-lg border-line bg-shell-surface"
                            />
                        </label>
                        <button type="submit" :disabled="busy" class="rounded-full border border-line px-3 py-2">
                            {{ $t('filters.save') }}
                        </button>
                    </form>
                </div>
            </details>
        </div>
        <DataNote v-if="showDataNote && config.automaticPeriod" />
        <p v-if="error" role="alert" class="text-sm text-error">{{ error }}</p>
        <BottomSheet :show="open" :title="$t('app.dashboard.filters')" @close="open = false">
            <form :id="mobileFormId" class="space-y-4 py-3" @submit.prevent="apply()">
                <FilterFields v-model="draft" :options="config.options" :grain="grain" />
            </form>
            <template #footer>
                <button type="submit" :form="mobileFormId" class="rounded-full bg-primary px-4 py-2 text-sm text-white">
                    {{ $t('app.dashboard.apply') }}
                </button>
            </template>
        </BottomSheet>
    </section>
</template>
