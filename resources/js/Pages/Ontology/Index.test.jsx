// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { columnOrderStorageKey, columnWidthStorageKey } from '../../Components/objectGridColumnState';
import Index, { objectListHref, recordListHrefForObject } from './Index';

const inertia = vi.hoisted(() => ({
    userId: 42,
    permissions: [
        'object.customer_contact.create',
        'object.customer_contact.update',
        'object.customer_contact.delete',
    ],
    post: vi.fn(),
    put: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

const storedValues = new Map();
Object.defineProperty(window, 'localStorage', {
    configurable: true,
    value: {
        clear: () => storedValues.clear(),
        getItem: (key) => storedValues.get(key) ?? null,
        removeItem: (key) => storedValues.delete(key),
        setItem: (key, value) => storedValues.set(key, String(value)),
    },
});

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: ({ children, preserveScroll: _preserveScroll, ...props }) => <a {...props}>{children}</a>,
        router: { reload: inertia.reload, visit: inertia.visit },
        usePage: () => ({ props: { auth: { user: { id: inertia.userId }, permissions: inertia.permissions } } }),
        useForm: (initial) => {
            const [data, setFormData] = React.useState(initial);
            const transformer = React.useRef((current) => current);
            const hasTransformer = React.useRef(false);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key, value) => setFormData((current) => ({ ...current, [key]: value })),
                post: (url, options) => hasTransformer.current
                    ? inertia.post(url, transformer.current(data), options)
                    : inertia.post(url, options),
                put: (url, options) => inertia.put(url, transformer.current(data), options),
                transform: (callback) => {
                    transformer.current = callback;
                    hasTransformer.current = true;
                },
            };
        },
    };
});

vi.mock('../../Components/Layout', () => ({
    default: ({ children, immersive = false, title, eyebrow, hideHeader = false }) => (
        <main data-immersive={String(immersive)} data-hide-header={String(hideHeader)}>
            {!hideHeader && <p>{eyebrow}</p>}
            {!hideHeader && <h1>{title}</h1>}
            {children}
        </main>
    ),
}));

vi.mock('../../Components/ObjectGrid', () => ({
    default: ({ object, records, fields, savedColumnWidths, columnOrderLocked, onColumnOrderChange, onColumnWidthsChange, onContactOpen, onContactCreate, canCreateContact, exportUrl }) => (
        <div>
            <div data-testid="grid-order">{fields.map((field) => field.key).join('|')}</div>
            <div data-testid="grid-widths">{JSON.stringify(savedColumnWidths)}</div>
            <div data-testid="grid-order-locked">{String(columnOrderLocked)}</div>
            <a data-testid="server-export" href={exportUrl}>服务端导出</a>
            <button type="button" onClick={() => onColumnOrderChange?.(['note', 'deleted_field', 'phone', 'note'])}>模拟拖动列</button>
            <button type="button" onClick={() => onColumnWidthsChange?.({ phone: 214, note: 320 })}>模拟调整列宽</button>
            {object.key === 'customer' && records[0] && (
                <>
                    <button type="button" onClick={() => onContactOpen?.(records[0])}>模拟打开联系人</button>
                    {canCreateContact && <button type="button" onClick={() => onContactCreate?.(records[0])}>模拟直接新增联系人</button>}
                </>
            )}
        </div>
    ),
}));

const fields = [
    { key: 'name', label: '姓名', type: 'text' },
    { key: 'position', label: '职务', type: 'text' },
    { key: 'phone', label: '电话', type: 'text' },
    { key: 'note', label: '备注', type: 'text' },
];

const record = {
    id: 'record-1',
    code: 'REC-001',
    title: '客户记录',
    payload: { name: '张三', position: '采购经理', phone: '13800000000', note: '重点联系人' },
    display: {},
};

