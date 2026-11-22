<script setup>
// Setup › Competitors and brands (docs/ux-arbeidsflate.md 5.3, sketch 3). Four tabs: unknown names waiting for a
// decision, the competitors with their color order, names decided as another actor and ignored names. The queue is
// handled with the keyboard (1 competitor, 2 other actor, 3 alias, 4 ignore, x selects, j and k move) and with
// multi-select. A decided row stays in the table with «Undo» until the page is loaded again; the counts on the tabs
// and in the sidebar follow at once.
import AliasPicker from '@/Components/Setup/AliasPicker.vue';
import CompetitorPanel from '@/Components/Setup/CompetitorPanel.vue';
import EntityPanel from '@/Components/Setup/EntityPanel.vue';
import {
    KEY_DECISIONS,
    aliasTargets,
    DECISION_BATCH,
    chunks,
    decisionTargets,
    nextOpenKey,
    reorderActive,
    responseError,
    shareBar,
    urlWithParams,
} from '@/Components/Setup/entityQueue.js';
import BulkBar from '@/Components/UI/BulkBar.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import Drawer from '@/Components/UI/Drawer.vue';
import { useOpenRow } from '@/Composables/useOpenRow';
import { useUndoToast } from '@/Composables/useUndoToast';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCount, formatDateTime, formatNumber, formatPercent, formatPoints, formatRelative } from '@/format';
import { Bars2Icon } from '@heroicons/vue/20/solid';
import { router, usePage } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    brand: { type: Object, required: true },
    markets: { type: Array, default: () => [] },
    market: { type: Object, default: null },
    tab: { type: String, default: 'pending' },
    engineNames: { type: Object, default: () => ({}) },
    queue: { type: Array, required: true },
    queueTotal: { type: Number, default: 0 },
    competitors: { type: Array, required: true },
    others: { type: Array, required: true },
    ignored: { type: Array, required: true },
    targets: { type: Array, required: true },
    minAnswers: { type: Number, default: 2 },
    undoHours: { type: Number, default: 24 },
    canManage: { type: Boolean, required: true },
    canManageBrand: { type: Boolean, default: false },
});

const TABS = ['pending', 'competitors', 'other', 'ignored'];
const DECISIONS = ['competitor', 'other', 'alias', 'ignored'];
const CATEGORIES = 6;
const SLASH = '/';
const KEY_J = 'J';
const KEY_K = 'K';

const page = usePage();
const toast = useUndoToast();
const { openId, open: openRow, close: closeRow } = useOpenRow();

const activeTab = computed(() => {
    const value = new URL(page.url, 'http://ekko.invalid').searchParams.get('tab');

    return TABS.includes(value) ? value : 'pending';
});

const selection = ref(new Set());
// Decided in this visit: key -> { decision, target, id }. The row stays until the page is loaded again.
const decided = reactive(new Map());
const picker = ref(null);
const error = ref('');
const announcement = ref('');
const queueWrap = ref(null);
const creating = ref(false);
const newName = ref('');
const createError = ref('');

const wide = ref(typeof window === 'undefined' ? true : window.matchMedia('(min-width: 1280px)').matches);
let wideQuery = null;
const onWide = (event) => {
    wide.value = event.matches;
};

// Competitors in color order. Dragging changes this copy at once; the server answers with the new colors.
const localCompetitors = ref(props.competitors.map((item) => ({ ...item })));
watch(
    () => props.competitors,
    (list) => {
        localCompetitors.value = list.map((item) => ({ ...item }));
    },
);

const order = computed(() => props.queue.map((row) => row.key));
const rowByKey = computed(() => new Map(props.queue.map((row) => [row.key, row])));
const pendingCount = computed(() => props.queue.filter((row) => !decided.has(row.key)).length);
const counts = computed(() => ({
    pending: pendingCount.value,
    competitors: props.competitors.length,
    other: props.others.length,
    ignored: props.ignored.length,
}));

const marketLabel = (id) => props.markets.find((market) => market.id === id)?.label ?? '';

// ---- Tabs

function selectTab(id) {
    if (id === activeTab.value) {
        return;
    }
    selection.value = new Set();
    picker.value = null;
    router.push({
        url: urlWithParams(page.url, { tab: id === 'pending' ? null : id, open: null, create: null }),
        preserveState: true,
        preserveScroll: true,
    });
}

const tabButtons = ref([]);
function onTabKey(event, index) {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (step || event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        const next =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? TABS.length - 1
                  : (index + step + TABS.length) % TABS.length;
        selectTab(TABS[next]);
        nextTick(() => tabButtons.value[next]?.focus());
    }
}

