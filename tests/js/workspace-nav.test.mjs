import assert from 'node:assert/strict';
import test from 'node:test';
import {
    NAV_GROUPS,
    brandSwitchTarget,
    countFor,
    isActive,
    linkQuery,
    marketFromUrl,
    readScope,
    scopeKey,
    writeScope,
} from '../../resources/js/Components/Workspace/workspaceNav.js';

const item = (id) => NAV_GROUPS.flatMap((group) => group.items).find((entry) => entry.id === id);
const markets = [
    { id: 4, label: 'Norge', is_active: true },
    { id: 9, label: 'Sverige', is_active: true },
];

function memoryStorage() {
    const values = new Map();

    return {
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, String(value)),
    };
}

test('the sidebar has the groups and links of the specification, in order', () => {
    assert.deepEqual(
        NAV_GROUPS.map((group) => [group.id, group.items.map((entry) => entry.route)]),
        [
            [
                'analysis',
                [
                    'brands.show',
                    'brands.prompts.index',
                    'brands.competitors.index',
                    'brands.sources',
                    'brands.crawlers',
                ],
            ],
            ['work', ['brands.actions.index', 'brands.facts.index']],
            [
                'setup',
                [
                    'brands.setup.prompts',
                    'brands.setup.competitors',
                    'brands.setup.markets',
                    'brands.setup.identity',
                    'brands.setup.integrations',
                    'brands.setup.notifications',
                ],
            ],
        ],
    );
});

test('the market comes from the address and must belong to the brand', () => {
    assert.equal(marketFromUrl('/brands/1/sources?market=9&days=28', markets), 9);
    assert.equal(marketFromUrl('/brands/1/sources?days=28', markets), null);
    assert.equal(marketFromUrl('/brands/1/sources?market=77', markets), null);
    assert.equal(marketFromUrl('/brands/1/sources?market=9x', markets), null);
});

test('links keep the market only where the page reads it', () => {
    assert.deepEqual(linkQuery(item('sources'), { marketId: 9 }), { market: 9 });
    assert.deepEqual(linkQuery(item('setup_prompts'), { marketId: 9 }), { market: 9 });
    assert.deepEqual(linkQuery(item('crawlers'), { marketId: 9 }), {});
    assert.deepEqual(linkQuery(item('actions'), { marketId: 9 }), {});
    assert.deepEqual(linkQuery(item('setup_identity'), { marketId: 9 }), {});
    assert.deepEqual(linkQuery(item('overview'), { marketId: null }), {});
});

test('analysis links keep the filter bar, and only the overview keeps the grain', () => {
    const analysisQuery = { days: 28, comparison: 'previous', grain: 'week', engine: 'chatgpt', market: 4 };

    assert.deepEqual(linkQuery(item('overview'), { marketId: 9, analysisQuery }), {
        days: 28,
        comparison: 'previous',
        grain: 'week',
        engine: 'chatgpt',
        market: 9,
    });
    assert.deepEqual(linkQuery(item('crawlers'), { marketId: 9, analysisQuery }), {
        days: 28,
        comparison: 'previous',
        engine: 'chatgpt',
    });
    assert.deepEqual(linkQuery(item('facts'), { marketId: 9, analysisQuery }), {});
});

test('detail pages mark their list, and the settings page marks identity until U24', () => {
    const on = (name) => (pattern) => pattern === name;

    assert.equal(isActive(item('prompts'), on('brands.prompts.show')), true);
    assert.equal(isActive(item('actions'), on('brands.actions.show')), true);
    assert.equal(isActive(item('setup_identity'), on('brands.settings')), true);
    assert.equal(isActive(item('setup_markets'), on('brands.markets.create')), true);
    assert.equal(isActive(item('overview'), on('brands.prompts.show')), false);
});

test('counts show on their links only when above zero', () => {
    const counts = { inbox: 9, actions_new: 3, entities_pending: 0, integrations_failing: 1 };

    assert.equal(countFor(item('actions'), counts), 3);
    assert.equal(countFor(item('setup_competitors'), counts), null);
    assert.equal(countFor(item('setup_integrations'), counts), 1);
    assert.equal(countFor(item('sources'), counts), null);
    assert.equal(countFor(item('actions'), null), null);
});

test('switching brand keeps the page and uses the market remembered for the new brand', () => {
    assert.deepEqual(brandSwitchTarget('brands.sources', 9), { name: 'brands.sources', query: { market: 9 } });
    assert.deepEqual(brandSwitchTarget('brands.crawlers', 9), { name: 'brands.crawlers', query: {} });
    assert.deepEqual(brandSwitchTarget('brands.prompts.show', null), { name: 'brands.prompts.index', query: {} });
    assert.deepEqual(brandSwitchTarget('brands.actions.show', 4), { name: 'brands.actions.index', query: {} });
    assert.deepEqual(brandSwitchTarget('dashboard', 4), { name: 'brands.show', query: { market: 4 } });
    assert.deepEqual(brandSwitchTarget(undefined, null), { name: 'brands.show', query: {} });
});

test('the last market is stored per tenant and brand, and broken storage is ignored', () => {
    const storage = memoryStorage();

    assert.equal(scopeKey(2, 15), 'ekko.scope.2.15');
    assert.equal(readScope(storage, 2, 15), null);
    writeScope(storage, 2, 15, 9);
    assert.equal(readScope(storage, 2, 15), 9);
    assert.equal(readScope(storage, 3, 15), null);
    writeScope(storage, 2, 15, null);
    assert.equal(storage.getItem('ekko.scope.2.15'), 'all');
    assert.equal(readScope(storage, 2, 15), null);

    const broken = {
        getItem() {
            throw new Error('blocked');
        },
        setItem() {
            throw new Error('blocked');
        },
    };
    assert.equal(readScope(broken, 2, 15), null);
    assert.doesNotThrow(() => writeScope(broken, 2, 15, 9));
});
