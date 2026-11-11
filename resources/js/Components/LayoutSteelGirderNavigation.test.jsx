// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Layout from './Layout';

const state = vi.hoisted(() => ({ url: '/steel-girder-projects', key: 'support-library', projectVisible: true }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: { post: vi.fn() },
    usePage: () => ({ url: state.url, props: {
        auth: { user: { name: '测试' }, roles: [] }, flash: {},
        nav: [{ key: state.key, label: '钢箱梁工作台', href: '/steel-girder-projects', visible: true,
            children: [{ label: '工作内容', items: [
                ...(state.projectVisible ? [{ label: '项目管理', href: '/steel-girder-projects' }] : []),
                { label: '支架检索', href: '/support-library' },
            ] }],
        }],
    } }),
}));
afterEach(() => { cleanup(); state.url = '/steel-girder-projects'; state.key = 'support-library'; state.projectVisible = true; });

it.each(['/steel-girder-projects', '/support-library'])('renders direct second-level links and preserves selection at %s', (url) => {
    state.url = url;
    render(<Layout><div>内容</div></Layout>);
    const parent = screen.getAllByRole('link', { name: '钢箱梁工作台', exact: true })[0];
    const submenu = parent.closest('.nav-group').querySelector('.nav-sublist');
    expect(within(submenu).queryByRole('heading')).toBeNull();
    expect(screen.queryByText('工作内容')).toBeNull();
    const links = within(submenu).getAllByRole('link');
    expect(links.map(link => link.textContent)).toEqual(['项目管理', '支架检索']);
    expect(links.map(link => link.getAttribute('href'))).toEqual(['/steel-girder-projects', '/support-library']);
    expect(links.find(link => link.getAttribute('href') === url)).toHaveClass('active');
    expect(parent).toHaveClass('active');
});

it('keeps project management hidden for support-only navigation', () => {
    state.projectVisible = false; state.url = '/support-library';
    render(<Layout><div>内容</div></Layout>);
    expect(screen.queryByRole('link', { name: '项目管理' })).toBeNull();
    expect(screen.getByRole('link', { name: '支架检索' })).toHaveClass('active');
});

it('preserves named group headings in other workbenches', () => {
    state.key = 'another-workbench';
    render(<Layout><div>内容</div></Layout>);
    expect(screen.getByRole('heading', { name: '工作内容', level: 4 })).toBeVisible();
});
