// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Approvals from './Approvals';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post },
}));

vi.mock('../../Components/Layout', () => ({
    default: ({ children }) => <main>{children}</main>,
}));

afterEach(() => {
    cleanup();
    post.mockClear();
    vi.restoreAllMocks();
});

describe('procurement approval decisions', () => {
    it('requires an explicit confirmation before approving a request', () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);

        render(
            <Approvals
                pending={[{
                    id: 'request-1',
                    code: 'QG-001',
                    payload: { requester: '生产', qty: 2, unit: '吨', status: '待处理' },
                    display: { material_id: '钢板', status: '待处理' },
                }]}
                processed={[]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '通过申请' }));

        expect(confirm).toHaveBeenCalledWith(expect.stringContaining('通过后将进入采购执行'));
        expect(post).toHaveBeenCalledWith(
            '/requests/request-1/approve',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
