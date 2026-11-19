// Charts draw in over 300 ms the first time they appear. Later redraws (filters, theme)
// stay still, and reduced motion skips the entrance entirely (docs/ui-forbedring.md 4.4).

export function chartAnimation(firstView) {
    const reduce = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    return { duration: firstView && !reduce ? 300 : 0 };
}