function addCompetitor() {
    router.push({
        url: urlWithParams(page.url, { tab: 'competitors', open: 'new', create: null }),
        preserveState: true,
        preserveScroll: true,
    });
}

// ---- The queue

// The open row: the one in the URL, else the first that is still undecided on a wide screen, so the panel is
// always there (sketch 3). On a narrow screen the panel opens only for a row the user opened.
// Right after a decision the next row is `cursor` until the URL has caught up, so quick key presses never act on
// a row that is already decided.
const cursor = ref(null);
watch(openId, (value) => {
    if (cursor.value !== null && String(value) === String(cursor.value)) {
        cursor.value = null;
    }
});
function openByUser(key) {
    cursor.value = null;
    openRow(key);
}
const shownKey = computed(() => {
    if (cursor.value !== null && props.queue.some((row) => row.key === cursor.value)) {
        return cursor.value;
    }
    const explicit = props.queue.find((row) => String(row.key) === String(openId.value ?? ''));
    if (explicit) {
        return explicit.key;
    }
    if (!wide.value || props.queue.length === 0) {
        return null;
    }

    return (props.queue.find((row) => !decided.has(row.key)) ?? props.queue[0]).key;
});
const shownItem = computed(() => (shownKey.value === null ? null : rowByKey.value.get(shownKey.value)));

const queueColumns = computed(() => {
    const list = [
        {
            id: 'name',
            header: trans('workspace.competitors.col.name'),
            accessor: 'name',
            primary: true,
            width: 'minmax(11rem, 1.4fr)',
        },
        { id: 'share', header: trans('workspace.competitors.col.share'), accessor: 'share', width: '9rem' },
        { id: 'last_seen', header: trans('workspace.competitors.col.last_seen'), accessor: 'last_seen', width: '6rem' },
    ];
    if (props.canManage) {
        list.push({
            id: 'act',
            header: trans('workspace.competitors.col.act'),
            accessor: () => '',
            width: 'minmax(13rem, 1.2fr)',
        });
    }

    return list;
});

function alsoLine(row) {
    const parts = [];
    const variants = row.variants.filter((variant) => variant !== row.name);
    if (variants.length > 0) {
        parts.push(trans('workspace.competitors.also', { names: variants.join(', ') }));
    }
    parts.push(row.markets.map(marketLabel).filter(Boolean).join(', '));
    parts.push(
        transChoice('workspace.competitors.engines', row.engines.length, { n: formatNumber(row.engines.length) }),
    );

    return parts.filter(Boolean).join(' · ');
}

function suggestionLabel(row) {
    const suggestion = row.suggestion;

    return suggestion
        ? trans(`workspace.competitors.suggestion.${suggestion.decision}`, { name: suggestion.target_name ?? '' })
        : '';
}

const isSuggested = (row, decision) => row.suggestion?.decision === decision;

function decidedLabel(entry) {
    return trans(`workspace.competitors.decided.${entry.decision}`, { name: entry.target?.name ?? '' });
}

function toastText(decision, keys, target) {
    if (keys.length === 1) {
        return trans(`workspace.competitors.toast.${decision}`, {
            name: rowByKey.value.get(keys[0])?.name ?? '',
            target: target?.name ?? '',
        });
    }

    return trans(`workspace.competitors.toast_many.${decision}`, {
        n: formatNumber(keys.length),
        target: target?.name ?? '',
    });
}

function focusRow(key) {
    const index = order.value.indexOf(key);
    if (index < 0) {
        return;
    }
    nextTick(() => queueWrap.value?.querySelector(`[data-row-position="${index}"]`)?.focus({ preventScroll: false }));
}

let reload = null;
const reloadExtra = new Set();
/**
 * The sidebar count, the competitors and the other tabs follow a change without loading the page again. A visit
 * that starts while the reload is on its way (the next row opening) cancels it, so a cancelled reload is asked for
 * again; the names to reload from earlier calls are kept until a reload goes through.
 */
function refreshSoon(extra = []) {
    extra.forEach((name) => reloadExtra.add(name));
    clearTimeout(reload);
    reload = setTimeout(() => {
        const only = ['workspace', 'competitors', 'others', 'ignored', 'targets', ...reloadExtra];
        router.reload({
            only,
            preserveScroll: true,
            onCancel: () => refreshSoon(),
            onSuccess: () => only.forEach((name) => reloadExtra.delete(name)),
        });
    }, 200);
}

