<script setup>
// The detail panel of a competitor (docs/ux-arbeidsflate.md 5.3): name and active with automatic saving, aliases
// with match type, domains and «Archive». With no competitor it is the form for a new one. Changes go to the JSON
// routes of CompetitorSetupController, which answer with the competitor as it is now.
import AliasMatchPreview from '@/Components/Setup/AliasMatchPreview.vue';
import SaveStatus from '@/Components/UI/SaveStatus.vue';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import { normalizeDomain, responseError } from './entityQueue.js';

const props = defineProps({
    brandId: { type: Number, required: true },
    competitor: { type: Object, default: null },
    canManage: { type: Boolean, required: true },
    // The text of the last save on the server's side, for a new competitor.
    createError: { type: String, default: '' },
    creating: { type: Boolean, default: false },
    // The name typed in the form for a new competitor. The page keeps it, so a panel that is built again (a page
    // update while the user types) does not lose it.
    draft: { type: String, default: '' },
    setDraft: { type: Function, default: () => {} },
    // Called with the competitor as the server answers after each change.
    apply: { type: Function, required: true },
});

const emit = defineEmits(['archive', 'create']);

const TIMES = '×';
const MODES = ['exact_word', 'case_insensitive', 'regex'];

const name = ref('');
const aliasText = ref('');
const aliasMode = ref('case_insensitive');
const domainText = ref('');
const state = ref('idle');
const error = ref('');
const fieldErrors = ref({});
let timer = null;

watch(
    () => props.competitor?.id ?? null,
    () => {
        name.value = props.competitor?.name ?? '';
        aliasText.value = '';
        domainText.value = '';
        fieldErrors.value = {};
        state.value = 'idle';
    },
    { immediate: true },
);

// The name changes under the panel after a save in another place (a rename, a reload).
watch(
    () => props.competitor?.name,
    (value) => {
        if (value !== undefined && state.value !== 'saving') {
            name.value = value;
        }
    },
);

function saved() {
    state.value = 'saved';
    clearTimeout(timer);
    timer = setTimeout(() => (state.value = 'idle'), 2000);
}

/** Sends one change, shows the state in the panel head and gives the new competitor to the page. */
async function send(method, url, body, field = 'general') {
    state.value = 'saving';
    fieldErrors.value = { ...fieldErrors.value, [field]: '' };
    try {
        const { data } = await window.axios({ method, url, data: body });
        props.apply(data.competitor);
        saved();

        return true;
    } catch (failure) {
        state.value = 'error';
        error.value = responseError(failure) ?? trans('workspace.competitors.failed');
        fieldErrors.value = { ...fieldErrors.value, [field]: error.value };

        return false;
    }
}

const url = (name, ...rest) => route(name, [props.brandId, props.competitor.id, ...rest]);

async function saveName() {
    const value = name.value.trim();
    if (!props.competitor || value === props.competitor.name) {
        return;
    }
    if (!(await send('patch', url('brands.competitors.update'), { name: value }, 'name'))) {
        name.value = props.competitor.name;
    }
}

function setActive(event) {
    send('patch', url('brands.competitors.update'), { is_active: event.target.checked }, 'active');
}

async function addAlias() {
    const value = aliasText.value.trim();
    if (value === '') {
        return;
    }
    if (
        await send(
            'post',
            url('brands.competitors.aliases.store'),
            { alias: value, match_mode: aliasMode.value },
            'alias',
        )
    ) {
        aliasText.value = '';
    }
}

function setMode(alias, event) {
    send('patch', url('brands.competitors.aliases.update', alias.id), { match_mode: event.target.value }, 'alias');
}

function removeAlias(alias) {
    send('delete', url('brands.competitors.aliases.destroy', alias.id), undefined, 'alias');
}

async function addDomain() {
    const value = normalizeDomain(domainText.value);
    if (value === '') {
        return;
    }
    if (await send('post', url('brands.competitors.domains.store'), { domain: value }, 'domain')) {
        domainText.value = '';
    }
}

function removeDomain(domain) {
    send('delete', url('brands.competitors.domains.destroy', domain.id), undefined, 'domain');
}

defineExpose({ state, error });
</script>

