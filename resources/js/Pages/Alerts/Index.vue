<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    alerts: { type: Array, required: true },
    rules: { type: Array, required: true },
    tenantWideTypes: { type: Array, required: true },
    brands: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    type: 'visibility_drop',
    threshold: '',
    is_active: true,
});
const receipt = useForm({});

const tenantWide = computed(() => props.tenantWideTypes.includes(form.type));
const field = 'rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text';

function addRule() {
    form.transform((data) => (tenantWide.value ? { ...data, brand_id: null } : data)).post(
        route('alerts.rules.store'),
        {
            preserveScroll: true,
            preserveState: (page) => Object.keys(page.props.errors).length > 0,
        },
    );
}

function saveRule(rule) {
    rule.form.put(route('alerts.rules.update', rule.id), {
        preserveScroll: true,
        preserveState: (page) => Object.keys(page.props.errors).length > 0,
    });
}

function acknowledge(id) {
    receipt.post(route('alerts.acknowledge', id), { preserveScroll: true });
}

const editable = props.rules.map((rule) => ({
    id: rule.id,
    brand: rule.brand,
    type: rule.type,
    form: useForm({ threshold: rule.threshold ?? '', is_active: rule.is_active }),
}));
</script>

<template>
    <AppLayout :title="$t('app.nav.alerts')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">
                {{ $t('app.nav.alerts') }}
            </h2>
        </template>

        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6">
            <section class="space-y-3 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('app.nav.alerts') }}</h3>
                <EmptyState
                    v-if="alerts.length === 0"
                    :title="$t('app.empty.alerts_title')"
                    :body="$t('app.empty.alerts_body')"
                />
                <article
                    v-for="alert in alerts"
                    :key="alert.id"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <div>
                        <p>{{ alert.title }}</p>
                        <p class="text-shell-muted">
                            <span v-if="alert.brand && !alert.detail.includes(alert.brand)">{{ alert.brand }} ·</span>
                            {{ alert.detail }}
                        </p>
                    </div>
                    <p v-if="alert.acknowledged">{{ $t('app.workspace.acknowledged') }}</p>
                    <button v-else-if="canManage" type="button" class="text-sm" @click="acknowledge(alert.id)">
                        {{ $t('app.workspace.acknowledge') }}
                    </button>
                </article>
            </section>

            <section class="space-y-4 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('app.workspace.rules') }}</h3>
                <EmptyState
                    v-if="editable.length === 0"
                    :title="$t('app.empty.rules_title')"
                    :body="$t('app.empty.rules_body')"
                />
                <form
                    v-for="rule in editable"
                    :key="rule.id"
                    class="grid gap-2 border-t border-line pt-3 sm:grid-cols-[1fr_8rem_auto_auto]"
                    @submit.prevent="saveRule(rule)"
                >
                    <p class="text-sm">
                        {{ rule.brand ?? $t('app.workspace.tenant_wide') }} ·
                        {{ $t(`app.alert_types.${rule.type}`) }}
                    </p>
                    <input
                        v-model="rule.form.threshold"
                        :aria-label="$t('app.workspace.threshold')"
                        :disabled="!canManage"
                        :class="field"
                    />
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="rule.form.is_active" :disabled="!canManage" type="checkbox" />
                        {{ $t('app.workspace.active') }}
                    </label>
                    <PrimaryButton v-if="canManage" :disabled="rule.form.processing">
                        {{ $t('app.workspace.save') }}
                    </PrimaryButton>
                    <InputError
                        class="sm:col-span-4"
                        :message="rule.form.errors.threshold || rule.form.errors.is_active"
                    />
                </form>

                <form v-if="canManage" class="grid gap-2 border-t border-line pt-3" @submit.prevent="addRule">
                    <h4 class="text-sm font-medium">{{ $t('app.workspace.add_rule') }}</h4>
                    <select
                        v-if="!tenantWide"
                        v-model="form.brand_id"
                        :aria-label="$t('app.workspace.brand')"
                        :class="field"
                    >
                        <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                    </select>
                    <p v-else class="text-sm text-shell-muted">{{ $t('app.workspace.tenant_wide') }}</p>
                    <InputError :message="form.errors.brand_id" />
                    <select v-model="form.type" :aria-label="$t('app.workspace.type')" :class="field">
                        <option value="visibility_drop">{{ $t('app.alert_types.visibility_drop') }}</option>
                        <option value="competitor_takeover">{{ $t('app.alert_types.competitor_takeover') }}</option>
                        <option value="citation_lost">{{ $t('app.alert_types.citation_lost') }}</option>
                        <option value="new_competitor">{{ $t('app.alert_types.new_competitor') }}</option>
                        <option value="model_change">{{ $t('app.alert_types.model_change') }}</option>
                        <option value="quota">{{ $t('app.alert_types.quota') }}</option>
                        <option value="integration_error">{{ $t('app.alert_types.integration_error') }}</option>
                    </select>
                    <InputError :message="form.errors.type" />
                    <input
                        v-model="form.threshold"
                        :aria-label="$t('app.workspace.threshold')"
                        :class="field"
                        :placeholder="$t('app.workspace.threshold')"
                    />
                    <InputError :message="form.errors.threshold" />
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_active" type="checkbox" />
                        {{ $t('app.workspace.active') }}
                    </label>
                    <InputError :message="form.errors.is_active" />
                    <PrimaryButton :disabled="form.processing">{{ $t('app.workspace.add_rule') }}</PrimaryButton>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
