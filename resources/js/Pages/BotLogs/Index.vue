<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    brands: { type: Array, required: true },
    enabled: Boolean,
    uploads: { type: Array, required: true },
});

const brandId = ref(props.brands[0]?.id ?? null);
const fileInput = ref(null);
const domains = computed(() => props.brands.find((brand) => brand.id === brandId.value)?.domains ?? []);
const form = useForm({ domain: domains.value[0] ?? '', log: null });

watch(domains, (available) => {
    form.domain = available[0] ?? '';
});

function submitUpload() {
    form.post(route('bot-log-uploads.store', { brand: brandId.value }), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('log');
            fileInput.value.value = '';
        },
    });
}
</script>

<template>
    <AppLayout :title="$t('bot_logs.title')">
        <template #header>
            <h1 class="text-xl leading-tight font-medium tracking-tight text-shell-fg">{{ $t('bot_logs.title') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-8 px-4 py-12 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-line bg-shell-panel p-6 text-shell-text">
                <form v-if="enabled && brands.length" class="space-y-4" @submit.prevent="submitUpload">
                    <p class="text-sm text-shell-muted">{{ $t('bot_logs.instructions') }}</p>
                    <div>
                        <InputLabel for="log-brand" :value="$t('bot_logs.brand')" />
                        <select id="log-brand" v-model="brandId" class="mt-1 w-full rounded-md border-line">
                            <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="log-domain" :value="$t('bot_logs.domain')" />
                        <select id="log-domain" v-model="form.domain" class="mt-1 w-full rounded-md border-line">
                            <option v-for="domain in domains" :key="domain" :value="domain">{{ domain }}</option>
                        </select>
                        <InputError :message="form.errors.domain" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="log-file" :value="$t('bot_logs.file')" />
                        <input
                            id="log-file"
                            ref="fileInput"
                            type="file"
                            accept=".gz,application/gzip"
                            required
                            class="mt-1 w-full text-sm"
                            @change="form.log = $event.target.files[0] ?? null"
                        />
                        <InputError :message="form.errors.log" class="mt-2" />
                    </div>
                    <PrimaryButton :disabled="form.processing || !form.log">{{ $t('bot_logs.upload') }}</PrimaryButton>
                </form>
                <p v-else class="text-sm text-shell-muted">{{ $t('bot_logs.unavailable') }}</p>
            </div>

            <section class="rounded-2xl border border-line bg-shell-panel p-6 text-shell-text">
                <h2 class="font-medium">{{ $t('bot_logs.recent_uploads') }}</h2>
                <EmptyState
                    v-if="uploads.length === 0"
                    class="mt-4"
                    :title="$t('app.empty.uploads_title')"
                    :body="$t('app.empty.uploads_body')"
                />
                <ul v-else class="mt-4 divide-y divide-line">
                    <li
                        v-for="upload in uploads"
                        :key="upload.id"
                        class="flex flex-wrap justify-between gap-2 py-3 text-sm"
                    >
                        <span>{{ upload.domain }}</span>
                        <span v-if="upload.status === 'pending'">{{ $t('bot_logs.pending') }}</span>
                        <span v-else-if="upload.status === 'processed'">{{ $t('bot_logs.processed') }}</span>
                        <span v-else>{{ $t('bot_logs.rejected') }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
