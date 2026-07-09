import assert from 'node:assert/strict';
import test from 'node:test';
import { columnOrderFromState, columnOrderState, columnOrderStorageKey } from './objectGridColumnState.js';

test('column order state keeps current field columns only', () => {
    const fields = ['drawing_no', 'project_id', 'name'];
    const state = [
        { colId: 'actions' },
        { colId: 'name' },
        { colId: 'old_field' },
        { colId: 'project_id' },
        { colId: 'name' },
    ];

    assert.deepEqual(columnOrderFromState(state, fields), ['name', 'project_id']);
});

test('column order state restores saved columns before remaining fields', () => {
    const fields = ['drawing_no', 'project_id', 'name'];

    assert.deepEqual(columnOrderState(['name', 'old_field', 'project_id', 'name'], fields), [
        { colId: 'name' },
        { colId: 'project_id' },
        { colId: 'drawing_no' },
    ]);
});

test('column order storage is scoped per object', () => {
    assert.equal(columnOrderStorageKey('drawing'), 'xyc.objectGrid.columnOrder.drawing');
});
