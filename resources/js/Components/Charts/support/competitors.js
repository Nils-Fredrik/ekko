// At most six competitors keep their own colour. The rest become one "others" series
// (docs/ux-arbeidsflate.md 7). Share of voice sums the rest; a rate such as visibility uses the mean
// so the line stays on the same scale.

function score(item) {
    let weighted = 0;
    let weight = 0;
    let last = null;
    for (const point of item.points ?? []) {
        if (point?.value === null || point?.value === undefined) {
            continue;
        }
        last = point.value;
        const sample = Number(point.n) || 0;
        if (sample > 0) {
            weighted += point.value * sample;
            weight += sample;
        }
    }

    return weight > 0 ? weighted / weight : (last ?? -1);
}

function byScore(left, right) {
    return score(right) - score(left) || String(left.label).localeCompare(String(right.label));
}

/**
 * The six competitors with the largest share, in that order.
 * Both charts use this order so one competitor keeps one colour.
 */
export function rankedCompetitorKeys(series, { limit = 6 } = {}) {
    return series
        .filter((item) => !item.own)
        .sort(byScore)
        .slice(0, limit)
        .map((item) => item.key);
}

function combine(items, index, reduce) {
    let total = 0;
    let samples = 0;
    let seen = 0;
    for (const item of items) {
        const point = item.points?.[index];
        if (point?.value === null || point?.value === undefined) {
            continue;
        }
        total += point.value;
        samples += Number(point.n) || 0;
        seen += 1;
    }
    if (seen === 0) {
        return { value: null, n: 0, low_sample: false };
    }

    return {
        value: reduce === 'mean' ? total / seen : total,
        n: samples,
        low_sample: false,
    };
}

export function limitCompetitors(series, { limit = 6, otherLabel, reduce = 'sum', order } = {}) {
    const own = [];
    const competitors = [];
    for (const item of series) {
        (item.own ? own : competitors).push(item);
    }
    const shared = Array.isArray(order)
        ? order
              .map((key) => competitors.find((item) => String(item.key) === String(key)))
              .filter((item) => item !== undefined)
        : [];
    const usingSharedOrder = shared.length > 0;
    const keptSource = usingSharedOrder ? shared : [...competitors].sort(byScore).slice(0, limit);
    const kept = keptSource.map((item, index) => ({
        ...item,
        colorIndex: usingSharedOrder ? order.findIndex((key) => String(key) === String(item.key)) : index,
    }));
    const keptKeys = new Set(kept.map((item) => String(item.key)));
    const rest = competitors.filter((item) => !keptKeys.has(String(item.key)));
    if (rest.length === 0) {
        return [...own, ...kept];
    }
    const length = Math.max(...rest.map((item) => item.points?.length ?? 0));

    return [
        ...own,
        ...kept,
        {
            key: 'others',
            label: otherLabel(rest.length),
            neutral: true,
            dashed: true,
            points: Array.from({ length }, (_, index) => combine(rest, index, reduce)),
        },
    ];
}
