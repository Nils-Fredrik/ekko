<script setup>
import AnswerText from '@/Components/Brand/AnswerText.vue';
import BulkBar from '@/Components/UI/BulkBar.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import Drawer from '@/Components/UI/Drawer.vue';
import { useAutosave } from '@/Composables/useAutosave';
import { useUndoToast } from '@/Composables/useUndoToast';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatNumber, formatPercent, formatRelative } from '@/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    market: { type: Object, default: null },
    markets: { type: Array, default: () => [] },
    library: { type: Object, required: true },
    unscoped: { type: Number, default: 0 },
    engines: { type: Array, default: () => [] },
    quota: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
    options: { type: Object, required: true },
    filters: { type: Object, required: true },
    open: { type: Number, default: null },
    detail: { type: Object, default: null },
    importResult: { type: Object, default: null },
    personalData: { type: Boolean, default: false },
});

const page = usePage();
const selection = ref(new Set());
const toast = useUndoToast();
const draftField = ref(null);
const tab = ref('results');
const drafting = ref(false);
const draftText = ref('');
const naming = ref(false);
const topicName = ref('');
const renaming = ref(null);
const renameText = ref('');
const showImport = ref(false);
const showSuggest = ref(false);
const csvText = ref('');
const preview = ref(null);
const suggestions = ref([]);
const suggestCount = ref(5);
const searchTimer = ref(null);
const dragId = ref(null);

const marketName = computed(() => new Map(props.markets.map((item) => [item.id, item.label])));
const topicNameOf = computed(() => new Map(props.library.topics.map((item) => [item.id, item.name])));
const selectedTopic = computed(() =>
    props.filters.topic && props.filters.topic !== 'none'
        ? props.library.topics.find((item) => String(item.id) === String(props.filters.topic))
        : null,
);
const atLimit = computed(
    () => props.quota.limit !== null && props.quota.limit !== undefined && props.quota.active >= props.quota.limit,
);
const openRow = computed(() => props.library.prompts.find((item) => item.id === props.open) ?? null);
const panelPrompt = computed(() => props.detail?.prompt ?? openRow.value);
const formError = computed(() => {
    const first = Object.values(page.props.errors ?? {}).flat()[0];

    return typeof first === 'string' ? first : '';
});
const values = reactive({
    text: '',
    topic_id: null,
    intent: 'category',
    funnel_stage: 'awareness',
    priority: 'standard',
    is_active: true,
});
const autosave = useAutosave(() => route('brands.prompts.update', [props.brand.id, props.open]), {
    values,
    only: ['library', 'detail', 'quota'],
});

watch(
    () => props.open,
    () => {
        autosave.reset();
        tab.value = 'results';
        const row = props.detail?.prompt ?? props.library.prompts.find((item) => item.id === props.open);
        if (!row) {
            return;
        }
        Object.assign(values, {
            text: row.text,
            topic_id: row.topic_id,
            intent: row.intent,
            funnel_stage: row.funnel_stage,
            priority: row.priority,
            is_active: row.is_active,
        });
    },
    { immediate: true },
);

const columns = computed(() => {
    const list = [
        {
            id: 'text',
            header: trans('workspace.prompts.prompt_text'),
            accessor: 'text',
            primary: true,
            width: 'minmax(16rem, 1.6fr)',
        },
    ];
    if (!props.filters.topic) {
        list.push({
            id: 'topic',
            header: trans('workspace.prompts.topic'),
            accessor: (row) => topicNameOf.value.get(row.topic_id) ?? '',
            width: '10rem',
        });
    }
    if (!props.market) {
        list.push({
            id: 'market',
            header: trans('workspace.prompts.market'),
            accessor: (row) => marketName.value.get(row.market_id) ?? '',
            width: '8rem',
        });
    }
    list.push({
        id: 'core',
        header: trans('workspace.prompts.core'),
        accessor: (row) => row.priority === 'core',
        width: '6rem',
    });
    for (const engine of props.engines) {
        list.push({
            id: `engine-${engine.key}`,
            header: engine.name,
            accessor: (row) => row.engines?.[engine.key]?.value ?? null,
            width: '4.5rem',
            align: 'end',
        });
    }
    list.push(
        {
            id: 'last_measured',
            header: trans('workspace.prompts.last_measured'),
            accessor: 'last_measured_at',
            hideable: true,
            width: '8rem',
        },
        {
            id: 'active',
            header: trans('workspace.prompts.active'),
            accessor: 'is_active',
            hideable: true,
            width: '6rem',
        },
    );

    return list;
});

function query(extra = {}) {
    const next = {
        market: props.market?.id,
        topic: props.filters.topic,
        status: props.filters.status,
        q: props.filters.search,
        intent: props.filters.intent,
        funnel: props.filters.funnel,
        priority: props.filters.priority,
        open: props.open,
        ...extra,
    };
    for (const key of Object.keys(next)) {
        if (
            next[key] === null ||
            next[key] === undefined ||
            next[key] === '' ||
            (Array.isArray(next[key]) && next[key].length === 0)
        ) {
            delete next[key];
        }
    }

    return next;
}

