import { splitLowSample } from './data.js';
import { changeAt, formatDate, formatN, formatValue, isLowSample } from './format.js';

// Line series with the low-n style: a solid line where n is enough, a dashed and muted line
// where it is not, and a hollow dot on each low-n point (docs/ui-forbedring.md 6).

export const LOW_OPACITY = 0.5;

/**
 * @param {{ id: string, name: string, color: string, points: Array, width?: number, opacity?: number, z?: number, dashed?: boolean, extra?: object }} options
 */
export function lineSeries({ id, name, color, points, width = 2, opacity = 1, z = 2, dashed = false, extra = {} }) {
    const { solid, dashed: low, lows, hasLow } = splitLowSample(points);
    const base = {
        type: 'line',
        name,
        showSymbol: false,
        symbol: 'circle',
        symbolSize: 6,
        connectNulls: false,
        emphasis: { focus: 'series' },
        ...extra,
    };
    const lonely = solid.map((value, index) => value !== null && solid[index - 1] == null && solid[index + 1] == null);
    const series = [
        {
            ...base,
            id,
            z: z + 1,
            data: solid.map((value, index) =>
                lonely[index] ? { value, symbol: 'circle', symbolSize: 6, itemStyle: { color } } : value,
            ),
            showSymbol: base.showSymbol || lonely.some(Boolean),
            itemStyle: { color, opacity },
            lineStyle: { color, width, opacity, type: dashed ? 'dashed' : 'solid' },
        },
    ];
    if (hasLow) {
        series.push({
            ...base,
            id: `${id}-low`,
            z,
            data: low.map((value, index) =>
                lows[index] && value !== null
                    ? { value, symbol: 'emptyCircle', symbolSize: 6, itemStyle: { color, opacity: LOW_OPACITY } }
                    : value,
            ),
            showSymbol: true,
            symbol: 'none',
            itemStyle: { color, opacity: LOW_OPACITY },
            lineStyle: { color, width: Math.max(1, width - 0.5), opacity: opacity * LOW_OPACITY, type: 'dashed' },
            tooltip: { show: false },
        });
    }

    return series;
}

/** Tooltip rows for one time bucket. */
export function pointRows(series, index, kind, colorFor) {
    return series.map((item, seriesIndex) => {
        const point = item.points[index];

        return {
            label: item.label,
            color: colorFor(item, seriesIndex),
            value: point?.value ?? null,
            change: changeAt(item.points, index),
            n: point?.n,
            lowSample: isLowSample(point),
            kind,
        };
    });
}

/** Table view for time series: one row per bucket, one column per series. */
export function timeTable(categories, series, kind, dateLabel) {
    return {
        columns: [
            { key: 'period', label: dateLabel },
            ...series.map((item) => ({ key: `s-${item.key}`, label: item.label, numeric: true })),
        ],
        rows: categories.map((category, index) => {
            const row = { key: category, period: formatDate(category) };
            series.forEach((item) => {
                const point = item.points[index];
                row[`s-${item.key}`] = isLowSample(point)
                    ? `${formatValue(point?.value, kind)} (${formatN(point?.n)})`
                    : formatValue(point?.value, kind);
            });

            return row;
        }),
    };
}

/** First and last value with data, for text descriptions. */
export function firstAndLast(points) {
    const withValue = points.map((point, index) => ({ point, index })).filter(({ point }) => point?.value != null);

    return withValue.length === 0 ? null : { first: withValue[0], last: withValue[withValue.length - 1] };
}