async function decideKeys(keys, decision, target = null) {
    keys = keys.filter((key) => rowByKey.value.has(key) && !decided.has(key));
    if (keys.length === 0 || !props.canManage) {
        return;
    }
    error.value = '';
    const from = shownKey.value;
    for (const key of keys) {
        decided.set(key, { decision, target, id: null });
    }
    selection.value = new Set([...selection.value].filter((key) => !keys.includes(key)));
    if (from !== null && decided.has(from)) {
        const next = nextOpenKey(order.value, decided, from);
        if (next !== null) {
            cursor.value = next;
            openRow(next);
            focusRow(next);
        }
    }

    // The API takes at most 100 names at a time; a longer selection goes in several requests, one after the other.
    const sent = [];
    try {
        for (const batch of chunks(keys, DECISION_BATCH)) {
            const { data } = await window.axios.post(route('brands.entities.decide', props.brand.id), {
                keys: batch,
                decision,
                ...(target ? { target_type: target.type, target_id: target.id } : {}),
            });
            sent.push(...batch);
            for (const created of data.decisions) {
                const entry = decided.get(created.key);
                if (entry) {
                    entry.id = created.id;
                }
            }
        }
        toast.show(toastText(decision, keys, target), () => undoKeys(keys));
        refreshSoon();
    } catch (failure) {
        // The names the server did not take go back to the queue; those it took stay decided.
        for (const key of keys.filter((item) => !sent.includes(item))) {
            decided.delete(key);
        }
        error.value = responseError(failure) ?? trans('workspace.competitors.failed');
        if (sent.length > 0) {
            toast.show(toastText(decision, sent, target), () => undoKeys(sent));
            refreshSoon();
        }
    }
}

/** Reverses decisions made in this visit. Throws when one fails, so the message shows «could not undo». */
async function undoKeys(keys) {
    const entries = keys.map((key) => [key, decided.get(key)]).filter(([, entry]) => entry?.id);
    await Promise.all(
        entries.map(([, entry]) => window.axios.delete(route('brands.entities.undo', [props.brand.id, entry.id]))),
    );
    for (const [key] of entries) {
        decided.delete(key);
    }
    refreshSoon();
}

async function undoRow(row) {
    error.value = '';
    try {
        await undoKeys([row.key]);
    } catch (failure) {
        error.value = responseError(failure) ?? trans('workspace.undo.failed');
    }
}

function openPicker(keys, anchor) {
    if (!props.canManage || keys.length !== 1) {
        return;
    }
    picker.value = { keys, anchor };
}

function pickAlias(target) {
    const keys = picker.value?.keys ?? [];
    picker.value = null;
    decideKeys(keys, 'alias', target);
}

function isTyping(target) {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) &&
        target.type !== 'checkbox'
    );
}

function onKey(event) {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }
    if (activeTab.value !== 'pending' || !props.canManage || picker.value || isTyping(event.target)) {
        return;
    }
    const decision = KEY_DECISIONS[event.key];
    if (!decision || (decision === 'alias' && aliasOptions.value.length === 0)) {
        return;
    }
    const keys = decisionTargets({ selection: selection.value, open: shownKey.value, decided, order: order.value });
    if (keys.length === 0) {
        return;
    }
    event.preventDefault();
    if (decision === 'alias') {
        if (keys.length === 1) {
            const anchor =
                queueWrap.value?.querySelector(`[data-alias-for="${CSS.escape(keys[0])}"]`) ??
                event.target ??
                document.body;
            openPicker(keys, anchor);
        }

        return;
    }
    decideKeys(keys, decision);
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    wideQuery = window.matchMedia('(min-width: 1280px)');
    wideQuery.addEventListener('change', onWide);
    // The palette's «Add competitor» opens the form (docs/ux-arbeidsflate.md 4.6).
    if (new URL(page.url, 'http://ekko.invalid').searchParams.get('create') === 'competitor' && props.canManage) {
        router.replace({
            url: urlWithParams(page.url, { tab: 'competitors', open: 'new', create: null }),
            preserveState: true,
            preserveScroll: true,
        });
    }
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    wideQuery?.removeEventListener('change', onWide);
    clearTimeout(reload);
});

const pendingPanelOpen = computed(() => activeTab.value === 'pending' && shownItem.value !== null);
const pendingLabel = computed(() => trans('workspace.competitors.detail.label'));
const shownDecided = computed(() => {
    const entry = shownItem.value ? decided.get(shownItem.value.key) : null;

    return entry ? decidedLabel(entry) : null;
});

// ---- Competitors

const activeCompetitorId = computed(() => (typeof openId.value === 'number' ? openId.value : null));
const openCompetitor = computed(
    () => localCompetitors.value.find((item) => item.id === activeCompetitorId.value) ?? null,
);
const creatingNew = computed(() => openId.value === 'new');
const competitorPanelOpen = computed(
    () => activeTab.value === 'competitors' && (openCompetitor.value !== null || creatingNew.value),
);

