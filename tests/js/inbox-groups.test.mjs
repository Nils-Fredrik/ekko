import assert from 'node:assert/strict';
import test from 'node:test';
import { configureFormat, dayDistance, formatWeekday } from '../../resources/js/format.js';
import { groupInbox } from '../../resources/js/inbox/groups.js';

const now = new Date('2026-11-21T10:00:00Z');

test('day distance uses the tenant calendar, and rows stay in day groups', () => {
    configureFormat({ locale: 'nb', timeZone: 'Europe/Oslo' });
    assert.equal(dayDistance('2026-11-21T08:00:00Z', now), 0);
    assert.equal(dayDistance('2026-11-20T22:30:00Z', now), -1);
    assert.equal(dayDistance('not-a-date', now), null);
    assert.equal(formatWeekday('2026-11-19T12:00:00Z'), 'torsdag');

    const groups = groupInbox(
        [
            { id: 1, at: '2026-11-21T08:00:00Z' },
            { id: 2, at: '2026-11-21T06:00:00Z' },
            { id: 3, at: '2026-11-20T18:00:00Z' },
        ],
        { now, heading: (_at, distance) => String(distance) },
    );

    assert.deepEqual(
        groups.map((group) => [group.label, group.items.map((item) => item.id)]),
        [
            ['0', [1, 2]],
            ['-1', [3]],
        ],
    );
});
