// Autosave with rollback, the undo message, the palette actions and the open row in the URL
// (docs/ux-arbeidsflate.md 4.4–4.6).
import assert from 'node:assert/strict';
import test from 'node:test';
import { reactive } from 'vue';
import { paletteActions, urlWithMarket } from '../../resources/js/Components/paletteActions.js';
import {
    SAVED_VISIBLE_MS,
    SaveCancelled,
    SaveError,
    createAutosave,
    inertiaRequest,
} from '../../resources/js/Composables/useAutosave.js';
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

// A request that keeps every call, so a test can answer them in any order.
function queuedRequest() {
    const calls = [];

    return {
        calls,
        request: (method, url, data) => {
            const answer = deferred();
            calls.push({ url, data, answer });

            return answer.promise;
        },
    };
}

// Lets the promise callbacks inside createAutosave run.
const settle = () => new Promise((resolve) => setImmediate(resolve));

test('an unchanged value is not sent', async () => {
    const { calls, request } = queuedRequest();
    const autosave = createAutosave({ url: '/x', values: reactive({ name: 'A' }), request, ...fakeTimers() });

    assert.equal(await autosave.save('name', 'A'), true);
    assert.equal(calls.length, 0);
});

test('saves to one field go one at a time, and only the newest waiting value is sent next', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A' });
    const autosave = createAutosave({ url: '/x', values, request, ...fakeTimers() });

    const b = autosave.save('name', 'B');
    const c = autosave.save('name', 'C');
    const d = autosave.save('name', 'D');
    assert.equal(values.name, 'D');
    assert.deepEqual(
        calls.map((call) => call.data),
        [{ name: 'B' }],
    );

    calls[0].answer.resolve();
    await settle();
    assert.deepEqual(
        calls.map((call) => call.data),
        [{ name: 'B' }, { name: 'D' }],
    );
    assert.equal(autosave.status('name'), 'saving');

    calls[1].answer.resolve();
    assert.deepEqual(await Promise.all([b, c, d]), [true, true, true]);
    assert.equal(values.name, 'D');
    assert.equal(autosave.status('name'), 'saved');
});

test('when the last value sent fails, the field rolls back to the last accepted value', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A' });
    const autosave = createAutosave({ url: '/x', values, request, ...fakeTimers() });

    const b = autosave.save('name', 'B');
    autosave.save('name', 'C');
    calls[0].answer.resolve();
    await settle();
    calls[1].answer.reject(new SaveError({ name: 'C er ugyldig' }));
    assert.equal(await b, false);
    assert.equal(values.name, 'B');
    assert.equal(autosave.errors.name, 'C er ugyldig');
});

test('when every value fails, the field rolls back to the value before them', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A' });
    const autosave = createAutosave({ url: '/x', values, request, ...fakeTimers() });

    const b = autosave.save('name', 'B');
    autosave.save('name', 'C');
    calls[0].answer.reject(new SaveError({ name: 'B er ugyldig' }));
    await settle();
    // The newer value is still sent, and its answer decides.
    assert.equal(calls.length, 2);
    assert.equal(autosave.status('name'), 'saving');
    calls[1].answer.reject(new SaveError({ name: 'C er ugyldig' }));
    assert.equal(await b, false);
    assert.equal(values.name, 'A');
    assert.equal(autosave.errors.name, 'C er ugyldig');
});

test('a newer value that succeeds after an older failure leaves no error', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A' });
    const autosave = createAutosave({ url: '/x', values, request, ...fakeTimers() });

    autosave.save('name', 'B');
    const c = autosave.save('name', 'C');
    calls[0].answer.reject(new SaveError({ name: 'B er ugyldig' }));
    await settle();
    calls[1].answer.resolve();
    assert.equal(await c, true);
    assert.equal(values.name, 'C');
    assert.equal(autosave.errors.name, undefined);
    assert.equal(autosave.status('name'), 'saved');
});

test('after reset, saves for the old row still reach it in order, and leave the new row alone', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A', row: 1 });
    const autosave = createAutosave({ url: () => `/rows/${values.row}`, values, request, ...fakeTimers() });

    const b = autosave.save('name', 'B');
    autosave.save('name', 'C');
    autosave.reset();
    assert.equal(await b, false);

    values.row = 2;
    values.name = 'X';
    const y = autosave.save('name', 'Y');
    // The new row does not wait for the old one.
    assert.deepEqual(
        calls.map((call) => [call.url, call.data.name]),
        [
            ['/rows/1', 'B'],
            ['/rows/2', 'Y'],
        ],
    );

    calls[0].answer.reject(new SaveError({ name: 'gammel rad' }));
    await settle();
    // C, the newest value for the old row, is still sent to the old row.
    assert.deepEqual(calls[2] && [calls[2].url, calls[2].data.name], ['/rows/1', 'C']);
    assert.equal(values.name, 'Y');
    assert.equal(autosave.errors.name, undefined);
    calls[2].answer.resolve();
    await settle();
    assert.equal(values.name, 'Y');
    assert.equal(autosave.status('name'), 'saving');

    calls[1].answer.reject(new SaveError({ name: 'Y er ugyldig' }));
    assert.equal(await y, false);
    assert.equal(values.name, 'X');
});

