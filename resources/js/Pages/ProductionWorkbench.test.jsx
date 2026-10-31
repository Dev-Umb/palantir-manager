// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProductionWorkbench from './ProductionWorkbench';
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
afterEach(cleanup);
const material = { url: '/material-arrivals/workbench?embedded=1', manage: true, export: true, recover: true };
const template = { ...material, url: '/template-accessories/workbench?embedded=1' };
describe('unified workbench', () => {
    it('lazy loads the other domain and preserves both frames across navigation', () => {
        render(<ProductionWorkbench workbenches={{ material, template }} initialModule="material" panelUrl="/dashboard" />);
        const original = screen.getByTitle('原材进场内容');
        expect(screen.queryByTitle('模板与配件内容')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: '台车模板' }));
        const second = screen.getByTitle('模板与配件内容');
        expect(original.hidden).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: '进场台账' }));
        expect(screen.getByTitle('原材进场内容')).toBe(original);
        expect(screen.getByTitle('模板与配件内容')).toBe(second);
        expect(second.hidden).toBe(true);
        expect(screen.getByRole('link').getAttribute('href')).toBe('/dashboard');
    });
    it('does not expose ungranted modules or modification controls', () => {
        render(<ProductionWorkbench workbenches={{ material: { ...material, manage: false, export: false, recover: false } }} initialModule="material" panelUrl="/dashboard" />);
        expect(screen.queryByRole('button', { name: '台车模板' })).toBeNull();
        expect(screen.queryByRole('button', { name: '快捷录入' })).toBeNull();
        expect(screen.queryByRole('button', { name: '恢复工程备份 ↗' })).toBeNull();
        expect(screen.getByRole('button', { name: '进场台账' })).toBeTruthy();
    });
});
