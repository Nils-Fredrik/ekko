// Autosave with rollback, the undo message, the palette actions and the open row in the URL
// (docs/ux-arbeidsflate.md 4.4–4.6).
import assert from 'node:assert/strict';
import test from 'node:test';
import { reactive } from 'vue';
import { paletteActions, urlWithMarket } from '../../resources/js/Components/paletteActions.js';
import { SAVED_VISIBLE_MS, SaveError, createAutosave } from '../../resources/js/Composables/useAutosave.js';
import { openIdFromUrl, urlWithOpen } from '../../resources/js/Composables/useOpenRow.js';
import { UNDO_VISIBLE_MS, createUndoToast } from '../../resources/js/Composables/useUndoToast.js';
import { useLanguage } from './support/i18n.mjs';

useLanguage('nb');

function fakeTimers() {
    const pending = new Map();
    let next = 1;

    return {
        setTimer: (callback, ms) => {
            const id = next++;
            pending.set(id, { callback, ms });
            return id;
        },
        clearTimer: (id) => pending.delete(id),
        run(ms) {
            for (const [id, timer] of [...pending]) {
                if (timer.ms === ms) {
                    pending.delete(id);
                    timer.callback();
                }
            }
        },
        pending,
    };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });

    return { promise, resolve, reject };
}

test('autosave shows the new value at once and «Lagret» for two seconds', async () => {
    const timers = fakeTimers();
    const calls = [];
    const pending = deferred();
    const values = reactive({ name: 'Gammel' });
    const autosave = createAutosave({
        url: () => '/prompts/7',
        values,
        request: (...args) => {
            calls.push(args);
            return pending.promise;
        },
        ...timers,
    });

    const saving = autosave.save('name', 'Ny');
    assert.equal(values.name, 'Ny');
    assert.equal(autosave.status('name'), 'saving');
    assert.equal(autosave.state.value, 'saving');
    assert.deepEqual(calls, [['patch', '/prompts/7', { name: 'Ny' }, { only: [] }]]);

    pending.resolve();
    assert.equal(await saving, true);
    assert.equal(autosave.status('name'), 'saved');
    timers.run(SAVED_VISIBLE_MS);
    assert.equal(autosave.status('name'), 'idle');
});

test('autosave rolls back and shows the validation message on an error', async () => {
    const values = reactive({ name: 'Gammel' });
    const autosave = createAutosave({
        url: '/prompts/7',
        values,
        request: () => Promise.reject(new SaveError({ name: 'Navnet er for langt.' })),
        ...fakeTimers(),
    });

    assert.equal(await autosave.save('name', 'x'.repeat(300)), false);
    assert.equal(values.name, 'Gammel');
    assert.equal(autosave.status('name'), 'error');
    assert.equal(autosave.errors.name, 'Navnet er for langt.');
    assert.equal(autosave.state.value, 'error');
    assert.equal(autosave.error.value, 'Navnet er for langt.');
});

test('a server error without messages shows the general text', async () => {
    const values = reactive({ active: true });
    const autosave = createAutosave({
        url: '/x',
        values,
        request: () => Promise.reject(new Error('500')),
        ...fakeTimers(),
    });

    await autosave.save('active', false);
    assert.equal(values.active, true);
    assert.equal(autosave.errors.active, 'Kunne ikke lagre. Prøv igjen.');
});

test('an unchanged value is not sent, and only the latest answer for a field counts', async () => {
    const calls = [];
    const first = deferred();
    const second = deferred();
    const answers = [first, second];
    const values = reactive({ name: 'A' });
    const autosave = createAutosave({
        url: '/x',
        values,
        request: (...args) => {
            calls.push(args);
            return answers.shift().promise;
        },
        ...fakeTimers(),
    });

    assert.equal(await autosave.save('name', 'A'), true);
    assert.equal(calls.length, 0);

    const one = autosave.save('name', 'B');
    const two = autosave.save('name', 'C');
    first.reject(new SaveError({ name: 'gammel feil' }));
    await one;
    // The older request failed, but the newer value stays and is still saving.
    assert.equal(values.name, 'C');
    assert.equal(autosave.status('name'), 'saving');
    second.resolve();
    await two;
    assert.equal(autosave.status('name'), 'saved');
});

test('reset clears the statuses when the panel shows another row', async () => {
    const autosave = createAutosave({ url: '/x', request: () => Promise.resolve(), ...fakeTimers() });
    await autosave.save('name', 'B');
    autosave.reset();
    assert.equal(autosave.state.value, 'idle');
});

test('the undo message shows one message at a time for eight seconds', () => {
    const timers = fakeTimers();
    const toast = createUndoToast(timers);

    toast.show('Første', () => {});
    toast.show('Andre', () => {});
    assert.equal(toast.current.value.text, 'Andre');
    assert.equal(timers.pending.size, 1);

    timers.run(UNDO_VISIBLE_MS);
    assert.equal(toast.current.value, null);
});