test('a queued save that succeeds after its row is opened again shows its value', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A', row: 1 });
    const autosave = createAutosave({ url: () => `/rows/${values.row}`, values, request, ...fakeTimers() });

    autosave.save('name', 'B');
    autosave.save('name', 'C');
    autosave.reset();
    values.row = 2;
    values.name = 'X';
    calls[0].answer.resolve();
    await settle();

    // Back to row 1, which the server has as B while C is on its way.
    autosave.reset();
    values.row = 1;
    values.name = 'B';
    calls[1].answer.resolve();
    await settle();
    assert.equal(values.name, 'C');
    assert.equal(autosave.errors.name, undefined);

    // A save for another row never changes the open one.
    values.row = 2;
    autosave.reset();
    values.name = 'X';
    assert.equal(values.name, 'X');
});

test('an old save that succeeds while the reopened row is saving becomes the value to roll back to', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A', row: 1 });
    const autosave = createAutosave({ url: () => `/rows/${values.row}`, values, request, ...fakeTimers() });

    autosave.save('name', 'B');
    autosave.reset();
    values.row = 2;
    autosave.reset();
    values.row = 1;
    values.name = 'A';
    // C goes into the same lane as B, so it waits for B.
    const c = autosave.save('name', 'C');
    calls[0].answer.resolve();
    await settle();
    assert.equal(values.name, 'C');
    calls[1].answer.reject(new SaveError({ name: 'C er ugyldig' }));
    assert.equal(await c, false);
    assert.equal(values.name, 'B');
});

test('reopening the same row keeps its saves in one lane, in order', async () => {
    const { calls, request } = queuedRequest();
    const values = reactive({ name: 'A' });
    const autosave = createAutosave({ url: '/rows/1', values, request, ...fakeTimers() });

    autosave.save('name', 'B');
    autosave.reset();
    values.name = 'B';
    const c = autosave.save('name', 'C');
    assert.equal(calls.length, 1);
    calls[0].answer.resolve();
    await settle();
    assert.deepEqual(
        calls.map((call) => call.data.name),
        ['B', 'C'],
    );
    calls[1].answer.resolve();
    assert.equal(await c, true);
    assert.equal(autosave.status('name'), 'saved');
});

// A stand-in for the Inertia router: it keeps every visit, so a test can answer them in any order.
function fakeRouter() {
    const visits = [];

    return {
        visits,
        patch: (url, data, options) => visits.push({ url, data, options }),
    };
}

test('the Inertia save is async, so two fields saved at once do not cancel each other', async () => {
    const client = fakeRouter();
    const values = reactive({ text: 'A', core: false });
    const autosave = createAutosave({
        url: '/prompts/7',
        values,
        request: (method, url, data, options) => inertiaRequest(method, url, data, options, client),
        ...fakeTimers(),
    });

    const text = autosave.save('text', 'B');
    const core = autosave.save('core', true);
    assert.equal(client.visits.length, 2);
    assert.ok(client.visits.every((visit) => visit.options.async === true && visit.options.preserveState === true));

    for (const visit of client.visits) {
        visit.options.onSuccess();
        visit.options.onFinish();
    }
    assert.deepEqual(await Promise.all([text, core]), [true, true]);
    assert.deepEqual({ ...values }, { text: 'B', core: true });
    assert.equal(autosave.status('text'), 'saved');
    assert.equal(autosave.status('core'), 'saved');
});

test('a stopped visit keeps the value and shows no error', async () => {
    const client = fakeRouter();
    const values = reactive({ text: 'A' });
    const autosave = createAutosave({
        url: '/x',
        values,
        request: (method, url, data, options) => inertiaRequest(method, url, data, options, client),
        ...fakeTimers(),
    });

    const saving = autosave.save('text', 'B');
    client.visits[0].options.onCancel();
    client.visits[0].options.onFinish();
    assert.equal(await saving, false);
    assert.equal(values.text, 'B');
    assert.equal(autosave.status('text'), 'idle');
    assert.equal(autosave.errors.text, undefined);
    assert.ok(new SaveCancelled() instanceof Error);
});

test('validation errors from Inertia reach the field', async () => {
    const client = fakeRouter();
    const saving = inertiaRequest('patch', '/x', { text: '' }, {}, client);
    client.visits[0].options.onError({ text: 'Teksten mangler.' });
    client.visits[0].options.onFinish();
    await assert.rejects(saving, (error) => error instanceof SaveError && error.errors.text === 'Teksten mangler.');
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
    assert.equal(items[0].href, 'brands.setup.prompts?brand=4&create=prompt&market=2');
    assert.equal(items[1].href, 'brands.setup.prompts?brand=4&create=topic');
    assert.equal(items[2].href, 'brands.setup.competitors?brand=4&create=competitor');
    const allMarkets = paletteActions(context(workspace({ market_id: null, can: can({ manage_prompts: true }) })));
    assert.equal(allMarkets[0].href, 'brands.setup.prompts?brand=4&create=prompt');
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