function visit(extra = {}) {
    router.get(route('brands.setup.prompts', props.brand.id), query(extra), {
        preserveState: true,
        preserveScroll: true,
        only: ['library', 'unscoped', 'quota', 'filters', 'open', 'detail', 'importResult', 'personalData', 'market'],
    });
}

function toggleFilter(key, value) {
    const current = new Set(props.filters[key] ?? []);
    current.has(value) ? current.delete(value) : current.add(value);
    visit({ [key]: [...current] });
}

function onSearch(event) {
    clearTimeout(searchTimer.value);
    const value = event.target.value;
    searchTimer.value = setTimeout(() => visit({ q: value }), 300);
}

async function bulk(body, message) {
    try {
        const { data } = await window.axios.post(route('brands.prompts.bulk', props.brand.id), body);
        selection.value = new Set();
        const undo = data.undo;
        toast.show(message(data.affected), undo ? () => undoBulk(undo) : null);
        router.reload({ only: ['library', 'unscoped', 'quota', 'detail'], preserveScroll: true });
    } catch (error) {
        const errors = error.response?.data?.errors ?? {};
        const first = Object.values(errors).flat()[0];
        toast.show(first || trans('workspace.save.failed'));

        return false;
    }

    return true;
}

function undoBulk(undo) {
    return window.axios
        .post(route('brands.prompts.bulk', props.brand.id), { ids: undo.ids, action: 'undo', value: undo.value })
        .then(() => router.reload({ only: ['library', 'unscoped', 'quota', 'detail'], preserveScroll: true }));
}

function moveTo(value) {
    if (!value) {
        return;
    }
    const topicId = value === 'none' ? null : Number(value);
    const name = topicId ? topicNameOf.value.get(topicId) : trans('workspace.prompts.no_topic');
    bulk({ ids: [...selection.value], action: 'move_topic', value: topicId }, (count) =>
        trans('workspace.prompts.moved', { count: formatNumber(count), topic: name }),
    );
}

function apply(action, value = null) {
    if (value === '') {
        return;
    }
    bulk({ ids: [...selection.value], action, value }, (count) =>
        trans('workspace.prompts.updated', { count: formatNumber(count) }),
    );
}

function createTopic() {
    const name = topicName.value.trim();
    if (!name) {
        return;
    }
    router.post(
        route('brands.topics.store', props.brand.id),
        { name },
        {
            preserveScroll: true,
            onSuccess: (visitPage) => {
                naming.value = false;
                topicName.value = '';
                const selected = visitPage.props.filters?.topic;
                if (selected) {
                    visit({ topic: selected });
                }
            },
        },
    );
}

function renameTopic(topic) {
    const name = renameText.value.trim();
    renaming.value = null;
    if (!name || name === topic.name) {
        return;
    }
    router.patch(route('brands.topics.update', [props.brand.id, topic.id]), { name }, { preserveScroll: true });
}

function mergeTopic(from, into) {
    if (!from || !into || from.id === into.id) {
        return;
    }
    if (
        !window.confirm(
            trans('workspace.prompts.merge_confirm', {
                count: formatNumber(from.count),
                from: from.name,
                into: into.name,
            }),
        )
    ) {
        return;
    }
    router.post(
        route('brands.topics.merge', [props.brand.id, from.id]),
        { into: into.id },
        {
            preserveScroll: true,
            onSuccess: () => visit({ topic: into.id }),
        },
    );
}

function deleteTopic(topic) {
    router.delete(route('brands.topics.destroy', [props.brand.id, topic.id]), { preserveScroll: true });
}

function applyOne(action) {
    if (action === 'delete') {
        router.delete(route('brands.prompts.destroy', [props.brand.id, props.open]), {
            preserveScroll: true,
            onSuccess: () => visit({ open: null }),
        });

        return;
    }
    bulk({ ids: [props.open], action }, (count) => trans('workspace.prompts.updated', { count: formatNumber(count) }));
}

function saveDraft() {
    const text = draftText.value.trim();
    if (!text || !props.market) {
        return;
    }
    router.post(
        route('brands.prompts.store', [props.brand.id, props.market.id]),
        {
            text,
            intent: 'category',
            funnel_stage: 'awareness',
            priority: 'standard',
            is_active: true,
            topic_id: selectedTopic.value?.id ?? null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                drafting.value = false;
                draftText.value = '';
            },
        },
    );
}

async function setActive(row, active, event) {
    const saved = await bulk({ ids: [row.id], action: active ? 'activate' : 'deactivate' }, (count) =>
        trans('workspace.prompts.updated', { count: formatNumber(count) }),
    );
    if (!saved && event?.target) {
        event.target.checked = row.is_active;
    }
}

async function startDraft() {
    if (!props.canManage || !props.market || atLimit.value) {
        return;
    }
    drafting.value = true;
    await nextTick();
    draftField.value?.focus();
}

