// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ObjectGrid, { columnBounds, fieldEditableForRecord, formatSubtotalValue, MIN_DATA_COLUMN_WIDTH, subtotalColumnWidth } from './ObjectGrid';
import { formatObjectNumber } from './objectNumberFormatting';

describe('ObjectGrid column resizing', () => {
    it('allows every ordinary data column type to shrink to about three Chinese characters', () => {
        const fields = [
            { key: 'customer_id', type: 'relation' },
            { key: 'delivery_date', type: 'date' },
            { key: 'contract_amount', type: 'number' },
            { key: 'status', type: 'select' },
            { key: 'project_no', type: 'readonly', system: 'code' },
            { key: 'remark', type: 'text' },
            { key: 'name', type: 'text' },
        ];

        expect(MIN_DATA_COLUMN_WIDTH).toBe(72);
        expect(fields.map((field) => columnBounds(field).min))
            .toEqual(fields.map(() => MIN_DATA_COLUMN_WIDTH));
    });
});

describe('project number formatting', () => {
    it('shows project numbers with two decimals without changing other objects', () => {
        const field = { key: 'occurred_amount', type: 'number' };

        expect(formatObjectNumber('project', field, -1234.555)).toBe('-1,234.56');
        expect(formatObjectNumber('project', field, 12)).toBe('12.00');
        expect(formatObjectNumber('contract', field, 12)).toBe(12);
        expect(formatObjectNumber('project', field, '')).toBe('');
    });
});

describe('ObjectGrid filtered subtotal', () => {
    beforeEach(() => {
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('appends a read-only subtotal after the real last record', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '业务项目' }}
                records={[{
                    id: 'project-last',
                    code: 'XYC-LAST',
                    title: '真实最后项目',
                    payload: { name: '真实最后项目', occurred_amount: 200 },
                    display: {},
                }]}
                subtotal={{ label: '小计', values: { occurred_amount: 1319435985.269999 } }}
                fields={[
                    { key: 'name', label: '项目名称', type: 'text' },
                    { key: 'occurred_amount', label: '已发生金额', type: 'number' },
                ]}
                can={{ update: true, delete: true }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByText('真实最后项目')).not.toBeNull();
        const subtotalLabel = await screen.findByText('小计');
        const subtotalGridRow = subtotalLabel.closest('[role="row"]');

        expect(subtotalGridRow).not.toBeNull();
        expect(within(subtotalGridRow).getByText('1,319,435,985.27')).not.toBeNull();
        expect(within(subtotalGridRow).queryByRole('button')).toBeNull();
        expect(within(subtotalGridRow).queryByRole('link')).toBeNull();
        fireEvent.doubleClick(within(subtotalGridRow).getByRole('gridcell', { name: '1,319,435,985.27' }));
        expect(document.querySelector('.grid-inline-editor')).toBeNull();
    });

    it('formats finite subtotals to two decimals and reserves room for billion-scale totals', () => {
        const field = { key: 'occurred_amount', type: 'number' };

        expect(formatSubtotalValue(1319435985.269999)).toBe('1,319,435,985.27');
        expect(formatSubtotalValue('not-a-number')).toBeNull();
        expect(subtotalColumnWidth(field, 1319435985.269999)).toBeGreaterThan(160);
        expect(subtotalColumnWidth({ key: 'name', type: 'text' }, 1319435985.269999)).toBe(0);
    });

    it('does not invent a subtotal row when the server omits it', async () => {
        render(
            <ObjectGrid
                object={{ key: 'customer', label: '客户信息' }}
                records={[]}
                fields={[{ key: 'name', label: '客户名称', type: 'text' }]}
                can={{ update: true, delete: true, create: true }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByText('暂无客户信息记录')).not.toBeNull();
        expect(screen.queryByText('小计')).toBeNull();
    });
});

describe('ObjectGrid status-dependent fields', () => {
    it('only enables the tender assignee field after the tender is won', () => {
        const field = {
            key: 'assignee_user_id',
            type: 'account',
            editable_when_status: ['已中标'],
        };

        expect(fieldEditableForRecord(field, { payload: { status: '跟踪中' } })).toBe(false);
        expect(fieldEditableForRecord(field, { payload: { status: '已中标' } })).toBe(true);
        expect(fieldEditableForRecord({ key: 'name', type: 'text' }, { payload: { status: '跟踪中' } })).toBe(true);
    });
});

