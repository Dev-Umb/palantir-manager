// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { afterEach, describe, expect, it } from 'vitest';
import RowActions from './RowActions';

describe('RowActions', () => {
    afterEach(cleanup);

    it('portals the overflow menu outside the grid and closes it from outside', () => {
        const { container } = render(
            <div className="ag-cell">
                <RowActions
                    primary={<a href="/records/1">查看</a>}
                    secondary={[
                        <a key="edit" href="/records/1?mode=edit" aria-label="编辑 REC-001">编辑</a>,
                        <button key="delete" type="button" aria-label="删除 REC-001">删除</button>,
                    ]}
                    menuLabel="记录更多操作"
                />
            </div>,
        );

        const trigger = screen.getByRole('button', { name: '记录更多操作' });
        fireEvent.click(trigger);

        const menu = screen.getByRole('menu');
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(menu.parentElement).toBe(document.body);
        expect(container.querySelector('.row-actions-menu-popup')).toBeNull();
        expect(screen.getByRole('link', { name: '编辑 REC-001' })).toHaveTextContent('编辑');
        expect(screen.getByRole('button', { name: '删除 REC-001' })).toHaveTextContent('删除');

        fireEvent.pointerDown(document.body);
        expect(screen.queryByRole('menu')).toBeNull();
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });

    it('closes with Escape and restores focus to the trigger', () => {
        render(
            <RowActions
                primary={<span>查看</span>}
                secondary={[<button key="delete" type="button">删除</button>]}
            />,
        );

        const trigger = screen.getByRole('button', { name: '更多操作' });
        fireEvent.click(trigger);
        fireEvent.keyDown(document, { key: 'Escape' });

        expect(screen.queryByRole('menu')).toBeNull();
        expect(trigger).toHaveFocus();
    });
});
