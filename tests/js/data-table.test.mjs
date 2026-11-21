import assert from 'node:assert/strict';
import test from 'node:test';
import {
    VIRTUAL_THRESHOLD,
    displayItems,
    gridTemplate,
    keyAction,
    moveIndex,
    readHiddenColumns,
    selectRange,
    toggleAllVisible,
    toggleId,
    visibleIds,
    visibleSelectionState,
    writeHiddenColumns,
} from '../../resources/js/Components/UI/dataTable.js';

const ids = [1, 2, 3, 4, 5, 6];

test('a click switches one row', () => {
    const one = toggleId(new Set(), 3);
    assert.deepEqual([...one], [3]);
    assert.deepEqual([...toggleId(one, 3)], []);
});

test('shift-click selects the range from the anchor, in either direction', () => {
    assert.deepEqual([...selectRange(new Set([2]), ids, 2, 5)].sort(), [2, 3, 4, 5]);
    assert.deepEqual([...selectRange(new Set([5]), ids, 5, 2)].sort(), [2, 3, 4, 5]);
});

test('shift-click on a selected row clears the range', () => {
    const all = new Set(ids);
    assert.deepEqual([...selectRange(all, ids, 2, 4)].sort(), [1, 5, 6]);
});

test('shift-click keeps rows outside the range and falls back without an anchor', () => {
    assert.deepEqual([...selectRange(new Set([6]), ids, 1, 2)].sort(), [1, 2, 6]);
    assert.deepEqual([...selectRange(new Set(), ids, 99, 4)], [4]);
});

test('the header checkbox selects or clears every visible row', () => {
    assert.equal(visibleSelectionState(new Set(), ids), 'none');
    assert.equal(visibleSelectionState(new Set([1]), ids), 'some');
    assert.equal(visibleSelectionState(new Set(ids), ids), 'all');
    assert.equal(visibleSelectionState(new Set([1]), []), 'none');

    const visible = [1, 2, 3];
    const selected = toggleAllVisible(new Set([9]), visible);
    assert.deepEqual([...selected].sort(), [1, 2, 3, 9]);
    assert.deepEqual([...toggleAllVisible(selected, visible)], [9]);
});

test('rows are grouped in the order of their first row, and collapsed groups hide their rows', () => {
    const rows = [
        { id: 1, topic: 'b' },
        { id: 2, topic: 'a' },
        { id: 3, topic: 'b' },
    ];
    const items = displayItems(rows, { groupBy: 'topic', groupLabel: (key, members) => `${key}:${members.length}` });
    assert.deepEqual(
        items.map((item) => (item.type === 'group' ? `[${item.label}]` : item.id)),
        ['[b:2]', 1, 3, '[a:1]', 2],
    );
    assert.deepEqual(visibleIds(items), [1, 3, 2]);

    const collapsed = displayItems(rows, { groupBy: 'topic', collapsed: new Set(['b']) });
    assert.deepEqual(
        collapsed.map((item) => (item.type === 'group' ? `[${item.key}${item.collapsed ? '-' : '+'}]` : item.id)),
        ['[b-]', '[a+]', 2],
    );
    assert.deepEqual(visibleIds(collapsed), [2]);
});

test('ungrouped rows keep their order and a function can give the id', () => {
    const items = displayItems([{ key: 'x' }, { key: 'y' }], { rowKey: (row) => row.key });
    assert.deepEqual(visibleIds(items), ['x', 'y']);
});

test('keyboard: arrows move, x selects, Enter opens, / searches', () => {
    assert.equal(keyAction({ key: 'ArrowDown' }), 'next');
    assert.equal(keyAction({ key: 'ArrowUp' }), 'previous');
    assert.equal(keyAction({ key: 'Home' }), 'first');
    assert.equal(keyAction({ key: 'End' }), 'last');
    assert.equal(keyAction({ key: 'Enter' }), 'open');
    assert.equal(keyAction({ key: '/' }), 'search');
    assert.equal(keyAction({ key: 'x' }, { selectable: true }), 'toggle');
    assert.equal(keyAction({ key: 'X', shiftKey: true }, { selectable: true }), 'toggle');
    assert.equal(keyAction({ key: 'x' }, { selectable: false }), null);
    assert.equal(keyAction({ key: 'ArrowDown', metaKey: true }), null);
    assert.equal(keyAction({ key: 'a' }), null);
});

test('keyboard: j and k move the open row only while the panel is open', () => {
    assert.equal(keyAction({ key: 'j' }), null);
    assert.equal(keyAction({ key: 'k' }), null);
    assert.equal(keyAction({ key: 'j' }, { panelOpen: true }), 'open-next');
    assert.equal(keyAction({ key: 'k' }, { panelOpen: true }), 'open-previous');
});

test('keyboard: Esc closes the panel first, then clears the selection', () => {
    assert.equal(keyAction({ key: 'Escape' }, { panelOpen: true, hasSelection: true }), 'close');
    assert.equal(keyAction({ key: 'Escape' }, { hasSelection: true }), 'clear');
    assert.equal(keyAction({ key: 'Escape' }), null);
});

test('the focus stays inside the list', () => {
    assert.equal(moveIndex('next', 2, 6), 3);
    assert.equal(moveIndex('next', 5, 6), 5);
    assert.equal(moveIndex('previous', 0, 6), 0);
    assert.equal(moveIndex('next', -1, 6), 0);
    assert.equal(moveIndex('first', 4, 6), 0);
    assert.equal(moveIndex('last', 0, 6), 5);
    assert.equal(moveIndex('open-next', 1, 6), 2);
    assert.equal(moveIndex('next', 0, 0), -1);
});

test('hidden columns are remembered per table, and a failing storage is ignored', () => {
    const memory = new Map();
    const storage = { getItem: (key) => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, value) };
    writeHiddenColumns('setup.prompts', ['market'], storage);
    assert.equal(memory.get('ekko.table.setup.prompts'), '{"hidden":["market"]}');
    assert.deepEqual(readHiddenColumns('setup.prompts', storage), ['market']);
    assert.deepEqual(readHiddenColumns('other', storage), []);

    const broken = {
        getItem: () => {
            throw new Error('blocked');
        },
        setItem: () => {
            throw new Error('blocked');
        },
    };
    assert.deepEqual(readHiddenColumns('setup.prompts', broken), []);
    assert.doesNotThrow(() => writeHiddenColumns('setup.prompts', ['x'], broken));
    memory.set('ekko.table.bad', '{not json');
    assert.deepEqual(readHiddenColumns('bad', storage), []);
    assert.deepEqual(readHiddenColumns('', storage), []);
});

test('the grid has a checkbox track when rows can be selected', () => {
    const columns = [{ id: 'a', width: '2fr' }, { id: 'b' }];
    assert.equal(gridTemplate(columns, false), '2fr minmax(0, 1fr)');
    assert.equal(gridTemplate(columns, true), '36px 2fr minmax(0, 1fr)');
});

test('the table virtualises above 200 rows', () => {
    assert.equal(VIRTUAL_THRESHOLD, 200);
});
