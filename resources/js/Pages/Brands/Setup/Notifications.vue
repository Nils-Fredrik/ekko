<script setup>
// Alerts and report plans for one brand (docs/ux-arbeidsflate.md 5.7, U26).
import AlertRuleRow from '@/Components/Alerts/AlertRuleRow.vue';
import Drawer from '@/Components/UI/Drawer.vue';
import { useUndoToast } from '@/Composables/useUndoToast';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/format';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    tenant: { type: String, required: true },
    rules: { type: Array, required: true },
    schedules: { type: Array, required: true },
    reports: { type: Array, required: true },
    locales: { type: Array, required: true },
    canAlerts: { type: Boolean, required: true },
    canReports: { type: Boolean, required: true },
});

const page = usePage();
const toast = useUndoToast();
const editing = ref(null);
const email = ref('');
const shareUrl = ref('');
const shareFor = ref(null);
const form = reactive({ cadence: 'monthly', recipients: [], locale: 'nb', branding: 'tenant' });
const crumb = computed(() =>
    [props.tenant, trans('workspace.shell.setup'), props.brand.name].filter(Boolean).join(' · '),
);
const open = computed(() => editing.value !== null);
const errors = computed(() => page.props.errors ?? {});

function reset(schedule = null) {
    editing.value = schedule ? schedule.id : 'new';
    form.cadence = schedule?.cadence ?? 'monthly';
    form.recipients = [...(schedule?.recipients ?? [])];
    form.locale = schedule?.locale ?? props.locales[0] ?? 'nb';
    form.branding = schedule?.branding ?? 'tenant';
    email.value = '';
}

function close() {
    editing.value = null;
}

function addRecipient() {
    const value = email.value.trim();
    if (value !== '' && !form.recipients.includes(value)) {
        form.recipients.push(value);
    }
    email.value = '';
}

function saveSchedule() {
    const payload = {
        cadence: form.cadence,
        recipients: form.recipients,
        locale: form.locale,
        branding: form.branding,
    };
    const options = {
        preserveScroll: true,
        only: ['schedules', 'reports'],
        onSuccess: () => close(),
    };
    if (editing.value === 'new') {
        router.post(route('brands.report-schedules.store', props.brand.id), payload, options);
    } else {
        router.put(route('brands.report-schedules.update', [props.brand.id, editing.value]), payload, options);
    }
}

function removeSchedule() {
    if (editing.value === 'new' || editing.value === null) {
        return;
    }
    router.delete(route('brands.report-schedules.destroy', [props.brand.id, editing.value]), {
        preserveScroll: true,
        only: ['schedules', 'reports'],
        onSuccess: () => close(),
    });
}

function sendTest(id) {
    router.post(
        route('brands.report-schedules.test', [props.brand.id, id]),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.show(trans('workspace.notifications.test_sent')),
            onError: () => toast.show(trans('workspace.notifications.test_failed')),
        },
    );
}

async function createLink(reportId) {
    shareUrl.value = '';
    shareFor.value = reportId;
    const response = await axios.post(route('reports.links.store', reportId), { days: 90 });
    shareUrl.value = response.data.url;
    router.reload({ only: ['reports'], preserveScroll: true, preserveState: true });
}

async function revokeLink(reportId, id) {
    await axios.delete(route('reports.links.destroy', [reportId, id]));
    if (shareFor.value === reportId) {
        shareUrl.value = '';
    }
    router.reload({ only: ['reports'], preserveScroll: true, preserveState: true });
}
</script>

