import assert from 'node:assert/strict';
import { test } from 'node:test';
import { alignByOffset, alignComparison, bucketMarkers, periodBuckets } from '../../resources/js/Components/Charts/support/data.js';

test('daily buckets keep days that have no answers', () => {
    assert.deepEqual(periodBuckets('2026-11-01', '2026-11-03', 'day'), ['2026-11-01', '2026-11-02', '2026-11-03']);
});

test('week buckets start on Monday, including a partial first week', () => {
    assert.deepEqual(periodBuckets('2026-11-18', '2026-11-19', 'week'), ['2026-11-16']);
});

test('month buckets start on the first of the month', () => {
    assert.deepEqual(periodBuckets('2026-11-18', '2026-12-02', 'month'), ['2026-11-01', '2026-12-01']);
});

test('a missing first day does not shift the previous period', () => {
    const current = periodBuckets('2026-11-01', '2026-11-03', 'day');
    const previous = periodBuckets('2026-10-29', '2026-10-31', 'day');
    const recorded = new Map([
        ['2026-10-30', 0.2],
        ['2026-10-31', 0.4],
    ]);
    const values = previous.map((date) => recorded.get(date) ?? null);

    assert.deepEqual(alignByOffset(current, previous, values, null), [null, 0.2, 0.4]);
});

test('a marker later in the last weekly bucket stays when until is the period end', () => {
    const categories = periodBuckets('2026-11-16', '2026-11-19', 'week');
    const marker = { key: 'm', date: '2026-11-18', kind: 'model', label: 'change' };

    assert.equal(bucketMarkers([marker], categories, '2026-11-19').get('2026-11-16')?.length, 1);
    assert.equal(bucketMarkers([marker], categories, categories.at(-1)).size, 0);
});


test('year comparison keeps March calendar dates after a prior leap day', () => {
    const current = periodBuckets('2025-02-28', '2025-03-02');
    const previous = periodBuckets('2024-02-28', '2024-03-02');
    assert.deepEqual(alignComparison(current, previous, [28, 29, 1, 2], { comparison: 'year' }), [28, 1, 2]);
});

test('year comparison clips February 29 to February 28 and keeps missing days missing', () => {
    const current = periodBuckets('2024-02-28', '2024-03-02');
    const previous = periodBuckets('2023-02-28', '2023-03-02');
    assert.deepEqual(alignComparison(current, previous, [28, null, 2], { comparison: 'year' }), [28, 28, null, 2]);
});

test('year comparison matches calendar months and preserves whole weekly aggregates', () => {
    assert.deepEqual(alignComparison(['2025-02-01', '2025-03-01'], ['2024-02-01', '2024-03-01'], [2, 3],
        { comparison: 'year', grain: 'month' }), [2, 3]);
    const current = periodBuckets('2025-01-01', '2025-01-12', 'week');
    const previous = periodBuckets('2024-01-01', '2024-01-12', 'week');
    assert.deepEqual(alignComparison(current, previous, [1, 8],
        { comparison: 'year', grain: 'week' }), [1, 8]);
});

test('preceding-period comparison still aligns by bucket offset', () => {
    assert.deepEqual(alignComparison(['2025-03-01', '2025-03-02'], ['2025-02-27', '2025-02-28'], [27, null]), [27, null]);
});
