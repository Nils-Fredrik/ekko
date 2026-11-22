import { router } from '@inertiajs/vue3';

// Fetch graphs with the filters, but let model-backed insight arrive separately (ADR 0038).
export const analysisProps = [
    'analysisFilters',
    'workspace',
    'period',
    'filters',
    'summary',
    'previous',
    'series',
    'voice',
    'engines',
    'modelChanges',
    'topActions',
    'chartData',
    'prompts',
    'competitors',
    'suggestions',
    'bots',
    'traffic',
    'funnel',
    'citing',
    'truncated',
    'shown',
];

let latestVisit = 0;

export function visitAnalysis(url, query, options = {}) {
    const visit = ++latestVisit;
    // Also cancel the initial deferred request: an old insight must never overwrite a newer filter's text.
    router.cancelAll({ prefetch: false });
    router.replaceProp('insight', null, {
        onFinish: () => {
            if (visit !== latestVisit) return;
            router.get(url, query, {
                only: analysisProps,
                preserveState: true,
                preserveScroll: true,
                ...options,
                onSuccess: async (page) => {
                    await options.onSuccess?.(page);
                    if (visit === latestVisit && page.component === 'Brands/Show') {
                        router.reload({ only: ['insight'] });
                    }
                },
            });
        },
    });
}

export function crossFilter(url, query, key, value) {
    const next = { ...query };
    if (value === null || value === '') delete next[key];
    else next[key] = value;
    visitAnalysis(url, next);
}
