// The links in the sidebar (docs/ux-arbeidsflate.md 4.1, U17). Plain functions, so tests/js can check
// which link is active, which badge it shows and which query it carries.

// Analysis pages that take the analysis filters (AnalysisFilters::PAGES) and which of them take `market`.
const MARKET_PAGES = new Set([
    'brands.show',
    'brands.prompts.index',
    'brands.competitors.index',
    'brands.sources',
    'brands.setup.prompts',
]);

export const GROUPS = [
    {
        id: 'analysis',
        label: 'workspace.shell.analysis',
        items: [
            { id: 'overview', route: 'brands.show', match: ['brands.show'], analysis: true },
            {
                id: 'prompts',
                route: 'brands.prompts.index',
                match: ['brands.prompts.index', 'brands.prompts.show'],
                analysis: true,
            },
            {
                id: 'competitors',
                route: 'brands.competitors.index',
                // The answers behind the sentiment (U28) belong to the competitors page.
                match: ['brands.competitors.index', 'brands.responses.index'],
                analysis: true,
            },
            { id: 'sources', route: 'brands.sources', match: ['brands.sources'], analysis: true },
            { id: 'crawlers', route: 'brands.crawlers', match: ['brands.crawlers'], analysis: true },
        ],
    },
    {
        id: 'work',
        label: 'workspace.shell.work',
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
        label: 'workspace.shell.setup',
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
                match: ['brands.setup.markets', 'brands.plan.show', 'brands.markets.create'],
            },
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

/** The market in the URL (`?market=<id>`), when it is one of the brand's markets. */
export function marketFromUrl(url, markets = []) {
    const value = new URL(url, 'http://ekko.invalid').searchParams.get('market');
    if (value === null || !/^\d+$/.test(value)) {
        return null;
    }
    const id = Number(value);

    return markets.length === 0 || markets.some((market) => market.id === id) ? id : null;
}

/**
 * The query a link carries. Analysis links keep the page's analysis filters, as before (period, comparison,
 * engine …); every link that takes `market` gets the market in scope.
 */
export function linkQuery(item, { analysisQuery = null, marketId = null } = {}) {
    const query = item.analysis && analysisQuery ? { ...analysisQuery } : {};
    if (item.route !== 'brands.show') {
        delete query.grain;
    }
    delete query.market;
    if (marketId !== null && marketId !== undefined && MARKET_PAGES.has(item.route)) {
        query.market = marketId;
    }

    return query;
}

/**
 * @param {{ brandId: number|null, marketId?: number|null, analysisQuery?: object|null, counts?: object|null, current: (name: string) => boolean, to: (name: string, params?: object) => string, has?: (name: string) => boolean }} context
 * @returns {Array<{ id: string, label: string, items: Array<{ id: string, label: string, href: string, active: boolean, badge: number }> }>}
 */
export function sidebarGroups({ brandId, marketId = null, analysisQuery = null, counts = null, current, to, has }) {
    if (brandId === null || brandId === undefined) {
        return [];
    }
    const exists = has ?? (() => true);

    return GROUPS.map((group) => ({
        id: group.id,
        label: group.label,
        items: group.items
            .filter((item) => exists(item.route))
            .map((item) => ({
                id: item.id,
                label: `workspace.shell.${item.id}`,
                href: to(item.route, { brand: brandId, ...linkQuery(item, { analysisQuery, marketId }) }),
                active: item.match.some((name) => current(name)),
                badge: item.count ? Number(counts?.[item.count] ?? 0) : 0,
            })),
    }));
}

/**
 * Portefølje and Innboks. A partner advisor's portfolio is the client overview (partner.index).
 *
 * @param {{ canViewPartner?: boolean, counts?: object|null, current: (name: string) => boolean, to: (name: string, params?: object) => string }} context
 */
export function globalLinks({ canViewPartner = false, counts = null, current, to }) {
    const links = [
        {
            id: 'portfolio',
            label: 'workspace.shell.portfolio',
            href: to(canViewPartner ? 'partner.index' : 'dashboard'),
            active: current('dashboard') || current('partner.index') || current('brands.index'),
            badge: 0,
        },
    ];
    if (canViewPartner) {
        links.push({
            id: 'pitches',
            label: 'workspace.shell.pitches',
            href: to('pitches.index'),
            active: current('pitches.*'),
            badge: 0,
        });
    }
    links.push({
        id: 'inbox',
        label: 'workspace.shell.inbox',
        href: to('inbox.index'),
        active: current('inbox.index') || current('alerts.*'),
        badge: Number(counts?.inbox ?? 0),
    });

    return links;
}

/**
 * Where «switch brand» goes: the same page for the other brand when its route takes only {brand}, otherwise the
 * overview. `parameters` are the route's own parameter names (Ziggy's `route().routeParams`), never the query.
 */
export function brandTarget(name, parameters = []) {
    const own = Array.isArray(parameters) ? parameters : [];

    return typeof name === 'string' && name.startsWith('brands.') && own.length === 1 && own[0] === 'brand'
        ? name
        : 'brands.show';
}

/** localStorage key for the last market chosen for a brand (4.1). */
export function scopeKey(tenantId, brandId) {
    return `ekko.scope.${tenantId}.${brandId}`;
}
