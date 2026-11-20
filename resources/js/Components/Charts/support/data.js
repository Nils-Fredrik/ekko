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
 * Every bucket start from `from` through `to`, including days without answers.
 * Week buckets start on Monday and month buckets on the first, matching Postgres
 * date_trunc, so a partial first bucket can start before `from`.
 *
 * @param {string} from
 * @param {string} to
 * @param {string} grain
 * @returns {string[]}
 */
export function periodBuckets(from, to, grain = 'day') {
    if (!ISO_DATE.test(from) || !ISO_DATE.test(to) || from > to) {
        return [];
    }
    const start = bucketStart(from, grain);
    const end = bucketStart(to, grain);
    const buckets = [];
    let cursor = start;
    while (cursor <= end && buckets.length < 400) {
        buckets.push(cursor);
        cursor = nextBucket(cursor, grain);
    }

    return buckets;
}

/**
 * Previous-period values on the current buckets, by offset rather than by calendar date.
 * A bucket with no answers stays null so a missing first day does not shift the rest.
 *
 * @param {string[]} currentBuckets
 * @param {string[]} previousBuckets
 * @param {unknown[]} previousValues values aligned with previousBuckets
 * @param {unknown} missing
 * @returns {unknown[]}
 */
export function alignByOffset(currentBuckets, previousBuckets, previousValues, missing = null) {
    const values = previousBuckets.map((_, index) => previousValues[index] ?? missing);

    return currentBuckets.map((_, index) => values[index] ?? missing);
}

/**
 * Daily year comparisons match calendar dates, clipping Feb 29 to Feb 28 like DashboardPeriod.
 * Months match the same month. Weekly values are already aggregated on different Monday boundaries
 * in each year, so keep whole weeks in window order rather than repeat or discard a partial week.
 */
export function alignComparison(
    currentBuckets,
    previousBuckets,
    previousValues,
    { comparison = 'previous', grain = 'day', missing = null } = {},
) {
    if (comparison !== 'year' || grain === 'week') {
        return alignByOffset(currentBuckets, previousBuckets, previousValues, missing);
    }
    const byDate = new Map(previousBuckets.map((date, index) => [date, previousValues[index] ?? missing]));

    return currentBuckets.map((date) => {
        const parsed = parseIso(date);
        const month = parsed.getUTCMonth();
        parsed.setUTCFullYear(parsed.getUTCFullYear() - 1);
        if (parsed.getUTCMonth() !== month) parsed.setUTCDate(0);

        return byDate.get(bucketStart(formatIso(parsed), grain)) ?? missing;
    });
}

function bucketStart(date, grain) {
    const parsed = parseIso(date);
    if (grain === 'week') {
        const day = parsed.getUTCDay();
        parsed.setUTCDate(parsed.getUTCDate() - (day === 0 ? 6 : day - 1));
    } else if (grain === 'month') {
        parsed.setUTCDate(1);
    }

    return formatIso(parsed);
}

function nextBucket(date, grain) {
    const parsed = parseIso(date);
    if (grain === 'week') {
        parsed.setUTCDate(parsed.getUTCDate() + 7);
    } else if (grain === 'month') {
        parsed.setUTCMonth(parsed.getUTCMonth() + 1);
    } else {
        parsed.setUTCDate(parsed.getUTCDate() + 1);
    }

    return formatIso(parsed);
}

function parseIso(value) {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

function formatIso(date) {
    return date.toISOString().slice(0, 10);
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
 * A chart with a second dimension adds it: `column` (heatmap) or `category` (trend lines and small multiples,
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

/** Convert an ECharts line click to the public series key and bucket date. */
export function trendSelection(params, categories, series) {
    if (
        params?.componentType !== 'series' ||
        params.seriesType !== 'line' ||
        !Number.isInteger(params.seriesIndex) ||
        !Number.isInteger(params.dataIndex)
    ) {
        return null;
    }
    // lineSeries adds a second ECharts series when a line contains low-sample points.
    // Previous-period and marker series follow these and must not select a current series.
    const rendered = series.flatMap((item) => (splitLowSample(item.points).hasLow ? [item, item] : [item]));
    const item = rendered[params.seriesIndex];
    const category = categories[params.dataIndex];
    if (item?.key == null || category == null || item.points[params.dataIndex]?.value == null) {
        return null;
    }

    return selection(item.key, { category });
}

/** Select a whole visible line for cross-filtering, without inventing a point date. */
export function trendSeriesSelection(params, series) {
    if (params?.componentType !== 'series' || params.seriesType !== 'line' || !Number.isInteger(params.seriesIndex))
        return null;
    const rendered = series.flatMap((item) => (splitLowSample(item.points).hasLow ? [item, item] : [item]));
    const item = rendered[params.seriesIndex];
    if (item?.key == null || !item.points.some((point) => point?.value != null)) return null;
    return selection(item.key);
}
