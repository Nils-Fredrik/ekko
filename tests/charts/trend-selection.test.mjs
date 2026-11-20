import assert from 'node:assert/strict';
import { test } from 'node:test';
import { trendSelection, trendSeriesSelection } from '../../resources/js/Components/Charts/support/data.js';

const categories = ['2026-10-01', '2026-10-02', '2026-10-03'];
const series = [
    {
        key: 'own',
        points: [
            { value: 0.4, n: 20 },
            { value: null, n: 0 },
            { value: 0.6, n: 3 },
        ],
    },
    {
        key: 'competitor-low',
        points: [
            { value: 0.2, n: 20 },
            { value: 0.3, n: 20 },
            { value: 0.4, n: 20 },
        ],
    },
];
const click = (seriesIndex, dataIndex) => ({ componentType: 'series', seriesType: 'line', seriesIndex, dataIndex });

test('the selected key and date come from the provided data, including low-sample layers', () => {
    assert.deepEqual(trendSelection(click(0, 0), categories, series), { key: 'own', category: '2026-10-01' });
    assert.deepEqual(trendSelection(click(1, 2), categories, series), { key: 'own', category: '2026-10-03' });
    assert.deepEqual(trendSelection(click(2, 1), categories, series), {
        key: 'competitor-low',
        category: '2026-10-02',
    });
});

test('missing data, previous-period and marker series cannot emit a selection', () => {
    assert.equal(trendSelection(click(0, 1), categories, series), null);
    assert.equal(trendSelection(click(3, 0), categories, series), null);
    assert.equal(trendSelection(click(9, 0), categories, series), null);
    assert.equal(trendSelection(click(0, 9), categories, series), null);
    assert.equal(trendSelection({ ...click(0, 0), seriesType: 'scatter' }, categories, series), null);
    assert.equal(trendSelection({ ...click(0, 0), componentType: 'markLine' }, categories, series), null);
    assert.equal(trendSelection({ ...click(0, 0), seriesIndex: -1 }, categories, series), null);
    assert.equal(trendSelection({ ...click(0, 0), dataIndex: '0' }, categories, series), null);
});


test('cross-filtering a line selects its series without inventing a date or selecting a comparison line', () => {
    const series = [{ key: 'brand', points: [{ value: 0.4, n: 20 }] }, { key: 'competitor', points: [{ value: 0.2, n: 2 }] }];
    const click = { componentType: 'series', seriesType: 'line', seriesIndex: 1 };
    assert.deepEqual(trendSeriesSelection(click, series), { key: 'competitor' });
    assert.deepEqual(trendSeriesSelection({ ...click, seriesIndex: 2 }, series), { key: 'competitor' });
    assert.equal(trendSeriesSelection({ ...click, seriesIndex: 3 }, series), null);
    assert.equal(trendSeriesSelection({ ...click, componentType: 'markLine' }, series), null);
});
