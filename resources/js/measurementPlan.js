/** Shared measurement-plan editing (docs/spec.md 7.6). Intervals follow Frequency::ORDER. */
export const FREQUENCY_RANK = { off: 0, monthly: 1, every_21_days: 2, every_14_days: 3, weekly: 4, daily: 5 };

export function allowsFrequency(frequency, cap) {
    return (FREQUENCY_RANK[frequency] ?? 99) <= (FREQUENCY_RANK[cap] ?? 0);
}

/**
 * One editor row per catalogue engine. A core rule beside an "all" rule is the optional core frequency.
 *
 * @param {Array<Record<string, unknown>>} rules
 * @param {Array<Record<string, unknown>>} catalog
 */
export function rulesToRows(rules, catalog, maxRepetitions = 3) {
    const byEngine = new Map();
    for (const rule of rules ?? []) {
        const list = byEngine.get(rule.engine_key) ?? [];
        list.push(rule);
        byEngine.set(rule.engine_key, list);
    }
    const keys = (catalog ?? []).map((engine) => engine.engine_key);
    for (const key of byEngine.keys()) {
        if (!keys.includes(key)) {
            keys.push(key);
        }
    }

    return keys.map((key) => {
        const engine = (catalog ?? []).find((item) => item.engine_key === key) ?? {
            engine_key: key,
            name: key,
            selectable: false,
            has_price: false,
            max_frequency: 'off',
        };
        const list = byEngine.get(key) ?? [];
        const all = list.find((rule) => rule.prompt_scope === 'all');
        const core = list.find((rule) => rule.prompt_scope === 'core');
        const selectable = engine.selectable === true && engine.max_frequency !== 'off';
        const allActive = selectable && all?.frequency && all.frequency !== 'off';
        const coreActive = selectable && core?.frequency && core.frequency !== 'off';
        const coreOnly = coreActive && !allActive;

        return {
            engine_key: key,
            name: engine.name ?? key,
            selectable,
            has_price: engine.has_price !== false,
            max_frequency: engine.max_frequency ?? 'off',
            frequency: coreOnly ? core.frequency : allActive ? all.frequency : 'off',
            prompt_scope: coreOnly ? 'core' : 'all',
            repetitions: clampRepetitions(coreOnly ? core.repetitions : all?.repetitions, maxRepetitions),
            core_frequency: allActive && core ? core.frequency : '',
            core_repetitions: allActive && core ? clampRepetitions(core.repetitions, maxRepetitions) : 1,
        };
    });
}

/** @param {Array<Record<string, unknown>>} rows */
export function rowsToRules(rows, maxRepetitions = 3) {
    const rules = [];
    for (const row of rows) {
        if (!row.selectable || row.frequency === 'off') {
            rules.push({ engine_key: row.engine_key, prompt_scope: 'all', frequency: 'off', repetitions: 1 });
            continue;
        }
        const repetitions = clampRepetitions(row.repetitions, maxRepetitions);
        if (row.prompt_scope === 'core') {
            rules.push({ engine_key: row.engine_key, prompt_scope: 'core', frequency: row.frequency, repetitions });
            continue;
        }
        rules.push({ engine_key: row.engine_key, prompt_scope: 'all', frequency: row.frequency, repetitions });
        if (row.core_frequency) {
            rules.push({
                engine_key: row.engine_key,
                prompt_scope: 'core',
                frequency: row.core_frequency,
                repetitions: clampRepetitions(row.core_repetitions, maxRepetitions),
            });
        }
    }

    return rules;
}

function clampRepetitions(value, maxRepetitions = 3) {
    const count = Number(value);
    const cap = Math.min(3, Math.max(1, Number(maxRepetitions) || 1));
    if (!Number.isInteger(count) || count < 1) {
        return 1;
    }

    return Math.min(count, cap);
}

/** The editor state that will be saved: unavailable engines are off, and repetitions stay inside the package. */
export function normalizePlan(rules, catalog, maxRepetitions = 3) {
    return rowsToRules(rulesToRows(rules, catalog, maxRepetitions), maxRepetitions);
}

/** An active rule whose engine has no current price cannot be saved. */
export function rulesLackPrice(rules, catalog) {
    return (rules ?? []).some((rule) => {
        if (rule.frequency === 'off') {
            return false;
        }
        const engine = (catalog ?? []).find((item) => item.engine_key === rule.engine_key);

        return engine?.has_price === false;
    });
}

/**
 * The preview may confirm only the choices it was calculated for.
 * A missing price on another stored market does not block a new market (pass its id, null in the wizard).
 *
 * @param {Record<string, unknown>|null} estimate
 * @param {number|null|undefined} marketId undefined means the whole brand plan
 */
export function estimateCanConfirm(estimate, marketId) {
    if (!estimate) {
        return false;
    }
    const blocked = (estimate.issues ?? []).some((issue) => {
        if (issue.code !== 'no_price') {
            return false;
        }

        return marketId === undefined || issue.market_id === marketId;
    });
    if (blocked) {
        return false;
    }
    if (marketId === undefined) {
        return estimate.total_cost_usd != null || estimate.answers === 0;
    }
    const market = (estimate.markets ?? []).find((item) => item.id === marketId);
    if (!market) {
        return false;
    }

    return market.total_cost_usd != null || market.answers === 0;
}
