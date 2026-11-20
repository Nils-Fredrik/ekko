import { router } from '@inertiajs/vue3';

// Include deferred props explicitly so a partial visit never keeps graphs from the old filter.
export const analysisProps = [
    'analysisFilters',
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
    'insight',
    'prompts',
    'competitors',
    'suggestions',
    'bots',
    'traffic',
    'citing',
    'truncated',
    'shown',
];

export function visitAnalysis(url, query, options = {}) {
    router.get(url, query, { only: analysisProps, preserveState: true, preserveScroll: true, ...options });
}

export function crossFilter(url, query, key, value) {
    const next = { ...query };
    if (value === null || value === '') delete next[key];
    else next[key] = value;
    visitAnalysis(url, next);
}
