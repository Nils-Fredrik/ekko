// U17: the sidebar's links, active page, badges and market scope (docs/ux-arbeidsflate.md 4.1).
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
import {
    brandTarget,
    globalLinks,
    inboxScope,
    linkQuery,
    marketFromUrl,
    scopeKey,
    sidebarGroups,
} from '../../resources/js/Components/Shell/sidebarNav.js';

const to = (name, params = {}) => {
    const query = new URLSearchParams(
        Object.entries(params)
            .filter(([key]) => key !== 'brand')
            .map(([key, value]) => [key, String(value)]),
    ).toString();

    return `${name}${params.brand ? `/${params.brand}` : ''}${query ? `?${query}` : ''}`;
};
const currentOf = (route) => (name) => (name.endsWith('.*') ? route.startsWith(name.slice(0, -1)) : name === route);

const flat = (groups) => groups.flatMap((group) => group.items);

test('the groups are Analyse, Arbeid and Oppsett with the links of the sketch', () => {
    const groups = sidebarGroups({ brandId: 7, current: currentOf('brands.show'), to });

    assert.deepEqual(
        groups.map((group) => [group.label, group.items.map((item) => item.label)]),
        [
            [
                'workspace.shell.analysis',
                [
                    'workspace.shell.overview',
                    'workspace.shell.prompts',
                    'workspace.shell.competitors',
                    'workspace.shell.sources',
                    'workspace.shell.crawlers',
                ],
            ],
            ['workspace.shell.work', ['workspace.shell.actions', 'workspace.shell.facts']],
            [
                'workspace.shell.setup',
                [
                    'workspace.shell.setup_prompts',
                    'workspace.shell.setup_competitors',
                    'workspace.shell.setup_markets',
                    'workspace.shell.setup_identity',
                    'workspace.shell.setup_integrations',
                    'workspace.shell.setup_notifications',
                ],
            ],
        ],
    );
    assert.deepEqual(
        flat(groups).map((item) => item.href),
        [
            'brands.show/7',
            'brands.prompts.index/7',
            'brands.competitors.index/7',
            'brands.sources/7',
            'brands.crawlers/7',
            'brands.actions.index/7',
            'brands.facts.index/7',
            'brands.setup.prompts/7',
            'brands.setup.competitors/7',
            'brands.setup.markets/7',
            'brands.setup.identity/7',
            'brands.setup.integrations/7',
            'brands.setup.notifications/7',
        ],
    );
});

test('a page without a brand has no brand links', () => {
    assert.deepEqual(sidebarGroups({ brandId: null, current: () => false, to }), []);
});

test('exactly one link is active, and detail pages mark their parent', () => {
    const active = (route) =>
        flat(sidebarGroups({ brandId: 7, current: currentOf(route), to }))
            .filter((item) => item.active)
            .map((item) => item.id);

    assert.deepEqual(active('brands.show'), ['overview']);
    assert.deepEqual(active('brands.prompts.show'), ['prompts']);
    assert.deepEqual(active('brands.actions.show'), ['actions']);
    assert.deepEqual(active('brands.settings'), ['setup_identity']);
    assert.deepEqual(active('brands.plan.show'), ['setup_markets']);
    assert.deepEqual(active('brands.setup.integrations'), ['setup_integrations']);
});

test('badges show the workspace counts on Tiltak, Konkurrenter og merkevarer and Integrasjoner', () => {
    const counts = { inbox: 9, actions_new: 3, entities_pending: 23, integrations_failing: 1 };
    const badges = Object.fromEntries(
        flat(sidebarGroups({ brandId: 7, counts, current: () => false, to }))
            .filter((item) => item.badge > 0)
            .map((item) => [item.id, item.badge]),
    );

    assert.deepEqual(badges, { actions: 3, setup_competitors: 23, setup_integrations: 1 });
    const inbox = globalLinks({ counts, current: () => false, to }).find((item) => item.id === 'inbox');
    assert.equal(inbox.badge, 9);
    assert.deepEqual(inboxScope(7, 5), { brand: 7, market: 5 });
    assert.deepEqual(inboxScope(null, null), {});
    assert.equal(
        globalLinks({ brandId: 7, marketId: 5, current: () => false, to }).find((item) => item.id === 'inbox').href,
        'inbox.index/7?market=5',
    );
    assert.equal(globalLinks({ counts: null, current: () => false, to }).find((i) => i.id === 'inbox').badge, 0);
});

