// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import WorkbenchPanel from './WorkbenchPanel';

const { state } = vi.hoisted(() => ({ state: { nav: [] } }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, ...props }) => <a {...props}>{children}</a>, usePage: () => ({ props: state }) }));
vi.mock('../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));

describe('technician panel', () => {
    it('renders authorized workbench links and excludes hidden or unrelated modules', () => {
        state.nav = [
            { key: 'production-workbench', label: '生产管理工作台', href: '/production-workbench', visible: true },
            { key: 'template-accessories', label: '模板与配件', href: '/template-accessories', visible: false },
            { key: 'ai', label: 'AI', href: '/ai', visible: true },
        ];
        render(<WorkbenchPanel />);
        expect(screen.getByRole('link', { name: '进入工作台' }).getAttribute('href')).toBe('/production-workbench');
        expect(screen.queryByText('模板与配件工作台')).toBeNull();
        expect(screen.queryByText('AI')).toBeNull();
    });
});
