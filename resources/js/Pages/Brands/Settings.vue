<script setup>
import { useDateTime } from '@/Composables/useDateTime';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const { dateTime } = useDateTime();

const props = defineProps({
    brand: { type: Object, required: true },
    aliases: { type: Array, required: true },
    domains: { type: Array, required: true },
    markets: { type: Array, required: true },
    engines: { type: Array, required: true },
    integrations: { type: Array, required: true },
    clickhouseEndpoints: { type: Array, required: true },
    logUploadUrl: { type: String, default: null },
    can: { type: Object, required: true },
});

const form = useForm({
    name: props.brand.name,
    primary_domain: props.brand.primary_domain,
    aliases: props.aliases.map((row) => ({ alias: row.alias, match_mode: row.match_mode })),
    domains: props.domains.map((row) => ({ domain: row.domain, include_subdomains: row.include_subdomains })),
    markets: props.markets.map((row) => ({
        id: row.id,
        location_label: row.location_label ?? '',
        is_active: row.is_active,
    })),
    engines: props.engines.map((row) => ({
        id: row.id,
        frequency: row.frequency,
        prompt_scope: row.prompt_scope,
        repetitions: row.repetitions,
    })),
    clickhouse_host: '',
    clickhouse_database: '',
    clickhouse_table: '',
    clickhouse_user: '',
    clickhouse_password: '',
    ga4_property_id: '',
    ga4_client_email: '',
    ga4_private_key: '',
});

const hasClickHouse = props.integrations.some((integration) => integration.type === 'clickhouse_logs');

const field = 'w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink';

function save() {
    form.put(route('brands.settings.update', props.brand.id), {
        preserveScroll: true,
        preserveState: (page) => Object.keys(page.props.errors).length > 0,
    });
}
</script>

