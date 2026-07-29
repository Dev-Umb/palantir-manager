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

    it('shows the unseen task count beside the target object', () => {
        render(<Layout title="工作台"><div>内容</div></Layout>);

        expect(screen.getByRole('link', { name: /技术图纸与方案/ }).textContent).toContain('3');
        expect(screen.getByLabelText('技术图纸与方案 3 个新任务')).not.toBeNull();
        expect(screen.getByText('技术视图')).not.toBeNull();
    });
});
