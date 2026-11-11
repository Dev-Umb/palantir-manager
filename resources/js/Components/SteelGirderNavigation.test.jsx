// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import SteelGirderNavigation from './SteelGirderNavigation';
let items, url;
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    usePage: () => ({ url, props: { nav: [{ key: 'support-library', children: [{ items }] }] } }),
}));
afterEach(cleanup);
it('keeps ordered parallel links and active section available on mobile as well as desktop', () => {
    items = [{ label: '项目管理', href: '/steel-girder-projects' }, { label: '支架检索', href: '/support-library' }]; url = '/support-library';
    render(<SteelGirderNavigation />);
    expect(screen.getAllByRole('link').map(el => el.textContent)).toEqual(['项目管理', '支架检索']);
    expect(screen.getByRole('link', { name: '支架检索' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: '项目管理' })).not.toHaveAttribute('aria-current');
});
it('does not invent hidden project links for support-only users', () => {
    items = [{ label: '支架检索', href: '/support-library' }]; url = '/support-library';
    render(<SteelGirderNavigation />);
    expect(screen.queryByRole('link', { name: '项目管理' })).toBeNull();
    expect(screen.getByRole('link', { name: '支架检索' })).toBeVisible();
});
