// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Layout from './Layout';
const state = vi.hoisted(() => ({ url: '/reimbursements', admin: true }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>, router: { post: vi.fn() },
    usePage: () => ({ url: state.url, props: { auth: { user: { name: '测试' }, roles: [] }, flash: {}, nav: [
        { key: 'reimbursements', label: '报销申请', href: '/reimbursements', visible: true },
        { key: 'reimbursement-approvals', label: 'OA 审批', href: '/reimbursements?workspace=approval', visible: state.admin },
    ] } }),
}));
afterEach(() => { cleanup(); state.admin = true; state.url = '/reimbursements'; });
it('highlights the matching applicant or OA entry without highlighting both', () => {
    state.url = '/reimbursements?workspace=approval';
    const { unmount } = render(<Layout><div>内容</div></Layout>);
    expect(screen.getAllByRole('link', { name: 'OA 审批', exact: true })[0]).toHaveClass('active');
    expect(screen.getAllByRole('link', { name: '报销申请', exact: true })[0]).not.toHaveClass('active');
    unmount(); state.url = '/reimbursements/create';
    render(<Layout><div>内容</div></Layout>);
    expect(screen.getAllByRole('link', { name: '报销申请', exact: true })[0]).toHaveClass('active');
    expect(screen.getAllByRole('link', { name: 'OA 审批', exact: true })[0]).not.toHaveClass('active');
});
it('ordinary users retain their applicant entry without the OA navigation', () => {
    state.admin = false; render(<Layout><div>内容</div></Layout>);
    expect(screen.getAllByRole('link', { name: '报销申请', exact: true })[0]).toHaveClass('active');
    expect(screen.queryByRole('link', { name: 'OA 审批', exact: true })).toBeNull();
});
