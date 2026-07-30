// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CustomerContactModal from './CustomerContactModal';

describe('CustomerContactModal', () => {
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('keeps contact management inside the list modal', () => {
        render(
            <CustomerContactModal
                mode="list"
                contactObjectId="contact-object"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contacts={[
                    { id: 'contact-1', name: '李经理', phone: '13900000000', projects: [] },
                    { id: 'contact-2', name: '王工', phone: '', projects: [] },
                ]}
                can={{ create: true, update: true, delete: true }}
                onClose={() => {}}
            />,
        );

        const listDialog = screen.getByRole('dialog', { name: '客户联系人' });
        expect(within(listDialog).getByText('共 2 位联系人')).toBeInTheDocument();
        expect(within(listDialog).getByRole('button', { name: '新增联系人' })).toBeInTheDocument();

        fireEvent.click(within(listDialog).getByRole('button', { name: '查看 李经理 详情' }));

        const detailDialog = screen.getByRole('dialog', { name: '联系人详情' });
        expect(within(detailDialog).getByText('13900000000')).toBeInTheDocument();
        expect(within(detailDialog).getByRole('button', { name: '删除联系人' })).toBeInTheDocument();
        fireEvent.click(within(detailDialog).getByRole('button', { name: '返回联系人列表' }));
        expect(screen.getByRole('dialog', { name: '客户联系人' })).toBeInTheDocument();
    });

    it('returns to the contact list when list-based creation is cancelled', () => {
        render(
            <CustomerContactModal
                mode="list"
                contactObjectId="contact-object"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contacts={[]}
                can={{ create: true }}
                onClose={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '新增联系人' }));
        expect(screen.getByRole('dialog', { name: '新增联系人' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: '取消' }));
        expect(screen.getByRole('dialog', { name: '客户联系人' })).toBeInTheDocument();
    });

    it('shows only the contact name, phone and related project names in detail mode', () => {
        render(
            <CustomerContactModal
                mode="detail"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contact={{
                    id: 'contact-1',
                    name: '李经理',
                    phone: '13900000000',
                    position: '采购经理',
                    status: '启用',
                    projects: [
                        { id: 'project-1', title: '北区项目', code: 'PRJ-001' },
                        { id: 'project-2', title: '南区项目', code: 'PRJ-002' },
                    ],
                }}
                can={{ update: true }}
                onClose={() => {}}
            />,
        );

        const dialog = screen.getByRole('dialog', { name: '联系人详情' });
        expect(within(dialog).getByText('李经理')).toBeInTheDocument();
        expect(within(dialog).getByText('13900000000')).toBeInTheDocument();
        expect(within(dialog).getByText('北区项目')).toBeInTheDocument();
        expect(within(dialog).getByText('南区项目')).toBeInTheDocument();
        expect(within(dialog).queryByText('PRJ-001')).not.toBeInTheDocument();
        expect(within(dialog).queryByText('采购经理')).not.toBeInTheDocument();
        expect(within(dialog).queryByText('启用')).not.toBeInTheDocument();
        expect(within(dialog).getByText('所属客户')).toBeInTheDocument();
        expect(within(dialog).getByText('基本信息')).toBeInTheDocument();
        expect(dialog.querySelector('.contact-modal-footer')).toContainElement(
            within(dialog).getByRole('button', { name: '编辑联系人' }),
        );
        expect(dialog.querySelectorAll('.contact-modal-detail dl > div')).toHaveLength(2);
    });

    it('creates a contact with only name, phone and the fixed customer id', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ record: { id: 'contact-1', title: '王工' }, status: '客户联系人已创建。' }),
        });
        const onSaved = vi.fn();

        render(
            <CustomerContactModal
                mode="create"
                contactObjectId="contact-object"
                customer={{ id: 'customer-1', title: '甲客户' }}
                can={{ create: true }}
                onSaved={onSaved}
                onClose={() => {}}
            />,
        );

        fireEvent.change(screen.getByLabelText('联系人姓名'), { target: { value: '王工' } });
        fireEvent.change(screen.getByLabelText('联系电话'), { target: { value: '13800000000' } });
        fireEvent.click(screen.getByRole('button', { name: '保存联系人' }));

        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledOnce());
        expect(globalThis.fetch).toHaveBeenCalledWith('/objects/contact-object', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ payload: {
                name: '王工',
                phone: '13800000000',
                customer_id: 'customer-1',
            } }),
        }));
        expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 'contact-1' }));
    });

    it('marks the cancel action as a secondary button', () => {
        render(
            <CustomerContactModal
                mode="create"
                contactObjectId="contact-object"
                customer={{ id: 'customer-1', title: '甲客户' }}
                can={{ create: true }}
                onSaved={() => {}}
                onClose={() => {}}
            />,
        );

        expect(screen.getByRole('button', { name: '取消' })).toHaveClass('secondary-button');
    });

    it('edits an existing contact without exposing legacy fields', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ record: { id: 'contact-1', title: '李经理' } }),
        });

        render(
            <CustomerContactModal
                mode="detail"
                contactObjectId="contact-object"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contact={{ id: 'contact-1', name: '李经理', phone: '13900000000', projects: [] }}
                can={{ update: true }}
                onSaved={() => {}}
                onClose={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '编辑联系人' }));
        expect(screen.queryByLabelText('职务')).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('联系电话'), { target: { value: '13700000000' } });
        fireEvent.click(screen.getByRole('button', { name: '保存联系人' }));

        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledWith('/records/contact-1', expect.objectContaining({
            method: 'PUT',
            body: JSON.stringify({ payload: {
                name: '李经理',
                phone: '13700000000',
                customer_id: 'customer-1',
            } }),
        })));
    });

    it('deletes an unreferenced contact after confirmation', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ status: '客户联系人已删除。' }),
        });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const onDeleted = vi.fn();

        render(
            <CustomerContactModal
                mode="detail"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contact={{ id: 'contact-1', name: '李经理', phone: '13900000000', projects: [] }}
                can={{ delete: true }}
                onDeleted={onDeleted}
                onClose={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '删除联系人' }));

        await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledWith('/records/contact-1', expect.objectContaining({
            method: 'DELETE',
        })));
        expect(window.confirm).toHaveBeenCalledWith('确定删除联系人“李经理”吗？\n\n删除后无法恢复。');
        expect(onDeleted).toHaveBeenCalledWith('contact-1');
    });

    it('does not delete a contact when confirmation is cancelled', () => {
        globalThis.fetch = vi.fn();
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const onDeleted = vi.fn();

        render(
            <CustomerContactModal
                mode="detail"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contact={{ id: 'contact-1', name: '李经理', phone: '13900000000', projects: [] }}
                can={{ delete: true }}
                onDeleted={onDeleted}
                onClose={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '删除联系人' }));

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(onDeleted).not.toHaveBeenCalled();
        expect(screen.getByRole('dialog', { name: '联系人详情' })).toBeInTheDocument();
    });

    it('keeps a referenced contact open and shows the deletion error', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({
                errors: {
                    record: ['无法删除：项目资料仍在引用该记录，请先解除关联。'],
                },
            }),
        });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const onDeleted = vi.fn();

        render(
            <CustomerContactModal
                mode="detail"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contact={{
                    id: 'contact-1',
                    name: '李经理',
                    phone: '13900000000',
                    projects: [{ id: 'project-1', title: '北区项目' }],
                }}
                can={{ delete: true }}
                onDeleted={onDeleted}
                onClose={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '删除联系人' }));

        expect(await screen.findByText('无法删除：项目资料仍在引用该记录，请先解除关联。')).toBeInTheDocument();
        expect(onDeleted).not.toHaveBeenCalled();
        expect(screen.getByRole('dialog', { name: '联系人详情' })).toBeInTheDocument();
    });

    it('does not expose contact deletion without permission', () => {
        render(
            <CustomerContactModal
                mode="detail"
                customer={{ id: 'customer-1', title: '甲客户' }}
                contact={{ id: 'contact-1', name: '李经理', phone: '13900000000', projects: [] }}
                can={{ update: true }}
                onClose={() => {}}
            />,
        );

        expect(screen.queryByRole('button', { name: '删除联系人' })).not.toBeInTheDocument();
    });
});
