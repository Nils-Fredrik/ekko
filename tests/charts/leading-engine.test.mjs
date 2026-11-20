import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pickLeadingEngine } from '../../resources/js/Pages/Brands/leadingEngine.js';

const engine = (key, value, n, lowSample = n < 10) => ({
    key,
    name: key,
    visibility: { value, n, low_sample: lowSample },
});

test('a low sample does not outrank a reliable engine', () => {
    const leader = pickLeadingEngine([engine('thin', 1, 2, true), engine('solid', 0.6, 300, false)]);

    assert.equal(leader.key, 'solid');
});

test('no sentence when every engine is a low sample or empty', () => {
    assert.equal(pickLeadingEngine([engine('thin', 1, 2, true), engine('empty', null, 0, true)]), null);
    assert.equal(pickLeadingEngine([]), null);
});

test('the highest reliable visibility leads, and n below 10 counts even without the flag', () => {
    const leader = pickLeadingEngine([
        engine('mid', 0.4, 40, false),
        engine('top', 0.7, 12, false),
        { key: 'unflagged', name: 'unflagged', visibility: { value: 1, n: 3 } },
    ]);

    assert.equal(leader.key, 'top');
});
