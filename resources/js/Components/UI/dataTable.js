// The state rules behind DataTable.vue (docs/ux-arbeidsflate.md 4.3): selection, shift ranges,
// grouping, keyboard and the remembered columns. Plain functions, so tests/js can run them in Node.

/** Above this many rows the table renders only the rows in view. */
export const VIRTUAL_THRESHOLD = 200;

const STORE_PREFIX = 'ekko.table.';

/** A new selection with one id switched. */
export function toggleId(selection, id) {
    const next = new Set(selection);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    return next;
}

/**
 * Shift-click: every row from the anchor to the clicked row gets the state the clicked row
 * gets. Without an anchor in the list, only the clicked row changes.
 */
export function selectRange(selection, orderedIds, anchorId, id) {
    const target = !selection.has(id);
    const from = orderedIds.indexOf(anchorId);
    const to = orderedIds.indexOf(id);
    if (from === -1 || to === -1) {
        return toggleId(selection, id);
    }
    const next = new Set(selection);
    const [start, end] = from < to ? [from, to] : [to, from];
    for (const rowId of orderedIds.slice(start, end + 1)) {
        if (target) {
            next.add(rowId);
        } else {
            next.delete(rowId);
        }
    }

    return next;
}

/** 'all', 'some' or 'none' of the visible rows are selected. */
export function visibleSelectionState(selection, visibleIds) {
    if (visibleIds.length === 0) {
        return 'none';
    }
    const selected = visibleIds.filter((id) => selection.has(id)).length;
    if (selected === 0) {
        return 'none';
    }

    return selected === visibleIds.length ? 'all' : 'some';
}

/** The header checkbox: selects every visible row, or clears them when all are selected. */
export function toggleAllVisible(selection, visibleIds) {
    const next = new Set(selection);
    const all = visibleSelectionState(selection, visibleIds) === 'all';
    for (const id of visibleIds) {
        if (all) {
            next.delete(id);
        } else {
            next.add(id);
        }
    }

    return next;
}

/**
 * Rows in display order with a heading per group. Groups keep the order of their first row.
 *
 * @returns {Array<{ type: 'group', key: string, label: string, count: number, collapsed: boolean } | { type: 'row', id: *, row: *, index: number }>}
 */
export function displayItems(rows, { rowKey = 'id', groupBy = null, groupLabel = null, collapsed = new Set() } = {}) {
    const idOf = (row) => (typeof rowKey === 'function' ? rowKey(row) : row[rowKey]);
    if (!groupBy) {
        return rows.map((row, index) => ({ type: 'row', id: idOf(row), row, index }));
    }
    const groups = new Map();
    for (const row of rows) {
        const key = String(typeof groupBy === 'function' ? groupBy(row) : (row[groupBy] ?? ''));
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(row);
    }
    const items = [];
    let index = 0;
    for (const [key, members] of groups) {
        const isCollapsed = collapsed.has(key);
        items.push({
            type: 'group',
            key,
            label: groupLabel ? groupLabel(key, members) : key,
            count: members.length,
            collapsed: isCollapsed,
        });
        if (isCollapsed) {
            continue;
        }
        for (const row of members) {
            items.push({ type: 'row', id: idOf(row), row, index: index++ });
        }
    }

    return items;
}

/** Ids of the rows that are shown (not inside a collapsed group). */
export function visibleIds(items) {
    return items.filter((item) => item.type === 'row').map((item) => item.id);
}

/**
 * What a key press in the table does. Arrows move the focus, x selects, Enter opens, / goes to the
 * search field and Esc closes the panel or clears the selection. j and k move the open row while
 * the detail panel is open.
 *
 * @returns {null | 'next' | 'previous' | 'first' | 'last' | 'toggle' | 'open' | 'open-next' | 'open-previous' | 'search' | 'close' | 'clear'}
 */
export function keyAction(event, { panelOpen = false, hasSelection = false, selectable = false } = {}) {
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return null;
    }
    switch (event.key) {
        case 'ArrowDown':
            return 'next';
        case 'ArrowUp':
            return 'previous';
        case 'Home':
            return 'first';
        case 'End':
            return 'last';
        case 'Enter':
            return 'open';
        case 'x':
        case 'X':
            return selectable ? 'toggle' : null;
        case 'j':
            return panelOpen ? 'open-next' : null;
        case 'k':
            return panelOpen ? 'open-previous' : null;
        case '/':
            return 'search';
        case 'Escape':
            if (panelOpen) {
                return 'close';
            }

            return hasSelection ? 'clear' : null;
        default:
            return null;
    }
}

/** The index the focus moves to, kept inside the list. */
export function moveIndex(action, index, count) {
    if (count === 0) {
        return -1;
    }
    switch (action) {
        case 'next':
        case 'open-next':
            return Math.min(count - 1, index < 0 ? 0 : index + 1);
        case 'previous':
        case 'open-previous':
            return Math.max(0, index < 0 ? 0 : index - 1);
        case 'first':
            return 0;
        case 'last':
            return count - 1;
        default:
            return index;
    }
}

/** Hidden columns remembered for a table. Storage can be missing or throw (private mode). */
export function readHiddenColumns(key, storage = globalThis.localStorage) {
    if (!key) {
        return [];
    }
    try {
        const value = JSON.parse(storage?.getItem(STORE_PREFIX + key) ?? 'null');

        return Array.isArray(value?.hidden) ? value.hidden.filter((id) => typeof id === 'string') : [];
    } catch {
        return [];
    }
}

export function writeHiddenColumns(key, hidden, storage = globalThis.localStorage) {
    if (!key) {
        return;
    }
    try {
        storage?.setItem(STORE_PREFIX + key, JSON.stringify({ hidden: [...hidden] }));
    } catch {
        // The choice is a convenience; the table works without it.
    }
}

/** The CSS grid tracks: a checkbox column when rows can be selected, then each visible column. */
export function gridTemplate(columns, selectable) {
    const tracks = columns.map((column) => column.width ?? 'minmax(0, 1fr)');

    return (selectable ? ['36px', ...tracks] : tracks).join(' ');
}