const competitorColumns = computed(() => [
    { id: 'drag', header: trans('workspace.competitors.col.handle'), accessor: () => '', width: '5rem' },
    {
        id: 'name',
        header: trans('workspace.competitors.col.name'),
        accessor: 'name',
        primary: true,
        width: 'minmax(12rem, 1.4fr)',
    },
    { id: 'active', header: trans('workspace.competitors.col.active'), accessor: 'is_active', width: '6rem' },
    {
        id: 'aliases',
        header: trans('workspace.competitors.col.aliases'),
        accessor: (row) => row.aliases.length,
        width: '6rem',
        align: 'end',
    },
    {
        id: 'domains',
        header: trans('workspace.competitors.col.domains'),
        accessor: (row) => row.domains.length,
        width: '6rem',
        align: 'end',
    },
    {
        id: 'visibility',
        header: trans('workspace.competitors.col.visibility'),
        accessor: 'visibility',
        width: '9rem',
        align: 'end',
    },
    {
        id: 'change',
        header: trans('workspace.competitors.col.change'),
        accessor: 'change',
        width: '6rem',
        align: 'end',
    },
]);

function colorStyle(competitor) {
    return {
        backgroundColor:
            competitor.is_active && Number.isInteger(competitor.color_index)
                ? `var(--data-cat-${(competitor.color_index % CATEGORIES) + 1})`
                : 'var(--data-neutral)',
    };
}

function changeClass(change) {
    if (change === null || change === undefined || Math.abs(change) < 0.0005) {
        return 'text-shell-muted';
    }

    return change > 0 ? 'text-success-text' : 'text-error-text';
}

function mergeCompetitor(updated) {
    localCompetitors.value = localCompetitors.value.map((item) =>
        item.id === updated.id ? { ...item, ...updated } : item,
    );
    // A change of «active» or a new place moves the colors of the others.
    refreshSoon();
}

function archive(competitor) {
    if (!window.confirm(trans('workspace.competitors.panel.archive_confirm', { name: competitor.name }))) {
        return;
    }
    router.delete(route('brands.competitors.destroy', [props.brand.id, competitor.id]), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeRow(),
    });
}

function createCompetitor(name) {
    if (name === '' || creating.value) {
        return;
    }
    creating.value = true;
    createError.value = '';
    router.post(
        route('brands.competitors.store', props.brand.id),
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                newName.value = '';
                closeRow();
            },
            onError: (errors) => {
                createError.value = errors.name ?? trans('workspace.competitors.failed');
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
}

async function setActive(row, event) {
    error.value = '';
    try {
        const { data } = await window.axios.patch(route('brands.competitors.update', [props.brand.id, row.id]), {
            is_active: event.target.checked,
        });
        mergeCompetitor(data.competitor);
    } catch (failure) {
        event.target.checked = row.is_active;
        error.value = responseError(failure) ?? trans('workspace.competitors.failed');
    }
}

// Drag and drop: the handle of a row is the drag source, any row is the drop target. Alt with the arrow keys on
// the handle does the same without a mouse.
const dragId = ref(null);
let reorderChain = Promise.resolve();

function onDragStart(event) {
    const handle = event.target.closest?.('[data-drag-id]');
    if (!handle || !props.canManage) {
        return;
    }
    dragId.value = Number(handle.dataset.dragId);
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(dragId.value));
}

function onDragOver(event) {
    if (dragId.value !== null) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }
}

function onDrop(event) {
    if (dragId.value === null) {
        return;
    }
    event.preventDefault();
    const id = dragId.value;
    dragId.value = null;
    const row = event.target.closest?.('[data-row-position]');
    if (row) {
        reorderTo(id, Number(row.dataset.rowPosition));
    }
}

function reorderTo(id, index) {
    const next = reorderActive(localCompetitors.value, id, index);
    if (next === localCompetitors.value) {
        return false;
    }
    localCompetitors.value = next;
    const active = localCompetitors.value.filter((item) => item.is_active).map((item) => item.id);
    reorderChain = reorderChain.then(async () => {
        try {
            const { data } = await window.axios.post(route('brands.competitors.reorder', props.brand.id), {
                ids: active,
            });
            localCompetitors.value = localCompetitors.value.map((item) => ({
                ...item,
                color_index: data.color_indexes?.[item.id] ?? null,
            }));
        } catch (failure) {
            localCompetitors.value = props.competitors.map((item) => ({ ...item }));
            error.value = responseError(failure) ?? trans('workspace.competitors.failed');
        }
    });

    return true;
}

