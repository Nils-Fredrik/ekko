<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    schedules: { type: Array, required: true },
    reports: { type: Array, required: true },
    brands: { type: Array, required: true },
    locales: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const field = 'rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink';

function blank(brandId) {
    return useForm({
        brand_id: brandId ?? '',
        cadence: 'monthly',
        recipients: '',
        locale: props.locales[0] ?? 'nb',
        branding: 'tenant',
    });
}

const rows = props.schedules.map((schedule) => ({
    id: schedule.id,
    brand: schedule.brand,
    form: useForm({
        cadence: schedule.cadence,
        recipients: schedule.recipients.join('\n'),
        locale: schedule.locale,
        branding: schedule.branding,
    }),
}));

const created = blank(props.brands[0]?.id);

function payload(data) {
    return {
        ...data,
        recipients: String(data.recipients)
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== ''),
    };
}

function save(row) {
    row.form
        .transform((data) => payload(data))
        .put(route('reports.schedules.update', row.id), {
            preserveScroll: true,
            preserveState: (page) => Object.keys(page.props.errors).length > 0,
        });
}

function add() {
    created
        .transform((data) => payload(data))
        .post(route('reports.schedules.store'), {
            preserveScroll: true,
            preserveState: (page) => Object.keys(page.props.errors).length > 0,
        });
}
</script>

<template>
    <AppLayout :title="$t('app.nav.reports')">
        <template #header>
            <h2 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">
                {{ $t('app.nav.reports') }}
            </h2>
        </template>

        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:px-6">
            <section class="space-y-4 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.schedules') }}</h3>
                <p v-if="rows.length === 0">{{ $t('app.workspace.no_schedules') }}</p>
                <form
                    v-for="row in rows"
                    :key="row.id"
                    class="grid gap-2 border-t border-line pt-3"
                    @submit.prevent="save(row)"
                >
                    <p class="text-sm font-medium">{{ row.brand }}</p>
                    <select v-model="row.form.cadence" :disabled="!canManage" :class="field">
                        <option value="weekly">{{ $t('app.workspace.cadence_weekly') }}</option>
                        <option value="monthly">{{ $t('app.workspace.cadence_monthly') }}</option>
                    </select>
                    <label class="text-sm">
                        {{ $t('app.workspace.recipients') }}
                        <textarea
                            v-model="row.form.recipients"
                            :disabled="!canManage"
                            :class="field"
                            class="mt-1 w-full"
                            rows="3"
                        />
                    </label>
                    <p class="text-xs text-ink-soft">{{ $t('app.workspace.recipients_help') }}</p>
                    <InputError :message="row.form.errors.recipients || row.form.errors['recipients.0']" />
                    <InputError :message="row.form.errors['recipients.0']" />
                    <select v-model="row.form.locale" :disabled="!canManage" :class="field">
                        <option v-for="locale in locales" :key="locale" :value="locale">{{ locale }}</option>
                    </select>
                    <select v-model="row.form.branding" :disabled="!canManage" :class="field">
                        <option value="tenant">{{ $t('app.workspace.branding_tenant') }}</option>
                        <option value="partner">{{ $t('app.workspace.branding_partner') }}</option>
                    </select>
                    <PrimaryButton v-if="canManage" :disabled="row.form.processing">
                        {{ $t('app.workspace.save') }}
                    </PrimaryButton>
                </form>

                <form v-if="canManage" class="grid gap-2 border-t border-line pt-3" @submit.prevent="add">
                    <h4 class="text-sm font-medium">{{ $t('app.workspace.add_schedule') }}</h4>
                    <select v-model="created.brand_id" :class="field">
                        <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                    </select>
                    <InputError :message="created.errors.brand_id" />
                    <select v-model="created.cadence" :class="field">
                        <option value="weekly">{{ $t('app.workspace.cadence_weekly') }}</option>
                        <option value="monthly">{{ $t('app.workspace.cadence_monthly') }}</option>
                    </select>
                    <textarea v-model="created.recipients" :class="field" rows="3" />
                    <InputError :message="created.errors.recipients || created.errors['recipients.0']" />
                    <select v-model="created.locale" :class="field">
                        <option v-for="locale in locales" :key="locale" :value="locale">{{ locale }}</option>
                    </select>
                    <select v-model="created.branding" :class="field">
                        <option value="tenant">{{ $t('app.workspace.branding_tenant') }}</option>
                        <option value="partner">{{ $t('app.workspace.branding_partner') }}</option>
                    </select>
                    <PrimaryButton :disabled="created.processing">{{ $t('app.workspace.add_schedule') }}</PrimaryButton>
                </form>
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-white p-5 text-ink">
                <h3 class="font-medium">{{ $t('app.workspace.history') }}</h3>
                <p v-if="reports.length === 0">{{ $t('app.workspace.no_reports') }}</p>
                <article
                    v-for="report in reports"
                    :key="report.id"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <p>
                        {{ report.brand }} ·
                        {{ $t('app.workspace.period_range', { start: report.period_start, end: report.period_end }) }}
                    </p>
                    <Link v-if="report.downloadable" :href="route('reports.download', report.id)" class="text-sm">
                        {{ $t('app.workspace.download') }}
                    </Link>
                    <p v-else class="text-ink-soft">
                        {{ report.sent_at ? report.sent_at : $t('app.workspace.not_sent') }}
                    </p>
                </article>
            </section>
        </div>
    </AppLayout>
</template>
