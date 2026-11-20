// Which buckets of the robot chart are drawn (docs/ui-forbedring.md 7.6, U09). No imports, so tests can load it in Node.
//
// The log keeps no row for a day without robot hits, so a bucket between the first and last bucket with log data
// counts as zero. Outside that span the chart stays empty. A stacked area does not draw a point with nulls on both
// sides, so a single bucket with data is widened by one zero bucket on each side, where the period has one.

/**
 * @param {string[]} categories bucket start dates, oldest first
 * @param {Set<string>|Map<string, unknown>} withData buckets that have log rows
 * @returns {{ from: number, to: number } | null} inclusive index span to draw, or null when nothing has data
 */
export function robotSpan(categories, withData) {
    const indexes = categories
        .map((bucket, index) => (withData.has(bucket) ? index : -1))
        .filter((index) => index >= 0);
    if (indexes.length === 0) {
        return null;
    }
    let from = indexes[0];
    let to = indexes[indexes.length - 1];
    if (from === to) {
        from = Math.max(from - 1, 0);
        to = Math.min(to + 1, categories.length - 1);
    }

    return { from, to };
}
