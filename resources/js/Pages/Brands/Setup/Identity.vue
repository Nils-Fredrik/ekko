<script setup>
// Brand identity (docs/ux-arbeidsflate.md 5.5, U24). The match test runs while the alias is typed.
import SaveStatus from '@/Components/UI/SaveStatus.vue';
import { useAutosave } from '@/Composables/useAutosave';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatNumber } from '@/format';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    tenantName: { type: String, required: true },
    aliases: { type: Array, required: true },
    domains: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const page = usePage();
const values = reactive({ name: props.brand.name, primary_domain: props.brand.primary_domain });
const { save, state, error } = useAutosave(() => route('brands.identity.update', props.brand.id), {
    values,
    only: ['brand'],
});
const draft = ref('');
const mode = ref('exact_word');
const preview = ref(null);
const previewError = ref('');
const domain = ref('');
const includeSubdomains = ref(false);
const confirmation = ref('');
const confirmError = ref('');
const modes = ['exact_word', 'case_insensitive', 'regex'];
const crumb = computed(() =>
    [props.tenantName, trans('workspace.shell.setup'), props.brand.name].filter(Boolean).join(' · '),
);
const errors = computed(() => page.props.errors ?? {});
let timer = 0;

watch([draft, mode], () => {
    window.clearTimeout(timer);
    preview.value = null;
    previewError.value = '';
    if (!props.canManage || draft.value.trim() === '') {
        return;
    }
    timer = window.setTimeout(() => testAlias(), 500);
});

onBeforeUnmount(() => window.clearTimeout(timer));

async function testAlias() {
    const alias = draft.value.trim();
    if (alias === '') {
        return;
    }
    try {
        const { data } = await axios.post(route('brands.aliases.test', props.brand.id), {
            alias,
            match_mode: mode.value,
            entity: 'brand',
        });
        preview.value = data;
        previewError.value = '';
    } catch (error) {
        preview.value = null;
        const messages = error.response?.data?.errors?.alias;
        previewError.value = (Array.isArray(messages) ? messages[0] : null) || error.response?.data?.message || '';
    }
}

function addAlias() {
    router.post(
        route('brands.aliases.store', props.brand.id),
        { alias: draft.value, match_mode: mode.value },
        {
            preserveScroll: true,
            only: ['aliases'],
            onSuccess: () => {
                draft.value = '';
                preview.value = null;
            },
        },
    );
}

function pause() {
    if (confirmation.value !== props.brand.name) {
        confirmError.value = trans('workspace.setup.confirm_mismatch');
        return;
    }
    confirmError.value = '';
    router.patch(
        route('brands.identity.update', props.brand.id),
        { status: 'paused' },
        { preserveScroll: true, only: ['brand'] },
    );
}

function resume() {
    router.patch(
        route('brands.identity.update', props.brand.id),
        { status: 'active' },
        { preserveScroll: true, only: ['brand'] },
    );
}
</script>

