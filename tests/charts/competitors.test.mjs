import assert from 'node:assert/strict';
import test from 'node:test';
import { limitCompetitors } from '../../resources/js/Components/Charts/support/competitors.js';
import { heatmapRows, rowHasCells, wrapLabel } from '../../resources/js/Components/Charts/support/heatmapRows.js';
import { emptyBeforeSpan } from '../../resources/js/Components/Charts/support/gap.js';

function point(value, n = 10) {
    return { value, n, low_sample: false };
}

function series(key, value) {
    return { key, label: key, points: [point(value), point(value)] };
}

test('sixteen competitors become six colours plus others', () => {
    const own = { key: 'brand', label: 'Own', own: true, points: [point(0.2), point(0.2)] };
    const competitors = Array.from({ length: 16 }, (_, index) => series(`c${index}`, (16 - index) / 100));
    const folded = limitCompetitors([own, ...competitors], { otherLabel: (count) => `Others (${count})` });
    const rest = folded.filter((item) => !item.own && !item.neutral);

    assert.equal(rest.length, 6);
    assert.equal(folded.at(-1).label, 'Others (10)');
    assert.equal(folded.at(-1).neutral, true);
    assert.equal(folded.at(-1).dashed, true);
    assert.equal(rest[0].key, 'c0');
    assert.ok(folded.at(-1).points[0].value > rest.at(-1).points[0].value);
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
});
