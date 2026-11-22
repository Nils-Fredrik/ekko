import { dayDistance } from '../format.js';

/**
 * Groups inbox rows by local day. The server already returns the newest first, so one day stays together.
 *
 * @param {Array<{ at: string }>} items
 * @param {{ now?: Date, heading: (at: string, distance: number|null) => string }} options
 */
export function groupInbox(items, { now = new Date(), heading }) {
    const groups = [];
    for (const item of items) {
        const distance = dayDistance(item.at, now);
        const key = distance === null ? 'unknown' : String(distance);
        const current = groups.at(-1);
        if (!current || current.key !== key) {
            groups.push({ key, label: heading(item.at, distance), items: [item] });
        } else {
            current.items.push(item);
        }
    }

    return groups;
}
