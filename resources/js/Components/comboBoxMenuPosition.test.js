import { expect, test } from 'vitest';
import { comboMenuStyleFromRect, shouldCloseComboForFocus, shouldCloseComboForPointer } from './comboBoxMenuPosition.js';

test('combo menu is positioned against the viewport, not its scroll container', () => {
    expect(comboMenuStyleFromRect({
        left: 24,
        bottom: 120,
        width: 360,
    }, 720)).toEqual({
        position: 'fixed',
        zIndex: 100,
        top: 126,
        left: 24,
        right: 'auto',
        width: 360,
        '--combo-menu-max-height': '240px',
    });
});

test('combo menu height is bounded by remaining viewport space', () => {
    expect(comboMenuStyleFromRect({
        left: 24,
        bottom: 660,
        width: 360,
    }, 720)['--combo-menu-max-height']).toBe('36px');
});

test('combo focus close ignores empty relatedTarget after portal autofocus', () => {
    expect(shouldCloseComboForFocus(null, null, null)).toBe(false);
});

test('combo focus close keeps focus inside the portaled menu', () => {
    const option = {};
    const menu = { contains: (target) => target === option };

    expect(shouldCloseComboForFocus(null, menu, option)).toBe(false);
});

test('combo pointer close only closes outside the trigger and portaled menu', () => {
    const trigger = {};
    const option = {};
    const outside = {};
    const root = { contains: (target) => target === trigger };
    const menu = { contains: (target) => target === option };

    expect(shouldCloseComboForPointer(root, menu, trigger)).toBe(false);
    expect(shouldCloseComboForPointer(root, menu, option)).toBe(false);
    expect(shouldCloseComboForPointer(root, menu, outside)).toBe(true);
});