describe('Ontology workspace layout', () => {
    afterEach(cleanup);

    it('hides the page header and limits tabs to the active business module', () => {
        window.history.replaceState({}, '', '/objects/project');

        const { container } = render(
            <Index
                objects={[
                    { id: 2, key: 'customer', label: '客户信息', group: '主数据' },
                    { id: 3, key: 'project', label: '项目主档', group: '主数据' },
                    { id: 4, key: 'contract', label: '合同台账', group: '履约' },
                ]}
                contactObject={{ id: 5, key: 'customer_contact', label: '客户联系人' }}
                currentObject={{
                    id: 3,
                    key: 'project',
                    group: '主数据',
                    label: '项目主档',
                    fields: [],
                }}
                records={{ data: [] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{}}
                selectedRecordId={null}
            />,
        );

        expect(container.querySelector('main')).toHaveAttribute('data-immersive', 'false');
        expect(container.querySelector('main')).toHaveAttribute('data-hide-header', 'true');
        expect(screen.queryByRole('heading', { name: '项目资料' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: '客户信息' })).toHaveAttribute('href', '/objects/customer');
        expect(screen.getByRole('link', { name: '项目资料' })).toHaveAttribute('href', '/objects/project');
        expect(screen.queryByRole('link', { name: '客户联系人' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: '合同台账' })).not.toBeInTheDocument();
        expect(screen.getByText('基础资料 · 2 张表')).toBeInTheDocument();
        expect(screen.getByLabelText('业务模块')).toHaveValue('主数据');
        expect(screen.queryByText('数据列表')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: '新建' })).toHaveAttribute('href', '/objects/project?mode=create');
    });

    it('shows the business summary beside customer and project without a create action', () => {
        window.history.replaceState({}, '', '/objects/project_business_summary');

        render(
            <Index
                objects={[
                    { id: 2, key: 'customer', label: '客户信息', group: '主数据' },
                    { id: 3, key: 'project', label: '项目主档', group: '主数据' },
                    { id: 4, key: 'project_business_summary', label: '业务概括表', group: '主数据' },
                ]}
                currentObject={{
                    id: 4,
                    key: 'project_business_summary',
                    group: '主数据',
                    label: '业务概括表',
                    read_only: true,
                    fields: [],
                }}
                records={{ data: [] }}
                can={{ create: false, update: false, delete: false }}
                relationOptions={{}}
                selectedRecordId={null}
            />,
        );

        expect(screen.getByRole('link', { name: '客户信息' })).toHaveAttribute('href', '/objects/customer');
        expect(screen.getByRole('link', { name: '项目资料' })).toHaveAttribute('href', '/objects/project');
        expect(screen.getByRole('link', { name: '业务概括表' })).toHaveAttribute('href', '/objects/project_business_summary');
        expect(screen.getByText('基础资料 · 3 张表')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: '新建' })).not.toBeInTheDocument();
    });
});

