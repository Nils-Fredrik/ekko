import assert from 'node:assert/strict';
import { test } from 'node:test';
import { robotSpan } from '../../resources/js/Pages/Brands/robotBuckets.js';

const days = ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'];

test('gaps between the first and last bucket with log data are drawn, the edges stay empty', () => {
    assert.deepEqual(robotSpan(days, new Set(['2026-11-02', '2026-11-04'])), { from: 1, to: 3 });
});

test('a single bucket with data is widened to its neighbours so the point is visible', () => {
    assert.deepEqual(robotSpan(days, new Set(['2026-11-03'])), { from: 1, to: 3 });
    assert.deepEqual(robotSpan(days, new Set(['2026-11-01'])), { from: 0, to: 1 });
    assert.deepEqual(robotSpan(days, new Set(['2026-11-05'])), { from: 3, to: 4 });
});

test('nothing is drawn without log data', () => {
    assert.equal(robotSpan(days, new Set()), null);
    assert.equal(robotSpan(days, new Map([['2026-10-01', 1]])), null);
});