function onHandleKey(event, row) {
    const step = { ArrowUp: -1, ArrowDown: 1 }[event.key];
    if (!event.altKey || !step || !props.canManage) {
        return;
    }
    event.preventDefault();
    const index = localCompetitors.value.findIndex((item) => item.id === row.id);
    if (reorderTo(row.id, index + step)) {
        announcement.value = trans('workspace.competitors.announce.moved', {
            name: row.name,
            n: index + step + 1,
            total: localCompetitors.value.length,
        });
        nextTick(() => document.querySelector(`[data-drag-id="${row.id}"]`)?.focus());
    }
}

// ---- Other actors and ignored names

const decidedColumns = (withShare) => [
    {
        id: 'name',
        header: trans('workspace.competitors.col.name'),
        accessor: 'name',
        primary: true,
        width: 'minmax(12rem, 1.2fr)',
    },
    {
        id: 'variants',
        header: trans('workspace.competitors.col.variants'),
        accessor: (row) => row.variants.join(', '),
        width: 'minmax(10rem, 1.2fr)',
    },
    ...(withShare
        ? [
              {
                  id: 'share',
                  header: trans('workspace.competitors.col.share'),
                  accessor: 'share',
                  width: '8rem',
                  align: 'end',
              },
          ]
        : []),
    {
        id: 'decided_by',
        header: trans('workspace.competitors.col.decided_by'),
        accessor: (row) => row.decided_by ?? '',
        width: '10rem',
    },
    { id: 'decided_at', header: trans('workspace.competitors.col.decided_at'), accessor: 'decided_at', width: '9rem' },
    { id: 'act', header: trans('workspace.competitors.col.act'), accessor: () => '', width: 'minmax(11rem, 1fr)' },
];

async function restore(row) {
    error.value = '';
    try {
        await window.axios.delete(route('brands.entities.undo', [props.brand.id, row.id]));
        decided.delete(row.key);
        toast.show(trans('workspace.competitors.toast.restored', { name: row.name }));
        refreshSoon(['queue']);
    } catch (failure) {
        error.value = responseError(failure) ?? trans('workspace.competitors.failed');
    }
}

async function makeCompetitor(row) {
    error.value = '';
    const done = () => toast.show(trans('workspace.competitors.toast.made_competitor', { name: row.name }));
    if (!row.undoable) {
        // Past the undo window the decision stays; the competitor is added the ordinary way.
        router.post(
            route('brands.competitors.store', props.brand.id),
            { name: row.name },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: done,
                onError: (errors) => {
                    error.value = errors.name ?? trans('workspace.competitors.failed');
                },
            },
        );

        return;
    }
    // The old decision is undone first, because a name has one decision. If the new one fails, the old one is
    // made again, so the name is not left in the queue by mistake.
    try {
        await window.axios.delete(route('brands.entities.undo', [props.brand.id, row.id]));
    } catch (failure) {
        error.value = responseError(failure) ?? trans('workspace.competitors.failed');

        return;
    }
    decided.delete(row.key);
    try {
        await window.axios.post(route('brands.entities.decide', props.brand.id), {
            keys: [row.key],
            decision: 'competitor',
        });
        done();
    } catch (failure) {
        error.value = responseError(failure) ?? trans('workspace.competitors.failed');
        try {
            await window.axios.post(route('brands.entities.decide', props.brand.id), {
                keys: [row.key],
                decision: 'other',
            });
        } catch {
            error.value = `${error.value} ${trans('workspace.competitors.restore_failed', { name: row.name })}`;
        }
    }
    refreshSoon(['queue']);
}

const emptyCopy = (kind) => ({
    title: trans(`workspace.competitors.empty.${kind}_title`),
    body: trans(`workspace.competitors.empty.${kind}_body`),
});

const aliasOptions = computed(() => aliasTargets(props.targets, props.canManageBrand));
const pickerSuggestion = computed(() => {
    const row = picker.value ? rowByKey.value.get(picker.value.keys[0]) : null;

    return row?.suggestion?.decision === 'alias' ? row.suggestion : null;
});

const eyebrow = computed(() => trans('workspace.competitors.eyebrow', { brand: props.brand.name }));
</script>