test('Angre calls the opposite action once and closes the message', async () => {
    const timers = fakeTimers();
    const toast = createUndoToast(timers);
    let undone = 0;
    toast.show('Konkurrenten ble lagt til.', async () => {
        undone++;
    });

    const first = toast.undo();
    assert.equal(await toast.undo(), false);
    assert.equal(await first, true);
    assert.equal(undone, 1);
    assert.equal(toast.current.value, null);
});

test('a failed undo replaces the message with the error', async () => {
    const toast = createUndoToast(fakeTimers());
    toast.show('Arkivert', () => Promise.reject(new Error('409')));

    assert.equal(await toast.undo(), false);
    assert.equal(toast.current.value.text, 'Kunne ikke angre. Last siden på nytt og prøv igjen.');
    assert.equal(toast.current.value.undo, null);
});

const routes = new Set([
    'reports.index',
    'brands.show',
    'brands.facts.index',
    'brands.setup.prompts',
    'brands.setup.competitors',
    'brands.setup.markets',
    'inbox.index',
]);
const context = (workspace, has = (name) => routes.has(name)) => ({
    workspace,
    url: '/brands/4/prompts?days=28&market=2',
    has,
    to: (name, params = {}) => `${name}?${new URLSearchParams(params)}`,
    t: (key) => key.split('.').pop(),
});
const can = (overrides = {}) => ({
    manage_prompts: false,
    manage_competitors: false,
    manage_brands: false,
    manage_markets: false,
    manage_alerts: false,
    manage_reports: false,
    ...overrides,
});
const workspace = (overrides = {}) => ({
    brands: [
        { id: 4, name: 'Fjordbyrå' },
        { id: 5, name: 'Annen' },
    ],
    brand: { id: 4, name: 'Fjordbyrå' },
    markets: [
        { id: 2, label: 'Norge', is_active: true },
        { id: 3, label: 'Sverige', is_active: true },
    ],
    market_id: 2,
    can: can(),
    ...overrides,
});

test('without the workspace prop the palette keeps today’s report action', () => {
    assert.deepEqual(
        paletteActions(context(null)).map((item) => item.id),
        ['report'],
    );
});

test('a reader sees switching and the inbox, but nothing that changes data', () => {
    assert.deepEqual(
        paletteActions(context(workspace())).map((item) => item.id),
        ['switch-market', 'switch-brand', 'inbox'],
    );
});

test('an editor gets the create actions that lead to the setup pages', () => {
    const items = paletteActions(
        context(workspace({ can: can({ manage_prompts: true, manage_competitors: true, manage_reports: true }) })),
    );
    assert.deepEqual(
        items.map((item) => item.id),
        ['create-prompt', 'create-topic', 'create-competitor', 'switch-market', 'switch-brand', 'inbox', 'report'],
    );
    assert.equal(items[0].href, 'brands.setup.prompts?brand=4&create=prompt');
    assert.equal(items[1].href, 'brands.setup.prompts?brand=4&create=topic');
    assert.equal(items[2].href, 'brands.setup.competitors?brand=4&create=competitor');
});

test('an owner also creates markets and facts', () => {
    const all = can(Object.fromEntries(Object.keys(can()).map((key) => [key, true])));
    const ids = paletteActions(context(workspace({ can: all }))).map((item) => item.id);
    assert.ok(ids.includes('create-market'));
    assert.ok(ids.includes('create-fact'));
});

test('actions for routes that are not built yet are left out', () => {
    const items = paletteActions(
        context(workspace({ can: can({ manage_prompts: true }) }), (name) => name === 'brands.show'),
    );
    assert.deepEqual(
        items.map((item) => item.id),
        ['switch-market', 'switch-brand'],
    );
});

test('switching market keeps the other filters and marks the current market', () => {
    const market = paletteActions(context(workspace())).find((item) => item.id === 'switch-market');
    assert.deepEqual(
        market.children.map((item) => [item.href, Boolean(item.current)]),
        [
            ['/brands/4/prompts?days=28', false],
            ['/brands/4/prompts?days=28&market=2', true],
            ['/brands/4/prompts?days=28&market=3', false],
        ],
    );
    assert.equal(urlWithMarket('/x', 7), '/x?market=7');
});

test('a global page without a brand offers only switching brand and the inbox', () => {
    const items = paletteActions(context(workspace({ brand: null, markets: [], can: can({ manage_prompts: true }) })));
    assert.deepEqual(
        items.map((item) => item.id),
        ['switch-brand', 'inbox'],
    );
});

test('the open row lives in the URL as ?open=<id>', () => {
    assert.equal(openIdFromUrl('/brands/4/setup/prompts?market=2&open=17'), 17);
    assert.equal(openIdFromUrl('/x?open=abc'), 'abc');
    assert.equal(openIdFromUrl('/x?open='), null);
    assert.equal(openIdFromUrl('/x'), null);
    assert.equal(urlWithOpen('/x?market=2', 17), '/x?market=2&open=17');
    assert.equal(urlWithOpen('/x?market=2&open=17', null), '/x?market=2');
});
