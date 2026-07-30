import { describe, expect, it } from 'vitest';
import * as gridRows from './objectGridRows';

const { expandObjectRecords, flatExportRows, sameRecordSpan, updateRecordField } = gridRows;

const fields = [
    { key: 'date', scope: 'common' },
    { key: 'supplier_name' },
    { key: 'material_id', scope: 'item' },
    { key: 'spec', scope: 'item' },
    { key: 'qty', scope: 'item' },
];

const record = {
    id: 'record-1',
    code: 'CG-001',
    title: '采购日报',
    payload: {
        date: '2026-07-11',
        supplier_name: '供应商甲',
        items: [
            { id: 'item-1', material_id: 'material-1', spec: '10mm', qty: 10 },
            { id: 'item-2', material_id: 'material-2', spec: '12mm', qty: 20 },
            { id: 'item-3', material_id: 'material-3', spec: '16mm', qty: 30 },
        ],
    },
    display: {},
};

describe('object grid line item rows', () => {
    it('expands one document into one row per item and repeats common values', () => {
        const rows = expandObjectRecords([record], fields);

        expect(rows).toHaveLength(3);
        expect(rows.map((row) => row.id)).toEqual([
            'record-1:item-1',
            'record-1:item-2',
            'record-1:item-3',
        ]);
        expect(rows.map((row) => row.date)).toEqual(['2026-07-11', '2026-07-11', '2026-07-11']);
        expect(rows.map((row) => row.spec)).toEqual(['10mm', '12mm', '16mm']);
    });

    it('merges common cells only for adjacent rows from the same record', () => {
        const rows = expandObjectRecords([record, { ...record, id: 'record-2', code: 'CG-002' }], fields);

        expect(sameRecordSpan({ nodeA: { data: rows[0] }, nodeB: { data: rows[1] } })).toBe(true);
        expect(sameRecordSpan({ nodeA: { data: rows[2] }, nodeB: { data: rows[3] } })).toBe(false);
    });

    it('updates only the selected item while common edits remain top-level', () => {
        const itemUpdate = updateRecordField(record, fields[4], 99, 'item-2');
        expect(itemUpdate.payload.items.map((item) => item.qty)).toEqual([10, 99, 30]);
        expect(record.payload.items.map((item) => item.qty)).toEqual([10, 20, 30]);

        const commonUpdate = updateRecordField(record, fields[1], '供应商乙', 'item-2');
        expect(commonUpdate.payload.supplier_name).toBe('供应商乙');
        expect(commonUpdate.payload.items).toEqual(record.payload.items);
    });

    it('builds flat export rows with repeated common fields', () => {
        expect(flatExportRows([record], fields)).toEqual([
            { date: '2026-07-11', supplier_name: '供应商甲', material_id: 'material-1', spec: '10mm', qty: 10 },
            { date: '2026-07-11', supplier_name: '供应商甲', material_id: 'material-2', spec: '12mm', qty: 20 },
            { date: '2026-07-11', supplier_name: '供应商甲', material_id: 'material-3', spec: '16mm', qty: 30 },
        ]);
    });

    it('scopes customer contacts and preserves selected historical labels separately', () => {
        expect(gridRows.scopedRelationOptions).toBeTypeOf('function');
        if (!gridRows.scopedRelationOptions) return;

        const relationOptions = {
            customer_contact_ids: {
                target: 'customer_contact',
                items: [
                    { id: 'contact-a', label: '甲联系人', meta: { customer_id: 'customer-a' } },
                    { id: 'contact-b', label: '乙联系人', meta: { customer_id: 'customer-b' } },
                ],
            },
        };

        expect(gridRows.scopedRelationOptions(relationOptions, {
            customer_id: '',
            customer_contact_ids: [],
        }).customer_contact_ids.items).toEqual([]);

        expect(gridRows.scopedRelationOptions(relationOptions, {
            customer_id: 'customer-a',
            customer_contact_ids: ['contact-a'],
        }).customer_contact_ids).toMatchObject({
            items: [{ id: 'contact-a' }],
            search_context: { customer_id: 'customer-a' },
        });

        expect(gridRows.scopedRelationOptions(relationOptions, {
            customer_id: 'customer-a',
            customer_contact_ids: ['contact-inactive'],
        }, {
            customer_contact_ids: ['停用联系人'],
        }).customer_contact_ids.selectedItems).toEqual([
            { id: 'contact-inactive', label: '停用联系人' },
        ]);

        expect(gridRows.scopedRelationOptions({
            production_owner_id: { items: [] },
        }, { team_id: 'team-a' }).production_owner_id.search_context).toEqual({ team_id: 'team-a' });

        const rowOptions = gridRows.scopedRelationOptions({
            project_id: {
                items: [],
                search_url: '/relation-options?source_object=receivable&field=project_id',
            },
        }, {}, {}, 'receivable-1');
        expect(new URL(rowOptions.project_id.search_url, 'http://localhost').searchParams.get('editing_record'))
            .toBe('receivable-1');
    });
});
