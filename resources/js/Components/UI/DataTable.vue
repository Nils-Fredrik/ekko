<script setup>
// The table for everything that exists in many copies (docs/ux-arbeidsflate.md 4.3). TanStack Table
// owns the column model, sorting and column visibility; TanStack Virtual renders only the rows in
// view above 200 rows. Selection, grouping and keyboard rules live in ./dataTable.js.
//
// <DataTable :rows :columns row-key="id" v-model:selection="ids" group-by="topic_id"
//     :group-label="(key, rows) => …" :active-row="openId" column-store-key="setup.prompts"
//     :empty="{ title, body, action: { label, href } }" :label="…" dense @open="id => …" @close="…" />
//
// A column is { id, header, accessor?, width?, align?: 'end', sortable?, hideable?, primary? }.
// Cells render through the slot `cell-<id>` ({ row, value }) or the plain value. The primary cell
// opens the row, so its slot must not contain links or buttons. Group headings use the slot `group`
// ({ group: { key, label, count, collapsed } }), the search field goes in the slot `toolbar`.
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue';
import { ChevronDownIcon, ChevronRightIcon, ChevronUpIcon } from '@heroicons/vue/20/solid';
import {
    columnVisibilityFeature,
    createSortedRowModel,
    rowSortingFeature,
    tableFeatures,
    useTable,
} from '@tanstack/vue-table';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { compareText, formatCount } from '@/format';
import {
    VIRTUAL_THRESHOLD,
    displayItems,
    gridTemplate,
    keyAction,
    moveIndex,
    readHiddenColumns,
    selectRange,
    toggleAllVisible,
    toggleId,
    visibleIds,
    visibleSelectionState,
    writeHiddenColumns,
} from './dataTable.js';

