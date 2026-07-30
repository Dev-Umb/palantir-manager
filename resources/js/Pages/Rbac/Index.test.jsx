// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

const { put } = vi.hoisted(() => ({
    put: vi.fn((url, data, options) => {
        options?.onSuccess?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { put },
}));

vi.mock('../../Components/Layout', () => ({
    default: ({ children, title, eyebrow }) => <main data-title={title} data-eyebrow={eyebrow}>{children}</main>,
}));

afterEach(() => {
    cleanup();
    put.mockClear();
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
});