describe('Ontology multi-condition filters', () => {
    afterEach(cleanup);

    it('restores URL-backed OR filters and allows adding another typed condition', () => {
        window.history.replaceState({}, '', '/objects/project?filter_logic=or&filters%5B0%5D%5Bfield%5D=overall_status&filters%5B0%5D%5Boperator%5D=equals&filters%5B0%5D%5Bvalue%5D=%E5%B7%B2%E4%B8%AD%E6%A0%87');
        render(<Index
            objects={[{ id: 3, key: 'project', label: '业务项目', group: '业务与合同' }]}
            currentObject={{ id: 3, key: 'project', label: '业务项目', group: '业务与合同', fields: [
                { key: 'overall_status', label: '总体状态', type: 'select', options: ['投标中', '已中标'] },
                { key: 'contract_amount', label: '合同金额', type: 'number' },
            ] }}
            records={{ data: [], per_page: 50 }}
            can={{ create: false, update: true, delete: false }}
            relationOptions={{}}
        />);

        expect(screen.queryByRole('dialog', { name: '设置筛选条件' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /1 筛选/ }));
        expect(screen.getByRole('dialog', { name: '设置筛选条件' })).toBeInTheDocument();
        expect(screen.getByDisplayValue('任一满足（OR）')).toBeInTheDocument();
        expect(screen.getByDisplayValue('已中标')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /添加条件/ }));
        expect(screen.getAllByLabelText(/筛选字段/)).toHaveLength(2);
    });

    it('groups search, sorting, filters and record actions in one toolbar row', () => {
        window.history.replaceState({}, '', '/objects/project');
        const { container } = render(<Index
            objects={[{ id: 3, key: 'project', label: '业务项目', group: '业务与合同' }]}
            currentObject={{ id: 3, key: 'project', label: '业务项目', group: '业务与合同', fields: [
                { key: 'overall_status', label: '总体状态', type: 'select', options: ['投标中', '已中标'] },
            ] }}
            records={{ data: [], per_page: 50 }}
            can={{ create: true, update: true, delete: false }}
            relationOptions={{}}
        />);

        const primaryRow = container.querySelector('.object-list-primary');
        expect(primaryRow).toContainElement(screen.getByRole('searchbox', { name: '搜索记录' }));
        expect(primaryRow).toContainElement(screen.getByRole('combobox', { name: '排序字段' }));
        expect(within(screen.getByRole('combobox', { name: '排序字段' })).getByRole('option', { name: '默认（项目名称）' })).toBeInTheDocument();
        expect(primaryRow).toContainElement(screen.getByRole('button', { name: /应用/ }));
        expect(primaryRow).toContainElement(screen.getByRole('button', { name: '筛选' }));
        expect(primaryRow).toContainElement(screen.getByRole('link', { name: /导出/ }));
        expect(primaryRow).toContainElement(screen.getByRole('link', { name: /新建/ }));
        expect(screen.queryByRole('dialog', { name: '设置筛选条件' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '筛选' }));

        const dialog = screen.getByRole('dialog', { name: '设置筛选条件' });
        expect(dialog).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: /添加条件/ })).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: '应用筛选' })).toBeInTheDocument();
    });

    it('keeps the existing default sorting label for non-project objects', () => {
        window.history.replaceState({}, '', '/objects/contract');
        render(<Index
            objects={[{ id: 4, key: 'contract', label: '合同表', group: '业务与合同' }]}
            currentObject={{ id: 4, key: 'contract', label: '合同表', group: '业务与合同', fields: [] }}
            records={{ data: [], per_page: 50 }}
            can={{ create: false, update: false, delete: false }}
            relationOptions={{}}
        />);

        const selector = screen.getByRole('combobox', { name: '排序字段' });

        expect(within(selector).getByRole('option', { name: '默认（最近更新）' })).toBeInTheDocument();
        expect(within(selector).queryByRole('option', { name: '默认（项目名称）' })).not.toBeInTheDocument();
    });

    it('offers page sizes from 10 to 100 in increments of 10', () => {
        window.history.replaceState({}, '', '/objects/project?per_page=30');
        render(<Index
            objects={[{ id: 3, key: 'project', label: '业务项目', group: '业务与合同' }]}
            currentObject={{ id: 3, key: 'project', label: '业务项目', group: '业务与合同', fields: [] }}
            records={{ data: [], per_page: 30 }}
            can={{ create: false, update: false, delete: false }}
            relationOptions={{}}
        />);

        const selector = screen.getByRole('combobox', { name: '每页条数' });

        expect(selector).toHaveValue('30');
        expect(within(selector).getAllByRole('option').map((option) => option.value))
            .toEqual(['10', '20', '30', '40', '50', '60', '70', '80', '90', '100']);
    });
});

