// The sidebar of the workspace shell (docs/ux-arbeidsflate.md 4.1). Plain functions, so tests/js can
// check the links, the active page, the counts and the market scope without a browser.

// Pages that read ?market=<id>. Links keep the market in scope only for these.
export const MARKET_ROUTES = [
    'brands.show',
    'brands.prompts.index',
    'brands.competitors.index',
    'brands.sources',
    'brands.setup.prompts',
    'brands.setup.competitors',
];

// Analysis pages share the filter bar (AnalysisFilters), so their links keep its period and filters.
export const ANALYSIS_ROUTES = [
    'brands.show',
    'brands.prompts.index',
    'brands.prompts.show',
    'brands.competitors.index',
    'brands.sources',
    'brands.crawlers',
];

export const NAV_GROUPS = [
    {
        id: 'analysis',
        items: [
            { id: 'overview', route: 'brands.show', match: ['brands.show'] },
            { id: 'prompts', route: 'brands.prompts.index', match: ['brands.prompts.index', 'brands.prompts.show'] },
            { id: 'competitors', route: 'brands.competitors.index', match: ['brands.competitors.index'] },
            { id: 'sources', route: 'brands.sources', match: ['brands.sources'] },
            { id: 'crawlers', route: 'brands.crawlers', match: ['brands.crawlers'] },
        ],
    },
    {
        id: 'work',
        items: [
            {
                id: 'actions',
                route: 'brands.actions.index',
                match: ['brands.actions.index', 'brands.actions.show'],
                count: 'actions_new',
            },
            { id: 'facts', route: 'brands.facts.index', match: ['brands.facts.index'] },
        ],
    },
    {
        id: 'setup',
        items: [
            { id: 'setup_prompts', route: 'brands.setup.prompts', match: ['brands.setup.prompts'] },
            {
                id: 'setup_competitors',
                route: 'brands.setup.competitors',
                match: ['brands.setup.competitors'],
                count: 'entities_pending',
            },
            {
                id: 'setup_markets',
                route: 'brands.setup.markets',
                match: ['brands.setup.markets', 'brands.markets.create'],
            },
            // Today's settings page holds the identity until U24 builds the setup pages.
            {
                id: 'setup_identity',
                route: 'brands.setup.identity',
                match: ['brands.setup.identity', 'brands.settings'],
            },
            {
                id: 'setup_integrations',
                route: 'brands.setup.integrations',
                match: ['brands.setup.integrations'],
                count: 'integrations_failing',
            },
            { id: 'setup_notifications', route: 'brands.setup.notifications', match: ['brands.setup.notifications'] },
        ],
    },
];

// Where switching brand leads: the same page for the new brand, a list instead of one of its rows.
const BRAND_SWITCH = {
    'brands.prompts.show': 'brands.prompts.index',
    'brands.actions.show': 'brands.actions.index',
};
const BRAND_PAGES = [
    ...NAV_GROUPS.flatMap((group) => group.items.map((item) => item.route)),
    'brands.settings',
    'brands.markets.create',
];

/** The market in ?market=<id> when it belongs to the brand, else null (all markets). */
export function marketFromUrl(url, markets = []) {
    const value = new URL(url, 'http://ekko.invalid').searchParams.get('market');
    if (value === null || !/^\d+$/.test(value)) {
        return null;
    }
    const id = Number(value);

    return markets.some((market) => market.id === id) ? id : null;
}

/**
 * The query for a link in the sidebar.
 *
 * @param {{ route: string }} item
 * @param {{ marketId: number|null, analysisQuery?: object|null }} scope
 */
export function linkQuery(item, { marketId, analysisQuery = null }) {
    const query = ANALYSIS_ROUTES.includes(item.route) && analysisQuery ? { ...analysisQuery } : {};
    if (item.route !== 'brands.show') {
        delete query.grain;
    }
    delete query.market;
    if (marketId !== null && marketId !== undefined && MARKET_ROUTES.includes(item.route)) {
        query.market = marketId;
    }

    return query;
}

/** Whether the item is the page shown. `current(name)` is Ziggy's route().current. */
export function isActive(item, current) {
    return item.match.some((name) => current(name));
}

/** The count shown on a link, or null when there is nothing to show. */
export function countFor(item, counts) {
    if (!item.count || !counts) {
        return null;
    }
    const value = Number(counts[item.count] ?? 0);

    return value > 0 ? value : null;
}

/**
 * The page to open for another brand: the same page when it is a brand page, else the overview.
 *
 * @param {string|null|undefined} routeName the current route
 * @param {number|null} marketId the market remembered for the new brand
 */
export function brandSwitchTarget(routeName, marketId) {
    let name = routeName && BRAND_SWITCH[routeName] ? BRAND_SWITCH[routeName] : routeName;
    if (!name || !BRAND_PAGES.includes(name)) {
        name = 'brands.show';
    }
    const query =
        marketId !== null && marketId !== undefined && MARKET_ROUTES.includes(name) ? { market: marketId } : {};

    return { name, query };
}

/** localStorage key for the last market of a brand (docs/ux-arbeidsflate.md 4.1). */
export function scopeKey(tenantId, brandId) {
    return `ekko.scope.${tenantId}.${brandId}`;
}

/** The remembered market for a brand: an id, or null for all markets and when nothing is stored. */
export function readScope(storage, tenantId, brandId) {
    try {
        const value = storage?.getItem(scopeKey(tenantId, brandId));

        return value && /^\d+$/.test(value) ? Number(value) : null;
    } catch {
        return null;
    }
}

export function writeScope(storage, tenantId, brandId, marketId) {
    try {
        storage?.setItem(scopeKey(tenantId, brandId), marketId === null ? 'all' : String(marketId));
    } catch {
        // Private windows and blocked storage: the URL still carries the market.
    }
}