const props = defineProps({
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    rowKey: { type: [String, Function], default: 'id' },
    // A Set of ids turns on selection; leave it out for a table without checkboxes.
    selection: { type: Set, default: undefined },
    groupBy: { type: [String, Function], default: null },
    groupLabel: { type: Function, default: null },
    activeRow: { type: [String, Number], default: null },
    columnStoreKey: { type: String, default: '' },
    empty: { type: Object, default: null },
    label: { type: String, required: true },
    rowLabel: { type: Function, default: null },
    sort: { type: Array, default: () => [] },
    minWidth: { type: String, default: '640px' },
    dense: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:selection', 'open', 'close']);

const idOf = (row) => (typeof props.rowKey === 'function' ? props.rowKey(row) : row[props.rowKey]);
const valueOf = (column, row) =>
    typeof column.accessor === 'function' ? column.accessor(row) : row[column.accessor ?? column.id];

function compareValues(a, b) {
    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    return compareText(a, b);
}

// Stable, as TanStack asks: the features never change, the column definitions follow the prop.
const features = tableFeatures({
    columnVisibilityFeature,
    rowSortingFeature,
    sortedRowModel: createSortedRowModel(),
});
const sorting = ref([...props.sort]);
const hidden = ref(readHiddenColumns(props.columnStoreKey));
watch(
    () => props.columnStoreKey,
    (key) => {
        hidden.value = readHiddenColumns(key);
    },
);
const columnDefs = computed(() =>
    props.columns.map((column) => ({
        id: column.id,
        header: column.header,
        accessorFn: (row) => valueOf(column, row),
        enableSorting: Boolean(column.sortable),
        enableHiding: Boolean(column.hideable) && !column.primary,
        sortUndefined: 'last',
        sortFn: (a, b, id) => compareValues(a.getValue(id), b.getValue(id)),
    })),
);
const table = useTable({
    features,
    columns: columnDefs,
    data: computed(() => props.rows),
    state: computed(() => ({
        sorting: sorting.value,
        columnVisibility: Object.fromEntries(hidden.value.map((id) => [id, false])),
    })),
    getRowId: (row) => String(idOf(row)),
    onSortingChange: (next) => {
        sorting.value = typeof next === 'function' ? next(sorting.value) : next;
    },
    onColumnVisibilityChange: (next) => {
        const current = Object.fromEntries(hidden.value.map((id) => [id, false]));
        const visibility = typeof next === 'function' ? next(current) : next;
        hidden.value = Object.keys(visibility).filter((id) => visibility[id] === false);
        writeHiddenColumns(props.columnStoreKey, hidden.value);
    },
});

const byId = computed(() => new Map(props.columns.map((column) => [column.id, column])));
const visibleColumns = computed(() =>
    table
        .getVisibleLeafColumns()
        .map((column) => byId.value.get(column.id))
        .filter(Boolean),
);
const hideableColumns = computed(() =>
    table
        .getAllLeafColumns()
        .filter((column) => column.getCanHide())
        .map((column) => ({ column, spec: byId.value.get(column.id) })),
);
const sortedRows = computed(() => table.getRowModel().rows.map((row) => row.original));

const collapsed = ref(new Set());
const items = computed(() =>
    displayItems(sortedRows.value, {
        rowKey: props.rowKey,
        groupBy: props.groupBy,
        groupLabel: props.groupLabel,
        collapsed: collapsed.value,
    }),
);
const ids = computed(() => visibleIds(items.value));
const rowPositions = computed(() => {
    const positions = new Map();
    let position = 0;
    items.value.forEach((item, index) => {
        if (item.type === 'row') {
            positions.set(index, position++);
        }
    });

    return positions;
});

const selectable = computed(() => props.selection instanceof Set);
const selected = computed(() => (selectable.value ? props.selection : new Set()));
const allState = computed(() => visibleSelectionState(selected.value, ids.value));
const template = computed(() => gridTemplate(visibleColumns.value, selectable.value));
const virtual = computed(() => props.rows.length > VIRTUAL_THRESHOLD);

const scroller = ref(null);
const toolbar = ref(null);
const grid = ref(null);
const virtualizer = useVirtualizer(
    computed(() => ({
        count: virtual.value ? items.value.length : 0,
        getScrollElement: () => scroller.value,
        estimateSize: (index) => (items.value[index]?.type === 'group' ? 37 : props.dense ? 33 : 39),
        getItemKey: (index) => keyOf(items.value[index], index),
        overscan: 8,
    })),
);
const rendered = computed(() =>
    virtual.value
        ? virtualizer.value.getVirtualItems().map((entry) => ({
              item: items.value[entry.index],
              index: entry.index,
              start: entry.start,
          }))
        : items.value.map((item, index) => ({ item, index, start: 0 })),
);

function keyOf(item, index) {
    return item?.type === 'group' ? `group-${item.key}` : `row-${item?.id ?? index}`;
}

function measure(element) {
    if (virtual.value && element) {
        virtualizer.value.measureElement(element);
    }
}

// Selection
let anchor = null;

function setSelection(next) {
    emit('update:selection', next);
}

function check(id, shift) {
    if (!selectable.value) {
        return;
    }
    setSelection(
        shift && anchor !== null ? selectRange(selected.value, ids.value, anchor, id) : toggleId(selected.value, id),
    );
    anchor = id;
}

function checkAll() {
    setSelection(toggleAllVisible(selected.value, ids.value));
}

// Keyboard: one row is in the tab order (roving tabindex), the arrows move it.
const focusPosition = ref(0);

// `focus: false` moves the tab stop and scrolls the row into view without taking the focus, for j/k
// pressed in the detail panel (which may be a dialog with a focus trap).
function focusRow(position, { focus = true } = {}) {
    if (position < 0) {
        return;
    }
    focusPosition.value = position;
    const index = [...rowPositions.value].find(([, value]) => value === position)?.[0];
    if (virtual.value && index !== undefined) {
        virtualizer.value.scrollToIndex(index, { align: 'auto' });
    }
    if (!focus) {
        return;
    }
    nextTick(() => {
        // A virtual row may need one more frame to be rendered after scrolling.
        const find = () => grid.value?.querySelector(`[data-row-position="${position}"]`);
        const element = find();
        if (element) {
            element.focus({ preventScroll: virtual.value });
        } else {
            requestAnimationFrame(() => find()?.focus());
        }
    });
}

function isTyping(target) {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) &&
        target.type !== 'checkbox'
    );
}