<template>
    <AppLayout :title="brand.name">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-6 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-shell-muted">{{ eyebrow }}</p>
                    <h2 class="text-[22px] leading-tight font-medium text-shell-fg">
                        {{ $t('workspace.shell.setup_competitors') }}
                    </h2>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded-full bg-primary-deep px-4 py-2 text-sm font-medium text-white hover:bg-primary-deep/90"
                    data-testid="add-competitor"
                    @click="addCompetitor"
                >
                    {{ $t('workspace.competitors.add') }}
                </button>
            </div>

            <div
                role="tablist"
                :aria-label="$t('workspace.competitors.tabs_label')"
                class="flex gap-1 border-b border-line"
            >
                <button
                    v-for="(id, index) in TABS"
                    :id="`tab-${id}`"
                    :key="id"
                    ref="tabButtons"
                    type="button"
                    role="tab"
                    :aria-selected="id === activeTab"
                    :aria-controls="`panel-${id}`"
                    :tabindex="id === activeTab ? 0 : -1"
                    :data-testid="`tab-${id}`"
                    class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm"
                    :class="
                        id === activeTab
                            ? 'border-primary font-medium text-shell-fg'
                            : 'border-transparent text-shell-muted hover:text-shell-text'
                    "
                    @click="selectTab(id)"
                    @keydown="onTabKey($event, index)"
                >
                    {{ $t(`workspace.competitors.tab.${id}`) }}
                    <span
                        class="rounded-full px-2 text-xs tabular-nums"
                        :class="
                            id === 'pending' && counts.pending > 0 ? 'bg-primary-deep text-white' : 'text-shell-muted'
                        "
                        :data-testid="`count-${id}`"
                    >
                        {{ formatNumber(counts[id]) }}
                    </span>
                </button>
            </div>

            <p
                v-if="error"
                class="rounded-xl border border-line bg-shell-surface px-4 py-2 text-sm text-error-text"
                role="alert"
                data-testid="setup-error"
            >
                {{ error }}
            </p>
            <p class="sr-only" aria-live="polite">{{ announcement }}</p>

            <!-- Til behandling -->
            <section
                v-show="activeTab === 'pending'"
                id="panel-pending"
                role="tabpanel"
                aria-labelledby="tab-pending"
                class="flex flex-col gap-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="max-w-2xl text-sm text-shell-text">{{ $t('workspace.competitors.intro') }}</p>
                    <p
                        v-if="canManage"
                        class="flex flex-wrap items-center gap-1 text-xs text-shell-muted"
                        :aria-label="$t('workspace.competitors.keys.label')"
                    >
                        <template v-for="(decision, key) in KEY_DECISIONS" :key="key">
                            <kbd class="rounded border border-line px-1.5 py-0.5">{{ key }}</kbd>
                            {{ $t(`workspace.competitors.keys.${decision}`) }}
                            <span aria-hidden="true">·</span>
                        </template>
                        <kbd class="rounded border border-line px-1.5 py-0.5">{{ KEY_J }}</kbd>
                        {{ SLASH }}
                        <kbd class="rounded border border-line px-1.5 py-0.5">{{ KEY_K }}</kbd>
                        {{ $t('workspace.competitors.keys.move') }}
                    </p>
                </div>

                <div class="flex items-start gap-4">
                    <div ref="queueWrap" class="min-w-0 flex-[2_1_0]" data-testid="queue-table">
                        <DataTable
                            v-model:selection="selection"
                            :rows="queue"
                            :columns="queueColumns"
                            row-key="key"
                            :row-selectable="(row) => canManage && !decided.has(row.key)"
                            :active-row="pendingPanelOpen ? shownKey : null"
                            :empty="emptyCopy('pending')"
                            :label="$t('workspace.competitors.tab.pending')"
                            :row-label="(row) => row.name"
                            wrap
                            min-width="640px"
                            @open="openByUser"
                            @close="closeRow()"
                        >
                            <template #cell-name="{ row }">
                                <span :class="decided.has(row.key) ? 'opacity-60' : ''">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-[13.5px] font-medium text-shell-fg">{{ row.name }}</span>
                                        <span
                                            v-if="row.suggestion && !decided.has(row.key)"
                                            class="rounded-full border border-line px-2 py-0.5 text-[11px] text-shell-muted"
                                            data-testid="suggestion"
                                        >
                                            {{ suggestionLabel(row) }}
                                        </span>
                                    </span>
                                    <span class="mt-0.5 block text-xs font-normal text-shell-muted">
                                        {{ alsoLine(row) }}
                                    </span>
                                </span>
                            </template>
                            <template #cell-share="{ row }">
                                <span class="block min-w-0 flex-1" :class="decided.has(row.key) ? 'opacity-60' : ''">
                                    <span class="block text-[13px] tabular-nums text-shell-text">
                                        {{
                                            trans('workspace.competitors.share_cell', {
                                                percent: formatPercent(row.share),
                                                answers: formatCount(row.answers, 'answers'),
                                            })
                                        }}
                                    </span>
                                    <span class="mt-1 block h-1 rounded-full bg-line-soft" aria-hidden="true">
                                        <span
                                            class="block h-1 rounded-full bg-medium-beige"
                                            :style="{ width: `${shareBar(row.share)}%` }"
                                        ></span>
                                    </span>
                                </span>
                            </template>
                            <template #cell-last_seen="{ row }">
                                <span
                                    class="text-[13px] text-shell-text"
                                    :class="decided.has(row.key) ? 'opacity-60' : ''"
                                >
                                    {{ formatRelative(row.last_seen) }}
                                </span>
                            </template>
                            <template #cell-act="{ row }">
                                <div v-if="decided.get(row.key)" class="flex items-center gap-3 text-sm">
                                    <span class="text-success-text" data-testid="decided">
                                        {{ decidedLabel(decided.get(row.key)) }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-shell-accent underline disabled:opacity-50"
                                        :disabled="!decided.get(row.key).id"
                                        data-testid="undo-row"
                                        :aria-label="$t('workspace.competitors.undo_named', { name: row.name })"
                                        @click="undoRow(row)"
                                    >
                                        {{ $t('workspace.competitors.undo') }}
                                    </button>
                                </div>
                                <div v-else class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="decision in DECISIONS"
                                        :key="decision"
                                        type="button"
                                        class="rounded-full border px-3 py-1 text-xs text-shell-text hover:bg-row-hover"
                                        :class="isSuggested(row, decision) ? 'border-medium-beige' : 'border-line'"
                                        :data-testid="`decide-${decision}`"
                                        :data-alias-for="decision === 'alias' ? row.key : undefined"
                                        :aria-label="`${$t(`workspace.competitors.decision.${decision}`)}: ${row.name}`"
                                        :disabled="decision === 'alias' && aliasOptions.length === 0"
                                        @click="
                                            decision === 'alias'
                                                ? openPicker([row.key], $event.currentTarget)
                                                : decideKeys([row.key], decision)
                                        "
                                    >
                                        {{ $t(`workspace.competitors.decision.${decision}`) }}
                                    </button>
                                </div>
                            </template>
                        </DataTable>
                        <p
                            v-if="queue.length > 0"
                            class="mt-2 px-1 text-xs text-shell-muted"
                            data-testid="queue-footer"
                        >
                            {{
                                $t('workspace.competitors.footer', {
                                    shown: formatNumber(queue.length),
                                    total: formatNumber(queueTotal),
                                    min: formatNumber(minAnswers),
                                })
                            }}
                        </p>
                        <BulkBar v-if="canManage" :count="selection.size" @clear="selection = new Set()">
                            <button
                                v-for="decision in ['competitor', 'other', 'ignored']"
                                :key="decision"
                                type="button"
                                class="rounded-full border border-line bg-shell-surface px-3 py-1 text-xs text-shell-text hover:bg-row-hover"
                                :data-testid="`bulk-${decision}`"
                                @click="
                                    decideKeys(decisionTargets({ selection, open: null, decided, order }), decision)
                                "
                            >
                                {{ $t(`workspace.competitors.decision.${decision}`) }}
                            </button>
                            <span class="text-xs text-shell-muted">
                                {{ $t('workspace.competitors.bulk.alias_hint') }}
                            </span>
                        </BulkBar>
                    </div>

                    <Drawer :open="pendingPanelOpen" :label="pendingLabel" @close="closeRow()">
                        <EntityPanel
                            v-if="shownItem"
                            :brand-id="brand.id"
                            :item="shownItem"
                            :decided="shownDecided"
                            :markets="markets"
                            :engine-names="engineNames"
                            :targets="targets"
                            :can-manage="canManage"
                            :can-manage-brand="canManageBrand"
                            @alias="(target) => decideKeys([shownItem.key], 'alias', target)"
                        />
                    </Drawer>
                </div>
            </section>

            <!-- Konkurrenter -->
            <section
                v-if="activeTab === 'competitors'"
                id="panel-competitors"
                role="tabpanel"
                aria-labelledby="tab-competitors"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="min-w-0 flex-[2_1_0]"
                        data-testid="competitor-table"
                        @dragstart="onDragStart"
                        @dragover="onDragOver"
                        @drop="onDrop"
                        @dragend="dragId = null"
                    >
                        <DataTable
                            :rows="localCompetitors"
                            :columns="competitorColumns"
                            row-key="id"
                            :active-row="activeCompetitorId"
                            :empty="emptyCopy('competitors')"
                            :label="$t('workspace.competitors.tab.competitors')"
                            :row-label="(row) => row.name"
                            min-width="720px"
                            @open="(id) => openRow(id)"
                            @close="closeRow()"
                        >
                            <template #cell-drag="{ row }">
                                <button
                                    type="button"
                                    class="cursor-grab rounded p-1 text-shell-muted hover:text-shell-text disabled:cursor-default disabled:opacity-40"
                                    :draggable="canManage"
                                    :disabled="!canManage || !row.is_active"
                                    :data-drag-id="row.id"
                                    data-testid="competitor-handle"
                                    :aria-label="$t('workspace.competitors.handle', { name: row.name })"
                                    @keydown="onHandleKey($event, row)"
                                    @click.stop
                                >
                                    <Bars2Icon class="h-4 w-4" aria-hidden="true" />
                                </button>
                            </template>
                            <template #cell-name="{ row }">
                                <span class="flex items-center gap-2" :class="row.is_active ? '' : 'text-shell-muted'">
                                    <span
                                        class="size-2.5 shrink-0 rounded-sm"
                                        :style="colorStyle(row)"
                                        data-testid="competitor-dot"
                                        aria-hidden="true"
                                    ></span>
                                    <span class="truncate font-medium">{{ row.name }}</span>
                                    <span v-if="!row.is_active" class="text-xs">
                                        ({{ $t('workspace.competitors.inactive') }})
                                    </span>
                                </span>
                            </template>
                            <template #cell-active="{ row }">
                                <input
                                    type="checkbox"
                                    class="rounded border-line bg-shell-surface text-primary focus:ring-primary"
                                    :checked="row.is_active"
                                    :disabled="!canManage"
                                    :aria-label="`${$t('workspace.competitors.panel.active')}: ${row.name}`"
                                    @click.stop
                                    @change="setActive(row, $event)"
                                />
                            </template>
                            <template #cell-aliases="{ row }">{{ formatNumber(row.aliases.length) }}</template>
                            <template #cell-domains="{ row }">{{ formatNumber(row.domains.length) }}</template>
                            <template #cell-visibility="{ row }">
                                <span class="tabular-nums">{{ formatPercent(row.visibility) }}</span>
                            </template>
                            <template #cell-change="{ row }">
                                <span class="tabular-nums" :class="changeClass(row.change)">
                                    {{ formatPoints(row.change) }}
                                </span>
                            </template>
                        </DataTable>
                    </div>

                    <Drawer
                        :open="competitorPanelOpen"
                        :label="
                            creatingNew
                                ? $t('workspace.competitors.panel.new')
                                : $t('workspace.competitors.panel.competitor')
                        "
                        @close="closeRow()"
                    >
                        <CompetitorPanel
                            :brand-id="brand.id"
                            :competitor="openCompetitor"
                            :can-manage="canManage"
                            :create-error="createError"
                            :creating="creating"
                            :draft="newName"
                            :set-draft="(value) => (newName = value)"
                            :apply="mergeCompetitor"
                            @archive="archive"
                            @create="createCompetitor"
                        />
                    </Drawer>
                </div>
            </section>

            <!-- Andre aktører og Ignorert -->
            <section
                v-for="kind in ['other', 'ignored']"
                v-show="activeTab === kind"
                :id="`panel-${kind}`"
                :key="kind"
                role="tabpanel"
                :aria-labelledby="`tab-${kind}`"
            >
                <DataTable
                    :rows="kind === 'other' ? others : ignored"
                    :columns="decidedColumns(kind === 'other')"
                    row-key="id"
                    :empty="emptyCopy(kind)"
                    :label="$t(`workspace.competitors.tab.${kind}`)"
                    :row-label="(row) => row.name"
                    min-width="760px"
                >
                    <template #cell-share="{ row }">
                        <span class="tabular-nums">{{ formatPercent(row.share) }}</span>
                    </template>
                    <template #cell-decided_at="{ row }">{{ formatDateTime(row.decided_at) }}</template>
                    <template #cell-act="{ row }">
                        <div v-if="canManage" class="flex flex-wrap items-center gap-2 text-sm">
                            <button
                                v-if="row.undoable"
                                type="button"
                                class="text-shell-accent underline"
                                data-testid="restore"
                                :aria-label="$t('workspace.competitors.undo_named', { name: row.name })"
                                @click="restore(row)"
                            >
                                {{ $t('workspace.competitors.undo') }}
                            </button>
                            <span v-else class="text-xs text-shell-muted">
                                {{ $t('workspace.competitors.undo_expired', { hours: undoHours }) }}
                            </span>
                            <button
                                v-if="kind === 'other'"
                                type="button"
                                class="rounded-full border border-line px-3 py-1 text-xs hover:bg-row-hover"
                                :aria-label="$t('workspace.competitors.make_competitor_named', { name: row.name })"
                                @click="makeCompetitor(row)"
                            >
                                {{ $t('workspace.competitors.make_competitor') }}
                            </button>
                        </div>
                    </template>
                </DataTable>
            </section>
        </div>

        <AliasPicker
            v-if="picker"
            :targets="aliasOptions"
            :suggested="pickerSuggestion"
            :anchor="picker.anchor"
            @pick="pickAlias"
            @close="picker = null"
        />
    </AppLayout>
</template>
