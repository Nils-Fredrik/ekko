<script setup>
import { computed, reactive, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AdminNav from '@/Components/AdminNav.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ plans: Array, engines: Array, frequencies: Array });
const field = 'mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink';

function blank(plan = {}) {
    return {
        name: plan.name ?? '',
        max_brands: plan.max_brands ?? '',
        max_markets_per_brand: plan.max_markets_per_brand ?? '',
        max_prompts_per_market: plan.max_prompts_per_market ?? '',
        monthly_call_quota: plan.monthly_call_quota ?? '',
        monthly_budget_usd: plan.monthly_budget_usd ?? '',
        max_frequency: plan.max_frequency ?? 'daily',
        max_repetitions: plan.max_repetitions ?? 1,
        allowed_engines: [...(plan.allowed_engines ?? [])],
        engine_max_frequencies: { ...(plan.engine_max_frequencies ?? {}) },
    };
}

const creating = useForm(blank());
const editing = reactive({});
function ensurePlan(plan) {
    if (!editing[plan.id]) {
        editing[plan.id] = useForm(blank(plan));
    }
}
props.plans.forEach(ensurePlan);
watch(
    () => props.plans,
    (plans) => plans.forEach(ensurePlan),
);
const rows = computed(() => [
    { key: 'new', form: creating, label: 'admin.plans.create' },
    ...props.plans.map((plan) => ({ key: plan.id, form: editing[plan.id], label: 'admin.plans.save' })),
]);

function send(row) {
    row.form.transform((data) => ({
        ...data,
        max_brands: data.max_brands === '' ? null : data.max_brands,
        max_markets_per_brand: data.max_markets_per_brand === '' ? null : data.max_markets_per_brand,
        max_prompts_per_market: data.max_prompts_per_market === '' ? null : data.max_prompts_per_market,
        monthly_call_quota: data.monthly_call_quota === '' ? null : data.monthly_call_quota,
        monthly_budget_usd: data.monthly_budget_usd === '' ? null : data.monthly_budget_usd,
        engine_max_frequencies: Object.fromEntries(
            Object.entries(data.engine_max_frequencies).filter(([, value]) => props.frequencies.includes(value)),
        ),
    }));
    if (row.key === 'new') {
        row.form.post(route('admin.plans.store'), { preserveScroll: true });
        return;
    }
    row.form.put(route('admin.plans.update', row.key), { preserveScroll: true });
}

function toggle(form, key) {
    form.allowed_engines = form.allowed_engines.includes(key)
        ? form.allowed_engines.filter((engine) => engine !== key)
        : [...form.allowed_engines, key];
}
</script>

<template>
    <AppLayout :title="$t('admin.plans.title')">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ $t('admin.plans.title') }}</h2>
        </template>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
            <AdminNav />
            <p class="text-sm text-ink-soft">{{ $t('admin.plans.intro') }} {{ $t('admin.plans.unlimited') }}</p>
            <form
                v-for="row in rows"
                :key="row.key"
                class="space-y-3 rounded-2xl border border-line bg-white p-6 text-ink"
                @submit.prevent="send(row)"
            >
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="text-sm">
                        {{ $t('admin.plans.name') }}
                        <TextInput v-model="row.form.name" required :class="field" />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.plans.brands') }}
                        <TextInput v-model="row.form.max_brands" type="number" min="1" :class="field" />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.plans.markets') }}
                        <TextInput v-model="row.form.max_markets_per_brand" type="number" min="1" :class="field" />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.plans.prompts') }}
                        <TextInput v-model="row.form.max_prompts_per_market" type="number" min="1" :class="field" />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.plans.quota') }}
                        <TextInput v-model="row.form.monthly_call_quota" type="number" min="0" :class="field" />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.plans.budget') }}
                        <TextInput
                            v-model="row.form.monthly_budget_usd"
                            type="number"
                            min="0"
                            step="0.01"
                            :class="field"
                        />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.plans.frequency') }}
                        <select v-model="row.form.max_frequency" :class="field">
                            <option v-for="frequency in frequencies" :key="frequency" :value="frequency">
                                {{ $t(`admin.plans.frequencies.${frequency}`) }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.plans.repetitions') }}
                        <TextInput
                            v-model="row.form.max_repetitions"
                            type="number"
                            min="1"
                            max="3"
                            required
                            :class="field"
                        />
                    </label>
                </div>
                <fieldset class="text-sm">
                    <legend class="mb-2">{{ $t('admin.plans.engines') }}</legend>
                    <label v-for="engine in engines" :key="engine.key" class="mr-4 inline-flex items-center gap-2">
                        <input
                            type="checkbox"
                            :checked="row.form.allowed_engines.includes(engine.key)"
                            @change="toggle(row.form, engine.key)"
                        />
                        {{ engine.key }}
                        <select
                            v-if="row.form.allowed_engines.includes(engine.key)"
                            v-model="row.form.engine_max_frequencies[engine.key]"
                            :class="field"
                            class="!mt-0"
                        >
                            <option value="">{{ $t(`admin.plans.frequencies.${row.form.max_frequency}`) }}</option>
                            <option v-for="frequency in frequencies" :key="frequency" :value="frequency">
                                {{ $t(`admin.plans.frequencies.${frequency}`) }}
                            </option>
                        </select>
                    </label>
                </fieldset>
                <InputError v-for="(message, key) in row.form.errors" :key="key" :message="message" />
                <PrimaryButton :disabled="row.form.processing">{{ $t(row.label) }}</PrimaryButton>
            </form>
        </div>
    </AppLayout>
</template>
