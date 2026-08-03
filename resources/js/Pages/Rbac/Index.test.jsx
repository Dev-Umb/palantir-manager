// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

const { put, destroy } = vi.hoisted(() => ({
    put: vi.fn((url, data, options) => {
        options?.onSuccess?.();
        options?.onFinish?.();
    }),
    destroy: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { put, delete: destroy },
}));

vi.mock('../../Components/Layout', () => ({
    default: ({ children, title, eyebrow }) => <main data-title={title} data-eyebrow={eyebrow}>{children}</main>,
}));

afterEach(() => {
    cleanup();
    put.mockClear();
    destroy.mockClear();
    vi.restoreAllMocks();
});

describe('RBAC role editor', () => {
    it('uses the unified page header contract', () => {
        const { container } = render(
            <Index users={[]} roles={[]} permissions={{}} />,
        );

        expect(container.querySelector('main')).toHaveAttribute('data-title', '用户与权限');
        expect(container.querySelector('main')).toHaveAttribute('data-eyebrow', '用户与权限');
    });

    it('only enables a user-specific save action after roles change', () => {
        render(
            <Index
                users={[{
                    id: 7,
                    name: '陈昊',
                    email: 'i@umb.ink',
                    roles: [{ id: 1, label: '管理' }],
                    can_delete: true,
                    delete_block_reason: null,
                }]}
                roles={[
                    { id: 1, label: '管理', permissions: [], locked: true },
                    { id: 2, label: '采购', permissions: [], locked: false, description: '采购业务' },
                ]}
                permissions={{}}
            />,
        );

        const save = screen.getByRole('button', { name: '保存陈昊的角色' });
        expect(save).toBeDisabled();

        fireEvent.click(screen.getByRole('checkbox', { name: '采购' }));

        expect(screen.getByText('有未保存修改')).toBeInTheDocument();
        expect(save).toBeEnabled();

        fireEvent.click(save);

        expect(put).toHaveBeenCalledWith(
            '/admin/users/7/roles',
            { roles: [1, 2] },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(save).toBeDisabled();
    });

    it('deletes one confirmed user and explains that history is preserved', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        render(
            <Index
                users={[{
                    id: 7,
                    name: '陈昊',
                    email: 'i@umb.ink',
                    roles: [],
                    can_delete: true,
                    delete_block_reason: null,
                }]}
                roles={[]}
                permissions={{}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '删除陈昊' }));

        expect(window.confirm).toHaveBeenCalledWith('确认删除用户“陈昊”吗？删除后该账号将立即无法登录，历史业务记录会保留。');
        expect(destroy).toHaveBeenCalledWith(
            '/admin/users/7',
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.getByRole('button', { name: '删除陈昊' })).toBeDisabled();
        expect(screen.getByText('删除中...')).toBeInTheDocument();
    });

    it('does not delete when confirmation is cancelled', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        render(
            <Index
                users={[{
                    id: 7,
                    name: '陈昊',
                    email: 'i@umb.ink',
                    roles: [],
                    can_delete: true,
                    delete_block_reason: null,
                }]}
                roles={[]}
                permissions={{}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '删除陈昊' }));

        expect(destroy).not.toHaveBeenCalled();
    });

    it('disables protected deletion and shows the reason', () => {
        render(
            <Index
                users={[{
                    id: 7,
                    name: '陈昊',
                    email: 'i@umb.ink',
                    roles: [{ id: 1, label: '管理' }],
                    can_delete: false,
                    delete_block_reason: '不能删除最后一个管理员',
                }]}
                roles={[{ id: 1, label: '管理', permissions: [], locked: true }]}
                permissions={{}}
            />,
        );

        expect(screen.getByRole('button', { name: '删除陈昊' })).toBeDisabled();
        expect(screen.getByText('不能删除最后一个管理员')).toBeInTheDocument();
    });
});
