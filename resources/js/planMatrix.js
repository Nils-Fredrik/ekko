/** Cell text for the measurement-plan matrix (docs/ux-arbeidsflate.md 5.4). */
export const INTERVALS = ['daily', 'weekly', 'every_14_days', 'every_21_days', 'monthly', 'off'];

/** Answers per month for the brand standard (no market) or one market. */
export function answersFor(estimate, marketId, engineKey) {
    if (!estimate) {
        return null;
    }
    const source =
        marketId == null ? estimate.engines : estimate.markets?.find((market) => market.id === marketId)?.engines;

    return source?.find((engine) => engine.engine_key === engineKey)?.answers ?? null;
}

/** Earliest next measurement for one engine in a market. */
export function nextDue(estimate, marketId, engineKey) {
    const market = estimate?.markets?.find((item) => item.id === marketId);
    const dates = (market?.rules ?? [])
        .filter((rule) => rule.engine_key === engineKey && rule.next_due_on)
        .map((rule) => rule.next_due_on)
        .sort();

    return dates[0] ?? null;
}

/** The prompt the estimate used as the example for that engine. */
export function samplePromptId(estimate, marketId, engineKey) {
    const market = estimate?.markets?.find((item) => item.id === marketId);
    const rule = (market?.rules ?? []).find((item) => item.engine_key === engineKey && item.sample_prompt_id);

    return rule?.sample_prompt_id ?? null;
}

/** Own choice that no longer matches the brand standard. */
export function rowChanged(standard, row) {
    if (!standard || !row) {
        return false;
    }

    return (
        standard.frequency !== row.frequency ||
        standard.prompt_scope !== row.prompt_scope ||
        standard.repetitions !== row.repetitions ||
        (standard.core_frequency || '') !== (row.core_frequency || '')
    );
}
