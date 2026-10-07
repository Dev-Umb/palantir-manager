// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { isEqual } from 'es-toolkit';

const source = readFileSync('public/browser-startup.js', 'utf8');
const nativeHasOwn = Object.getOwnPropertyDescriptor(Object, 'hasOwn');
let callbacks;

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div id="app"></div>';
    callbacks = [];
    const addEventListener = document.addEventListener.bind(document);
    vi.spyOn(document, 'addEventListener').mockImplementation((name, callback, options) => {
        if (name === 'DOMContentLoaded') callbacks.push(callback);
        else addEventListener(name, callback, options);
    });
});

afterEach(() => {
    Object.defineProperty(Object, 'hasOwn', nativeHasOwn);
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    document.body.innerHTML = '';
    document.getElementById('legacy-browser-base')?.remove();
});

function start() {
    new Function(source)();
    callbacks.forEach((callback) => callback());
}

describe('legacy browser startup', () => {
    it('reproduces the missing API dependency failure and restores equality used by forms', () => {
        Object.defineProperty(Object, 'hasOwn', { ...nativeHasOwn, value: undefined });
        expect(() => isEqual({ email: '' }, { email: '' })).toThrow();
        start();
        expect(isEqual({ email: '' }, { email: '' })).toBe(true);
        expect(isEqual({ email: 'changed' }, { email: '' })).toBe(false);
    });

    it('handles null prototypes, inherited keys, symbols and null consistently', () => {
        Object.defineProperty(Object, 'hasOwn', { ...nativeHasOwn, value: undefined });
        start();
        const key = Symbol('key');
        const object = Object.assign(Object.create(null), { hasOwnProperty: 1, [key]: 2 });
        expect(Object.hasOwn(object, 'hasOwnProperty')).toBe(true);
        expect(Object.hasOwn(object, key)).toBe(true);
        expect(Object.hasOwn({}, 'toString')).toBe(false);
        expect(() => Object.hasOwn(null, 'key')).toThrow(TypeError);
        expect(Object.getOwnPropertyDescriptor(Object, 'hasOwn').enumerable).toBe(false);
    });

    it('preserves the native implementation', () => {
        start();
        expect(Object.getOwnPropertyDescriptor(Object, 'hasOwn')).toEqual(nativeHasOwn);
    });

    it('provides theme defaults only when modern CSS colors are unavailable', () => {
        vi.stubGlobal('CSS', { supports: () => false });
        start();
        expect(document.getElementById('legacy-browser-base').textContent).toContain('--radius-md:10px');
    });

    it('leaves modern CSS unchanged', () => {
        vi.stubGlobal('CSS', { supports: () => true });
        start();
        expect(document.getElementById('legacy-browser-base')).toBeNull();
    });

    it('shows actionable feedback only after the startup timeout', () => {
        start();
        vi.advanceTimersByTime(19999);
        expect(document.querySelector('[role="alert"]')).toBeNull();
        vi.advanceTimersByTime(1);
        expect(document.querySelector('[role="alert"]').textContent).toContain('重新加载');
        expect(document.querySelector('[role="alert"]').textContent).toContain('极速模式');
    });

    it('cleans up feedback when mounting completes after the timeout', async () => {
        start();
        vi.advanceTimersByTime(20000);
        document.getElementById('app').appendChild(document.createElement('main'));
        await Promise.resolve();
        expect(document.querySelector('[role="alert"]')).toBeNull();
    });

    it('never overlays an already mounted page', () => {
        document.getElementById('app').innerHTML = '<main>登录</main>';
        start();
        vi.advanceTimersByTime(60000);
        expect(document.querySelector('[role="alert"]')).toBeNull();
    });

    it('loads the classic compatibility script before application modules', () => {
        const blade = readFileSync('resources/views/app.blade.php', 'utf8');
        expect(blade.indexOf("asset('browser-startup.js')")).toBeLessThan(blade.indexOf('@vite'));
        expect(blade).toContain('@inertia');
    });
});
