<script setup>
// One card per integration (docs/ux-arbeidsflate.md 5.8, U24). Secrets stay empty and are not returned.
import InputError from '@/Components/InputError.vue';
import WorkspaceHeader from '@/Components/Shell/WorkspaceHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/format';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    tenantName: { type: String, required: true },
    integrations: { type: Array, required: true },
    clickhouseEndpoints: { type: Array, required: true },
    logUploadUrl: { type: String, default: null },
    canManage: { type: Boolean, required: true },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const forms = reactive({
    clickhouse_logs: {
        clickhouse_host: '',
        clickhouse_database: '',
        clickhouse_table: '',
        clickhouse_user: '',
        clickhouse_password: '',
    },
    ga4: { ga4_property_id: '', ga4_client_email: '', ga4_private_key: '' },
});
const field = 'mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2';

function fieldError(key) {
    const value = errors.value[key];

    return Array.isArray(value) ? value[0] : (value ?? '');
}

function statusText(integration) {
    if (!integration.connected) {
        return trans('workspace.setup.not_connected');
    }
    if (integration.status === 'error') {
        if (!integration.failing_since) {
            return trans('app.workspace.integration_status.error');
        }

        return trans('workspace.setup.failing_since', {
            date: formatDate(integration.failing_since),
        });
    }
    if (integration.status === 'synced') {
        return trans('workspace.setup.ok');
    }

    return trans('app.workspace.integration_status.pending');
}

function save(type) {
    const payload = Object.fromEntries(Object.entries(forms[type]).filter(([, value]) => value !== ''));
    router.put(route('brands.integrations.update', [props.brand.id, type]), payload, {
        preserveScroll: true,
        only: ['integrations'],
        onSuccess: () => {
            for (const key of Object.keys(forms[type])) {
                forms[type][key] = '';
            }
        },
    });
}
</script>

<template>
    <AppLayout :title="$t('workspace.setup.integrations_title')">
        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
            <WorkspaceHeader
                :brand="brand.name"
                :group="$t('workspace.shell.setup')"
                :title="$t('workspace.setup.integrations_title')"
            />

            <p v-if="integrations.length === 0" class="text-sm text-shell-muted">
                {{ $t('app.empty.integrations_body') }}
            </p>

            <section
                v-for="integration in integrations"
                :key="integration.type"
                class="space-y-3 rounded-xl border border-line bg-shell-panel p-4 text-sm text-shell-text"
            >
                <h2 class="font-medium">
                    {{
                        integration.type === 'clickhouse_logs'
                            ? $t('workspace.setup.clickhouse')
                            : $t('workspace.setup.ga4')
                    }}
                </h2>
                <p>{{ statusText(integration) }}</p>
                <p class="text-xs text-shell-muted">
                    {{
                        integration.last_sync_at
                            ? $t('workspace.setup.last_sync', { time: formatDateTime(integration.last_sync_at) })
                            : $t('workspace.setup.never_synced')
                    }}
                </p>

                <template v-if="integration.type === 'clickhouse_logs'">
                    <template v-if="clickhouseEndpoints.length > 0">
                        <label class="block">
                            {{ $t('app.workspace.clickhouse_host') }}
                            <select
                                v-model="forms.clickhouse_logs.clickhouse_host"
                                :disabled="!canManage"
                                :class="field"
                            >
                                <option value="">{{ $t('app.workspace.clickhouse_unchanged') }}</option>
                                <option v-for="endpoint in clickhouseEndpoints" :key="endpoint" :value="endpoint">
                                    {{ endpoint }}
                                </option>
                            </select>
                        </label>
                        <InputError :message="fieldError('clickhouse_host')" />
                        <label class="block">
                            {{ $t('app.workspace.clickhouse_database') }}
                            <input
                                v-model="forms.clickhouse_logs.clickhouse_database"
                                :disabled="!canManage"
                                :class="field"
                                autocomplete="off"
                            />
                        </label>
                        <InputError :message="fieldError('clickhouse_database')" />
                        <label class="block">
                            {{ $t('app.workspace.clickhouse_table') }}
                            <input
                                v-model="forms.clickhouse_logs.clickhouse_table"
                                :disabled="!canManage"
                                :class="field"
                                autocomplete="off"
                            />
                        </label>
                        <InputError :message="fieldError('clickhouse_table')" />
                        <label class="block">
                            {{ $t('app.workspace.clickhouse_user') }}
                            <input
                                v-model="forms.clickhouse_logs.clickhouse_user"
                                :disabled="!canManage"
                                :class="field"
                                autocomplete="off"
                            />
                        </label>
                        <InputError :message="fieldError('clickhouse_user')" />
                        <label class="block">
                            {{ $t('app.workspace.clickhouse_password') }}
                            <input
                                v-model="forms.clickhouse_logs.clickhouse_password"
                                :disabled="!canManage"
                                type="password"
                                :class="field"
                                autocomplete="new-password"
                            />
                        </label>
                        <InputError :message="fieldError('clickhouse_password')" />
                    </template>
                    <a v-if="logUploadUrl && !integration.connected" :href="logUploadUrl" class="inline-block text-sm">
                        {{ $t('app.workspace.upload_logs') }}
                    </a>
                </template>

                <template v-else>
                    <label class="block">
                        {{ $t('app.workspace.ga4_property') }}
                        <input
                            v-model="forms.ga4.ga4_property_id"
                            :disabled="!canManage"
                            :class="field"
                            autocomplete="off"
                        />
                    </label>
                    <InputError :message="fieldError('ga4_property_id')" />
                    <label class="block">
                        {{ $t('app.workspace.ga4_email') }}
                        <input
                            v-model="forms.ga4.ga4_client_email"
                            :disabled="!canManage"
                            :class="field"
                            autocomplete="off"
                        />
                    </label>
                    <InputError :message="fieldError('ga4_client_email')" />
                    <label class="block">
                        {{ $t('app.workspace.ga4_key') }}
                        <textarea
                            v-model="forms.ga4.ga4_private_key"
                            :disabled="!canManage"
                            :class="field"
                            rows="3"
                            autocomplete="off"
                        />
                    </label>
                    <InputError :message="fieldError('ga4_private_key')" />
                </template>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-full bg-primary-deep px-3 py-1.5 text-sm text-white"
                    @click="save(integration.type)"
                >
                    {{ $t('workspace.setup.save') }}
                </button>
            </section>
        </div>
    </AppLayout>
</template>
