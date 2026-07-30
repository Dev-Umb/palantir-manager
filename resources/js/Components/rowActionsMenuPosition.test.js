import { describe, expect, it } from 'vitest';
import { rowActionsMenuStyleFromRects } from './rowActionsMenuPosition';

describe('rowActionsMenuStyleFromRects', () => {
    it('opens below the trigger when there is enough room', () => {
        expect(rowActionsMenuStyleFromRects(
            { top: 100, bottom: 132, right: 500 },
            { width: 140, height: 90 },
            { width: 800, height: 600 },
        )).toMatchObject({
            top: 138,
            left: 360,
            position: 'fixed',
            zIndex: 120,
        });
    });

    it('opens above bottom rows and stays inside the horizontal viewport', () => {
        expect(rowActionsMenuStyleFromRects(
            { top: 540, bottom: 572, right: 790 },
            { width: 180, height: 120 },
            { width: 800, height: 600 },
        )).toMatchObject({
            top: 414,
            left: 608,
        });
    });
});
