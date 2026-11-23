<script setup>
// Markets and the measurement plan (docs/ux-arbeidsflate.md 5.4, U24).
import PlanMatrix from '@/Components/Brand/PlanMatrix.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import Drawer from '@/Components/UI/Drawer.vue';
import { inertiaRequest, useAutosave } from '@/Composables/useAutosave';
import { useOpenRow } from '@/Composables/useOpenRow';
import AppLayout from '@/Layouts/AppLayout.vue';
import { empty, formatDate, formatNumber } from '@/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    tenantName: { type: String, required: true },
    markets: { type: Array, required: true },
    measurementPlan: { type: Object, required: true },
    prompts: { type: Object, required: true },
    countries: { type: Array, required: true },
    languages: { type: Object, required: true },
    selectedMarketId: { type: Number, default: null },
    canManage: { type: Boolean, required: true },
});

const page = usePage();
const modes = ref({});
const activity = ref(null);
const table = ref(null);
const { openId, open: openRow, close: closeRow } = useOpenRow();
const values = reactive({ location_label: '', is_active: false });
const creating = ref(new URL(page.url, 'http://ekko.invalid').searchParams.get('create') === 'market');
const languageEdited = ref(false);
const form = reactive({
    country: 'NO',
    language: props.languages.NO ?? 'en',
    location_label: '',
    copy_from: '',
});
const crumb = computed(() =>
    [props.tenantName, trans('workspace.shell.setup'), props.brand.name].filter(Boolean).join(' · '),
);
const selected = computed(() => props.markets.find((market) => market.id === openId.value) ?? null);
const names = computed(() => new Map(props.markets.map((market) => [market.id, market.name])));
const autosave = useAutosave(
    () => (selected.value ? route('brands.markets.update', [props.brand.id, selected.value.id]) : ''),
    {
        values,
        only: ['markets', 'measurementPlan', 'workspace'],
        request: (method, url, data, options) =>
            inertiaRequest(method, url, data, {
                ...options,
                onBeforeUpdate(response) {
                    // A field can finish saving after the user opens or closes another market.
                    if (
                        response.component === page.component &&
                        new URL(response.url, window.location.origin).pathname === window.location.pathname
                    ) {
                        response.url = page.url;
                    }
                },
            }),
    },
);
const columns = computed(() => [
    { id: 'name', header: trans('workspace.setup.market'), primary: true, width: 'minmax(9rem, 1.5fr)' },
    { id: 'status', header: trans('workspace.setup.status'), width: 'minmax(5rem, 0.7fr)' },
    { id: 'prompts', header: trans('workspace.setup.prompts'), width: 'minmax(6rem, 0.8fr)' },
    { id: 'plan', header: trans('workspace.setup.plan'), width: 'minmax(8rem, 1.2fr)' },
    { id: 'next', header: trans('workspace.setup.next_measurement'), width: 'minmax(6.5rem, 1fr)' },
]);

watch(
    openId,
    () => {
        autosave.reset();
        values.location_label = selected.value?.location_label ?? '';
        values.is_active = selected.value?.is_active ?? false;
    },
    { immediate: true },
);

const copySources = computed(() => props.markets.filter((market) => market.language === form.language));
const errors = computed(() => page.props.errors ?? {});

watch(
    () => form.country,
    (country) => {
        if (!languageEdited.value) {
            form.language = props.languages[country] ?? 'en';
        }
    },
);

function resetCreateForm() {
    languageEdited.value = false;
    form.country = 'NO';
    form.language = props.languages.NO ?? 'en';
    form.location_label = '';
    form.copy_from = '';
}

watch(
    () => props.selectedMarketId,
    (id) => {
        if (id == null) {
            return;
        }
        openRow(id);
        creating.value = false;
        resetCreateForm();
    },
    { immediate: true },
);

function nextMeasurement(market) {
    return market.next_due_on ? formatDate(market.next_due_on) : empty();
}

function planText(market) {
    return (modes.value[market.id] ?? market.plan_mode) === 'own'
        ? trans('workspace.setup.own_plan')
        : trans('workspace.setup.inherits');
}

async function setActive(active) {
    const id = selected.value.id;
    if (await autosave.save('is_active', active)) {
        activity.value = { id, is_active: active, token: (activity.value?.token ?? 0) + 1 };
    }
}

