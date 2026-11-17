<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminNav from '@/Components/AdminNav.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ engines: Array });
const field = 'mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink';
const settings = Object.fromEntries(
    props.engines.map((engine) => [
        engine.key,
        useForm({ model_id: engine.model_id ?? '', is_enabled: engine.is_enabled }),
    ]),
);
const prices = Object.fromEntries(
    props.engines.map((engine) => [
        engine.key,
        useForm({
            valid_from: '',
            price_per_call_usd: engine.price?.price_per_call_usd ?? '0',
            price_per_1k_input_tokens_usd: engine.price?.price_per_1k_input_tokens_usd ?? '0',
            price_per_1k_output_tokens_usd: engine.price?.price_per_1k_output_tokens_usd ?? '0',
            price_per_search_usd: engine.price?.price_per_search_usd ?? '0',
        }),
    ]),
);
</script>

<template>
    <AppLayout :title="$t('admin.engines.title')">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ $t('admin.engines.title') }}</h2>
        </template>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
            <AdminNav />
            <p class="text-sm text-ink-soft">{{ $t('admin.engines.intro') }}</p>
            <section
                v-for="engine in engines"
                :key="engine.key"
                class="space-y-4 rounded-2xl border border-line bg-white p-6 text-ink"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-medium">{{ engine.key }}</h3>
                    <p class="text-sm" :class="engine.unhealthy ? 'text-error' : 'text-ink-soft'">
                        {{
                            engine.runs === 0
                                ? $t('admin.engines.no_runs')
                                : engine.unhealthy
                                  ? $t('admin.engines.unhealthy')
                                  : $t('admin.engines.healthy')
                        }}
                        <span v-if="!engine.available">· {{ $t('admin.engines.unavailable') }}</span>
                    </p>
                </div>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="
                        settings[engine.key].put(route('admin.engines.update', engine.key), { preserveScroll: true })
                    "
                >
                    <label class="text-sm">
                        {{ $t('admin.engines.model') }}
                        <TextInput v-model="settings[engine.key].model_id" :class="field" />
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="settings[engine.key].is_enabled" type="checkbox" />
                        {{ $t('admin.engines.enabled') }}
                    </label>
                    <PrimaryButton :disabled="settings[engine.key].processing">
                        {{ $t('admin.engines.save') }}
                    </PrimaryButton>
                    <InputError v-for="(message, key) in settings[engine.key].errors" :key="key" :message="message" />
                </form>
                <p class="text-sm text-ink-soft">
                    {{ $t('admin.engines.price') }}:
                    {{ engine.price ? engine.price.price_per_call_usd : $t('admin.engines.no_price') }}
                </p>
                <form
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    @submit.prevent="
                        prices[engine.key].post(route('admin.engines.prices.store', engine.key), {
                            preserveScroll: true,
                        })
                    "
                >
                    <label class="text-sm">
                        {{ $t('admin.engines.valid_from') }}
                        <TextInput
                            v-model="prices[engine.key].valid_from"
                            type="datetime-local"
                            required
                            :class="field"
                        />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.engines.per_call') }}
                        <TextInput
                            v-model="prices[engine.key].price_per_call_usd"
                            type="number"
                            min="0"
                            step="0.00000001"
                            required
                            :class="field"
                        />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.engines.input') }}
                        <TextInput
                            v-model="prices[engine.key].price_per_1k_input_tokens_usd"
                            type="number"
                            min="0"
                            step="0.00000001"
                            required
                            :class="field"
                        />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.engines.output') }}
                        <TextInput
                            v-model="prices[engine.key].price_per_1k_output_tokens_usd"
                            type="number"
                            min="0"
                            step="0.00000001"
                            required
                            :class="field"
                        />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.engines.search') }}
                        <TextInput
                            v-model="prices[engine.key].price_per_search_usd"
                            type="number"
                            min="0"
                            step="0.00000001"
                            required
                            :class="field"
                        />
                    </label>
                    <div class="self-end">
                        <PrimaryButton :disabled="prices[engine.key].processing">
                            {{ $t('admin.engines.save_price') }}
                        </PrimaryButton>
                        <InputError v-for="(message, key) in prices[engine.key].errors" :key="key" :message="message" />
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