function onKey(event) {
    if (event.metaKey || event.ctrlKey || event.altKey || (event.key !== 'n' && event.key !== 'N')) {
        return;
    }
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || event.target?.isContentEditable) {
        return;
    }
    event.preventDefault();
    startDraft();
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    if (!props.canManage) {
        return;
    }
    const create = new URLSearchParams(window.location.search).get('create');
    if (create === 'prompt') {
        startDraft();
    }
    if (create === 'topic') {
        naming.value = true;
    }
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    clearTimeout(searchTimer.value);
});

function openSuggest() {
    suggestions.value = [];
    showSuggest.value = true;
}

function optionLabel(group, value) {
    return props.options[group]?.find((item) => item.value === value)?.label ?? '';
}

function heat(value) {
    if (value === null || value === undefined) {
        return 'bg-shell-surface text-shell-muted';
    }
    const step = Math.min(6, Math.max(1, Math.ceil(Number(value) * 6)));

    return ['bg-data-seq-1', 'bg-data-seq-2', 'bg-data-seq-3', 'bg-data-seq-4', 'bg-data-seq-5', 'bg-data-seq-6'][
        step - 1
    ];
}

function looksPersonal(text) {
    return /[^\s@]+@[^\s@]+\.[^\s@]+/.test(text) || /(?:\+|00)?\d(?:[\s-]?\d){7,}/.test(text);
}

async function readCsv(event) {
    const file = event.target.files?.[0];
    csvText.value = file ? await file.text() : '';
}

watch(csvText, () => {
    preview.value = null;
});

async function runPreview() {
    try {
        const { data } = await window.axios.post(route('brands.prompts.import.preview', props.brand.id), {
            csv: csvText.value,
        });
        preview.value = data;
    } catch (error) {
        preview.value = null;
        const errors = error.response?.data?.errors ?? {};
        const first = Object.values(errors).flat()[0];
        toast.show(first || trans('workspace.save.failed'));
    }
}

function runImport() {
    router.post(
        route('brands.prompts.import', props.brand.id),
        { csv: csvText.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                preview.value = null;
                showImport.value = false;
            },
        },
    );
}

async function loadSuggestions() {
    if (!selectedTopic.value || !props.market) {
        return;
    }
    try {
        const { data } = await window.axios.post(
            route('brands.topics.suggestions', [props.brand.id, selectedTopic.value.id]),
            {
                market_id: props.market.id,
                count: suggestCount.value,
            },
        );
        suggestions.value = (data.suggestions ?? []).map((item) => ({ ...item, selected: true }));
    } catch (error) {
        const errors = error.response?.data?.errors ?? {};
        const message = error.response?.data?.message;
        const first = Object.values(errors).flat()[0];
        toast.show(first || message || trans('workspace.save.failed'));
    }
}

function addSuggestions() {
    const chosen = suggestions.value.filter((item) => item.selected);
    bulk(
        {
            action: 'create',
            market_id: props.market.id,
            prompts: chosen.map((item) => ({
                text: item.text,
                intent: item.intent,
                funnel_stage: item.funnel_stage,
                priority: item.priority ?? 'standard',
                topic_id: selectedTopic.value.id,
                is_active: true,
            })),
        },
        (count) => trans('workspace.prompts.added', { count: formatNumber(count) }),
    );
    showSuggest.value = false;
}

const newRowLabel = computed(() =>
    selectedTopic.value
        ? trans('workspace.prompts.new_row', { market: props.market?.label ?? '', topic: selectedTopic.value.name })
        : trans('workspace.prompts.new_row_market', { market: props.market?.label ?? '' }),
);
const emptyCopy = computed(() =>
    props.filters.topic
        ? { title: trans('workspace.prompts.empty_topic') }
        : {
              title: trans('workspace.prompts.empty_market', {
                  market: props.market?.label ?? trans('workspace.shell.all_markets'),
              }),
          },
);
</script>

