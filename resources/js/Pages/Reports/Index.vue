<script setup>
import { useDateTime } from '@/Composables/useDateTime';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const { dateTime } = useDateTime();

const props = defineProps({
    schedules: { type: Array, required: true },
    reports: { type: Array, required: true },
    brands: { type: Array, required: true },
    reportLocales: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const field = 'rounded-lg border border-line bg-shell-surface px-3 py-2 text-sm text-shell-text';

function blank(brandId) {
    return useForm({
        brand_id: brandId ?? '',
        cadence: 'monthly',
        recipients: '',
        locale: props.reportLocales[0] ?? 'nb',
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

function recipientError(errors) {
    if (errors.recipients) {
        return errors.recipients;
    }

    const key = Object.keys(errors).find((name) => name.startsWith('recipients.'));

    return key ? errors[key] : '';
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
            <section class="space-y-4 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('app.workspace.schedules') }}</h3>
                <EmptyState
                    v-if="rows.length === 0"
                    :title="$t('app.empty.schedules_title')"
                    :body="$t('app.empty.schedules_body')"
                />
                <form
                    v-for="row in rows"
                    :key="row.id"
                    class="grid gap-2 border-t border-line pt-3"
                    @submit.prevent="save(row)"
                >
                    <p class="text-sm font-medium">{{ row.brand }}</p>
                    <select
                        v-model="row.form.cadence"
                        :aria-label="$t('app.workspace.cadence')"
                        :disabled="!canManage"
                        :class="field"
                    >
                        <option value="weekly">{{ $t('app.workspace.cadence_weekly') }}</option>
                        <option value="monthly">{{ $t('app.workspace.cadence_monthly') }}</option>
                    </select>
                    <InputError :message="row.form.errors.cadence" />
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
                    <p class="text-xs text-shell-muted">{{ $t('app.workspace.recipients_help') }}</p>
                    <InputError :message="recipientError(row.form.errors)" />
                    <select
                        v-model="row.form.locale"
                        :aria-label="$t('app.workspace.locale')"
                        :disabled="!canManage"
                        :class="field"
                    >
                        <option v-for="locale in reportLocales" :key="locale" :value="locale">
                            {{ $t(`app.locales.${locale}`) }}
                        </option>
                    </select>
                    <InputError :message="row.form.errors.locale" />
                    <select
                        v-model="row.form.branding"
                        :aria-label="$t('app.workspace.branding')"
                        :disabled="!canManage"
                        :class="field"
                    >
                        <option value="tenant">{{ $t('app.workspace.branding_tenant') }}</option>
                        <option value="partner">{{ $t('app.workspace.branding_partner') }}</option>
                    </select>
                    <InputError :message="row.form.errors.branding" />
                    <PrimaryButton v-if="canManage" :disabled="row.form.processing">
                        {{ $t('app.workspace.save') }}
                    </PrimaryButton>
                </form>

                <form v-if="canManage" class="grid gap-2 border-t border-line pt-3" @submit.prevent="add">
                    <h4 class="text-sm font-medium">{{ $t('app.workspace.add_schedule') }}</h4>
                    <select v-model="created.brand_id" :aria-label="$t('app.workspace.brand')" :class="field">
                        <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                    </select>
                    <InputError :message="created.errors.brand_id" />
                    <select v-model="created.cadence" :aria-label="$t('app.workspace.cadence')" :class="field">
                        <option value="weekly">{{ $t('app.workspace.cadence_weekly') }}</option>
                        <option value="monthly">{{ $t('app.workspace.cadence_monthly') }}</option>
                    </select>
                    <InputError :message="created.errors.cadence" />
                    <textarea
                        v-model="created.recipients"
                        :aria-label="$t('app.workspace.recipients')"
                        :class="field"
                        rows="3"
                    />
                    <InputError :message="recipientError(created.errors)" />
                    <select v-model="created.locale" :aria-label="$t('app.workspace.locale')" :class="field">
                        <option v-for="locale in reportLocales" :key="locale" :value="locale">
                            {{ $t(`app.locales.${locale}`) }}
                        </option>
                    </select>
                    <InputError :message="created.errors.locale" />
                    <select v-model="created.branding" :aria-label="$t('app.workspace.branding')" :class="field">
                        <option value="tenant">{{ $t('app.workspace.branding_tenant') }}</option>
                        <option value="partner">{{ $t('app.workspace.branding_partner') }}</option>
                    </select>
                    <InputError :message="created.errors.branding" />
                    <PrimaryButton :disabled="created.processing">{{ $t('app.workspace.add_schedule') }}</PrimaryButton>
                </form>
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-shell-panel p-5 text-shell-text">
                <h3 class="font-medium">{{ $t('app.workspace.history') }}</h3>
                <EmptyState
                    v-if="reports.length === 0"
                    :title="$t('app.empty.reports_title')"
                    :body="$t('app.empty.reports_body')"
                />
                <article
                    v-for="report in reports"
                    :key="report.id"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <p>
                        {{ report.brand }} ·
                        {{ $t('app.workspace.period_range', { start: report.period_start, end: report.period_end }) }}
                    </p>
                    <span class="flex items-center gap-3">
                        <Link :href="route('reports.show', report.id)" class="text-sm text-primary no-underline">
                            {{ $t('app.workspace.view') }}
                        </Link>
                        <a v-if="report.downloadable" :href="route('reports.download', report.id)" class="text-sm">
                            {{ $t('app.workspace.download') }}
                        </a>
                        <span v-else class="text-shell-muted">
                            {{ report.sent_at ? dateTime(report.sent_at) : $t('app.workspace.not_sent') }}
                        </span>
                    </span>
                </article>
            </section>
        </div>
    </AppLayout>
</template>