<template>
    <AppLayout :title="$t('workspace.setup.identity_title')">
        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
            <header>
                <p class="text-xs text-shell-muted">{{ crumb }}</p>
                <h1 class="text-2xl font-medium tracking-tight text-shell-fg">
                    {{ $t('workspace.setup.identity_title') }}
                </h1>
            </header>

            <section class="space-y-3 rounded-xl border border-line bg-shell-panel p-4 text-sm text-shell-text">
                <label class="block">
                    {{ $t('app.workspace.name') }}
                    <input
                        :value="values.name"
                        :disabled="!canManage"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2"
                        @change="save('name', $event.target.value)"
                    />
                </label>
                <label class="block">
                    {{ $t('app.workspace.primary_domain') }}
                    <input
                        :value="values.primary_domain"
                        :disabled="!canManage"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2"
                        @change="save('primary_domain', $event.target.value)"
                    />
                </label>
                <SaveStatus :state="state" :error="error" />
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-shell-panel p-4 text-sm text-shell-text">
                <h2 class="font-medium">{{ $t('app.workspace.aliases') }}</h2>
                <div v-for="alias in aliases" :key="alias.id" class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate">{{ alias.alias }}</span>
                    <select
                        :value="alias.match_mode"
                        :disabled="!canManage"
                        :aria-label="$t('app.workspace.match_mode')"
                        class="rounded-lg border border-line bg-shell-surface px-2 py-1"
                        @change="
                            router.patch(
                                route('brands.aliases.update', [brand.id, alias.id]),
                                { match_mode: $event.target.value },
                                { preserveScroll: true, only: ['aliases'] },
                            )
                        "
                    >
                        <option v-for="item in modes" :key="item" :value="item">
                            {{ $t(`workspace.setup.match_${item}`) }}
                        </option>
                    </select>
                    <button
                        v-if="canManage"
                        type="button"
                        :aria-label="$t('app.workspace.remove')"
                        @click="
                            router.delete(route('brands.aliases.destroy', [brand.id, alias.id]), {
                                preserveScroll: true,
                                only: ['aliases'],
                            })
                        "
                    >
                        {{ $t('workspace.setup.remove_mark') }}
                    </button>
                </div>
                <p v-if="errors.alias" class="text-xs text-warning">{{ errors.alias }}</p>
                <div v-if="canManage" class="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                    <input
                        v-model="draft"
                        :aria-label="$t('workspace.setup.add_alias')"
                        :placeholder="$t('workspace.setup.add_alias')"
                        class="rounded-lg border border-line bg-shell-surface px-3 py-2"
                    />
                    <select
                        v-model="mode"
                        :aria-label="$t('app.workspace.match_mode')"
                        class="rounded-lg border border-line bg-shell-surface px-2 py-1"
                    >
                        <option v-for="item in modes" :key="item" :value="item">
                            {{ $t(`workspace.setup.match_${item}`) }}
                        </option>
                    </select>
                    <button type="button" class="rounded-lg border border-line px-3" @click="testAlias">
                        {{ $t('workspace.setup.match_test') }}
                    </button>
                </div>
                <p v-if="preview" data-testid="alias-preview" class="text-xs">
                    {{
                        $t('workspace.setup.match_result', {
                            hits: formatNumber(preview.matches),
                            answers: formatNumber(preview.scanned),
                        })
                    }}
                </p>
                <p v-for="(sample, index) in preview?.samples ?? []" :key="index" class="text-xs text-shell-muted">
                    {{ sample.excerpt.before }}
                    <mark>{{ sample.excerpt.match }}</mark>
                    {{ sample.excerpt.after }}
                </p>
                <p
                    v-for="conflict in preview?.conflicts ?? []"
                    :key="`${conflict.type}-${conflict.name}`"
                    class="text-xs text-warning"
                >
                    {{ $t('workspace.setup.conflict', { count: formatNumber(conflict.count), name: conflict.name }) }}
                </p>
                <p v-if="previewError" class="text-xs text-warning">{{ previewError }}</p>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-full bg-primary-deep px-3 py-1.5 text-sm text-white"
                    @click="addAlias"
                >
                    {{ $t('app.workspace.add_alias') }}
                </button>
            </section>

            <section class="space-y-3 rounded-xl border border-line bg-shell-panel p-4 text-sm text-shell-text">
                <h2 class="font-medium">{{ $t('app.workspace.domains') }}</h2>
                <div v-for="row in domains" :key="row.id" class="flex items-center gap-3">
                    <span class="min-w-0 flex-1 truncate">{{ row.domain }}</span>
                    <label class="flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            :checked="row.include_subdomains"
                            :disabled="!canManage"
                            @change="
                                router.patch(
                                    route('brands.domains.update', [brand.id, row.id]),
                                    { include_subdomains: $event.target.checked },
                                    { preserveScroll: true, only: ['domains'] },
                                )
                            "
                        />
                        {{ $t('app.workspace.include_subdomains') }}
                    </label>
                    <span class="text-xs text-shell-muted">
                        {{ $t('workspace.setup.citations', { count: formatNumber(row.citations) }) }}
                    </span>
                    <button
                        v-if="canManage"
                        type="button"
                        :aria-label="$t('app.workspace.remove')"
                        @click="
                            router.delete(route('brands.domains.destroy', [brand.id, row.id]), {
                                preserveScroll: true,
                                only: ['domains'],
                            })
                        "
                    >
                        {{ $t('workspace.setup.remove_mark') }}
                    </button>
                </div>
                <div v-if="canManage" class="flex gap-2">
                    <input
                        v-model="domain"
                        :aria-label="$t('workspace.setup.add_domain')"
                        :placeholder="$t('workspace.setup.add_domain')"
                        class="min-w-0 flex-1 rounded-lg border border-line bg-shell-surface px-3 py-2"
                    />
                    <label class="flex items-center gap-2 text-xs">
                        <input v-model="includeSubdomains" type="checkbox" />
                        {{ $t('app.workspace.include_subdomains') }}
                    </label>
                    <button
                        type="button"
                        class="rounded-lg border border-line px-3"
                        @click="
                            router.post(
                                route('brands.domains.store', brand.id),
                                { domain, include_subdomains: includeSubdomains },
                                { preserveScroll: true, only: ['domains'], onSuccess: () => (domain = '') },
                            )
                        "
                    >
                        {{ $t('app.workspace.add_domain') }}
                    </button>
                </div>
                <p v-if="errors.domain" class="text-xs text-warning">{{ errors.domain }}</p>
            </section>

            <section v-if="canManage" class="space-y-3 rounded-xl border border-line p-4 text-sm text-shell-text">
                <h2 class="font-medium">{{ $t('workspace.setup.danger') }}</h2>
                <p class="text-xs text-shell-muted">{{ $t('workspace.setup.pause_help') }}</p>
                <p class="text-xs text-shell-muted">{{ $t('workspace.setup.archive_later') }}</p>
                <template v-if="brand.status !== 'paused'">
                    <label class="block text-xs">
                        {{ $t('workspace.setup.confirm_name') }}
                        <input
                            v-model="confirmation"
                            class="mt-1 w-full rounded-lg border border-line bg-shell-surface px-3 py-2"
                        />
                    </label>
                    <p v-if="confirmError" class="text-xs text-warning">{{ confirmError }}</p>
                    <button type="button" class="rounded-lg border border-line px-3 py-1.5" @click="pause">
                        {{ $t('workspace.setup.pause') }}
                    </button>
                </template>
                <button v-else type="button" class="rounded-lg border border-line px-3 py-1.5" @click="resume">
                    {{ $t('workspace.setup.resume') }}
                </button>
            </section>
        </div>
    </AppLayout>
</template>
