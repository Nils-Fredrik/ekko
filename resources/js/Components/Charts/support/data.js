// Pure data helpers for the charts. No imports, so tests can load this file in Node.

export const LOW_SAMPLE_N = 10;

/** True when a point has too few observations to trust: the backend flag, else n < 10 (docs/spec.md 9). */
export function isLowSample(point) {
    if (!point || typeof point !== 'object') {
        return false;
    }
    if (typeof point.low_sample === 'boolean') {
        return point.low_sample;
    }

    return Number.isFinite(point.n) && point.n < LOW_SAMPLE_N;
}

// Markers for model changes and actions on time charts (docs/spec.md 7.5, T45).
//
// A marker is { key, date: 'YYYY-MM-DD', kind: 'model' | 'action', label, detail?, href? }.
// The categories of a time chart are the first day of each bucket (day, week or month), as
// the metrics API returns them. A marker belongs to the last bucket that starts on or before
// its date, so it lands on the right point in daily, weekly and monthly views. Markers before
// the first bucket or after `until` (the last day of the period) are left out.

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

export function bucketMarkers(markers, categories, until = null) {
    const buckets = new Map();
    if (!Array.isArray(markers) || categories.length === 0) {
        return buckets;
    }
    const sorted = [...categories].filter((category) => ISO_DATE.test(category)).sort();
    const end = until && ISO_DATE.test(until) ? until : null;

    for (const marker of markers) {
        const date = String(marker?.date ?? '').slice(0, 10);
        if (!ISO_DATE.test(date) || date < sorted[0] || (end && date > end)) {
            continue;
        }
        let bucket = null;
        for (const category of sorted) {
            if (category <= date) {
                bucket = category;
            } else {
                break;
            }
        }
        if (bucket === null) {
            continue;
        }
        if (!buckets.has(bucket)) {
            buckets.set(bucket, []);
        }
        buckets.get(bucket).push(marker);
    }

    return buckets;
}

/**
 * Splits values into a solid series and a dashed one for low-n stretches. A segment is low when
 * one of its two points has low n; such a point is left out of the solid series, so the solid line
 * never bridges a low segment. The dashed series is drawn below the solid one.
 */
export function splitLowSample(points) {
    const lows = points.map((point) => isLowSample(point));
    const value = (point) => (point && typeof point === 'object' ? (point.value ?? null) : (point ?? null));
    const solid = points.map((point, index) => (lows[index] ? null : value(point)));
    const dashed = points.map((point, index) =>
        lows[index] || lows[index - 1] || lows[index + 1] ? value(point) : null,
    );

    return { solid, dashed, lows, hasLow: lows.some(Boolean) };
}

/**
 * The payload of the `select` event, the same shape in every chart type (docs/ui-forbedring.md 5):
 * `{ key }` is the key of what was clicked (row, bar, tile or panel), as the page passed it in.
 * A chart with a second dimension adds it: `column` (heatmap) or `category` (small multiples,
 * the bucket date of the clicked point). Context fields without a value are left out.
 * Aliases kept for pages written against the first version are passed in `context` as well.
 *
 * @param {string} key
 * @param {Record<string, unknown>} [context]
 * @returns {{ key: string } & Record<string, unknown>}
 */
export function selection(key, context = {}) {
    const payload = { key };
    for (const [name, value] of Object.entries(context)) {
        if (value !== undefined && value !== null) {
            payload[name] = value;
        }
    }

    return payload;
}
