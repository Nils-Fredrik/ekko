/** Cell text for the measurement-plan matrix (docs/ux-arbeidsflate.md 5.4). */
export const INTERVALS = ['daily', 'weekly', 'every_14_days', 'every_21_days', 'monthly', 'off'];

/**
 * Answers per month for one market.
 * The brand standard is a template and has no prompt population of its own.
 * estimate.engines is the sum of every market, so the standard column does not use it.
 */
export function answersFor(estimate, marketId, engineKey) {
    if (!estimate || marketId == null) {
        return null;
    }

    return (
        estimate.markets
            ?.find((market) => market.id === marketId)
            ?.engines?.find((engine) => engine.engine_key === engineKey)?.answers ?? null
    );
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

/**
 * The validation message for one engine, matched on the rule index in the saved payload.
 * prefix is standard.engines or markets.N.engines.
 */
export function cellError(errors, rules, prefix, engineKey) {
    const head = `${prefix}.`;
    for (const [key, message] of Object.entries(errors ?? {})) {
        if (!key.startsWith(head)) {
            continue;
        }
        const index = Number(key.slice(head.length).split('.')[0]);
        if (!Number.isInteger(index) || rules?.[index]?.engine_key !== engineKey) {
            continue;
        }
        const text = Array.isArray(message) ? message[0] : message;
        if (text) {
            return text;
        }
    }

    return '';
}