<template>
    <AppLayout :title="$t('workspace.shell.setup_notifications')">
        <div class="mx-auto flex max-w-7xl items-start gap-4 px-4 py-6 sm:px-6">
            <div class="flex min-w-0 flex-1 flex-col gap-8">
                <div>
                    <p class="text-xs text-shell-muted">{{ crumb }}</p>
                    <h1 class="mt-1 text-[26px] font-medium tracking-tight text-shell-fg">
                        {{ $t('workspace.shell.setup_notifications') }}
                    </h1>
                </div>

                <section>
                    <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.notifications.rules') }}</h2>
                    <AlertRuleRow
                        v-for="rule in rules"
                        :key="rule.type"
                        :rule="rule"
                        :brand-id="brand.id"
                        :tenant="tenant"
                        :can="canAlerts"
                    />
                </section>

                <section>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.notifications.schedules') }}</h2>
                        <button
                            v-if="canReports"
                            type="button"
                            class="rounded-full bg-primary-deep px-3 py-1.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            data-testid="new-schedule"
                            @click="reset()"
                        >
                            {{ $t('workspace.notifications.new_schedule') }}
                        </button>
                    </div>
                    <p v-if="schedules.length === 0" class="mt-3 text-sm text-shell-muted">
                        {{ $t('workspace.notifications.no_schedules') }}
                    </p>
                    <ul v-else class="mt-3 flex flex-col gap-2">
                        <li
                            v-for="schedule in schedules"
                            :key="schedule.id"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-line-soft bg-shell-surface p-3"
                        >
                            <div>
                                <p class="text-sm text-shell-fg">
                                    {{ $t(`workspace.notifications.cadence.${schedule.cadence}`) }}
                                    · {{ $t(`workspace.notifications.branding.${schedule.branding}`) }} ·
                                    {{ schedule.locale }}
                                </p>
                                <ul class="mt-1 flex flex-wrap gap-1">
                                    <li
                                        v-for="recipient in schedule.recipients"
                                        :key="recipient"
                                        class="rounded-full bg-line-soft px-2 py-0.5 text-xs text-shell-text"
                                    >
                                        {{ recipient }}
                                    </li>
                                </ul>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    v-if="canReports"
                                    type="button"
                                    class="rounded-full border border-line px-3 py-1.5 text-sm"
                                    @click="sendTest(schedule.id)"
                                >
                                    {{ $t('workspace.notifications.send_test') }}
                                </button>
                                <button
                                    v-if="canReports"
                                    type="button"
                                    class="rounded-full border border-line px-3 py-1.5 text-sm"
                                    @click="reset(schedule)"
                                >
                                    {{ $t('workspace.notifications.edit') }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-sm font-medium text-shell-fg">{{ $t('workspace.notifications.history') }}</h2>
                    <p v-if="reports.length === 0" class="mt-3 text-sm text-shell-muted">
                        {{ $t('workspace.notifications.no_reports') }}
                    </p>
                    <ul v-else class="mt-3 flex flex-col gap-2">
                        <li
                            v-for="report in reports"
                            :key="report.id"
                            class="rounded-[14px] border border-line-soft bg-shell-surface p-3 text-sm"
                        >
                            <p class="text-shell-fg">
                                {{
                                    $t('workspace.notifications.period', {
                                        from: formatDate(report.period_start),
                                        to: formatDate(report.period_end),
                                    })
                                }}
                                <span class="text-shell-muted">
                                    {{
                                        report.sent_at
                                            ? $t('workspace.notifications.sent', {
                                                  when: formatDateTime(report.sent_at),
                                              })
                                            : $t('workspace.notifications.not_sent')
                                    }}
                                </span>
                            </p>
                            <p class="mt-1 flex flex-wrap gap-3">
                                <a :href="route('reports.show', report.id)" class="text-shell-accent no-underline">
                                    {{ $t('workspace.notifications.web') }}
                                </a>
                                <a
                                    v-if="report.downloadable"
                                    :href="route('reports.download', report.id)"
                                    class="text-shell-accent no-underline"
                                >
                                    {{ $t('workspace.notifications.pdf') }}
                                </a>
                                <button
                                    v-if="canReports"
                                    type="button"
                                    class="text-shell-accent"
                                    @click="createLink(report.id)"
                                >
                                    {{ $t('workspace.notifications.share') }}
                                </button>
                            </p>
                            <label
                                v-if="shareFor === report.id && shareUrl"
                                class="mt-2 block text-xs text-shell-muted"
                            >
                                {{ $t('workspace.notifications.share_once') }}
                                <input
                                    :value="shareUrl"
                                    readonly
                                    class="mt-1 w-full rounded-lg border-line bg-shell text-sm"
                                />
                            </label>
                            <ul v-if="report.links.length" class="mt-2 space-y-1 text-xs text-shell-muted">
                                <li v-for="link in report.links" :key="link.id" class="flex items-center gap-2">
                                    <span>
                                        {{
                                            link.revoked_at
                                                ? $t('workspace.notifications.revoked')
                                                : $t('workspace.notifications.expires', {
                                                      when: formatDate(link.expires_at),
                                                  })
                                        }}
                                    </span>
                                    <button
                                        v-if="canReports && !link.revoked_at"
                                        type="button"
                                        class="underline"
                                        @click="revokeLink(report.id, link.id)"
                                    >
                                        {{ $t('workspace.notifications.revoke') }}
                                    </button>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </section>
            </div>

            <Drawer :open="open" :label="$t('workspace.notifications.schedule')" @close="close">
                <form class="flex flex-col gap-4" @submit.prevent="saveSchedule">
                    <label class="text-sm text-shell-text">
                        {{ $t('workspace.notifications.cadence_label') }}
                        <select v-model="form.cadence" class="mt-1 w-full rounded-lg border-line bg-shell-surface">
                            <option value="weekly">{{ $t('workspace.notifications.cadence.weekly') }}</option>
                            <option value="monthly">{{ $t('workspace.notifications.cadence.monthly') }}</option>
                        </select>
                    </label>
                    <label class="text-sm text-shell-text">
                        {{ $t('workspace.notifications.language') }}
                        <select v-model="form.locale" class="mt-1 w-full rounded-lg border-line bg-shell-surface">
                            <option v-for="locale in locales" :key="locale" :value="locale">{{ locale }}</option>
                        </select>
                    </label>
                    <label class="text-sm text-shell-text">
                        {{ $t('workspace.notifications.profile') }}
                        <select v-model="form.branding" class="mt-1 w-full rounded-lg border-line bg-shell-surface">
                            <option value="tenant">{{ $t('workspace.notifications.branding.tenant') }}</option>
                            <option value="partner">{{ $t('workspace.notifications.branding.partner') }}</option>
                        </select>
                    </label>
                    <div>
                        <p class="text-sm text-shell-text">{{ $t('workspace.notifications.recipients') }}</p>
                        <ul class="mt-2 flex flex-wrap gap-1">
                            <li v-for="recipient in form.recipients" :key="recipient">
                                <button
                                    type="button"
                                    class="rounded-full bg-line-soft px-2 py-0.5 text-xs"
                                    @click="form.recipients = form.recipients.filter((item) => item !== recipient)"
                                >
                                    {{ recipient }}
                                    <span class="sr-only">{{ $t('workspace.notifications.remove_recipient') }}</span>
                                </button>
                            </li>
                        </ul>
                        <div class="mt-2 flex gap-2">
                            <input
                                v-model="email"
                                type="email"
                                class="min-w-0 flex-1 rounded-lg border-line bg-shell-surface text-sm"
                                :aria-label="$t('workspace.notifications.recipient_email')"
                                @keydown.enter.prevent="addRecipient"
                            />
                            <button
                                type="button"
                                class="rounded-full border border-line px-3 py-1.5 text-sm"
                                @click="addRecipient"
                            >
                                {{ $t('workspace.notifications.add_recipient') }}
                            </button>
                        </div>
                        <p v-if="errors.recipients || errors['recipients.0']" class="mt-1 text-xs text-error">
                            {{ errors.recipients || errors['recipients.0'] }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-full bg-primary-deep px-3 py-1.5 text-sm text-white">
                            {{ $t('workspace.notifications.save') }}
                        </button>
                        <button
                            v-if="editing !== 'new'"
                            type="button"
                            class="rounded-full border border-line px-3 py-1.5 text-sm"
                            @click="removeSchedule"
                        >
                            {{ $t('workspace.notifications.delete') }}
                        </button>
                    </div>
                </form>
            </Drawer>
        </div>
    </AppLayout>
</template>