function run(action, event, { fromPanel = false } = {}) {
    const count = ids.value.length;
    switch (action) {
        case 'next':
        case 'previous':
        case 'first':
        case 'last':
            focusRow(moveIndex(action, focusPosition.value, count));
            break;
        case 'open-next':
        case 'open-previous': {
            const current = ids.value.indexOf(props.activeRow);
            const position = moveIndex(action, current < 0 ? focusPosition.value : current, count);
            focusRow(position, { focus: !fromPanel });
            if (position >= 0) {
                emit('open', ids.value[position]);
            }
            break;
        }
        case 'toggle':
            if (ids.value[focusPosition.value] !== undefined) {
                check(ids.value[focusPosition.value], event.shiftKey);
            }
            break;
        case 'open':
            if (ids.value[focusPosition.value] !== undefined) {
                emit('open', ids.value[focusPosition.value]);
            }
            break;
        case 'search':
            toolbar.value?.querySelector('input:not([type="checkbox"]), [data-table-search]')?.focus();
            break;
        case 'close':
            emit('close');
            break;
        case 'clear':
            setSelection(new Set());
            break;
    }
}

function onKeydown(event) {
    if (event.defaultPrevented || isTyping(event.target)) {
        return;
    }
    const inRow = event.target instanceof HTMLElement && event.target.closest('[data-row-position]');
    const action = keyAction(event, {
        panelOpen: props.activeRow !== null,
        hasSelection: selected.value.size > 0,
        selectable: selectable.value,
    });
    const rowAction = ['next', 'previous', 'first', 'last', 'toggle', 'open'].includes(action);
    if (!action || (rowAction && !inRow)) {
        return;
    }
    event.preventDefault();
    run(action, event);
}

// j and k also work while the focus is in the detail panel (Drawer, data-detail-panel).
function onWindowKeydown(event) {
    if (event.defaultPrevented || isTyping(event.target) || grid.value?.contains(event.target)) {
        return;
    }
    // Only from the detail panel (Drawer marks itself), not from the menu or the rest of the page.
    if (!(event.target instanceof Element) || !event.target.closest('[data-detail-panel]')) {
        return;
    }
    const action = keyAction(event, { panelOpen: props.activeRow !== null });
    if (action === 'open-next' || action === 'open-previous') {
        event.preventDefault();
        run(action, event, { fromPanel: true });
    }
}

