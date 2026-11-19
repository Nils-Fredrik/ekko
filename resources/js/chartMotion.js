// Charts draw in over 300 ms the first time they appear on a page. A filter change
// remounts the page, so the intro is remembered per path. Theme redraws and later
// visits of the same path stay still. Reduced motion skips the entrance
// (docs/ui-forbedring.md 4.4).

let seenPath = '';
const seen = new Set();

function rememberPath() {
    const path = typeof window === 'undefined' ? '' : window.location.pathname;

    if (path !== seenPath) {
        seenPath = path;
        seen.clear();
    }
}

export function chartIntroPending(key) {
    rememberPath();

    return !seen.has(key);
}

export function markChartIntro(key) {
    rememberPath();
    seen.add(key);
}

export function chartAnimation(firstView) {
    const reduce = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    return { duration: firstView && !reduce ? 300 : 0 };
}