<template>
    <AppLayout :title="brand.name">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-6 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-medium text-shell-fg">{{ $t('workspace.shell.setup_prompts') }}</h2>
                    <p v-if="market && quota.limit !== null" class="mt-1 text-sm text-shell-muted">
                        {{
                            $t('workspace.prompts.quota', {
                                count: formatNumber(quota.active),
                                limit: formatNumber(quota.limit),
                            })
                        }}
                    </p>
                </div>
                <div v-if="canManage" class="flex flex-wrap items-center gap-2">
                    <a
                        :href="route('brands.prompts.export', brand.id)"
                        class="rounded-full border border-line px-3 py-1.5 text-sm no-underline"
                    >
                        {{ $t('workspace.prompts.export') }}
                    </a>
                    <button
                        type="button"
                        class="rounded-full border border-line px-3 py-1.5 text-sm"
                        data-testid="import-csv"
                        @click="showImport = !showImport"
                    >
                        {{ $t('workspace.prompts.import') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full border border-line px-3 py-1.5 text-sm disabled:opacity-50"
                        :disabled="!market || !selectedTopic"
                        @click="openSuggest"
                    >
                        {{ $t('workspace.prompts.suggest') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full bg-primary px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="!market || atLimit"
                        :title="
                            !market
                                ? $t('workspace.prompts.choose_market')
                                : atLimit
                                  ? $t('workspace.prompts.at_limit')
                                  : ''
                        "
                        @click="startDraft"
                    >
                        {{ $t('workspace.prompts.new_prompt') }}
                    </button>
                </div>
            </div>

            <section v-if="showImport && canManage" class="rounded-xl border border-line bg-shell-surface p-4 text-sm">
                <label class="block">
                    {{ $t('workspace.prompts.paste') }}
                    <textarea
                        v-model="csvText"
                        rows="4"
                        class="mt-1 w-full rounded-lg border border-line bg-shell-panel px-3 py-2"
                    />
                </label>
                <input
                    data-testid="import-file"
                    type="file"
                    accept=".csv,text/csv"
                    class="mt-2 text-sm"
                    @change="readCsv"
                />
                <div class="mt-3 flex gap-2">
                    <button
                        type="button"
                        class="rounded-full border border-line px-3 py-1.5"
                        data-testid="preview-import"
                        @click="runPreview"
                    >
                        {{ $t('workspace.prompts.preview') }}
                    </button>
                    <button
                        v-if="preview"
                        type="button"
                        class="rounded-full bg-primary px-3 py-1.5 font-medium text-white"
                        data-testid="confirm-import"
                        @click="runImport"
                    >
                        {{
                            $t('workspace.prompts.import_changes', {
                                count: formatNumber((preview.created?.length ?? 0) + (preview.changed?.length ?? 0)),
                            })
                        }}
                    </button>
                </div>
                <div v-if="preview" class="mt-3 space-y-2">
                    <p>
                        {{ $t('workspace.prompts.preview_new', { count: formatNumber(preview.created?.length ?? 0) }) }}
                    </p>
                    <ul>
                        <li v-for="(row, index) in preview.created" :key="index">{{ row.text }}</li>
                    </ul>
                    <p>
                        {{
                            $t('workspace.prompts.preview_changed', {
                                count: formatNumber(preview.changed?.length ?? 0),
                            })
                        }}
                    </p>
                    <ul>
                        <li v-for="row in preview.changed" :key="row.id">
                            <span v-for="(pair, field) in row.fields" :key="field" class="block">
                                {{
                                    $t('workspace.prompts.field_change', {
                                        field,
                                        before: pair[0] ?? '',
                                        after: pair[1] ?? '',
                                    })
                                }}
                            </span>
                        </li>
                    </ul>
                    <p>
                        {{ $t('workspace.prompts.preview_unchanged', { count: formatNumber(preview.unchanged ?? 0) }) }}
                    </p>
                    <ul v-if="preview.errors?.length" class="text-error-text">
                        <li v-for="(error, index) in preview.errors" :key="index">
                            <template v-if="error.line != null">{{ error.line }}:</template>
                            {{ error.message }}
                        </li>
                    </ul>
                </div>
            </section>

            <p v-if="importResult" class="text-sm">
                {{
                    $t('app.library.imported', {
                        created: importResult.created,
                        changed: importResult.changed ?? 0,
                        skipped: importResult.skipped,
                    })
                }}
            </p>
            <p v-if="personalData" class="border-s-4 border-warning ps-3 text-sm">
                {{ $t('app.library.personal_data') }}
            </p>
            <p v-if="formError" class="text-sm text-error-text">{{ formError }}</p>

            <section v-if="showSuggest && canManage" class="rounded-xl border border-line bg-shell-surface p-4 text-sm">
                <h3 class="font-medium">
                    {{
                        selectedTopic
                            ? $t('workspace.prompts.suggest_title', {
                                  topic: selectedTopic.name,
                                  market: market?.label,
                              })
                            : $t('workspace.prompts.suggest_any', { market: market?.label })
                    }}
                </h3>
                <p class="mt-1 text-shell-muted">{{ $t('workspace.prompts.suggest_cost') }}</p>
                <label class="mt-2 block">
                    <select
                        v-model.number="suggestCount"
                        class="rounded-lg border border-line bg-shell-panel px-2 py-1"
                    >
                        <option :value="5">{{ $t('workspace.prompts.count_5') }}</option>
                        <option :value="10">{{ $t('workspace.prompts.count_10') }}</option>
                    </select>
                </label>
                <button type="button" class="mt-3 rounded-full border border-line px-3 py-1.5" @click="loadSuggestions">
                    {{ $t('workspace.prompts.suggest') }}
                </button>
                <ul class="mt-3 space-y-2">
                    <li v-for="(item, index) in suggestions" :key="index" class="flex gap-2">
                        <input v-model="item.selected" type="checkbox" />
                        <span>{{ item.text }}</span>
                        <span class="text-shell-muted">
                            {{ optionLabel('intents', item.intent) }} · {{ optionLabel('funnels', item.funnel_stage) }}
                        </span>
                    </li>
                </ul>
                <button
                    type="button"
                    class="mt-3 rounded-full bg-primary px-3 py-1.5 font-medium text-white"
                    @click="addSuggestions"
                >
                    {{ $t('workspace.prompts.add_selected') }}
                </button>
            </section>

            <div class="flex items-start gap-4">
                <aside class="min-w-0 flex-[1_1_200px] rounded-xl border border-line bg-shell-surface p-3 text-sm">
                    <button
                        type="button"
                        class="block w-full rounded-lg px-2 py-1.5 text-left hover:bg-row-hover"
                        :class="!filters.topic ? 'bg-row-hover font-medium' : ''"
                        @click="visit({ topic: null })"
                    >
                        {{ $t('workspace.prompts.all_topics') }}
                    </button>
                    <div
                        v-for="topic in library.topics"
                        :key="topic.id"
                        class="group rounded-lg hover:bg-row-hover"
                        :draggable="canManage"
                        @dragstart="dragId = topic.id"
                        @dragover.prevent
                        @drop="
                            mergeTopic(
                                library.topics.find((item) => item.id === dragId),
                                topic,
                            )
                        "
                    >
                        <div class="flex items-center gap-1 px-2 py-1.5">
                            <button
                                type="button"
                                class="min-w-0 flex-1 text-left"
                                :class="String(filters.topic) === String(topic.id) ? 'font-medium' : ''"
                                @click="visit({ topic: topic.id })"
                                @dblclick="
                                    renaming = topic.id;
                                    renameText = topic.name;
                                "
                            >
                                <span class="block truncate">{{ topic.name }}</span>
                                <span class="block truncate text-xs text-shell-muted">
                                    {{ topic.markets.map((item) => item.location_label || item.country).join(' · ') }}
                                    {{ formatPercent(topic.visibility?.value) }} · {{ formatNumber(topic.count) }}
                                </span>
                            </button>
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-shell-muted"
                                :aria-label="$t('workspace.prompts.more')"
                                @click="
                                    renaming = renaming === topic.id ? null : topic.id;
                                    renameText = topic.name;
                                "
                            >
                                {{ $t('workspace.prompts.more') }}
                            </button>
                        </div>
                        <div v-if="renaming === topic.id && canManage" class="space-y-1 px-2 pb-2">
                            <input
                                v-model="renameText"
                                class="w-full rounded-lg border border-line px-2 py-1"
                                :aria-label="$t('workspace.prompts.topic_name')"
                                @keydown.enter.prevent="renameTopic(topic)"
                            />
                            <button type="button" class="text-xs" @click="renameTopic(topic)">
                                {{ $t('workspace.prompts.rename') }}
                            </button>
                            <label class="block text-xs">
                                {{ $t('workspace.prompts.merge_into') }}
                                <select
                                    class="mt-1 w-full rounded-lg border border-line"
                                    @change="
                                        mergeTopic(
                                            topic,
                                            library.topics.find((item) => item.id === Number($event.target.value)),
                                        )
                                    "
                                >
                                    <option value="" />
                                    <option
                                        v-for="other in library.topics.filter((item) => item.id !== topic.id)"
                                        :key="other.id"
                                        :value="other.id"
                                    >
                                        {{ other.name }}
                                    </option>
                                </select>
                            </label>
                            <button
                                type="button"
                                class="text-xs disabled:opacity-50"
                                :disabled="topic.count > 0"
                                :title="topic.count > 0 ? $t('workspace.prompts.topic_not_empty') : ''"
                                @click="deleteTopic(topic)"
                            >
                                {{ $t('workspace.prompts.delete_topic') }}
                            </button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="mt-1 block w-full rounded-lg px-2 py-1.5 text-left hover:bg-row-hover"
                        :class="filters.topic === 'none' ? 'bg-row-hover font-medium' : ''"
                        @click="visit({ topic: 'none' })"
                    >
                        {{ $t('workspace.prompts.no_topic') }}
                        <span class="text-shell-muted">{{ formatNumber(unscoped) }}</span>
                    </button>
                    <form v-if="canManage && naming" class="mt-2" @submit.prevent="createTopic">
                        <input
                            v-model="topicName"
                            class="w-full rounded-lg border border-line px-2 py-1"
                            :aria-label="$t('workspace.prompts.topic_name')"
                            @keydown.enter.prevent="createTopic"
                        />
                    </form>
                    <button
                        v-else-if="canManage"
                        type="button"
                        class="mt-2 px-2 text-sm text-primary"
                        @click="naming = true"
                    >
                        {{ $t('workspace.prompts.new_topic') }}
                    </button>
                    <p class="mt-3 px-2 text-xs text-shell-muted">{{ $t('workspace.prompts.drag_hint') }}</p>
                </aside>

                <div class="min-w-0 flex-[1_1_0]">
                    <form v-if="drafting" class="mb-3" @submit.prevent="saveDraft">
                        <label class="block text-sm">
                            {{ newRowLabel }}
                            <input
                                ref="draftField"
                                v-model="draftText"
                                data-testid="new-prompt"
                                class="mt-1 w-full rounded-lg border border-line px-3 py-2"
                                :aria-label="$t('workspace.prompts.prompt_text')"
                                @keydown.enter.prevent="saveDraft"
                                @keydown.esc.prevent="drafting = false"
                            />
                        </label>
                    </form>
                    <DataTable
                        v-model:selection="selection"
                        :rows="library.prompts"
                        :columns="columns"
                        row-key="id"
                        :group-by="market ? null : 'market_id'"
                        :group-label="(key) => marketName.get(Number(key)) ?? ''"
                        :active-row="open"
                        column-store-key="setup.prompts"
                        wrap
                        :default-hidden="['last_measured', 'active']"
                        :empty="emptyCopy"
                        :label="$t('workspace.shell.setup_prompts')"
                        :row-label="(row) => row.text"
                        @open="(id) => visit({ open: id })"
                        @close="visit({ open: null })"
                    >
                        <template #toolbar>
                            <input
                                :value="filters.search"
                                type="search"
                                class="rounded-full border border-line bg-shell-surface px-3 py-1.5 text-sm"
                                :placeholder="$t('workspace.prompts.search')"
                                :aria-label="$t('workspace.prompts.search')"
                                @input="onSearch"
                            />
                            <details class="text-sm">
                                <summary class="cursor-pointer rounded-full border border-line px-3 py-1.5">
                                    {{ $t('workspace.prompts.intent') }}
                                </summary>
                                <label v-for="item in options.intents" :key="item.value" class="mt-1 flex gap-2">
                                    <input
                                        type="checkbox"
                                        :checked="filters.intent.includes(item.value)"
                                        @change="toggleFilter('intent', item.value)"
                                    />
                                    {{ item.label }}
                                </label>
                            </details>
                            <details class="text-sm">
                                <summary class="cursor-pointer rounded-full border border-line px-3 py-1.5">
                                    {{ $t('workspace.prompts.funnel') }}
                                </summary>
                                <label v-for="item in options.funnels" :key="item.value" class="mt-1 flex gap-2">
                                    <input
                                        type="checkbox"
                                        :checked="filters.funnel.includes(item.value)"
                                        @change="toggleFilter('funnel', item.value)"
                                    />
                                    {{ item.label }}
                                </label>
                            </details>
                            <label class="text-sm">
                                <input
                                    type="checkbox"
                                    :checked="filters.priority.includes('core')"
                                    @change="toggleFilter('priority', 'core')"
                                />
                                {{ $t('workspace.prompts.core') }}
                            </label>
                            <select
                                class="rounded-full border border-line bg-shell-surface px-3 py-1.5 text-sm"
                                :value="filters.status"
                                :aria-label="$t('workspace.prompts.status')"
                                @change="visit({ status: $event.target.value })"
                            >
                                <option value="active">{{ $t('workspace.prompts.status_active') }}</option>
                                <option value="inactive">{{ $t('workspace.prompts.status_inactive') }}</option>
                                <option value="archived">{{ $t('workspace.prompts.status_archived') }}</option>
                                <option value="all">{{ $t('workspace.prompts.status_all') }}</option>
                            </select>
                        </template>
                        <template #cell-text="{ row }">
                            <span class="block" :class="row.is_active ? '' : 'text-shell-muted'">{{ row.text }}</span>
                            <span class="block text-xs text-shell-muted">
                                {{ optionLabel('intents', row.intent) }} ·
                                {{ optionLabel('funnels', row.funnel_stage) }}
                            </span>
                        </template>
                        <template #cell-core="{ row }">
                            <span
                                v-if="row.priority === 'core'"
                                class="rounded-full bg-data-seq-1 px-2 py-0.5 text-xs text-shell-accent"
                            >
                                {{ $t('workspace.prompts.core') }}
                            </span>
                        </template>
                        <template v-for="engine in engines" :key="engine.key" #[`cell-engine-${engine.key}`]="{ row }">
                            <span
                                class="inline-flex h-[22px] w-7 items-center justify-center rounded text-[11px]"
                                :class="heat(row.engines?.[engine.key]?.value)"
                                :aria-label="
                                    row.engines?.[engine.key]?.value == null
                                        ? $t('workspace.prompts.engine_empty', { engine: engine.name })
                                        : $t('workspace.prompts.engine_cell', {
                                              engine: engine.name,
                                              percent: formatPercent(row.engines[engine.key].value),
                                          })
                                "
                            >
                                {{
                                    row.engines?.[engine.key]?.value == null
                                        ? '–'
                                        : formatPercent(row.engines[engine.key].value)
                                }}
                            </span>
                        </template>
                        <template #cell-last_measured="{ value }">{{ formatRelative(value) }}</template>
                        <template #cell-active="{ row, value }">
                            <input
                                v-if="canManage"
                                type="checkbox"
                                :checked="value"
                                :aria-label="$t('workspace.prompts.active')"
                                @click.stop
                                @change="setActive(row, $event.target.checked, $event)"
                            />
                            <span v-else>
                                {{
                                    value
                                        ? $t('workspace.prompts.status_active')
                                        : $t('workspace.prompts.status_inactive')
                                }}
                            </span>
                        </template>
                    </DataTable>
                    <button
                        v-if="canManage && market && !drafting"
                        type="button"
                        class="mt-2 text-sm text-primary disabled:opacity-50"
                        :disabled="atLimit"
                        :title="atLimit ? $t('workspace.prompts.at_limit') : ''"
                        @click="startDraft"
                    >
                        {{ newRowLabel }}
                    </button>
                    <div v-if="canManage && !library.prompts.length && !filters.topic" class="mt-3 flex gap-2">
                        <button type="button" class="text-sm text-primary" @click="showImport = true">
                            {{ $t('workspace.prompts.import') }}
                        </button>
                        <button
                            type="button"
                            class="text-sm text-primary disabled:opacity-50"
                            :disabled="!market || !selectedTopic"
                            @click="openSuggest"
                        >
                            {{ $t('workspace.prompts.suggest') }}
                        </button>
                    </div>

                    <BulkBar v-if="canManage" :count="selection.size" @clear="selection = new Set()">
                        <select
                            class="rounded-full border border-line bg-shell-surface px-2 py-1"
                            :aria-label="$t('workspace.prompts.move')"
                            @change="
                                moveTo($event.target.value);
                                $event.target.value = '';
                            "
                        >
                            <option value="">{{ $t('workspace.prompts.move') }}</option>
                            <option value="none">{{ $t('workspace.prompts.no_topic') }}</option>
                            <option v-for="topic in library.topics" :key="topic.id" :value="topic.id">
                                {{ topic.name }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="rounded-full border border-line px-2 py-1"
                            @click="apply('set_priority', 'core')"
                        >
                            {{ $t('workspace.prompts.set_core') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full border border-line px-2 py-1"
                            @click="apply('set_priority', 'standard')"
                        >
                            {{ $t('workspace.prompts.clear_core') }}
                        </button>
                        <select
                            class="rounded-full border border-line bg-shell-surface px-2 py-1"
                            :aria-label="$t('workspace.prompts.set_intent')"
                            @change="
                                apply('set_intent', $event.target.value);
                                $event.target.value = '';
                            "
                        >
                            <option value="">{{ $t('workspace.prompts.set_intent') }}</option>
                            <option v-for="item in options.intents" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </select>
                        <select
                            class="rounded-full border border-line bg-shell-surface px-2 py-1"
                            :aria-label="$t('workspace.prompts.set_funnel')"
                            @change="
                                apply('set_funnel', $event.target.value);
                                $event.target.value = '';
                            "
                        >
                            <option value="">{{ $t('workspace.prompts.set_funnel') }}</option>
                            <option v-for="item in options.funnels" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="rounded-full border border-line px-2 py-1"
                            @click="apply('activate')"
                        >
                            {{ $t('workspace.prompts.activate') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full border border-line px-2 py-1"
                            @click="apply('deactivate')"
                        >
                            {{ $t('workspace.prompts.deactivate') }}
                        </button>
                        <button type="button" class="rounded-full px-2 py-1 text-error-text" @click="apply('archive')">
                            {{ $t('workspace.prompts.archive') }}
                        </button>
                    </BulkBar>
                </div>

                <Drawer
                    :open="open !== null"
                    :label="trans('workspace.prompts.panel', { market: marketName.get(panelPrompt?.market_id) ?? '' })"
                    :save-state="autosave.state.value"
                    :save-error="autosave.error.value"
                    @close="visit({ open: null })"
                >
                    <template v-if="panelPrompt">
                        <label v-if="canManage" class="block text-sm">
                            {{ $t('workspace.prompts.prompt_text') }}
                            <textarea
                                :value="values.text"
                                rows="3"
                                class="mt-1 w-full rounded-lg border border-line px-3 py-2"
                                @blur="autosave.save('text', $event.target.value)"
                                @keydown.enter.prevent="$event.target.blur()"
                            />
                        </label>
                        <p v-if="autosave.errors.text" class="mt-1 text-xs text-error-text">
                            {{ autosave.errors.text }}
                        </p>
                        <p v-if="canManage && looksPersonal(values.text)" class="mt-1 text-xs text-warning">
                            {{ $t('workspace.prompts.personal') }}
                        </p>
                        <div v-if="!canManage" class="text-sm">
                            <p>{{ values.text }}</p>
                            <p class="mt-1 text-shell-muted">
                                {{ optionLabel('intents', values.intent) }} ·
                                {{ optionLabel('funnels', values.funnel_stage) }}
                            </p>
                        </div>
                        <div v-if="canManage" class="mt-3 grid gap-2 text-sm">
                            <select
                                :value="values.topic_id ?? ''"
                                @change="
                                    autosave.save('topic_id', $event.target.value ? Number($event.target.value) : null)
                                "
                            >
                                <option value="">{{ $t('workspace.prompts.no_topic') }}</option>
                                <option v-for="topic in library.topics" :key="topic.id" :value="topic.id">
                                    {{ topic.name }}
                                </option>
                            </select>
                            <select :value="values.intent" @change="autosave.save('intent', $event.target.value)">
                                <option v-for="item in options.intents" :key="item.value" :value="item.value">
                                    {{ item.label }}
                                </option>
                            </select>
                            <select
                                :value="values.funnel_stage"
                                @change="autosave.save('funnel_stage', $event.target.value)"
                            >
                                <option v-for="item in options.funnels" :key="item.value" :value="item.value">
                                    {{ item.label }}
                                </option>
                            </select>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    :checked="values.priority === 'core'"
                                    @change="autosave.save('priority', $event.target.checked ? 'core' : 'standard')"
                                />
                                {{ $t('workspace.prompts.core') }}
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    :checked="values.is_active"
                                    @change="autosave.save('is_active', $event.target.checked)"
                                />
                                {{ $t('workspace.prompts.active') }}
                            </label>
                        </div>
                        <div class="mt-4 flex gap-2 text-sm" role="tablist">
                            <button
                                type="button"
                                role="tab"
                                class="rounded-full px-3 py-1"
                                :class="tab === 'results' ? 'bg-shell-panel font-medium' : ''"
                                :aria-selected="tab === 'results'"
                                @click="tab = 'results'"
                            >
                                {{ $t('workspace.prompts.results') }}
                            </button>
                            <button
                                type="button"
                                role="tab"
                                class="rounded-full px-3 py-1"
                                :class="tab === 'latest' ? 'bg-shell-panel font-medium' : ''"
                                :aria-selected="tab === 'latest'"
                                @click="tab = 'latest'"
                            >
                                {{ $t('workspace.prompts.latest') }}
                            </button>
                            <button
                                type="button"
                                role="tab"
                                class="rounded-full px-3 py-1"
                                :class="tab === 'history' ? 'bg-shell-panel font-medium' : ''"
                                :aria-selected="tab === 'history'"
                                @click="tab = 'history'"
                            >
                                {{ $t('workspace.prompts.history') }}
                            </button>
                        </div>
                        <div v-show="tab === 'results'" class="mt-3 space-y-3 text-sm">
                            <div
                                v-for="engine in detail?.results ?? []"
                                :key="engine.key"
                                class="flex items-center justify-between gap-2"
                            >
                                <span>{{ engine.name }}</span>
                                <span v-if="!engine.measured" class="text-shell-muted">
                                    {{ $t('workspace.prompts.not_measured') }}
                                    <template v-if="engine.reason === 'core'">
                                        · {{ $t('workspace.prompts.core_only') }}
                                    </template>
                                </span>
                                <span v-else class="flex items-center gap-1">
                                    <span
                                        v-for="(dot, index) in engine.dots"
                                        :key="index"
                                        class="h-2 w-2 rounded-full"
                                        :class="dot ? 'bg-primary' : 'bg-line'"
                                    />
                                    {{ $t('workspace.prompts.mentioned', { days: formatNumber(engine.days) }) }}
                                    <template v-if="engine.place">
                                        · {{ $t('workspace.prompts.place', { place: formatNumber(engine.place) }) }}
                                    </template>
                                </span>
                            </div>
                        </div>
                        <div v-show="tab === 'latest'" class="mt-3 space-y-3 text-sm">
                            <p v-if="!detail?.answer" class="text-shell-muted">
                                {{ $t('workspace.prompts.no_answer') }}
                            </p>
                            <div v-else>
                                <p class="text-xs text-shell-muted">
                                    {{ detail.answer.engine }} · {{ formatRelative(detail.answer.at) }}
                                </p>
                                <AnswerText :segments="detail.answer.segments" />
                                <Link
                                    :href="route('brands.prompts.show', [brand.id, panelPrompt.id])"
                                    class="mt-1 inline-block text-sm"
                                >
                                    {{ $t('workspace.prompts.open_prompt') }}
                                </Link>
                            </div>
                        </div>
                        <div v-show="tab === 'history'" class="mt-3 space-y-3 text-sm">
                            <p v-if="!detail?.history?.length" class="text-shell-muted">
                                {{ $t('workspace.prompts.no_history') }}
                            </p>
                            <ul v-else class="space-y-1">
                                <li v-for="(entry, index) in detail.history" :key="index">
                                    {{ entry.who }} · {{ formatRelative(entry.at) }}
                                    <span v-for="field in entry.fields" :key="field.field">
                                        {{ field.field }}: {{ field.value }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <button
                            v-if="canManage"
                            type="button"
                            class="mt-4 text-sm text-error-text"
                            @click="detail?.runs ? applyOne('archive') : applyOne('delete')"
                        >
                            {{ detail?.runs ? $t('workspace.prompts.archive') : $t('workspace.prompts.delete') }}
                        </button>
                    </template>
                </Drawer>
            </div>
        </div>
    </AppLayout>
</template>
