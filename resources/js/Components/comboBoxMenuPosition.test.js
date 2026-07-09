import assert from 'node:assert/strict';
import test from 'node:test';
import { comboMenuStyleFromRect, shouldCloseComboForFocus, shouldCloseComboForPointer } from './comboBoxMenuPosition.js';

test('combo menu is positioned against the viewport, not its scroll container', () => {
    assert.deepEqual(comboMenuStyleFromRect({
        left: 24,
        bottom: 120,
        width: 360,
    }, 720), {
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
    assert.equal(comboMenuStyleFromRect({
        left: 24,
        bottom: 660,
        width: 360,
    }, 720)['--combo-menu-max-height'], '36px');
});

test('combo focus close ignores empty relatedTarget after portal autofocus', () => {
    assert.equal(shouldCloseComboForFocus(null, null, null), false);
});

test('combo focus close keeps focus inside the portaled menu', () => {
    const option = {};
    const menu = { contains: (target) => target === option };

    assert.equal(shouldCloseComboForFocus(null, menu, option), false);
});

test('combo pointer close only closes outside the trigger and portaled menu', () => {
    const trigger = {};
    const option = {};
    const outside = {};
    const root = { contains: (target) => target === trigger };
    const menu = { contains: (target) => target === option };

    assert.equal(shouldCloseComboForPointer(root, menu, trigger), false);
    assert.equal(shouldCloseComboForPointer(root, menu, option), false);
    assert.equal(shouldCloseComboForPointer(root, menu, outside), true);
});
