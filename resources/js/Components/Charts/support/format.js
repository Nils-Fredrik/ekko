import { trans } from 'laravel-vue-i18n';

export { isLowSample, LOW_SAMPLE_N } from './data.js';

// Formatting and the shared tooltip (value, change and n) for every chart.
// Kinds: 'rate' is a share from 0 to 1, 'count' a whole number, 'number' a decimal
// (sentiment, average position).

function locale() {
    return document.documentElement.lang || 'nb';
}

export function isMissing(value) {
    return value === null || value === undefined || Number.isNaN(value);
}

export function formatValue(value, kind = 'rate', digits) {
    if (isMissing(value)) {
        return trans('app.charts.empty');
    }
    if (kind === 'rate') {
        return new Intl.NumberFormat(locale(), { style: 'percent', maximumFractionDigits: digits ?? 1 }).format(value);
    }
    if (kind === 'count') {
        return new Intl.NumberFormat(locale(), { maximumFractionDigits: 0 }).format(value);
    }

    return new Intl.NumberFormat(locale(), { maximumFractionDigits: digits ?? 1 }).format(value);
}

/** Change against the previous value. A rate changes in percentage points. */
export function formatChange(change, kind = 'rate') {
    if (isMissing(change)) {
        return trans('app.charts.empty');
    }
    const amount = kind === 'rate' ? change * 100 : change;
    const number = new Intl.NumberFormat(locale(), {
        maximumFractionDigits: kind === 'count' ? 0 : 1,
        signDisplay: 'exceptZero',
    }).format(amount);

    return kind === 'rate' ? trans('app.charts.change_points', { change: number }) : number;
}

export function formatN(n) {
    return trans('app.charts.n', { n: new Intl.NumberFormat(locale()).format(n ?? 0) });
}

export function formatDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return value ?? '';
    }
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(locale(), { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
        new Date(Date.UTC(year, month - 1, day)),
    );
}

export function escapeHtml(value) {
    return String(value ?? '').replace(
        /[&<>"']/g,
        (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character],
    );
}

/**
 * The tooltip every chart shows: a title, one row per series with value, change and n,
 * and notes (markers). A low-n row says so in words, not only with a muted color.
 *
 * @param {{ title?: string, rows: Array<{ label: string, color?: string, value: number|null, change?: number|null, n?: number, lowSample?: boolean, kind?: string, text?: string }>, notes?: Array<{ color?: string, text: string }> }} content
 */
export function tooltipHtml({ title, rows = [], notes = [] }) {
    const swatch = (color) =>
        color
            ? `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;margin-right:6px;background:${escapeHtml(color)}"></span>`
            : '';
    const lines = rows.map((row) => {
        const parts = [row.text ?? formatValue(row.value, row.kind)];
        if (!isMissing(row.change)) {
            parts.push(formatChange(row.change, row.kind));
        }
        if (Number.isFinite(row.n)) {
            parts.push(formatN(row.n));
        }
        const low = row.lowSample
            ? ` <span style="opacity:.8;font-style:italic">${escapeHtml(trans('app.charts.low_sample'))}</span>`
            : '';

        return `<div style="display:flex;gap:12px;justify-content:space-between;align-items:center"><span>${swatch(row.color)}${escapeHtml(row.label)}</span><span style="font-variant-numeric:tabular-nums">${escapeHtml(parts.join(' · '))}${low}</span></div>`;
    });
    const extra = notes.map(
        (note) => `<div style="margin-top:4px">${swatch(note.color)}${escapeHtml(note.text)}</div>`,
    );
    const heading = title ? `<div style="font-weight:600;margin-bottom:4px">${escapeHtml(title)}</div>` : '';

    return `<div style="min-width:160px">${heading}${lines.join('')}${extra.join('')}</div>`;
}

/** Change from the previous point when the backend does not send one. */
export function changeAt(points, index) {
    const point = points[index];
    if (!isMissing(point?.change)) {
        return point.change;
    }
    const previous = points[index - 1];
    if (isMissing(point?.value) || isMissing(previous?.value)) {
        return null;
    }

    return point.value - previous.value;
}

/** Axis labels: whole percent for rates, the plain number otherwise. */
export function axisFormatter(kind = 'rate') {
    return (value) => formatValue(value, kind, kind === 'number' ? 1 : 0);
}
