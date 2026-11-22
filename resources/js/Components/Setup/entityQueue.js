// Pure rules for Setup › Competitors and brands (docs/ux-arbeidsflate.md 5.3), tested in Node
// (tests/js/competitor-setup.test.mjs).

/** The decision a digit key stands for: 1 competitor, 2 other actor, 3 alias, 4 ignore. */
export const KEY_DECISIONS = { 1: 'competitor', 2: 'other', 3: 'alias', 4: 'ignored' };

/**
 * The names a decision key or button applies to: the selected rows that are still undecided, or else the open
 * row. A decided row (a Map key) is never decided twice.
 *
 * @param {{ selection: Set<string>, open: string | null, decided: { has: (key: string) => boolean }, order: string[] }} state
 * @returns {string[]} in queue order
 */
export function decisionTargets({ selection, open, decided, order }) {
    if (selection.size > 0) {
        return order.filter((key) => selection.has(key) && !decided.has(key));
    }

    return open !== null && order.includes(open) && !decided.has(open) ? [open] : [];
}

/**
 * The row to open after `from` was decided: the next undecided row below it, else the nearest above, else null.
 *
 * @param {string[]} order
 * @param {{ has: (key: string) => boolean }} decided
 * @param {string | null} from
 */
export function nextOpenKey(order, decided, from) {
    const start = from === null ? -1 : order.indexOf(from);
    for (let index = start + 1; index < order.length; index++) {
        if (!decided.has(order[index])) {
            return order[index];
        }
    }
    for (let index = Math.min(start, order.length) - 1; index >= 0; index--) {
        if (!decided.has(order[index])) {
            return order[index];
        }
    }

    return null;
}

/** The ids with `id` moved to `index` (clamped); the same array when nothing changes. */
export function moveId(ids, id, index) {
    const from = ids.indexOf(id);
    const to = Math.max(0, Math.min(ids.length - 1, index));
    if (from < 0 || from === to) {
        return ids;
    }
    const next = ids.filter((item) => item !== id);
    next.splice(to, 0, id);

    return next;
}

/** The width of the share line in percent: the share times three, at most 100. */
export function shareBar(share) {
    return share === null || share === undefined ? 0 : Math.min(100, Math.max(0, share * 300));
}

/** The targets an alias can point at: the own brand needs the right to manage the brand. */
export function aliasTargets(targets, canManageBrand) {
    return targets.filter((target) => target.type !== 'brand' || canManageBrand);
}

/** The targets whose name contains the search text, a suggested target first. */
export function filterTargets(targets, search, suggested = null) {
    const needle = search.trim().toLocaleLowerCase();
    const matching = targets.filter((target) => needle === '' || target.name.toLocaleLowerCase().includes(needle));
    if (!suggested) {
        return matching;
    }
    const isSuggested = (target) => target.type === suggested.target_type && target.id === suggested.target_id;

    return [...matching.filter(isSuggested), ...matching.filter((target) => !isSuggested(target))];
}

/** The same URL with the given parameters set, or removed for null. Other parameters stay. */
export function urlWithParams(url, changes) {
    const target = new URL(url, 'http://ekko.invalid');
    for (const [name, value] of Object.entries(changes)) {
        if (value === null || value === undefined) {
            target.searchParams.delete(name);
        } else {
            target.searchParams.set(name, String(value));
        }
    }

    return `${target.pathname}${target.search}${target.hash}`;
}

/** The first message of a Laravel validation error or the response message, else null. */
export function responseError(error) {
    const data = error?.response?.data;
    if (data?.errors && typeof data.errors === 'object') {
        const first = Object.values(data.errors)[0];

        return Array.isArray(first) ? first[0] : String(first);
    }

    return typeof data?.message === 'string' ? data.message : null;
}

/** A domain as typed or pasted, without protocol, credentials, port, path, query and a leading www. */
export function normalizeDomain(text) {
    const withoutScheme = text.trim().replace(/^[a-z][a-z0-9+.-]*:\/\//i, '');
    const host = withoutScheme.split(/[/?#]/)[0].replace(/^.*@/, '').replace(/:\d+$/, '');

    return host
        .replace(/^www\./i, '')
        .replace(/\.$/, '')
        .toLowerCase();
}

/** The API takes at most this many names in one decision. */
export const DECISION_BATCH = 100;

/** The list in pieces of at most `size` items. */
export function chunks(list, size = DECISION_BATCH) {
    const pieces = [];
    for (let index = 0; index < list.length; index += size) {
        pieces.push(list.slice(index, index + size));
    }

    return pieces;
}

/**
 * The competitors after `id` is dragged to the place of the row at `index`. Like the server, only the active
 * competitors change places among themselves; an inactive competitor keeps its place, and an inactive one cannot
 * be moved. Dropping on an inactive row moves to the nearest active one in the direction of the move. The same
 * array comes back when nothing changes.
 */
export function reorderActive(list, id, index) {
    const from = list.findIndex((item) => item.id === id);
    if (from < 0 || !list[from].is_active || index < 0 || index >= list.length || index === from) {
        return list;
    }
    const step = index > from ? 1 : -1;
    let target = index;
    while (target >= 0 && target < list.length && !list[target].is_active) {
        target += step;
    }
    if (target < 0 || target >= list.length || target === from) {
        return list;
    }
    const active = list.filter((item) => item.is_active);
    const moved = moveId(
        active.map((item) => item.id),
        id,
        active.findIndex((item) => item.id === list[target].id),
    );
    const byId = new Map(active.map((item) => [item.id, item]));
    let slot = 0;

    return list.map((item) => (item.is_active ? byId.get(moved[slot++]) : item));
}
