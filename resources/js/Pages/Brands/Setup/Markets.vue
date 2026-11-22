<script setup>
// Markets and the measurement plan (docs/ux-arbeidsflate.md 5.4, U24).
import PlanMatrix from '@/Components/Brand/PlanMatrix.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { empty, formatDate } from '@/format';
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
const estimate = ref(null);
const selectedId = ref(props.selectedMarketId ?? props.markets[0]?.id ?? null);
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
const selected = computed(() => props.markets.find((market) => market.id === selectedId.value) ?? null);
const columnId = computed(() => {
    const current = props.markets.find((market) => market.id === selectedId.value);
    if (current?.is_active) {
        return current.id;
    }

    return props.markets.find((market) => market.is_active)?.id ?? null;
});
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

function nextMeasurement(market) {
    const row = estimate.value?.markets?.find((item) => item.id === market.id);
    const date = (row?.rules ?? [])
        .map((rule) => rule.next_due_on)
        .filter(Boolean)
        .sort()[0];

    return date ? formatDate(date) : empty();
}

function planText(market) {
    return (modes.value[market.id] ?? market.plan_mode) === 'own'
        ? trans('workspace.setup.own_plan')
        : trans('workspace.setup.inherits');
}

function saveMarket(market, changes) {
    router.patch(route('brands.markets.update', [props.brand.id, market.id]), changes, {
        preserveScroll: true,
        only: ['markets', 'measurementPlan'],
    });
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
        { preserveScroll: true },
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
            <div v-else class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
                <table class="w-full text-left text-xs">
                    <thead class="text-shell-muted">
                        <tr>
                            <th class="py-1 font-medium">{{ $t('workspace.setup.market') }}</th>
                            <th class="py-1 font-medium">{{ $t('workspace.setup.status') }}</th>
                            <th class="py-1 font-medium">{{ $t('workspace.setup.prompts') }}</th>
                            <th class="py-1 font-medium">{{ $t('workspace.setup.plan') }}</th>
                            <th class="py-1 font-medium">{{ $t('workspace.setup.next_measurement') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="market in markets"
                            :key="market.id"
                            data-testid="market-row"
                            class="cursor-pointer border-t border-line"
                            @click="selectedId = market.id"
                        >
                            <td class="py-1">
                                <span class="block text-shell-fg">{{ market.name }}</span>
                                <span class="text-shell-muted">{{ $t('workspace.setup.language_place', market) }}</span>
                            </td>
                            <td :class="market.is_active ? 'text-success' : 'text-shell-muted'">
                                {{ market.is_active ? $t('workspace.setup.active') : $t('workspace.setup.inactive') }}
                            </td>
                            <td>{{ $t('workspace.setup.prompt_counts', market) }}</td>
                            <td>{{ planText(market) }}</td>
                            <td>{{ nextMeasurement(market) }}</td>
                        </tr>
                    </tbody>
                </table>
                <section
                    v-if="selected"
                    class="rounded-xl border border-line bg-shell-panel p-3 text-sm text-shell-text"
                >
                    <h2 class="font-medium">{{ selected.name }}</h2>
                    <label class="mt-2 block text-xs">
                        {{ $t('workspace.setup.location') }}
                        <input
                            :value="selected.location_label"
                            :disabled="!canManage"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-2 py-1"
                            @change="saveMarket(selected, { location_label: $event.target.value })"
                        />
                    </label>
                    <label class="mt-3 flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            :checked="selected.is_active"
                            :disabled="!canManage"
                            @change="saveMarket(selected, { is_active: $event.target.checked })"
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
                </section>
            </div>

            <PlanMatrix
                :plan="measurementPlan"
                :brand-id="brand.id"
                :can-manage="canManage"
                :column-market-id="columnId"
                :prompts="prompts"
                @modes="modes = $event"
                @estimate="estimate = $event"
            />
        </div>
    </AppLayout>
</template>