<template>
    <AppLayout :title="brand.name">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ brand.name }}</h2>
        </template>

        <form class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6" @submit.prevent="save">
            <section class="space-y-4 rounded-xl border border-line bg-white p-5 text-ink">
                <label class="block text-sm">
                    {{ $t('app.workspace.name') }}
                    <input v-model="form.name" :disabled="!can.manage" :class="field" class="mt-1" />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="block text-sm">
                    {{ $t('app.workspace.primary_domain') }}
                    <input v-model="form.primary_domain" :disabled="!can.manage" :class="field" class="mt-1" />
                    <InputError :message="form.errors.primary_domain" />
                </label>
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.aliases') }}</h3>
                <div v-for="(row, index) in form.aliases" :key="index" class="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                    <input v-model="row.alias" :disabled="!can.manage" :class="field" />
                    <select v-model="row.match_mode" :disabled="!can.manage" :class="field">
                        <option value="exact_word">{{ $t('app.workspace.match_exact') }}</option>
                        <option value="case_insensitive">{{ $t('app.workspace.match_ignore_case') }}</option>
                    </select>
                    <button
                        v-if="can.manage"
                        type="button"
                        class="text-sm text-ink-soft"
                        @click="form.aliases.splice(index, 1)"
                    >
                        {{ $t('app.workspace.remove') }}
                    </button>
                    <InputError
                        class="sm:col-span-3"
                        :message="form.errors[`aliases.${index}.alias`] || form.errors[`aliases.${index}.match_mode`]"
                    />
                </div>
                <button
                    v-if="can.manage"
                    type="button"
                    class="text-sm"
                    @click="form.aliases.push({ alias: '', match_mode: 'case_insensitive' })"
                >
                    {{ $t('app.workspace.add_alias') }}
                </button>
                <InputError :message="form.errors.aliases" />
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.domains') }}</h3>
                <div v-for="(row, index) in form.domains" :key="index" class="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                    <input v-model="row.domain" :disabled="!can.manage" :class="field" />
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="row.include_subdomains" :disabled="!can.manage" type="checkbox" />
                        {{ $t('app.workspace.include_subdomains') }}
                    </label>
                    <button
                        v-if="can.manage"
                        type="button"
                        class="text-sm text-ink-soft"
                        @click="form.domains.splice(index, 1)"
                    >
                        {{ $t('app.workspace.remove') }}
                    </button>
                    <InputError
                        class="sm:col-span-3"
                        :message="
                            form.errors[`domains.${index}.domain`] || form.errors[`domains.${index}.include_subdomains`]
                        "
                    />
                </div>
                <button
                    v-if="can.manage"
                    type="button"
                    class="text-sm"
                    @click="form.domains.push({ domain: '', include_subdomains: false })"
                >
                    {{ $t('app.workspace.add_domain') }}
                </button>
                <InputError :message="form.errors.domains" />
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.markets') }}</h3>
                <div v-for="(row, index) in form.markets" :key="row.id" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                    <label class="text-sm">
                        {{ markets[index].country }} / {{ markets[index].language }}
                        <input v-model="row.location_label" :disabled="!can.manage" :class="field" class="mt-1" />
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="row.is_active" :disabled="!can.manage" type="checkbox" />
                        {{ $t('app.workspace.active') }}
                    </label>
                    <InputError
                        :message="
                            form.errors[`markets.${index}.id`] ||
                            form.errors[`markets.${index}.location_label`] ||
                            form.errors[`markets.${index}.is_active`]
                        "
                    />
                </div>
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.engines') }}</h3>
                <div v-for="(row, index) in form.engines" :key="row.id" class="grid gap-2 sm:grid-cols-4">
                    <p class="text-sm sm:col-span-4">{{ engines[index].market }} · {{ engines[index].name }}</p>
                    <select v-model="row.frequency" :disabled="!can.manage" :class="field">
                        <option value="daily">{{ $t('app.workspace.freq_daily') }}</option>
                        <option value="weekly">{{ $t('app.workspace.freq_weekly') }}</option>
                        <option value="monthly">{{ $t('app.workspace.freq_monthly') }}</option>
                        <option value="off">{{ $t('app.workspace.freq_off') }}</option>
                    </select>
                    <select v-model="row.prompt_scope" :disabled="!can.manage" :class="field">
                        <option value="all">{{ $t('app.workspace.scope_all') }}</option>
                        <option value="core">{{ $t('app.workspace.scope_core') }}</option>
                    </select>
                    <select v-model.number="row.repetitions" :disabled="!can.manage" :class="field">
                        <option v-for="count in [1, 2, 3]" :key="count" :value="count">{{ count }}</option>
                    </select>
                    <InputError
                        class="sm:col-span-4"
                        :message="
                            form.errors[`engines.${index}.id`] ||
                            form.errors[`engines.${index}.frequency`] ||
                            form.errors[`engines.${index}.prompt_scope`] ||
                            form.errors[`engines.${index}.repetitions`]
                        "
                    />
                </div>
            </section>

            <section v-if="can.integrations" class="space-y-3 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.integrations') }}</h3>
                <p v-if="integrations.length === 0">{{ $t('app.workspace.no_integrations') }}</p>
                <div v-for="integration in integrations" :key="integration.type" class="text-sm">
                    <p>
                        {{
                            $t(
                                integration.type === 'clickhouse_logs'
                                    ? 'app.workspace.integration_clickhouse'
                                    : 'app.workspace.integration_ga4',
                            )
                        }}
                        ·
                        {{
                            $t(
                                `app.workspace.integration_status.${['pending', 'synced', 'error'].includes(integration.status) ? integration.status : 'unknown'}`,
                            )
                        }}
                    </p>
                    <p class="text-ink-soft">
                        {{
                            integration.last_sync_at
                                ? $t('app.workspace.last_sync', { time: dateTime(integration.last_sync_at) })
                                : $t('app.workspace.never_synced')
                        }}
                    </p>
                </div>
                <template v-if="clickhouseEndpoints.length > 0">
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_host') }}
                        <select v-model="form.clickhouse_host" :disabled="!can.manage" :class="field" class="mt-1">
                            <option value="">{{ $t('app.workspace.clickhouse_unchanged') }}</option>
                            <option v-for="endpoint in clickhouseEndpoints" :key="endpoint" :value="endpoint">
                                {{ endpoint }}
                            </option>
                        </select>
                        <InputError :message="form.errors.clickhouse_host" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_database') }}
                        <input
                            v-model="form.clickhouse_database"
                            :disabled="!can.manage"
                            :class="field"
                            class="mt-1"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.clickhouse_database" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_table') }}
                        <input
                            v-model="form.clickhouse_table"
                            :disabled="!can.manage"
                            :class="field"
                            class="mt-1"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.clickhouse_table" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_user') }}
                        <input
                            v-model="form.clickhouse_user"
                            :disabled="!can.manage"
                            :class="field"
                            class="mt-1"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.clickhouse_user" />
                    </label>
                    <label class="block text-sm">
                        {{ $t('app.workspace.clickhouse_password') }}
                        <input
                            v-model="form.clickhouse_password"
                            :disabled="!can.manage"
                            type="password"
                            :class="field"
                            class="mt-1"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.clickhouse_password" />
                    </label>
                </template>
                <a v-if="logUploadUrl && !hasClickHouse" :href="logUploadUrl" class="text-sm">
                    {{ $t('app.workspace.upload_logs') }}
                </a>
                <label class="block text-sm">
                    {{ $t('app.workspace.ga4_property') }}
                    <input
                        v-model="form.ga4_property_id"
                        :disabled="!can.manage"
                        :class="field"
                        class="mt-1"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.ga4_property_id" />
                </label>
                <label class="block text-sm">
                    {{ $t('app.workspace.ga4_email') }}
                    <input
                        v-model="form.ga4_client_email"
                        :disabled="!can.manage"
                        :class="field"
                        class="mt-1"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.ga4_client_email" />
                </label>
                <label class="block text-sm">
                    {{ $t('app.workspace.ga4_key') }}
                    <textarea
                        v-model="form.ga4_private_key"
                        :disabled="!can.manage"
                        :class="field"
                        class="mt-1"
                        rows="3"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.ga4_private_key" />
                </label>
            </section>

            <PrimaryButton v-if="can.manage" :disabled="form.processing">{{ $t('app.workspace.save') }}</PrimaryButton>
        </form>
    </AppLayout>
</template>
