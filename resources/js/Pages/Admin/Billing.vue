<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AdminNav from '@/Components/AdminNav.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BillingMarkupForm from '@/Components/BillingMarkupForm.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ month: String, rows: Array, accounts: Array });
const filter = useForm({ month: props.month });
const ready = computed(() => props.rows.length > 0 && props.rows.every((row) => row.invoice_total_usd !== null));
</script>

<template>
    <AppLayout :title="$t('billing.title')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ $t('billing.title') }}</h2>
        </template>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
            <AdminNav />
            <section class="rounded-2xl border border-line bg-shell-panel p-6 text-shell-text">
                <p class="text-sm text-shell-muted">{{ $t('billing.intro') }}</p>
                <form
                    class="mt-5 flex flex-wrap items-end gap-3"
                    @submit.prevent="filter.get(route('admin.billing.index'))"
                >
                    <div>
                        <label for="billing-month" class="mb-2 block text-sm">{{ $t('billing.month') }}</label>
                        <TextInput id="billing-month" v-model="filter.month" type="month" required />
                    </div>
                    <PrimaryButton :disabled="filter.processing">{{ $t('billing.show') }}</PrimaryButton>
                    <a
                        v-if="ready"
                        :href="route('admin.billing.export', { month })"
                        class="rounded-full border border-line px-4 py-2 text-sm font-medium text-primary hover:bg-shell-surface"
                    >
                        {{ $t('billing.download') }}
                    </a>
                </form>
                <InputError :message="filter.errors.month" class="mt-3" />
                <p v-if="rows.length && !ready" role="status" class="mt-4 text-sm text-shell-text">
                    {{ $t('billing.missing_markup') }}
                </p>
            </section>
            <section v-if="accounts.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="account in accounts"
                    :key="account.id"
                    class="rounded-2xl border border-line bg-shell-panel p-5 text-shell-text"
                >
                    <h3 class="font-medium">{{ account.name }}</h3>
                    <p class="mt-1 text-xs text-shell-muted">{{ $t('billing.account_total') }}</p>
                    <p class="mt-3 text-xl tabular-nums">{{ account.invoice_total_usd ?? $t('billing.unpriced') }}</p>
                </div>
            </section>
            <section class="overflow-x-auto rounded-2xl border border-line bg-shell-panel p-6 text-shell-text">
                <p v-if="!rows.length" class="text-sm text-shell-muted">{{ $t('billing.empty') }}</p>
                <table v-else class="w-full text-left text-sm">
                    <thead class="text-xs text-shell-muted">
                        <tr>
                            <th class="p-3">{{ $t('billing.customer') }}</th>
                            <th class="p-3">{{ $t('billing.calls') }}</th>
                            <th class="p-3">{{ $t('billing.platform_cost') }}</th>
                            <th class="p-3">{{ $t('billing.byok_cost') }}</th>
                            <th class="p-3">{{ $t('billing.markup') }}</th>
                            <th class="p-3">{{ $t('billing.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.tenant_id" class="border-t border-line-soft">
                            <td class="p-3">
                                <span class="block font-medium">{{ row.tenant_name }}</span>
                                <span class="block text-xs text-shell-muted">{{ row.plan }}</span>
                                <span class="block text-xs text-shell-muted">
                                    {{ $t('billing.recipient', { name: row.account_name }) }}
                                </span>
                            </td>
                            <td class="p-3 tabular-nums">{{ row.calls }}</td>
                            <td class="p-3 tabular-nums">{{ row.platform_cost_usd }}</td>
                            <td class="p-3 tabular-nums">{{ row.byok_cost_usd }}</td>
                            <td class="p-3">
                                <BillingMarkupForm
                                    :tenant-id="row.tenant_id"
                                    :name="row.tenant_name"
                                    :value="row.markup_percent"
                                />
                            </td>
                            <td class="p-3 font-medium tabular-nums">
                                {{ row.invoice_total_usd ?? $t('billing.unpriced') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
