import { describe, expect, it } from 'vitest';
import * as columnState from './objectGridColumnState.js';

const { columnOrderFromState, columnOrderState, columnOrderStorageKey } = columnState;

describe('personal object grid column order', () => {
    it('keeps current field columns only', () => {
        const fields = ['drawing_no', 'project_id', 'name'];
        const state = [
            { colId: 'actions' },
            { colId: 'name' },
            { colId: 'old_field' },
            { colId: 'project_id' },
            { colId: 'name' },
        ];

        expect(columnOrderFromState(state, fields)).toEqual(['name', 'project_id']);
    });

    it('restores saved columns before newly added fields', () => {
        const fields = ['drawing_no', 'project_id', 'name'];

        expect(columnOrderState(['name', 'old_field', 'project_id', 'name'], fields)).toEqual([
            { colId: 'name' },
            { colId: 'project_id' },
            { colId: 'drawing_no' },
        ]);
    });

    it('scopes persisted order by both user and object', () => {
        expect(columnOrderStorageKey(42, 'drawing')).toBe('xyc.objectGrid.columnOrder.42.drawing');
        expect(columnOrderStorageKey(73, 'drawing')).toBe('xyc.objectGrid.columnOrder.73.drawing');
    });

    it('provides a shared field ordering function for table, forms, and details', () => {
        expect(columnState.fieldsInColumnOrder).toBeTypeOf('function');
    });

    it('removes deleted fields, appends new fields, and keeps common and item fields partitioned', () => {
        const fields = [
            { key: 'name' },
            { key: 'spec', scope: 'item' },
            { key: 'customer_id' },
            { key: 'weight', scope: 'item' },
            { key: 'new_field' },
        ];

        expect(columnState.fieldsInColumnOrder(fields, [
            'weight',
            'deleted_field',
            'customer_id',
            'spec',
            'weight',
        ]).map((field) => field.key)).toEqual([
            'customer_id',
            'name',
            'new_field',
            'weight',
            'spec',
        ]);
    });
});
