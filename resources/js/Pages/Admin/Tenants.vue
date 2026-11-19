<script setup>
import { reactive, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AdminNav from '@/Components/AdminNav.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmsPassword from '@/Components/ConfirmsPassword.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ tenants: Array, plans: Array, partners: Array, statuses: Array });
const field = 'mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text';

const closePassword = {
    title: 'app.tenant_close.title',
    content: 'app.tenant_close.confirm',
    button: 'app.tenant_close.title',
};
const creating = useForm({ name: '', plan_id: props.plans[0]?.id ?? '', partner_team_id: '' });
const acting = useForm({ team_id: props.tenants[0]?.id ?? '', reason: '' });
function tenantForm(tenant) {
    return useForm({
        name: tenant.name,
        plan_id: tenant.plan_id ?? '',
        partner_team_id: tenant.partner_team_id ?? '',
        status: tenant.status,
        monthly_call_quota_override: tenant.monthly_call_quota_override ?? '',
    });
}

const editing = reactive({});
function ensureTenant(tenant) {
    if (!editing[tenant.id]) {
        editing[tenant.id] = tenantForm(tenant);
    }
}
props.tenants.forEach(ensureTenant);
watch(
    () => props.tenants,
    (tenants) => tenants.forEach(ensureTenant),
);

function create() {
    creating
        .transform((data) => ({ ...data, partner_team_id: data.partner_team_id || null }))
        .post(route('admin.tenants.store'), { preserveScroll: true });
}

function isClosing(tenant) {
    return tenant.status !== 'closed' && editing[tenant.id].status === 'closed';
}

function save(tenant, confirmed = false) {
    if (isClosing(tenant) && !confirmed) {
        return;
    }
    editing[tenant.id]
        .transform((data) => ({
            ...data,
            partner_team_id: data.partner_team_id || null,
            monthly_call_quota_override:
                data.monthly_call_quota_override === '' ? null : data.monthly_call_quota_override,
        }))
        .put(route('admin.tenants.update', tenant.id), { preserveScroll: true });
}

function act() {
    acting.post(route('admin.acting-as.start', acting.team_id));
}
</script>

<template>
    <AppLayout :title="$t('admin.tenants.title')">
        <template #header>
            <h2 class="text-xl font-medium tracking-tight text-shell-fg">{{ $t('admin.tenants.title') }}</h2>
        </template>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
            <AdminNav />
            <p class="text-sm text-shell-muted">{{ $t('admin.tenants.intro') }}</p>
            <form
                class="space-y-3 rounded-2xl border border-line bg-shell-panel p-6 text-shell-text"
                @submit.prevent="create"
            >
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">
                        {{ $t('admin.tenants.name') }}
                        <TextInput v-model="creating.name" required :class="field" />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.tenants.plan') }}
                        <select v-model="creating.plan_id" required :class="field">
                            <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.tenants.partner') }}
                        <select v-model="creating.partner_team_id" :class="field">
                            <option value="">{{ $t('admin.tenants.none') }}</option>
                            <option v-for="partner in partners" :key="partner.id" :value="partner.id">
                                {{ partner.name }}
                            </option>
                        </select>
                    </label>
                </div>
                <InputError v-for="(message, key) in creating.errors" :key="key" :message="message" />
                <PrimaryButton :disabled="creating.processing">{{ $t('admin.tenants.create') }}</PrimaryButton>
            </form>
            <EmptyState
                v-if="!tenants.length"
                :title="$t('app.empty.tenants_title')"
                :body="$t('app.empty.tenants_body')"
            />
            <form
                v-for="tenant in tenants"
                :key="tenant.id"
                class="space-y-3 rounded-2xl border border-line bg-shell-panel p-6 text-shell-text"
                @submit.prevent="save(tenant)"
            >
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label class="text-sm">
                        {{ $t('admin.tenants.name') }}
                        <TextInput v-model="editing[tenant.id].name" required :class="field" />
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.tenants.plan') }}
                        <select v-model="editing[tenant.id].plan_id" required :class="field">
                            <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.tenants.partner') }}
                        <select v-model="editing[tenant.id].partner_team_id" :class="field">
                            <option value="">{{ $t('admin.tenants.none') }}</option>
                            <option
                                v-for="partner in partners.filter((item) => item.id !== tenant.id)"
                                :key="partner.id"
                                :value="partner.id"
                            >
                                {{ partner.name }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.tenants.status') }}
                        <select v-model="editing[tenant.id].status" :class="field">
                            <option
                                v-for="status in tenant.status === 'closed' ? ['closed'] : statuses"
                                :key="status"
                                :value="status"
                            >
                                {{ $t(`admin.tenants.statuses.${status}`) }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.tenants.quota') }}
                        <TextInput
                            v-model="editing[tenant.id].monthly_call_quota_override"
                            type="number"
                            min="0"
                            :class="field"
                        />
                    </label>
                </div>
                <InputError v-for="(message, key) in editing[tenant.id].errors" :key="key" :message="message" />
                <ConfirmsPassword
                    v-if="isClosing(tenant)"
                    :title="closePassword.title"
                    :content="closePassword.content"
                    :button="closePassword.button"
                    @confirmed="save(tenant, true)"
                >
                    <PrimaryButton type="button" :disabled="editing[tenant.id].processing">
                        {{ $t('admin.tenants.save') }}
                    </PrimaryButton>
                </ConfirmsPassword>
                <PrimaryButton v-else :disabled="editing[tenant.id].processing">
                    {{ $t('admin.tenants.save') }}
                </PrimaryButton>
            </form>
            <form
                v-if="tenants.length"
                class="space-y-3 rounded-2xl border border-line bg-shell-panel p-6 text-shell-text"
                @submit.prevent
            >
                <h3 class="font-medium">{{ $t('admin.tenants.act') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">
                        {{ $t('admin.tenants.name') }}
                        <select v-model="acting.team_id" :class="field">
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                {{ tenant.name }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm">
                        {{ $t('admin.tenants.reason') }}
                        <TextInput v-model="acting.reason" required minlength="10" :class="field" />
                    </label>
                </div>
                <InputError :message="acting.errors.reason" />
                <ConfirmsPassword @confirmed="act">
                    <PrimaryButton type="button" :disabled="acting.processing">
                        {{ $t('admin.tenants.act') }}
                    </PrimaryButton>
                </ConfirmsPassword>
            </form>
        </div>
    </AppLayout>
</template>
