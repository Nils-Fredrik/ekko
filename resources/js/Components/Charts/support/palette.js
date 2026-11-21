// Chart colors come from the theme tokens in resources/css/app.css (docs/ui-forbedring.md 4.2).
// They are read from the computed style, so the same code draws both themes, and read again
// when data-theme changes.

const CATEGORIES = 6;
const STEPS = 7;

function token(style, name) {
    return style.getPropertyValue(name).trim();
}

export function readPalette(element = document.documentElement) {
    const style = getComputedStyle(element);
    const read = (name) => token(style, name);

    return {
        theme: element.dataset.theme === 'light' ? 'light' : 'dark',
        own: read('--data-own'),
        categories: Array.from({ length: CATEGORIES }, (_, index) => read(`--data-cat-${index + 1}`)),
        sequential: Array.from({ length: STEPS }, (_, index) => read(`--data-seq-${index}`)),
        positive: read('--data-positive'),
        negative: read('--data-negative'),
        neutral: read('--data-neutral'),
        markerModel: read('--data-marker-model'),
        markerAction: read('--data-marker-action'),
        shell: read('--color-shell'),
        panel: read('--color-shell-panel'),
        surface: read('--color-shell-surface'),
        text: read('--color-shell-text'),
        muted: read('--color-shell-muted'),
        line: read('--color-line'),
        lineSoft: read('--color-line-soft'),
        font: getComputedStyle(document.body ?? element).fontFamily,
    };
}

/**
 * Color for a series. The own brand always has --data-own. Competitors keep the color of their fixed
 * position (colorIndex), so a competitor has the same color on every page; the series index is only
 * the fallback.
 */
export function seriesColor(palette, series, index = 0) {
    if (series?.own) {
        return palette.own;
    }
    if (series?.neutral) {
        return palette.neutral;
    }
    const position = Number.isInteger(series?.colorIndex) ? series.colorIndex : index;

    return palette.categories[((position % CATEGORIES) + CATEGORIES) % CATEGORIES];
}

/** A palette key ('own', 'cat-1' … 'cat-6', 'positive', 'negative', 'neutral', 'seq-0' … 'seq-6') as a color. */
export function paletteColor(palette, key) {
    const category = /^cat-(\d)$/.exec(key ?? '');
    if (category) {
        return palette.categories[Number(category[1]) - 1] ?? palette.neutral;
    }
    const step = /^seq-(\d)$/.exec(key ?? '');
    if (step) {
        return palette.sequential[Number(step[1])] ?? palette.own;
    }

    return palette[key] ?? palette.neutral;
}

/** The ECharts theme built from the tokens. Registered on init and replaced with setTheme on a theme switch. */
export function buildTheme(palette) {
    const axis = {
        axisLine: { show: true, lineStyle: { color: palette.line } },
        axisTick: { show: false },
        axisLabel: { color: palette.muted, fontSize: 11 },
        splitLine: { show: true, lineStyle: { color: palette.lineSoft } },
        splitArea: { show: false },
    };

    return {
        color: [palette.own, ...palette.categories],
        backgroundColor: 'transparent',
        textStyle: { color: palette.text, fontFamily: palette.font },
        title: { textStyle: { color: palette.text, fontSize: 12, fontWeight: 500 } },
        legend: { textStyle: { color: palette.muted }, inactiveColor: palette.line },
        tooltip: {
            backgroundColor: palette.surface,
            borderColor: palette.line,
            borderWidth: 1,
            textStyle: { color: palette.text, fontSize: 12 },
            extraCssText: 'box-shadow: var(--shadow-pop); border-radius: 8px;',
            axisPointer: {
                lineStyle: { color: palette.line },
                crossStyle: { color: palette.line },
                shadowStyle: { color: palette.lineSoft },
            },
        },
        categoryAxis: { ...axis, splitLine: { show: false } },
        valueAxis: { ...axis, axisLine: { show: false } },
        timeAxis: axis,
        line: { symbol: 'circle', symbolSize: 5, smooth: false, lineStyle: { width: 2 } },
        bar: { itemStyle: { borderRadius: 2 } },
        markLine: { label: { color: palette.text } },
    };
}

function luminance(color) {
    const match = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})/i.exec(color ?? '');
    if (!match) {
        return null;
    }
    const [r, g, b] = match.slice(1).map((pair) => {
        const value = parseInt(pair, 16) / 255;

        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Text color on a filled mark: the shell text or the panel color, whichever contrasts more. */
export function readableOn(palette, background) {
    const base = luminance(background);
    if (base === null) {
        return palette.text;
    }
    const contrast = (color) => {
        const other = luminance(color) ?? 0;

        return (Math.max(base, other) + 0.05) / (Math.min(base, other) + 0.05);
    };

    return contrast(palette.text) >= contrast(palette.panel) ? palette.text : palette.panel;
}