watch(
    () => props.activeRow !== null,
    (open) => {
        if (open) {
            window.addEventListener('keydown', onWindowKeydown);
        } else {
            window.removeEventListener('keydown', onWindowKeydown);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => window.removeEventListener('keydown', onWindowKeydown));

watch(ids, (current) => {
    if (focusPosition.value >= current.length) {
        focusPosition.value = Math.max(0, current.length - 1);
    }
});

function toggleGroup(key) {
    const next = new Set(collapsed.value);
    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }
    collapsed.value = next;
}

function sortState(columnId) {
    return table.getColumn(columnId)?.getIsSorted() ?? false;
}

function toggleSort(columnId) {
    table.getColumn(columnId)?.toggleSorting();
}

function labelFor(row) {
    if (props.rowLabel) {
        return props.rowLabel(row);
    }
    const primary = props.columns.find((column) => column.primary) ?? props.columns[0];

    return String(primary ? (valueOf(primary, row) ?? '') : '');
}

const cellPadding = computed(() => (props.dense ? 'px-3 py-[6px]' : 'px-3 py-[9px]'));
const showEmpty = computed(() => !props.loading && props.rows.length === 0 && props.empty);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3" @keydown="onKeydown">
        <div v-if="$slots.toolbar || hideableColumns.length" class="flex flex-wrap items-center gap-2">
            <div ref="toolbar" class="min-w-0 flex-1">
                <slot name="toolbar" />
            </div>
            <Popover v-if="hideableColumns.length" class="relative">
                <PopoverButton
                    class="rounded-full border border-line px-3 py-1.5 text-sm text-shell-text hover:bg-row-hover"
                >
                    {{ $t('workspace.table.columns') }}
                </PopoverButton>
                <PopoverPanel
                    class="absolute right-0 top-full z-30 mt-2 w-56 rounded-xl border border-line bg-shell-surface p-3 shadow-pop"
                >
                    <fieldset>
                        <legend class="mb-2 text-xs text-shell-muted">
                            {{ $t('workspace.table.columns_legend') }}
                        </legend>
                        <label
                            v-for="{ column, spec } in hideableColumns"
                            :key="column.id"
                            class="flex items-center gap-2 py-1 text-sm text-shell-text"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-line bg-shell-surface text-primary focus:ring-primary"
                                :checked="column.getIsVisible()"
                                @change="column.toggleVisibility($event.target.checked)"
                            />
                            {{ spec.header }}
                        </label>
                    </fieldset>
                </PopoverPanel>
            </Popover>
        </div>

        <EmptyState
            v-if="showEmpty"
            :title="empty.title"
            :body="empty.body"
            :href="empty.action?.href ?? ''"
            :link="empty.action?.label ?? ''"
        />

        <div
            v-else
            ref="scroller"
            class="overflow-x-auto rounded-[14px] border border-line"
            :class="virtual ? 'max-h-[70vh] overflow-y-auto' : ''"
            :tabindex="virtual ? -1 : undefined"
        >
            <div
                ref="grid"
                role="grid"
                :aria-label="label"
                :aria-rowcount="items.length + 1"
                :aria-multiselectable="selectable || undefined"
                :aria-busy="loading || undefined"
                :style="{ minWidth }"
            >
                <div role="rowgroup" class="sticky top-0 z-10 bg-shell-panel">
                    <div
                        role="row"
                        aria-rowindex="1"
                        class="grid border-b border-line"
                        :style="{ gridTemplateColumns: template }"
                    >
                        <div v-if="selectable" role="columnheader" class="flex items-center px-3 py-2">
                            <input
                                type="checkbox"
                                class="rounded border-line bg-shell-surface text-primary focus:ring-primary"
                                :checked="allState === 'all'"
                                :indeterminate="allState === 'some'"
                                :disabled="ids.length === 0"
                                :aria-label="$t('workspace.table.select_all')"
                                @change="checkAll"
                            />
                        </div>
                        <div
                            v-for="column in visibleColumns"
                            :key="column.id"
                            role="columnheader"
                            class="flex min-w-0 items-center px-3 py-2 text-[11.5px] text-shell-muted"
                            :class="column.align === 'end' ? 'justify-end text-right' : ''"
                            :aria-sort="
                                sortState(column.id) === 'asc'
                                    ? 'ascending'
                                    : sortState(column.id) === 'desc'
                                      ? 'descending'
                                      : undefined
                            "
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="inline-flex min-w-0 items-center gap-1 hover:text-shell-text"
                                @click="toggleSort(column.id)"
                            >
                                <span class="truncate">{{ column.header }}</span>
                                <ChevronUpIcon
                                    v-if="sortState(column.id) === 'asc'"
                                    class="h-3.5 w-3.5"
                                    aria-hidden="true"
                                />
                                <ChevronDownIcon
                                    v-else-if="sortState(column.id) === 'desc'"
                                    class="h-3.5 w-3.5"
                                    aria-hidden="true"
                                />
                            </button>
                            <span v-else class="truncate">{{ column.header }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="loading" role="rowgroup">
                    <div
                        v-for="n in 5"
                        :key="n"
                        role="row"
                        class="grid border-b border-line-soft last:border-b-0"
                        :style="{ gridTemplateColumns: template }"
                    >
                        <div v-if="selectable" role="gridcell" :class="cellPadding"></div>
                        <div v-for="column in visibleColumns" :key="column.id" role="gridcell" :class="cellPadding">
                            <div class="ekko-skeleton h-4 rounded"></div>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    role="rowgroup"
                    :style="virtual ? { height: `${virtualizer.getTotalSize()}px`, position: 'relative' } : undefined"
                >
                    <template v-for="entry in rendered" :key="keyOf(entry.item, entry.index)">
                        <div
                            v-if="entry.item.type === 'group'"
                            :ref="measure"
                            role="row"
                            :data-index="entry.index"
                            :aria-rowindex="entry.index + 2"
                            class="grid border-b border-line-soft bg-shell-surface"
                            :class="virtual ? 'absolute inset-x-0 top-0' : ''"
                            :style="[
                                { gridTemplateColumns: template },
                                virtual ? { transform: `translateY(${entry.start}px)` } : {},
                            ]"
                        >
                            <div role="gridcell" class="px-3 py-2" style="grid-column: 1 / -1">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 text-sm font-medium text-shell-text"
                                    :aria-expanded="!entry.item.collapsed"
                                    @click="toggleGroup(entry.item.key)"
                                >
                                    <ChevronRightIcon v-if="entry.item.collapsed" class="h-4 w-4" aria-hidden="true" />
                                    <ChevronDownIcon v-else class="h-4 w-4" aria-hidden="true" />
                                    <slot name="group" :group="entry.item">
                                        <span>{{ entry.item.label }}</span>
                                    </slot>
                                    <span class="rounded-full bg-line-soft px-2 text-xs font-normal text-shell-muted">
                                        {{ formatCount(entry.item.count, 'rows') }}
                                    </span>
                                </button>
                            </div>
                        </div>
                        <div
                            v-else
                            :ref="measure"
                            role="row"
                            :data-index="entry.index"
                            :data-row-position="rowPositions.get(entry.index)"
                            :aria-rowindex="entry.index + 2"
                            :aria-selected="selectable ? selected.has(entry.item.id) : undefined"
                            :tabindex="rowPositions.get(entry.index) === focusPosition ? 0 : -1"
                            class="grid border-b border-line-soft text-sm text-shell-text outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                            :class="[
                                virtual ? 'absolute inset-x-0 top-0' : '',
                                entry.item.id === activeRow
                                    ? 'bg-row-hover'
                                    : selected.has(entry.item.id)
                                      ? 'bg-row-hover/60'
                                      : 'hover:bg-row-hover/40',
                            ]"
                            :style="[
                                { gridTemplateColumns: template },
                                virtual ? { transform: `translateY(${entry.start}px)` } : {},
                            ]"
                            @focusin="focusPosition = rowPositions.get(entry.index)"
                        >
                            <div v-if="selectable" role="gridcell" class="flex items-center" :class="cellPadding">
                                <input
                                    type="checkbox"
                                    tabindex="-1"
                                    class="rounded border-line bg-shell-surface text-primary focus:ring-primary"
                                    :checked="selected.has(entry.item.id)"
                                    :aria-label="$t('workspace.table.select_row', { label: labelFor(entry.item.row) })"
                                    @click.stop="check(entry.item.id, $event.shiftKey)"
                                />
                            </div>
                            <div
                                v-for="column in visibleColumns"
                                :key="column.id"
                                role="gridcell"
                                class="flex min-w-0 items-center"
                                :class="[
                                    cellPadding,
                                    column.align === 'end' ? 'justify-end text-right tabular-nums' : '',
                                ]"
                            >
                                <button
                                    v-if="column.primary"
                                    type="button"
                                    tabindex="-1"
                                    class="min-w-0 flex-1 truncate text-left"
                                    @click="emit('open', entry.item.id)"
                                >
                                    <slot
                                        :name="`cell-${column.id}`"
                                        :row="entry.item.row"
                                        :value="valueOf(column, entry.item.row)"
                                    >
                                        {{ valueOf(column, entry.item.row) }}
                                    </slot>
                                </button>
                                <slot
                                    v-else
                                    :name="`cell-${column.id}`"
                                    :row="entry.item.row"
                                    :value="valueOf(column, entry.item.row)"
                                >
                                    <span class="min-w-0 truncate">{{ valueOf(column, entry.item.row) }}</span>
                                </slot>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