test('links carry the market where the page takes it, and analysis links keep the filters', () => {
    const analysisQuery = { days: 7, comparison: 'previous', grain: 'week', market: 4, engine: 'chatgpt' };
    const hrefs = Object.fromEntries(
        flat(sidebarGroups({ brandId: 7, marketId: 5, analysisQuery, current: () => false, to })).map((item) => [
            item.id,
            item.href,
        ]),
    );

    assert.equal(hrefs.overview, 'brands.show/7?days=7&comparison=previous&grain=week&engine=chatgpt&market=5');
    assert.equal(hrefs.prompts, 'brands.prompts.index/7?days=7&comparison=previous&engine=chatgpt&market=5');
    assert.equal(hrefs.sources, 'brands.sources/7?days=7&comparison=previous&engine=chatgpt&market=5');
    assert.equal(hrefs.crawlers, 'brands.crawlers/7?days=7&comparison=previous&engine=chatgpt');
    assert.equal(hrefs.actions, 'brands.actions.index/7');
    assert.equal(hrefs.setup_prompts, 'brands.setup.prompts/7?market=5');
    assert.equal(hrefs.setup_competitors, 'brands.setup.competitors/7');
    assert.deepEqual(linkQuery({ route: 'brands.show', analysis: true }, { analysisQuery: null, marketId: null }), {});
});

test('the market comes from the URL, so a copied URL gives the same scope', () => {
    const markets = [{ id: 5 }, { id: 6 }];

    assert.equal(marketFromUrl('/brands/7?market=6&days=7', markets), 6);
    assert.equal(marketFromUrl('https://ekko.example/brands/7/sources?market=5', markets), 5);
    assert.equal(marketFromUrl('/brands/7?market=99', markets), null);
    assert.equal(marketFromUrl('/brands/7?market=abc', markets), null);
    assert.equal(marketFromUrl('/brands/7', markets), null);
    // Before the deferred markets arrive, the URL alone decides.
    assert.equal(marketFromUrl('/brands/7?market=99', []), 99);
});

test('Portefølje is the client overview for partner advisors, and Innboks covers the alerts', () => {
    const own = globalLinks({ current: currentOf('dashboard'), to });
    assert.deepEqual(
        own.map((item) => [item.id, item.href, item.active]),
        [
            ['portfolio', 'dashboard', true],
            ['inbox', 'inbox.index', false],
        ],
    );

    const partner = globalLinks({ canViewPartner: true, current: currentOf('alerts.index'), to });
    assert.deepEqual(
        partner.map((item) => [item.id, item.href, item.active]),
        [
            ['portfolio', 'partner.index', false],
            ['pitches', 'pitches.index', false],
            ['inbox', 'inbox.index', true],
        ],
    );
});

test('switching brand keeps the page when its route takes only {brand}, whatever the query', () => {
    // Ziggy's route().params also holds the query (?market=5); the route definition does not.
    assert.equal(brandTarget('brands.sources', ['brand']), 'brands.sources');
    assert.equal(brandTarget('brands.actions.index', ['brand']), 'brands.actions.index');
    assert.equal(brandTarget('brands.prompts.show', ['brand', 'prompt']), 'brands.show');
    assert.equal(brandTarget('pitches.report', ['brand']), 'brands.show');
    assert.equal(brandTarget('alerts.index', undefined), 'brands.show');
    assert.equal(brandTarget(undefined, []), 'brands.show');
});

test('the last market is stored per tenant and brand', () => {
    assert.equal(scopeKey(3, 7), 'ekko.scope.3.7');
});

test('no page has the top menu or BrandNav; the app layout renders the sidebar', () => {
    const root = new URL('../../resources/js/', import.meta.url).pathname;
    assert.equal(existsSync(join(root, 'Components/Brand/BrandNav.vue')), false);

    const files = (dir) =>
        readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
            entry.isDirectory()
                ? files(join(dir, entry.name))
                : entry.name.endsWith('.vue')
                  ? [join(dir, entry.name)]
                  : [],
        );
    for (const file of files(root)) {
        const source = readFileSync(file, 'utf8');
        assert.doesNotMatch(source, /BrandNav|NavLink/, file);
    }
    const layout = readFileSync(join(root, 'Layouts/AppLayout.vue'), 'utf8');
    assert.match(layout, /<Sidebar /);
    assert.doesNotMatch(layout, /<nav\b/);
});
