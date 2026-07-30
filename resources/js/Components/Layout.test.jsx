// @vitest-environment jsdom

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Layout from './Layout';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    router: { post: vi.fn() },
    usePage: () => ({
        url: '/objects/drawing',
        props: {
            auth: { user: { name: '技术员' }, roles: [{ id: 1, label: '技术' }] },
            flash: {},
            nav: [{
                key: 'ontology',
                label: '本体工作台',
                href: '/objects',
                visible: true,
                children: [{
                    label: '履约',
                    items: [{
                        label: '技术图纸与方案',
                        href: '/objects/drawing',
                        new_task_count: 3,
                    }],
                }],
            }],
        },
    }),
}));

describe('Layout workflow task navigation', () => {
    afterEach(cleanup);

    it('shows flat business modules without expanding their table items', () => {
        render(<Layout title="工作台"><div>内容</div></Layout>);

        expect(screen.getByRole('link', { name: '履约' }).getAttribute('href')).toBe('/objects/drawing');
        expect(screen.queryByRole('link', { name: /技术图纸与方案/ })).toBeNull();
        expect(screen.queryByLabelText('技术图纸与方案 3 个新任务')).toBeNull();
        expect(screen.getByText('技术')).not.toBeNull();
    });

    it('always renders the unified two-level page header without a role strip', () => {
        const { container } = render(<Layout title="技术图纸与方案" eyebrow="业务资料"><div>内容</div></Layout>);

        expect(screen.getByRole('heading', { name: '技术图纸与方案' })).not.toBeNull();
        expect(container.querySelector('.workspace-head p')?.textContent).toBe('业务资料');
        expect(container.querySelector('.role-strip')).toBeNull();
        expect(screen.getByText('内容')).not.toBeNull();
    });

    it('can omit the page header for dense table workspaces', () => {
        const { container } = render(<Layout title="客户信息" eyebrow="业务资料" hideHeader><div>内容</div></Layout>);

        expect(container.querySelector('.workspace-head')).toBeNull();
        expect(screen.queryByRole('heading', { name: '客户信息' })).toBeNull();
        expect(screen.getByText('内容')).not.toBeNull();
    });
});