function createMarket() {
    router.post(
        route('brands.markets.store', props.brand.id),
        {
            country: form.country.toLowerCase(),
            language: form.language,
            location_label: form.location_label,
            copy_from: form.copy_from === '' ? null : Number(form.copy_from),
        },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <AppLayout :title="$t('workspace.setup.markets_title')">
        <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-4 sm:px-6">
            <header>
                <p class="text-xs text-shell-muted">{{ crumb }}</p>
                <div class="flex items-center justify-between gap-3">
                    <h1 class="text-2xl font-medium tracking-tight text-shell-fg">
                        {{ $t('workspace.setup.markets_title') }}
                    </h1>
                    <button
                        v-if="canManage"
                        type="button"
                        class="rounded-full bg-primary-deep px-3 py-1.5 text-sm text-white"
                        @click="creating = !creating"
                    >
                        {{ $t('workspace.setup.new_market') }}
                    </button>
                </div>
            </header>

            <section
                v-if="creating && canManage"
                class="rounded-xl border border-line bg-shell-panel p-4 text-sm text-shell-text"
            >
                <h2 class="font-medium">{{ $t('workspace.setup.new_market') }}</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <label>
                        {{ $t('workspace.setup.country') }}
                        <select
                            v-model="form.country"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1"
                        >
                            <option v-for="country in countries" :key="country.code" :value="country.code">
                                {{ country.name }}
                            </option>
                        </select>
                    </label>
                    <label>
                        {{ $t('workspace.setup.language') }}
                        <input
                            v-model="form.language"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1"
                            @input="languageEdited = true"
                        />
                    </label>
                    <label>
                        {{ $t('workspace.setup.location') }}
                        <input
                            v-model="form.location_label"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1"
                        />
                    </label>
                </div>
                <label class="mt-3 block">
                    {{ $t('workspace.setup.copy_from') }}
                    <select
                        v-model="form.copy_from"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1"
                    >
                        <option value="">{{ $t('workspace.setup.copy_none') }}</option>
                        <option v-for="market in copySources" :key="market.id" :value="market.id">
                            {{ market.name }}
                        </option>
                    </select>
                </label>
                <p v-if="errors.copy_from || errors.plan_mode || errors.country" class="mt-2 text-xs text-warning">
                    {{ errors.copy_from || errors.plan_mode || errors.country }}
                </p>
                <div class="mt-3 flex gap-3">
                    <button
                        type="button"
                        class="rounded-full bg-primary-deep px-3 py-1.5 text-sm text-white"
                        @click="createMarket"
                    >
                        {{ $t('workspace.setup.create_market') }}
                    </button>
                    <Link :href="route('brands.markets.create', brand.id)" class="self-center text-sm">
                        {{ $t('workspace.setup.suggest') }}
                    </Link>
                </div>
            </section>

            <p v-if="markets.length === 0" class="text-sm text-shell-muted">{{ $t('workspace.setup.no_markets') }}</p>
            <div v-else class="flex items-start gap-4">
                <div class="min-w-0 flex-1">
                    <DataTable
                        ref="table"
                        :rows="markets"
                        :columns="columns"
                        :active-row="openId"
                        :label="$t('workspace.setup.markets_title')"
                        :row-label="(row) => row.name"
                        dense
                        @open="openRow"
                        @close="closeRow"
                    >
                        <template #cell-name="{ row }">
                            <span class="block truncate text-shell-fg">{{ row.name }}</span>
                            <span class="block truncate text-xs text-shell-muted">
                                {{
                                    $t('workspace.setup.language_place', {
                                        language: row.language_label,
                                        place: row.place,
                                    })
                                }}
                            </span>
                        </template>
                        <template #cell-status="{ row }">
                            <span :class="row.is_active ? 'text-success' : 'text-shell-muted'">
                                {{ row.is_active ? $t('workspace.setup.active') : $t('workspace.setup.inactive') }}
                            </span>
                        </template>
                        <template #cell-prompts="{ row }">
                            {{
                                $t('workspace.setup.prompt_counts', {
                                    prompts: formatNumber(row.prompts),
                                    core: formatNumber(row.core),
                                })
                            }}
                        </template>
                        <template #cell-plan="{ row }">{{ planText(row) }}</template>
                        <template #cell-next="{ row }">{{ nextMeasurement(row) }}</template>
                    </DataTable>
                </div>
                <Drawer
                    :open="!!selected"
                    :label="selected?.name ?? ''"
                    :save-state="autosave.state.value"
                    :save-error="autosave.error.value"
                    @close="closeRow"
                    @closed="table?.focusCurrentRow()"
                >
                    <div v-if="selected">
                        <p class="text-sm text-shell-muted">
                            {{
                                $t('workspace.setup.language_place', {
                                    language: selected.language_label,
                                    place: selected.place,
                                })
                            }}
                        </p>
                        <label class="mt-2 block text-xs">
                            {{ $t('workspace.setup.location') }}
                            <input
                                :value="values.location_label"
                                :disabled="!canManage"
                                class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1"
                                @change="autosave.save('location_label', $event.target.value)"
                                @keydown.enter.prevent="$event.target.blur()"
                            />
                        </label>
                        <label class="mt-3 flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                data-testid="market-active"
                                :checked="values.is_active"
                                :disabled="!canManage"
                                @change="setActive($event.target.checked)"
                            />
                            {{ $t('workspace.setup.active') }}
                        </label>
                        <p class="mt-1 text-xs text-shell-muted">{{ $t('workspace.setup.inactive_note') }}</p>
                        <Link
                            :href="route('brands.setup.prompts', { brand: brand.id, market: selected.id })"
                            class="mt-3 inline-block text-xs"
                        >
                            {{ $t('workspace.setup.prompts_link') }}
                        </Link>
                    </div>
                </Drawer>
            </div>

            <PlanMatrix
                :plan="measurementPlan"
                :brand-id="brand.id"
                :can-manage="canManage"
                :prompts="prompts"
                :activity="activity"
                :market-names="names"
                @modes="modes = $event"
            />
        </div>
    </AppLayout>
</template>