describe('ObjectGrid informed projects', () => {
    beforeEach(() => {
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
        globalThis.fetch = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('labels an informed project and keeps its cells and edit action read-only', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '业务项目' }}
                records={[{
                    id: 'project-informed',
                    code: 'PRJ-001',
                    title: '知会测试项目',
                    payload: { name: '知会测试项目' },
                    display: { name: '知会测试项目' },
                    can_update: false,
                    is_informed_project: true,
                }]}
                fields={[{ key: 'name', label: '项目名称', type: 'text' }]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByText('知会项目')).not.toBeNull();
        expect(screen.queryByLabelText('编辑 PRJ-001')).toBeNull();

        const nameCell = await screen.findByRole('gridcell', { name: /知会测试项目/ });
        fireEvent.doubleClick(nameCell);
        expect(document.querySelector('.grid-inline-editor')).toBeNull();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });
});

describe('ObjectGrid date editing', () => {
    beforeEach(() => {
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                record: {
                    id: 'purchase-1',
                    code: 'CG-001',
                    title: '采购日报',
                    payload: { date: '2026-07-12', requester: '生产' },
                    display: { date: '2026-07-12', requester: '生产' },
                },
            }),
        });
    });

    afterEach(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
        cleanup();
        vi.restoreAllMocks();
    });

    it('commits a changed date as soon as the date control changes', async () => {
        render(
            <ObjectGrid
                object={{ key: 'purchase' }}
                records={[{
                    id: 'purchase-1',
                    code: 'CG-001',
                    title: '采购日报',
                    payload: { date: '2026-07-11', requester: '生产' },
                    display: { date: '2026-07-11', requester: '生产' },
                }]}
                fields={[
                    { key: 'date', label: '日期', type: 'date' },
                    { key: 'requester', label: '发起人', type: 'text' },
                ]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        const dateCell = await screen.findByRole('gridcell', { name: '2026-07-11' });
        fireEvent.click(dateCell);
        fireEvent.keyDown(dateCell, { key: 'Enter', code: 'Enter' });

        const input = await waitFor(() => {
            const control = document.querySelector('input[type="date"]');
            expect(control).not.toBeNull();

            return control;
        });
        fireEvent.input(input, { target: { value: '2026-07-12' } });

        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledTimes(1));
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body).payload.date).toBe('2026-07-12');
    });

    it('updates one item by id while sending every sibling item', async () => {
        render(
            <ObjectGrid
                object={{ key: 'purchase', label: '采购日报' }}
                records={[{
                    id: 'purchase-1',
                    code: 'CG-001',
                    title: '采购日报',
                    payload: {
                        date: '2026-07-11',
                        items: [
                            { id: 'item-1', spec: '10mm', qty: 1 },
                            { id: 'item-2', spec: '12mm', qty: 2 },
                        ],
                    },
                    display: { date: '2026-07-11' },
                }]}
                fields={[
                    { key: 'date', label: '日期', type: 'date' },
                    { key: 'spec', label: '规格', type: 'text', scope: 'item' },
                    { key: 'qty', label: '数量', type: 'number', scope: 'item' },
                ]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        const qtyCell = await screen.findByRole('gridcell', { name: '2' });
        fireEvent.click(qtyCell);
        fireEvent.keyDown(qtyCell, { key: 'Enter', code: 'Enter' });
        const input = await waitFor(() => document.querySelector('input[type="number"]'));
        fireEvent.change(input, { target: { value: '9' } });
        fireEvent.keyDown(input, { key: 'Enter', code: 'Enter' });

        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledTimes(1));
        const payload = JSON.parse(globalThis.fetch.mock.calls[0][1].body).payload;
        expect(payload.items).toEqual([
            { id: 'item-1', spec: '10mm', qty: 1 },
            { id: 'item-2', spec: '12mm', qty: '9' },
        ]);
    });

    it('filters inline customer contact choices by the records customer', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[{
                    id: 'project-1',
                    code: 'PRJ-001',
                    title: '甲项目',
                    payload: {
                        customer_id: 'customer-a',
                        customer_contact_ids: ['contact-a'],
                    },
                    display: { customer_contact_ids: ['甲联系人'] },
                }]}
                fields={[
                    { key: 'customer_contact_ids', label: '客户联系人', type: 'multirelation' },
                ]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{
                    customer_contact_ids: {
                        items: [
                            { id: 'contact-a', label: '甲联系人', meta: { customer_id: 'customer-a' } },
                            { id: 'contact-b', label: '乙联系人', meta: { customer_id: 'customer-b' } },
                        ],
                    },
                }}
                onRecordChange={() => {}}
            />,
        );

        const contactCell = await screen.findByRole('gridcell', { name: '甲联系人' });
        fireEvent.click(contactCell);
        fireEvent.keyDown(contactCell, { key: 'Enter', code: 'Enter' });
        const trigger = await waitFor(() => document.querySelector('.multi-combo .combo-trigger'));
        fireEvent.click(trigger);

        expect(screen.getAllByText('甲联系人').length).toBeGreaterThan(0);
        expect(screen.queryByText('乙联系人')).toBeNull();
    });

    it('commits a quick customer contact selection from the portal menu', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[{
                    id: 'project-1',
                    code: 'PRJ-001',
                    title: '甲项目',
                    payload: {
                        customer_id: 'customer-a',
                        customer_contact_ids: [],
                    },
                    display: { customer_contact_ids: [] },
                }]}
                fields={[
                    { key: 'customer_contact_ids', label: '客户联系人', type: 'multirelation' },
                ]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{
                    customer_contact_ids: {
                        items: [
                            { id: 'contact-a', label: '李经理 · 13900000000', meta: { customer_id: 'customer-a' } },
                        ],
                    },
                }}
                onRecordChange={() => {}}
            />,
        );

        const contactCell = await screen.findByRole('gridcell', { name: '—' });
        fireEvent.click(contactCell);
        fireEvent.keyDown(contactCell, { key: 'Enter', code: 'Enter' });
        fireEvent.click(await screen.findByRole('button', { name: '李经理 · 13900000000' }));
        fireEvent.click(screen.getByRole('button', { name: '完成选择' }));

        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledOnce());
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body).payload.customer_contact_ids).toEqual(['contact-a']);
    });

    it('shows only the customer name in the project table while preserving other relation labels', async () => {
        const record = {
            id: 'project-1',
            code: 'PRJ-001',
            title: '甲项目',
            payload: { customer_id: 'customer-1' },
            display: { customer_id: 'CUST-001 · 甲客户' },
        };
        const field = { key: 'customer_id', label: '客户名称', type: 'relation', target: 'customer' };
        const commonProps = {
            records: [record],
            fields: [field],
            can: { update: true, delete: false },
            selectedRecordId: null,
            relationOptions: { customer_id: { items: [{ id: 'customer-1', label: 'CUST-001 · 甲客户' }] } },
            onRecordChange: () => {},
        };

        const { unmount } = render(<ObjectGrid object={{ key: 'project', label: '业务项目' }} {...commonProps} />);

        expect(await screen.findByRole('gridcell', { name: '甲客户' })).not.toBeNull();
        expect(screen.queryByText('CUST-001 · 甲客户')).toBeNull();

        unmount();
        render(<ObjectGrid object={{ key: 'contract', label: '合同表' }} {...commonProps} />);

        expect(await screen.findByRole('gridcell', { name: 'CUST-001 · 甲客户' })).not.toBeNull();
    });

    it('does not edit a relation explicitly marked readonly', async () => {
        render(
            <ObjectGrid
                object={{ key: 'teardown', label: '拆解表' }}
                records={[{
                    id: 'teardown-1',
                    code: 'CJ-001',
                    title: '拆解记录',
                    payload: { project_id: 'project-1' },
                    display: { project_id: 'PRJ-001 · 甲项目' },
                }]}
                fields={[
                    { key: 'project_id', label: '项目名称', type: 'relation', target: 'project', readonly: true },
                ]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{ project_id: { items: [{ id: 'project-1', label: 'PRJ-001 · 甲项目' }] } }}
                onRecordChange={() => {}}
            />,
        );

        const projectCell = await screen.findByRole('gridcell', { name: 'PRJ-001 · 甲项目' });
        fireEvent.click(projectCell);
        fireEvent.keyDown(projectCell, { key: 'Enter', code: 'Enter' });

        expect(document.querySelector('.grid-combo-editor')).toBeNull();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('keeps one primary row action and moves editing into the overflow menu', async () => {
        render(
            <ObjectGrid
                object={{ key: 'purchase', label: '采购日报' }}
                records={[{
                    id: 'purchase-1',
                    code: 'CG-001',
                    title: '采购日报',
                    payload: {},
                    display: {},
                }]}
                fields={[]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByRole('link', { name: '查看 CG-001 详情' })).not.toBeNull();
        expect(screen.getByLabelText('CG-001 更多操作')).not.toBeNull();
    });

    it('inserts the one-line contact summary immediately after the customer name', async () => {
        const onContactOpen = vi.fn();
        const onContactCreate = vi.fn();

        render(
            <ObjectGrid
                object={{ key: 'customer', label: '客户信息' }}
                records={[{
                    id: 'customer-1',
                    code: 'CUST-001',
                    title: '甲客户',
                    payload: { customer_no: 'CUST-001', name: '甲客户', address: '新区' },
                    display: { customer_no: 'CUST-001', name: '甲客户', address: '新区' },
                    contacts: [{ id: 'contact-1', name: '李经理', phone: '13900000000' }],
                }]}
                fields={[
                    { key: 'customer_no', label: '客户编号', type: 'readonly' },
                    { key: 'name', label: '客户名称', type: 'text' },
                    { key: 'address', label: '地址', type: 'text' },
                ]}
                can={{ create: true, update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
                onContactOpen={onContactOpen}
                onContactCreate={onContactCreate}
                canCreateContact
            />,
        );

        const headers = (await screen.findAllByRole('columnheader')).map((header) => header.textContent.trim());
        expect(headers.indexOf('联系人列表')).toBe(headers.indexOf('客户名称') + 1);
        expect(await screen.findByText('李经理 · 13900000000')).not.toBeNull();
        expect(screen.getByRole('button', { name: '为甲客户新增联系人' })).not.toBeNull();
        expect(screen.queryByRole('button', { name: /查看李经理详情/ })).toBeNull();
        expect(Number.parseInt(document.querySelector('.ag-row')?.style.height || '0', 10)).toBe(44);

        fireEvent.click(screen.getByRole('button', { name: '李经理 · 13900000000，共 1 项' }));
        expect(onContactOpen).toHaveBeenCalledWith(expect.objectContaining({ id: 'customer-1' }));

        fireEvent.click(screen.getByRole('button', { name: '为甲客户新增联系人' }));
        expect(onContactCreate).toHaveBeenCalledWith(expect.objectContaining({ id: 'customer-1' }));
    });

    it('keeps the customer row height fixed when the contact count changes', async () => {
        const customer = {
            id: 'customer-1',
            code: 'CUST-001',
            title: '甲客户',
            payload: { name: '甲客户' },
            display: { name: '甲客户' },
            contacts: [{ id: 'contact-1', name: '李经理', phone: '13900000000' }],
        };
        const props = {
            object: { key: 'customer', label: '客户信息' },
            fields: [{ key: 'name', label: '客户名称', type: 'text' }],
            can: { create: true, update: true, delete: false },
            selectedRecordId: null,
            relationOptions: {},
            onRecordChange: () => {},
        };
        const { rerender } = render(<ObjectGrid {...props} records={[customer]} />);

        await waitFor(() => expect(Number.parseInt(document.querySelector('.ag-row')?.style.height || '0', 10)).toBe(44));

        rerender(<ObjectGrid {...props} records={[{
            ...customer,
            contacts: [...customer.contacts, { id: 'contact-2', name: '王工', phone: '13700000000' }],
        }]} />);

        await waitFor(() => expect(Number.parseInt(document.querySelector('.ag-row')?.style.height || '0', 10)).toBe(44));
        expect(await screen.findByText('+1')).not.toBeNull();
    });

    it('keeps an empty grid localized without the AG Grid English default', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[]}
                fields={[]}
                can={{ create: true, update: false, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByText('共 0 个字段，当前可见 0 列，可横向滚动查看全部字段')).not.toBeNull();
        expect(screen.queryByText('No Rows To Show')).toBeNull();
    });

    it('uses the authorized display URL for private attachment links', async () => {
        render(
            <ObjectGrid
                object={{ key: 'drawing', label: '技术图纸' }}
                records={[{
                    id: 'drawing-1',
                    code: 'TZ-001',
                    title: '主梁图',
                    payload: { attachment: 'attachments/private-file.pdf' },
                    display: { attachment: '/attachments/drawing-1/attachment' },
                }]}
                fields={[{ key: 'attachment', label: '附件', type: 'file' }]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect((await screen.findByRole('link', { name: '查看附件' })).getAttribute('href'))
            .toBe('/attachments/drawing-1/attachment');
    });

    it('renders an item relation column when an existing row has no relation value or snapshot', async () => {
        expect(() => render(
            <ObjectGrid
                object={{ key: 'outbound', label: '生产领料出库单' }}
                records={[{
                    id: 'outbound-1',
                    code: 'CK-001',
                    title: '生产领料出库单',
                    payload: { items: [{ id: 'item-1', actual_weight: 10 }] },
                    display: {},
                }]}
                fields={[
                    { key: 'material_id', label: '物资名称', type: 'relation', target: 'material', scope: 'item' },
                    { key: 'actual_weight', label: '实发重量', type: 'number', scope: 'item' },
                ]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{ material_id: { items: [] } }}
                onRecordChange={() => {}}
            />,
        )).not.toThrow();

        expect(await screen.findByRole('gridcell', { name: '10' })).not.toBeNull();
    });

    it('shows a new task badge for an unseen workflow record', async () => {
        render(
            <ObjectGrid
                object={{ key: 'drawing', label: '技术图纸与方案' }}
                records={[{
                    id: 'drawing-new-task',
                    code: 'TZ-001',
                    title: '新图纸任务',
                    payload: { name: '新图纸任务' },
                    display: { name: '新图纸任务' },
                    is_new_task: true,
                }]}
                fields={[{ key: 'name', label: '图纸名称', type: 'text' }]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByText('新任务')).not.toBeNull();
    });

    it('sizes a column from every row on the current page and uses the long-text cap', async () => {
        const records = Array.from({ length: 31 }, (_, index) => ({
            id: `record-${index}`,
            code: `CODE-${index}`,
            title: `记录 ${index}`,
            payload: {
                name: index === 30 ? '这是第31行中用于验证完整当前页列宽计算的超长中文单元格内容' : '短内容',
                remark: '这是一段用于验证备注字段能够使用更大最大宽度的超长中文备注内容',
            },
            display: {},
        }));

        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={records}
                fields={[
                    { key: 'name', label: '项目名称', type: 'text' },
                    { key: 'remark', label: '备注', type: 'text' },
                ]}
                can={{ update: false, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        await screen.findByRole('columnheader', { name: '项目名称' });
        const nameWidth = Number.parseInt(document.querySelector('.ag-header-cell[col-id="name"]')?.style.width || '0', 10);
        const remarkWidth = Number.parseInt(document.querySelector('.ag-header-cell[col-id="remark"]')?.style.width || '0', 10);

        expect(nameWidth).toBe(320);
        expect(remarkWidth).toBe(420);
    });

    it('starts with every field visible for horizontal review', async () => {
        const fields = Array.from({ length: 10 }, (_, index) => ({
            key: `field_${index + 1}`,
            label: `字段 ${index + 1}`,
            type: 'text',
        }));

        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[]}
                fields={fields}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByText(/共 10 个字段，当前可见 \d+ 列，可横向滚动查看全部字段/)).not.toBeNull();
        expect(await screen.findByRole('columnheader', { name: '字段 10' })).not.toBeNull();
    });

    it('exposes the complete column name from a truncated header', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[]}
                fields={[{ key: 'contract_delivery_status', label: '合同交付与履约状态', type: 'text' }]}
                can={{ update: false, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                savedColumnWidths={{ contract_delivery_status: 160 }}
                onRecordChange={() => {}}
            />,
        );

        await screen.findByRole('columnheader', { name: '合同交付与履约状态' });
        expect(document.querySelector('.ag-header-cell[col-id="contract_delivery_status"] .grid-header-label')?.getAttribute('title'))
            .toBe('合同交付与履约状态');
    });

    it('applies saved user widths before adaptive defaults', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[]}
                fields={[{ key: 'name', label: '项目名称', type: 'text' }]}
                can={{ update: false, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                savedColumnWidths={{ name: 286 }}
                onRecordChange={() => {}}
            />,
        );

        await screen.findByRole('columnheader', { name: '项目名称' });
        expect(document.querySelector('.ag-header-cell[col-id="name"]')?.style.width).toBe('286px');
    });

    it('shows zero and a visible empty marker without treating them as the same value', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[{
                    id: 'project-1',
                    code: 'PRJ-001',
                    title: '甲项目',
                    payload: { progress: 0, remark: '' },
                    display: {},
                }]}
                fields={[
                    { key: 'progress', label: '进度', type: 'number' },
                    { key: 'remark', label: '备注', type: 'text' },
                ]}
                can={{ update: false, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect(await screen.findByRole('gridcell', { name: '0.00' })).not.toBeNull();
        expect((await screen.findByText('—')).classList.contains('empty-value')).toBe(true);
        expect(Number.parseInt(document.querySelector('.ag-row')?.style.height || '0', 10)).toBe(44);
    });

    it('uses explicit text and accessible names for row actions', async () => {
        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目主档' }}
                records={[{
                    id: 'project-1',
                    code: 'PRJ-001',
                    title: '甲项目',
                    payload: { name: '甲项目' },
                    display: { name: '甲项目' },
                }]}
                fields={[{ key: 'name', label: '项目名称', type: 'text' }]}
                can={{ update: true, delete: true }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        expect((await screen.findByRole('link', { name: '查看 PRJ-001 详情' })).textContent).toContain('查看');
        expect(screen.getByRole('button', { name: 'PRJ-001 更多操作' })).not.toBeNull();
    });

    it('shows a referenced project deletion failure in a dialog', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({
                errors: {
                    record: ['无法删除：合同台账仍在引用该项目，请先解除关联。'],
                },
            }),
        });
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        render(
            <ObjectGrid
                object={{ key: 'project', label: '项目资料' }}
                records={[{
                    id: 'project-1',
                    code: 'PRJ-001',
                    title: '甲项目',
                    payload: { name: '甲项目' },
                    display: { name: '甲项目' },
                }]}
                fields={[{ key: 'name', label: '项目名称', type: 'text' }]}
                can={{ update: true, delete: true }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        fireEvent.click(await screen.findByRole('button', { name: 'PRJ-001 更多操作' }));
        fireEvent.click(await screen.findByRole('button', { name: '删除 PRJ-001' }));

        const dialog = await screen.findByRole('alertdialog', { name: '无法删除项目资料' });
        expect(screen.getByText('无法删除：合同台账仍在引用该项目，请先解除关联。')).not.toBeNull();
        expect(dialog).not.toBeNull();
    });

    it('renders customer cooperation history from linked projects and keeps it readonly', async () => {
        render(
            <ObjectGrid
                object={{ key: 'customer', label: '客户信息' }}
                records={[{
                    id: 'customer-1',
                    code: 'CUST-001',
                    title: '甲客户',
                    payload: { cooperation_history: '旧文本' },
                    display: { cooperation_history: '旧文本' },
                    cooperation_projects: [{
                        id: 'project-1',
                        code: 'XYC-001',
                        title: '北区项目',
                        date: '2026-07-30',
                    }],
                }]}
                fields={[{ key: 'cooperation_history', label: '合作历史', type: 'text' }]}
                can={{ update: true, delete: false }}
                selectedRecordId={null}
                relationOptions={{}}
                onRecordChange={() => {}}
            />,
        );

        const historyCell = await screen.findByRole('gridcell', { name: 'XYC-001 · 北区项目 · 2026-07-30' });
        fireEvent.click(historyCell);
        fireEvent.keyDown(historyCell, { key: 'Enter', code: 'Enter' });

        expect(document.querySelector('.grid-inline-editor')).toBeNull();
        expect(screen.queryByText('旧文本')).toBeNull();
    });

});
