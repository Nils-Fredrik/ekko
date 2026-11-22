// The rules of Setup › Competitors and brands (docs/ux-arbeidsflate.md 5.3).
import assert from 'node:assert/strict';
import test from 'node:test';
import {
    aliasTargets,
    chunks,
    decisionTargets,
    filterTargets,
    moveId,
    normalizeDomain,
    nextOpenKey,
    reorderActive,
    responseError,
    shareBar,
    urlWithParams,
} from '../../resources/js/Components/Setup/entityQueue.js';

const order = ['a', 'b', 'c', 'd'];

test('a decision applies to the selected undecided rows, else to the open row', () => {
    const decided = new Map([['b', {}]]);
    assert.deepEqual(decisionTargets({ selection: new Set(['d', 'b', 'a']), open: 'c', decided, order }), ['a', 'd']);
    assert.deepEqual(decisionTargets({ selection: new Set(), open: 'c', decided, order }), ['c']);
    assert.deepEqual(decisionTargets({ selection: new Set(), open: 'b', decided, order }), []);
    assert.deepEqual(decisionTargets({ selection: new Set(), open: null, decided, order }), []);
});

test('the next row to open skips decided rows and falls back upwards', () => {
    assert.equal(nextOpenKey(order, new Map([['a', 1]]), 'a'), 'b');
    assert.equal(nextOpenKey(order, new Map([['a', 1], ['b', 1]]), 'a'), 'c');
    assert.equal(nextOpenKey(order, new Map([['c', 1], ['d', 1]]), 'c'), 'b');
    assert.equal(nextOpenKey(order, new Map(order.map((key) => [key, 1])), 'a'), null);
});

test('moveId moves one id and keeps the array when nothing changes', () => {
    const ids = [1, 2, 3, 4];
    assert.deepEqual(moveId(ids, 4, 0), [4, 1, 2, 3]);
    assert.deepEqual(moveId(ids, 1, 2), [2, 3, 1, 4]);
    assert.deepEqual(moveId(ids, 2, 99), [1, 3, 4, 2]);
    assert.equal(moveId(ids, 2, 1), ids);
    assert.equal(moveId(ids, 9, 0), ids);
});

test('the share line is three times the share, at most 100 percent', () => {
    assert.equal(shareBar(null), 0);
    assert.ok(Math.abs(shareBar(0.18) - 54) < 1e-9);
    assert.equal(shareBar(0.6), 100);
});

test('alias targets hide the own brand without the right, and a suggested target comes first', () => {
    const targets = [
        { type: 'brand', id: 1, name: 'Fjordbyrå' },
        { type: 'competitor', id: 7, name: 'Netthavn Digital' },
        { type: 'competitor', id: 8, name: 'Brygge Digital' },
    ];
    assert.equal(aliasTargets(targets, false).length, 2);
    assert.equal(aliasTargets(targets, true).length, 3);
    assert.deepEqual(
        filterTargets(targets, '', { target_type: 'competitor', target_id: 8 }).map((target) => target.id),
        [8, 1, 7],
    );
    assert.deepEqual(filterTargets(targets, 'digital').map((target) => target.id), [7, 8]);
});

test('urlWithParams sets and removes parameters and keeps the rest', () => {
    assert.equal(urlWithParams('/x?market=2&tab=other&open=5', { tab: null, open: 'new' }), '/x?market=2&open=new');
    assert.equal(urlWithParams('/x', { tab: 'competitors' }), '/x?tab=competitors');
});

test('responseError reads a validation message or the response message', () => {
    assert.equal(responseError({ response: { data: { errors: { 'keys.0': ['Navnet er behandlet.'] } } } }), 'Navnet er behandlet.');
    assert.equal(responseError({ response: { data: { message: 'Nei' } } }), 'Nei');
    assert.equal(responseError(new Error('x')), null);
});

test('a domain is normalized without protocol, path and www', () => {
    assert.equal(normalizeDomain(' https://www.Beta-Rival.example.test:8080/about?x=1 '), 'beta-rival.example.test');
    assert.equal(normalizeDomain('user@shop.example.test/'), 'shop.example.test');
    assert.equal(normalizeDomain('example.test'), 'example.test');
});

test('a long selection is sent in batches of at most 100 names', () => {
    const keys = Array.from({ length: 250 }, (_, index) => `n${index}`);
    assert.deepEqual(chunks(keys).map((piece) => piece.length), [100, 100, 50]);
    assert.deepEqual(chunks([]), []);
});

test('only active competitors change places, and an inactive one keeps its place', () => {
    const list = [
        { id: 1, is_active: true },
        { id: 2, is_active: false },
        { id: 3, is_active: true },
        { id: 4, is_active: true },
    ];
    const ids = (result) => result.map((item) => item.id);
    assert.deepEqual(ids(reorderActive(list, 4, 0)), [4, 2, 1, 3]);
    assert.deepEqual(ids(reorderActive(list, 1, 3)), [3, 2, 4, 1]);
    // Dropped on the inactive row: the nearest active one in the direction of the move.
    assert.deepEqual(ids(reorderActive(list, 3, 1)), [3, 2, 1, 4]);
    assert.equal(reorderActive(list, 2, 0), list);
    assert.deepEqual(ids(reorderActive(list, 1, 1)), [3, 2, 1, 4]);
    assert.equal(reorderActive(list, 1, 0), list);
});