describe('Ontology personal field order', () => {
    beforeEach(() => {
        window.localStorage.clear();
        window.localStorage.setItem(
            columnOrderStorageKey(inertia.userId, 'customer'),
            JSON.stringify(['phone', 'deleted_field', 'name']),
        );
        window.localStorage.setItem(
            columnWidthStorageKey(inertia.userId, 'customer'),
            JSON.stringify({ phone: 188 }),
        );
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    for (const mode of ['create', 'edit', 'detail']) {
        it(`uses the saved table field order in ${mode}`, async () => {
            renderPage(mode);

            expect(await screen.findByTestId('grid-order')).toHaveTextContent('phone|name|position|note');
            const dialog = screen.getByRole('dialog');

            if (mode === 'detail') {
                expect([...dialog.querySelectorAll('.detail-grid > div > span')].map((node) => node.textContent)).toEqual([
                    '编号',
                    '标题',
                    '电话',
                    '姓名',
                    '职务',
                    '备注',
                ]);
            } else {
                expect([...dialog.querySelectorAll('form label > span')].map((node) => node.textContent)).toEqual([
                    '电话',
                    '姓名',
                    '职务',
                    '备注',
                ]);
            }
        });
    }

    it('persists a normalized order after the user drags a column', async () => {
        renderPage('create');

        fireEvent.click(await screen.findByRole('button', { name: '模拟拖动列' }));

        await waitFor(() => expect(JSON.parse(window.localStorage.getItem(
            columnOrderStorageKey(inertia.userId, 'customer'),
        ))).toEqual(['note', 'phone', 'name', 'position']));
        expect(screen.getByTestId('grid-order')).toHaveTextContent('note|phone|name|position');
    });

    it('ignores historical personal order and locks the project table to configured field order', async () => {
        const projectStorageKey = columnOrderStorageKey(inertia.userId, 'project');
        const historicalOrder = JSON.stringify(['note', 'name', 'phone', 'position']);
        window.localStorage.setItem(projectStorageKey, historicalOrder);
        window.history.replaceState({}, '', '/objects/project');

        render(
            <Index
                currentObject={{ id: 3, key: 'project', group: '业务与合同', label: '业务项目', fields }}
                objects={[]}
                records={{ data: [] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{}}
                selectedRecordId={null}
            />,
        );

        expect(await screen.findByTestId('grid-order')).toHaveTextContent('name|position|phone|note');
        expect(screen.getByTestId('grid-order-locked')).toHaveTextContent('true');

        fireEvent.click(screen.getByRole('button', { name: '模拟拖动列' }));

        expect(window.localStorage.getItem(projectStorageKey)).toBe(historicalOrder);
        expect(screen.getByTestId('grid-order')).toHaveTextContent('name|position|phone|note');
    });

    it('restores and persists per-user widths after the user resizes a column', async () => {
        renderPage('create');

        expect(await screen.findByTestId('grid-widths')).toHaveTextContent('{"phone":188}');
        fireEvent.click(screen.getByRole('button', { name: '模拟调整列宽' }));

        await waitFor(() => expect(JSON.parse(window.localStorage.getItem(
            columnWidthStorageKey(inertia.userId, 'customer'),
        ))).toEqual({ phone: 214, note: 320 }));
        expect(screen.getByTestId('grid-widths')).toHaveTextContent('{"phone":214,"note":320}');
    });
});

describe('customer contact detail list', () => {
    beforeEach(() => window.localStorage.clear());
    afterEach(cleanup);

    it('opens the contact list and then a unified contact detail from the customer grid', async () => {
        window.history.replaceState({}, '', '/objects/customer');
        renderPage(null, {
            ...record,
            contacts: [{
                id: 'contact-1',
                name: '李经理',
                phone: '13900000000',
                position: '采购负责人',
                status: '启用',
                projects: [{ id: 'project-1', title: '北区项目', code: 'PRJ-001' }],
            }],
        });

        fireEvent.click(await screen.findByRole('button', { name: '模拟打开联系人' }));

        const listDialog = await screen.findByRole('dialog', { name: '客户联系人' });
        expect(within(listDialog).getByText('共 1 位联系人')).toBeInTheDocument();
        fireEvent.click(within(listDialog).getByRole('button', { name: '查看 李经理 详情' }));

        const dialog = await screen.findByRole('dialog', { name: '联系人详情' });
        expect(within(dialog).getByText('李经理')).toBeInTheDocument();
        expect(within(dialog).getByText('13900000000')).toBeInTheDocument();
        expect(within(dialog).getByText('北区项目')).toBeInTheDocument();
        expect(within(dialog).queryByText('PRJ-001')).not.toBeInTheDocument();
        expect(within(dialog).queryByText('采购负责人')).not.toBeInTheDocument();
        expect(within(dialog).queryByText('启用')).not.toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: '删除联系人' })).toBeInTheDocument();
    });

    it('opens contact creation from the contact list', async () => {
        window.history.replaceState({}, '', '/objects/customer');
        renderPage(null, { ...record, contacts: [] });

        fireEvent.click(await screen.findByRole('button', { name: '模拟打开联系人' }));
        const listDialog = await screen.findByRole('dialog', { name: '客户联系人' });
        fireEvent.click(within(listDialog).getByRole('button', { name: '新增联系人' }));

        const dialog = await screen.findByRole('dialog', { name: '新增联系人' });
        expect(within(dialog).getByLabelText('联系人姓名')).toBeInTheDocument();
        expect(within(dialog).getByLabelText('联系电话')).toBeInTheDocument();
        expect(within(dialog).queryByText('职务')).not.toBeInTheDocument();
    });

    it('opens contact creation directly from the customer grid', async () => {
        window.history.replaceState({}, '', '/objects/customer');
        renderPage(null, { ...record, contacts: [] });

        fireEvent.click(await screen.findByRole('button', { name: '模拟直接新增联系人' }));

        const dialog = await screen.findByRole('dialog', { name: '新增联系人' });
        expect(within(dialog).getByText('所属客户')).toBeInTheDocument();
        expect(within(dialog).getByText('客户记录')).toBeInTheDocument();
        expect(within(dialog).getByLabelText('联系人姓名')).toBeEnabled();
    });
});

