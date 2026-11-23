<script setup>
import ReportSection from '@/Components/Actions/ReportSection.vue';
import WorkspaceHeader from '@/Components/Shell/WorkspaceHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    brand: { type: String, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    actions: { type: Object, required: true },
    downloadable: { type: Boolean, required: true },
    reportId: { type: Number, required: true },
    canShare: { type: Boolean, required: true },
    links: { type: Array, required: true },
});
const days = ref(90);
const shareUrl = ref('');
const sharedLinkId = ref(null);
const busy = ref(false);
const failed = ref(false);

async function createLink() {
    busy.value = true;
    failed.value = false;
    try {
        const response = await axios.post(route('reports.links.store', props.reportId), { days: days.value });
        shareUrl.value = response.data.url;
        sharedLinkId.value = response.data.id;
        router.reload({ only: ['links'] });
    } catch {
        failed.value = true;
    } finally {
        busy.value = false;
    }
}

async function revokeLink(id) {
    busy.value = true;
    failed.value = false;
    try {
        await axios.delete(route('reports.links.destroy', [props.reportId, id]));
        if (sharedLinkId.value === id) {
            shareUrl.value = '';
            sharedLinkId.value = null;
        }
        router.reload({ only: ['links'] });
    } catch {
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppLayout :title="$t('app.nav.reports')">
        <template #header>
            <WorkspaceHeader :brand="brand" :group="$t('workspace.shell.work')" :title="$t('app.nav.reports')" />
        </template>
        <div class="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-8">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-shell-muted">{{ $t('app.workspace.period_range', { start: from, end: to }) }}</p>
                <a v-if="downloadable" :href="route('reports.download', reportId)" class="text-sm text-primary">
                    {{ $t('app.workspace.download') }}
                </a>
            </div>
            <ReportSection :actions="actions" />
            <section v-if="canShare" class="rounded border border-shell-border p-4">
                <h3 class="font-semibold">{{ $t('reports.share.title') }}</h3>
                <p class="mt-1 text-sm">{{ $t('reports.share.once') }}</p>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="createLink">
                    <label class="text-sm">
                        {{ $t('reports.share.days') }}
                        <input
                            v-model.number="days"
                            type="number"
                            min="1"
                            max="3650"
                            required
                            class="block rounded border-shell-border"
                        />
                    </label>
                    <button type="submit" :disabled="busy" class="rounded bg-primary px-3 py-2 text-white">
                        {{ $t('reports.share.create') }}
                    </button>
                </form>
                <p v-if="failed" role="alert" class="mt-2 text-sm text-red-700">{{ $t('reports.share.failed') }}</p>
                <label v-if="shareUrl" class="mt-3 block text-sm">
                    {{ $t('reports.share.url') }}
                    <input
                        :value="shareUrl"
                        readonly
                        class="block w-full rounded border-shell-border"
                        @focus="$event.target.select()"
                    />
                </label>
                <ul class="mt-3 space-y-2">
                    <li v-for="link in links" :key="link.id" class="flex flex-wrap items-center gap-3 text-sm">
                        <span>
                            {{ $t('reports.share.expiry', { date: link.expires_at.slice(0, 10), views: link.views }) }}
                        </span>
                        <span v-if="link.revoked_at">{{ $t('reports.share.revoked') }}</span>
                        <button
                            v-else
                            type="button"
                            :disabled="busy"
                            class="text-primary underline"
                            @click="revokeLink(link.id)"
                        >
                            {{ $t('reports.share.revoke') }}
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
