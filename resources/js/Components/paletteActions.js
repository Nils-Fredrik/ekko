// The «Handlinger» group in CommandPalette (docs/ux-arbeidsflate.md 4.6). Plain functions, so
// tests/js can check which actions a role sees and where they lead.
//
// `workspace` is the shared prop from the shell (U17). Before it exists, only «Lag rapport nå» is
// shown, as before. Routes that are not built yet are left out (`has`).

const CREATE = [
    { id: 'prompt', label: 'workspace.palette.new_prompt', route: 'brands.setup.prompts', can: 'manage_prompts' },
    { id: 'topic', label: 'workspace.palette.new_topic', route: 'brands.setup.prompts', can: 'manage_prompts' },
    {
        id: 'competitor',
        label: 'workspace.palette.add_competitor',
        route: 'brands.setup.competitors',
        can: 'manage_competitors',
    },
    { id: 'market', label: 'workspace.palette.new_market', route: 'brands.setup.markets', can: 'manage_markets' },
    { id: 'fact', label: 'workspace.palette.new_fact', route: 'brands.facts.index', can: 'manage_brands' },
];

/** The same URL with `market` set, or removed for all markets. Other parameters stay. */
export function urlWithMarket(url, marketId) {
    const target = new URL(url, 'http://ekko.invalid');
    if (marketId === null || marketId === undefined) {
        target.searchParams.delete('market');
    } else {
        target.searchParams.set('market', String(marketId));
    }

    return `${target.pathname}${target.search}`;
}

/**
 * @param {{ workspace?: object|null, url: string, has: (name: string) => boolean, to: (name: string, params?: object) => string, t: (key: string, replace?: object) => string }} context
 * @returns {Array<{ id: string, label: string, href?: string, children?: Array<{ id: string, label: string, href: string, current?: boolean }> }>}
 */
export function paletteActions({ workspace, url, has, to, t }) {
    const items = [];
    if (!workspace) {
        return has('reports.index')
            ? [{ id: 'report', label: t('workspace.palette.create_report'), href: to('reports.index') }]
            : [];
    }
    const can = workspace.can ?? {};
    const brand = workspace.brand ?? null;

    if (brand) {
        for (const action of CREATE) {
            if (can[action.can] && has(action.route)) {
                items.push({
                    id: `create-${action.id}`,
                    label: t(action.label),
                    href: to(action.route, { brand: brand.id, create: action.id }),
                });
            }
        }
        const markets = workspace.markets ?? [];
        if (markets.length > 0) {
            items.push({
                id: 'switch-market',
                label: t('workspace.palette.switch_market'),
                children: [
                    {
                        id: 'market-all',
                        label: t('workspace.palette.all_markets'),
                        href: urlWithMarket(url, null),
                        current: workspace.market_id == null,
                    },
                    ...markets.map((market) => ({
                        id: `market-${market.id}`,
                        label: market.label,
                        href: urlWithMarket(url, market.id),
                        current: market.id === workspace.market_id,
                    })),
                ],
            });
        }
    }

    const brands = workspace.brands ?? [];
    if (brands.length > 1 && has('brands.show')) {
        items.push({
            id: 'switch-brand',
            label: t('workspace.palette.switch_brand'),
            children: brands.map((item) => ({
                id: `brand-${item.id}`,
                label: item.name,
                href: to('brands.show', { brand: item.id }),
                current: item.id === brand?.id,
            })),
        });
    }
    if (has('inbox.index')) {
        items.push({ id: 'inbox', label: t('workspace.palette.open_inbox'), href: to('inbox.index') });
    }
    if (can.manage_reports && has('reports.index')) {
        items.push({ id: 'report', label: t('workspace.palette.create_report'), href: to('reports.index') });
    }

    return items;
}