<template>
    <div v-if="!competitor" data-testid="competitor-new">
        <h3 class="text-[20px] leading-tight font-medium text-shell-fg">{{ $t('workspace.competitors.panel.new') }}</h3>
        <form class="mt-4" @submit.prevent="emit('create', draft.trim())">
            <label class="block text-xs text-shell-muted">
                {{ $t('workspace.competitors.panel.name') }}
                <input
                    :value="draft"
                    type="text"
                    maxlength="200"
                    class="mt-1 w-full rounded-xl border border-line bg-shell-panel px-3 py-2 text-sm text-shell-fg"
                    data-testid="new-competitor-name"
                    :disabled="!canManage"
                    @input="setDraft($event.target.value)"
                />
            </label>
            <p v-if="createError" class="mt-1 text-xs text-error-text" role="alert">{{ createError }}</p>
            <button
                type="submit"
                class="mt-3 rounded-full bg-primary-deep px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                :disabled="!canManage || creating || draft.trim() === ''"
                data-testid="new-competitor-submit"
            >
                {{ $t('workspace.competitors.panel.create') }}
            </button>
        </form>
    </div>

    <div v-else data-testid="competitor-panel">
        <div class="flex items-center gap-2">
            <label class="min-w-0 flex-1 text-xs text-shell-muted">
                {{ $t('workspace.competitors.panel.name') }}
                <input
                    v-model="name"
                    type="text"
                    maxlength="200"
                    class="mt-1 w-full rounded-xl border border-line bg-shell-panel px-3 py-2 text-[15px] font-medium text-shell-fg"
                    data-testid="competitor-name"
                    :disabled="!canManage"
                    @blur="saveName"
                    @keydown.enter.prevent="$event.target.blur()"
                />
            </label>
        </div>
        <p v-if="fieldErrors.name" class="mt-1 text-xs text-error-text" role="alert">{{ fieldErrors.name }}</p>
        <div class="mt-1 flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-shell-text">
                <input
                    type="checkbox"
                    class="rounded border-line bg-shell-surface text-primary focus:ring-primary"
                    :checked="competitor.is_active"
                    :disabled="!canManage"
                    data-testid="competitor-active"
                    @change="setActive"
                />
                {{ $t('workspace.competitors.panel.active') }}
            </label>
            <SaveStatus :state="state" :error="error" />
        </div>

        <h4 class="mt-5 text-xs text-shell-muted">{{ $t('workspace.competitors.panel.aliases') }}</h4>
        <ul class="mt-2 space-y-1.5" data-testid="competitor-aliases">
            <li v-for="alias in competitor.aliases" :key="alias.id" class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate rounded-full bg-row-hover px-3 py-1 text-sm">
                    {{ alias.alias }}
                </span>
                <select
                    :value="alias.match_mode"
                    class="rounded-lg border border-line bg-shell-panel py-1 text-xs"
                    :aria-label="$t('workspace.competitors.panel.match_mode', { alias: alias.alias })"
                    :disabled="!canManage"
                    @change="setMode(alias, $event)"
                >
                    <option v-for="mode in MODES" :key="mode" :value="mode">
                        {{ $t(`workspace.competitors.match.${mode}`) }}
                    </option>
                </select>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-full px-2 text-shell-muted hover:bg-row-hover hover:text-shell-text"
                    :aria-label="$t('workspace.competitors.panel.remove_alias', { alias: alias.alias })"
                    @click="removeAlias(alias)"
                >
                    {{ TIMES }}
                </button>
            </li>
            <li v-if="competitor.aliases.length === 0" class="text-sm text-shell-muted">
                {{ $t('workspace.competitors.panel.no_aliases') }}
            </li>
        </ul>
        <form v-if="canManage" class="mt-2 flex gap-2" @submit.prevent="addAlias">
            <input
                v-model="aliasText"
                type="text"
                maxlength="200"
                class="min-w-0 flex-1 rounded-lg border border-line bg-shell-panel px-3 py-1.5 text-sm"
                :placeholder="$t('workspace.competitors.panel.add_alias')"
                :aria-label="$t('workspace.competitors.panel.add_alias')"
                data-testid="alias-input"
            />
            <select
                v-model="aliasMode"
                class="rounded-lg border border-line bg-shell-panel py-1 text-xs"
                :aria-label="$t('workspace.competitors.panel.new_match_mode')"
            >
                <option v-for="mode in MODES" :key="mode" :value="mode">
                    {{ $t(`workspace.competitors.match.${mode}`) }}
                </option>
            </select>
            <button type="submit" class="rounded-full border border-line px-3 text-sm" data-testid="alias-add">
                {{ $t('workspace.competitors.panel.create') }}
            </button>
        </form>
        <AliasMatchPreview
            v-if="canManage"
            :brand-id="brandId"
            :entity="`competitor:${competitor.id}`"
            :alias="aliasText"
            :mode="aliasMode"
        />
        <p v-if="fieldErrors.alias" class="mt-1 text-xs text-error-text" role="alert">{{ fieldErrors.alias }}</p>

        <h4 class="mt-5 text-xs text-shell-muted">{{ $t('workspace.competitors.panel.domains') }}</h4>
        <ul class="mt-2 flex flex-wrap gap-1.5" data-testid="competitor-domains">
            <li
                v-for="domain in competitor.domains"
                :key="domain.id"
                class="inline-flex items-center gap-1 rounded-full bg-row-hover py-1 pl-3 pr-2 text-sm"
            >
                {{ domain.domain }}
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-full px-1 text-shell-muted hover:text-shell-text"
                    :aria-label="$t('workspace.competitors.panel.remove_domain', { domain: domain.domain })"
                    @click="removeDomain(domain)"
                >
                    {{ TIMES }}
                </button>
            </li>
            <li v-if="competitor.domains.length === 0" class="text-sm text-shell-muted">
                {{ $t('workspace.competitors.panel.no_domains') }}
            </li>
        </ul>
        <form v-if="canManage" class="mt-2 flex gap-2" @submit.prevent="addDomain">
            <input
                v-model="domainText"
                type="text"
                maxlength="255"
                class="min-w-0 flex-1 rounded-lg border border-line bg-shell-panel px-3 py-1.5 text-sm"
                :placeholder="$t('workspace.competitors.panel.add_domain')"
                :aria-label="$t('workspace.competitors.panel.add_domain')"
                data-testid="domain-input"
            />
            <button type="submit" class="rounded-full border border-line px-3 text-sm" data-testid="domain-add">
                {{ $t('workspace.competitors.panel.create') }}
            </button>
        </form>
        <p v-if="fieldErrors.domain" class="mt-1 text-xs text-error-text" role="alert">{{ fieldErrors.domain }}</p>

        <button
            v-if="canManage"
            type="button"
            class="mt-6 rounded-full border border-line px-4 py-1.5 text-sm text-error-text hover:bg-row-hover"
            data-testid="competitor-archive"
            @click="emit('archive', competitor)"
        >
            {{ $t('workspace.competitors.panel.archive') }}
        </button>
    </div>
</template>
