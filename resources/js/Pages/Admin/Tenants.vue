<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminNav from '@/Components/AdminNav.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmsPassword from '@/Components/ConfirmsPassword.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ tenants: Array, plans: Array, partners: Array, statuses: Array });
const field = 'mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink';

const creating = useForm({ name: '', plan_id: props.plans[0]?.id ?? '', partner_team_id: '' });
const acting = useForm({ team_id: props.tenants[0]?.id ?? '', reason: '' });
const editing = Object.fromEntries(
    props.tenants.map((tenant) => [
        tenant.id,
        useForm({
            name: tenant.name,
            plan_id: tenant.plan_id ?? '',
            partner_team_id: tenant.partner_team_id ?? '',
            status: tenant.status,
            monthly_call_quota_override: tenant.monthly_call_quota_override ?? '',
        }),
    ]),
);

function create() {
    creating
        .transform((data) => ({ ...data, partner_team_id: data.partner_team_id || null }))
        .post(route('admin.tenants.store'), { preserveScroll: true });
}

function save(id) {
    editing[id]
        .transform((data) => ({
            ...data,
            partner_team_id: data.partner_team_id || null,
            monthly_call_quota_override:
                data.monthly_call_quota_override === '' ? null : data.monthly_call_quota_override,
        }))
        .put(route('admin.tenants.update', id), { preserveScroll: true });
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
            <p class="text-sm text-ink-soft">{{ $t('admin.tenants.intro') }}</p>
            <form class="space-y-3 rounded-2xl border border-line bg-white p-6 text-ink" @submit.prevent="create">
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
                <InputError :message="creating.errors.name" />
                <InputError :message="creating.errors.plan_id" />
                <InputError :message="creating.errors.partner_team_id" />
                <PrimaryButton :disabled="creating.processing">{{ $t('admin.tenants.create') }}</PrimaryButton>
            </form>
            <p v-if="!tenants.length" class="text-sm text-ink-soft">{{ $t('admin.tenants.empty') }}</p>
            <form
                v-for="tenant in tenants"
                :key="tenant.id"
                class="space-y-3 rounded-2xl border border-line bg-white p-6 text-ink"
                @submit.prevent="save(tenant.id)"
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
                            <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
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
                <InputError :message="editing[tenant.id].errors.name" />
                <InputError :message="editing[tenant.id].errors.partner_team_id" />
                <PrimaryButton :disabled="editing[tenant.id].processing">{{ $t('admin.tenants.save') }}</PrimaryButton>
            </form>
            <form
                v-if="tenants.length"
                class="space-y-3 rounded-2xl border border-line bg-white p-6 text-ink"
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
