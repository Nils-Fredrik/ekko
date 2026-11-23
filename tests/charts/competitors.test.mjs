import assert from 'node:assert/strict';
import test from 'node:test';
import { limitCompetitors, rankedCompetitorKeys } from '../../resources/js/Components/Charts/support/competitors.js';
import { heatmapRows, rowHasCells, wrapLabel } from '../../resources/js/Components/Charts/support/heatmapRows.js';
import { emptyBeforeSpan } from '../../resources/js/Components/Charts/support/gap.js';

function point(value, n = 10) {
    return { value, n, low_sample: false };
}

function series(key, value, colorIndex) {
    return { key, label: key, colorIndex, points: [point(value), point(value)] };
}

test('sixteen competitors become six colours plus others', () => {
    const own = { key: 'brand', label: 'Own', own: true, points: [point(0.2), point(0.2)] };
    const competitors = Array.from({ length: 16 }, (_, index) => series(`c${index}`, (16 - index) / 100, 15 - index));
    const folded = limitCompetitors([own, ...competitors], { otherLabel: (count) => `Others (${count})` });
    const rest = folded.filter((item) => !item.own && !item.neutral);

    assert.equal(rest.length, 6);
    assert.deepEqual(
        rest.map((item) => item.colorIndex),
        [15, 14, 13, 12, 11, 10],
    );
    assert.equal(folded.at(-1).label, 'Others (10)');
    assert.equal(folded.at(-1).neutral, true);
    assert.equal(folded.at(-1).dashed, true);
    assert.equal(rest[0].key, 'c0');
    assert.ok(folded.at(-1).points[0].value > rest.at(-1).points[0].value);
});

test('the visibility trend keeps the share ranking and colours', () => {
    const share = [
        { key: 'brand', label: 'Own', own: true, points: [point(0.4)] },
        ...Array.from({ length: 8 }, (_, index) => series(`c${index}`, (16 - index) / 100, 15 - index)),
    ];
    const trend = [
        { key: 'brand', label: 'Own', own: true, points: [point(0.2)] },
        ...Array.from({ length: 8 }, (_, index) => series(`c${index}`, index / 100, 15 - index)),
    ];
    const order = rankedCompetitorKeys(share);
    const options = { order, otherLabel: (count) => `Others (${count})` };
    const foldedShare = limitCompetitors(share, options);
    const foldedTrend = limitCompetitors(trend, { ...options, reduce: 'mean' });
    const keys = (items) => items.filter((item) => !item.own && !item.neutral).map((item) => item.key);
    const colours = (items) => items.filter((item) => !item.own && !item.neutral).map((item) => item.colorIndex);

    assert.deepEqual(order, ['c0', 'c1', 'c2', 'c3', 'c4', 'c5']);
    assert.deepEqual(keys(foldedTrend), keys(foldedShare));
    assert.deepEqual(colours(foldedTrend), colours(foldedShare));
    assert.deepEqual(colours(foldedTrend), [15, 14, 13, 12, 11, 10]);
    assert.equal(foldedTrend.at(-1).key, 'others');
});

test('heatmap rows follow the selected market or group every market', () => {
    const prompts = [
        { id: 2, text: 'Beta prompt text that is long enough to wrap onto two lines', market: 'Sweden' },
        { id: 1, text: 'Alpha', market: 'Norway' },
        { id: 3, text: 'Gamma', market: 'Norway' },
    ];

    assert.deepEqual(
        heatmapRows(prompts, { marketSelected: true }).map((row) => row.group),
        [undefined, undefined, undefined],
    );
    assert.deepEqual(
        heatmapRows(prompts).map((row) => `${row.group}:${row.label}`),
        ['Norway:Alpha', 'Norway:Gamma', 'Sweden:Beta prompt text that is long enough to wrap onto two lines'],
    );
    const wrapped = wrapLabel(prompts[0].text, 28);
    assert.equal(wrapped.split('\n').length, 2);
    assert.equal(rowHasCells('1', [{ row: '2', column: 'a' }]), false);
    assert.equal(emptyBeforeSpan(['2026-10-01', '2026-10-10', '2026-10-14'], '2026-10-10')[1], '2026-10-01');
    assert.equal(emptyBeforeSpan(['2026-10-05', '2026-10-12'], '2026-10-10'), null);
    assert.deepEqual(emptyBeforeSpan(['2026-10-05', '2026-10-12'], '2026-10-13'), ['2026-10-05', '2026-10-05']);
});