describe('project customer contact choices', () => {
    beforeEach(() => window.localStorage.clear());
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('keeps the form intact and only submits after customer conflicts are confirmed', async () => {
        let resolveRequest;
        const request = new Promise((resolve) => {
            resolveRequest = resolve;
        });
        vi.spyOn(globalThis, 'fetch').mockReturnValue(request);
        window.history.replaceState({}, '', '/objects/project?mode=create');
        const { container } = render(
            <Index
                currentObject={{
                    id: 3,
                    key: 'project',
                    group: '业务',
                    label: '项目主档',
                    fields: [{ key: 'name', label: '项目名称', type: 'text' }],
                }}
                records={{ data: [] }}
                can={{ create: true, update: true, delete: true, manage_customers: true }}
                relationOptions={{}}
                selectedRecordId={null}
            />,
        );

        fireEvent.change(screen.getByLabelText('项目名称'), { target: { value: '演示项目' } });
        fireEvent.change(screen.getByLabelText('客户名称*'), { target: { value: '演示客户' } });
        fireEvent.change(screen.getByLabelText('客户地址'), { target: { value: '演示地址' } });

        const projectSubmit = container.querySelector('.modal-body > form button[type="submit"]');
        fireEvent.click(projectSubmit);
        expect(projectSubmit).toBeDisabled();

        resolveRequest({
            ok: true,
            json: async () => ({
                customer: { id: 'customer-1', name: '演示客户', address: '演示地址' },
                conflicts: [{ field: 'level', label: '客户等级', current: 'B', submitted: 'A' }],
            }),
        });

        const conflictDialog = await screen.findByRole('dialog', { name: '客户资料冲突' });
        expect(inertia.post).not.toHaveBeenCalled();
        expect(screen.getByPlaceholderText('搜索已有客户，或直接输入新客户名称')).toHaveValue('演示客户');
        fireEvent.click(within(conflictDialog).getByRole('button', { name: '确认覆盖并保存' }));

        await waitFor(() => expect(inertia.post).toHaveBeenCalledWith(
            '/objects/3',
            expect.objectContaining({
                payload: expect.objectContaining({
                    name: '演示项目',
                    customer_profile: expect.objectContaining({
                        name: '演示客户',
                        address: '演示地址',
                        overwrite_confirmed: true,
                    }),
                }),
            }),
            expect.objectContaining({ preserveScroll: true }),
        ));
    });

    it('shows no contact candidates before a customer is selected', async () => {
        window.history.replaceState({}, '', '/objects/project?mode=create');
        render(
            <Index
                currentObject={{
                    id: 3,
                    key: 'project',
                    group: '业务',
                    label: '项目主档',
                    fields: [
                        { key: 'customer_id', label: '客户', type: 'relation' },
                        { key: 'customer_contact_ids', label: '客户联系人', type: 'multirelation' },
                    ],
                }}
                records={{ data: [] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{
                    customer_id: { items: [{ id: 'customer-a', label: '甲客户' }] },
                    customer_contact_ids: {
                        items: [{ id: 'contact-a', label: '甲联系人', meta: { customer_id: 'customer-a' } }],
                    },
                }}
                selectedRecordId={null}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        const contactLabel = [...dialog.querySelectorAll('label')]
            .find((label) => label.querySelector(':scope > span')?.textContent === '客户联系人');
        fireEvent.click(within(contactLabel).getByRole('button'));

        expect(screen.getByText('没有可选项')).toBeInTheDocument();
        expect(screen.queryByText('甲联系人')).not.toBeInTheDocument();
    });

    it('shows an immediate notice when changing customer clears selected contacts', async () => {
        const project = {
            id: 'project-1',
            code: 'PRJ-001',
            title: '甲项目',
            payload: {
                customer_id: 'customer-a',
                customer_contact_ids: ['contact-a'],
            },
            display: {
                customer_id: '甲客户',
                customer_contact_ids: ['甲联系人'],
            },
        };
        window.history.replaceState({}, '', '/objects/project?q=%E7%94%B2&sort=name&direction=asc&filter_logic=and&filters%5B0%5D%5Bfield%5D=overall_status&filters%5B0%5D%5Boperator%5D=equals&filters%5B0%5D%5Bvalue%5D=%E5%B7%B2%E4%B8%AD%E6%A0%87&page=2&mode=edit&record=project-1');
        render(
            <Index
                currentObject={{
                    id: 3,
                    key: 'project',
                    group: '业务',
                    label: '项目主档',
                    fields: [
                        { key: 'customer_id', label: '客户', type: 'relation' },
                        { key: 'customer_contact_ids', label: '客户联系人', type: 'multirelation' },
                    ],
                }}
                records={{ data: [project] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{
                    customer_id: {
                        items: [
                            { id: 'customer-a', label: '甲客户' },
                            { id: 'customer-b', label: '乙客户' },
                        ],
                    },
                    customer_contact_ids: {
                        items: [
                            { id: 'contact-a', label: '甲联系人', meta: { customer_id: 'customer-a' } },
                            { id: 'contact-b', label: '乙联系人', meta: { customer_id: 'customer-b' } },
                        ],
                    },
                }}
                selectedRecordId={project.id}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        const customerLabel = [...dialog.querySelectorAll('label')]
            .find((label) => label.querySelector(':scope > span')?.textContent === '客户');
        fireEvent.click(within(customerLabel).getByRole('button'));
        fireEvent.click(await screen.findByRole('button', { name: '乙客户' }));

        expect(within(dialog).getByText('客户已变更，已清除 1 位不属于新客户的联系人。')).toBeInTheDocument();
        const saveButton = within(dialog).getByRole('button', { name: '保存' });
        saveButton.click();
        saveButton.click();

        const returnTo = objectListHref('project', new URLSearchParams(window.location.search));
        expect(recordListHrefForObject('project', new URLSearchParams(window.location.search))).toBe(returnTo);
        expect(recordListHrefForObject('contract', new URLSearchParams(window.location.search))).toBeNull();
        expect(returnTo).toContain('q=%E7%94%B2');
        expect(returnTo).toContain('filters%5B0%5D%5Bfield%5D=overall_status');
        expect(returnTo).toContain('page=2');
        expect(returnTo).not.toContain('mode=edit');
        expect(returnTo).not.toContain('record=project-1');
        expect(inertia.put).toHaveBeenCalledWith(
            `/records/project-1?return_to=${encodeURIComponent(returnTo)}`,
            {
                payload: {
                    customer_id: 'customer-b',
                    customer_contact_ids: [],
                },
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(inertia.put).toHaveBeenCalledTimes(1);
    });

    it('renders project contacts with name and phone only', async () => {
        const project = {
            id: 'project-1',
            code: 'PRJ-001',
            title: '甲项目',
            payload: { customer_contact_ids: ['contact-1'] },
            display: { customer_contact_ids: ['李经理'] },
            contacts: [{
                id: 'contact-1',
                name: '李经理',
                phone: '13900000000',
                position: '项目采购经理',
            }],
        };
        window.history.replaceState({}, '', '/objects/project?mode=detail&record=project-1');
        render(
            <Index
                currentObject={{
                    id: 3,
                    key: 'project',
                    group: '业务',
                    label: '项目主档',
                    fields: [{ key: 'customer_contact_ids', label: '客户联系人', type: 'multirelation' }],
                }}
                records={{ data: [project] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{}}
                selectedRecordId={project.id}
            />,
        );

        const table = await screen.findByRole('table', { name: '项目联系人' });
        expect(within(table).getByText('李经理')).toBeInTheDocument();
        expect(within(table).getByText('13900000000')).toBeInTheDocument();
        expect(within(table).queryByText('项目采购经理')).not.toBeInTheDocument();
    });
});

describe('contact creation defaults', () => {
    beforeEach(() => window.localStorage.clear());
    afterEach(cleanup);

    it('prefills the customer when opened from customer detail', async () => {
        window.history.replaceState({}, '', '/objects/customer_contact?mode=create&customer_id=customer-1');
        render(
            <Index
                currentObject={{
                    id: 9,
                    key: 'customer_contact',
                    group: '主数据',
                    label: '客户联系人',
                    fields: [{ key: 'customer_id', label: '所属客户', type: 'relation' }],
                }}
                records={{ data: [] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{
                    customer_id: { items: [{ id: 'customer-1', label: '甲客户' }] },
                }}
                selectedRecordId={null}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        const customerLabel = [...dialog.querySelectorAll('label')]
            .find((label) => label.querySelector(':scope > span')?.textContent === '所属客户');
        expect(within(customerLabel).getByRole('button')).toHaveTextContent('甲客户');
    });
});

describe('readonly relation fields', () => {
    afterEach(cleanup);

    it('does not render a derived teardown project as an editable form control', async () => {
        window.history.replaceState({}, '', '/objects/teardown?mode=create');
        render(
            <Index
                currentObject={{
                    id: 10,
                    key: 'teardown',
                    group: '履约',
                    label: '拆解表',
                    fields: [
                        { key: 'drawing_id', label: '图纸编号', type: 'relation', target: 'drawing', required: true },
                        { key: 'project_id', label: '项目名称', type: 'relation', target: 'project', readonly: true },
                    ],
                }}
                records={{ data: [] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{ drawing_id: { items: [] }, project_id: { items: [] } }}
                selectedRecordId={null}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        const labels = [...dialog.querySelectorAll('form label > span')].map((node) => node.textContent);
        expect(labels).toContain('图纸编号*');
        expect(labels).not.toContain('项目名称');
    });
});

describe('server pagination and export', () => {
    beforeEach(() => window.localStorage.clear());
    afterEach(cleanup);

    it('renders Chinese pagination while preserving q mode and record outside the current page', async () => {
        const selected = {
            id: 'material-87',
            code: 'NO.087',
            title: '跨页材料',
            payload: { name: '跨页材料' },
            display: {},
        };
        const previousUrl = 'http://localhost/objects/material?q=Q235B&mode=detail&record=material-87&per_page=50&page=1';
        const nextUrl = 'http://localhost/objects/material?q=Q235B&mode=detail&record=material-87&per_page=50&page=3';
        window.history.replaceState({}, '', '/objects/material?q=Q235B&sort=name&direction=asc&mode=detail&record=material-87&per_page=50&page=2');

        render(
            <Index
                currentObject={{
                    id: 2,
                    key: 'material',
                    group: '主数据',
                    label: '材料主库',
                    fields: [{ key: 'name', label: '物资名称', type: 'text' }],
                }}
                records={{
                    data: [],
                    current_page: 2,
                    last_page: 3,
                    per_page: 50,
                    total: 120,
                    from: 51,
                    to: 100,
                    prev_page_url: previousUrl,
                    next_page_url: nextUrl,
                }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{}}
                selectedRecordId={selected.id}
                selectedRecord={selected}
            />,
        );

        expect(await screen.findByRole('dialog', { name: 'NO.087 · 详情' })).toBeInTheDocument();
        expect(screen.getByRole('searchbox', { name: '搜索记录' })).toHaveValue('Q235B');
        expect(screen.getByRole('combobox', { name: '每页条数' })).toHaveValue('50');
        expect(screen.getByRole('combobox', { name: '排序字段' })).toHaveValue('name');
        expect(screen.getByRole('combobox', { name: '排序方向' })).toHaveValue('asc');
        expect(screen.getByText('第 2 / 3 页，共 120 条')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: '上一页' })).toHaveAttribute('href', previousUrl);
        expect(screen.getByRole('link', { name: '下一页' })).toHaveAttribute('href', nextUrl);

        const filterForm = screen.getByRole('search', { name: '业务数据筛选' });
        expect(filterForm.querySelector('input[name="mode"]')).toHaveValue('detail');
        expect(filterForm.querySelector('input[name="record"]')).toHaveValue('material-87');
        expect(screen.getByRole('link', { name: '导出' })).toHaveAttribute(
            'href',
            '/objects/material/export.csv?q=Q235B&sort=name&direction=asc',
        );
    });
});

describe('historical item relation snapshots', () => {
    afterEach(cleanup);

    it('shows the saved material name when the current option is no longer in the first page', async () => {
        const purchase = {
            id: 'purchase-1',
            code: 'CG-001',
            title: '采购日报',
            payload: {
                date: '2026-07-12',
                items: [{
                    id: 'item-1',
                    material_id: 'material-87',
                    qty: 2,
                    _snapshots: {
                        material_id: { id: 'material-87', label: '保存时的钢板名称' },
                    },
                }],
            },
            display: {},
        };
        window.history.replaceState({}, '', '/objects/purchase?mode=detail&record=purchase-1');

        render(
            <Index
                currentObject={{
                    id: 11,
                    key: 'purchase',
                    group: '采购库存',
                    label: '采购日报',
                    fields: [
                        { key: 'date', label: '日期', type: 'date' },
                        { key: 'material_id', label: '材料名称', type: 'relation', scope: 'item' },
                        { key: 'qty', label: '数量', type: 'number', scope: 'item' },
                    ],
                }}
                records={{ data: [purchase] }}
                can={{ create: true, update: true, delete: true }}
                relationOptions={{ material_id: { items: [] } }}
                selectedRecordId={purchase.id}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('保存时的钢板名称')).toBeInTheDocument();
        expect(within(dialog).queryByText('material-87')).not.toBeInTheDocument();
    });
});

describe('customer cooperation history', () => {
    afterEach(cleanup);

    for (const mode of ['detail', 'edit']) {
        it(`shows linked project number, name and time in ${mode} without an editable legacy field`, async () => {
            const customer = {
                id: 'customer-history',
                code: 'CUST-HISTORY',
                title: '合作客户',
                payload: {
                    name: '合作客户',
                    cooperation_history: '旧文本',
                },
                display: {},
                cooperation_projects: [{
                    id: 'project-1',
                    code: 'XYC-001',
                    title: '北区项目',
                    date: '2026-07-30',
                }],
            };
            window.history.replaceState({}, '', `/objects/customer?mode=${mode}&record=${customer.id}`);

            render(
                <Index
                    currentObject={{
                        id: 8,
                        key: 'customer',
                        group: '业务',
                        label: '客户信息',
                        fields: [
                            { key: 'name', label: '客户名称', type: 'text' },
                            { key: 'cooperation_history', label: '合作历史', type: 'text' },
                        ],
                    }}
                    objects={[]}
                    records={{ data: [customer] }}
                    can={{ create: true, update: true, delete: true }}
                    relationOptions={{}}
                    selectedRecordId={customer.id}
                />,
            );

            const dialog = await screen.findByRole('dialog', { name: `${customer.code} · ${mode === 'edit' ? '编辑' : '详情'}` });
            expect(within(dialog).getByText('XYC-001')).toBeInTheDocument();
            expect(within(dialog).getByText('北区项目')).toBeInTheDocument();
            expect(within(dialog).getByText('2026-07-30')).toBeInTheDocument();
            expect(within(dialog).queryByDisplayValue('旧文本')).not.toBeInTheDocument();
        });
    }
});

describe('tender management', () => {
    afterEach(() => {
        cleanup();
        inertia.post.mockReset();
    });

    it('uses minute-precise controls and hides the won status from generic creation', async () => {
        window.history.replaceState({}, '', '/objects/tender?mode=create');
        render(
            <Index
                currentObject={{
                    id: 30,
                    key: 'tender',
                    group: '招投标',
                    label: '招投标信息',
                    fields: [
                        { key: 'name', label: '标的名称', type: 'text', required: true },
                        { key: 'submit_deadline', label: '投标截止时间', type: 'datetime', required: true },
                        { key: 'status', label: '招投标状态', type: 'select', options: ['跟踪中', '已中标'], restricted_options: ['已中标'] },
                    ],
                }}
                objects={[]}
                records={{ data: [] }}
                can={{ create: true, update: true, delete: true, convert: true }}
                relationOptions={{}}
                selectedRecordId={null}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        expect(dialog.querySelector('input[type="datetime-local"]')).toBeInTheDocument();
        expect(within(dialog).queryByRole('option', { name: '已中标' })).not.toBeInTheDocument();
    });

    it('requires a business assignee before submitting the conversion confirmation', async () => {
        const tender = {
            id: 'tender-1',
            code: 'ZB-001',
            title: '厂房标的',
            payload: { name: '厂房标的', status: '已递交' },
            display: {},
        };
        window.history.replaceState({}, '', '/objects/tender?mode=convert&record=tender-1');
        render(
            <Index
                currentObject={{ id: 30, key: 'tender', group: '招投标', label: '招投标信息', fields: [] }}
                objects={[]}
                records={{ data: [tender] }}
                can={{ create: true, update: true, delete: true, convert: true }}
                relationOptions={{}}
                selectedRecordId={tender.id}
                businessUsers={[{ id: 9, name: '接手业务员' }]}
            />,
        );

        const dialog = await screen.findByRole('dialog', { name: '确认中标并流转' });
        const submit = within(dialog).getByRole('button', { name: '确认中标并流转' });
        expect(submit).toBeDisabled();
        fireEvent.change(within(dialog).getByRole('combobox', { name: '接手业务员' }), { target: { value: '9' } });
        expect(submit).toBeEnabled();
        fireEvent.click(submit);
        expect(inertia.post).toHaveBeenCalledWith('/records/tender-1/convert-to-project', { preserveScroll: true });
    });
});

function renderPage(mode, selected = record) {
    if (mode) window.history.replaceState({}, '', `/objects/customer?mode=${mode}&record=${selected.id}`);

    return render(
        <Index
            currentObject={{ id: 8, key: 'customer', group: '业务', label: '客户信息', fields }}
            objects={[{ id: 8, key: 'customer', group: '业务', label: '客户信息' }]}
            contactObject={{ id: 9, key: 'customer_contact', label: '客户联系人' }}
            records={{ data: [selected] }}
            can={{ create: true, update: true, delete: true }}
            relationOptions={{}}
            selectedRecordId={selected.id}
        />,
    );
}
