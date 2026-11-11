// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Index from './Index';
const state = vi.hoisted(() => ({ before: null, unsubscribe: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { on: (name, callback) => { state.before = callback; return state.unsubscribe; } } }));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../Components/SteelGirderNavigation', () => ({ default: () => <nav>项目管理 支架检索</nav> }));
afterEach(() => { cleanup(); vi.restoreAllMocks(); });
it('protects unsaved work when navigating the surrounding platform and cleans up the guard', () => {
    const { unmount } = render(<Index workspaceUrl="about:blank" />);
    const frame = screen.getByTitle('钢箱梁项目管理');
    frame.contentWindow.SteelGirderHasUnsavedChanges = () => true;
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    const event = { preventDefault: vi.fn() };
    state.before(event);
    expect(event.preventDefault).toHaveBeenCalledOnce();
    frame.contentWindow.SteelGirderHasUnsavedChanges = () => false;
    const clean = { preventDefault: vi.fn() };
    state.before(clean);
    expect(clean.preventDefault).not.toHaveBeenCalled();
    unmount();
    expect(state.unsubscribe).toHaveBeenCalled();
});
