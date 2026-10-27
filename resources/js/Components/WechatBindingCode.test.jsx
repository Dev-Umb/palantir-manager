// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import WechatBindingCode from './WechatBindingCode';
const { post, reset, data } = vi.hoisted(() => ({post: vi.fn(), reset: vi.fn(), data: {current_password: '', reset: false}}));
vi.mock('@inertiajs/react', () => ({useHttp: () => ({data, errors: {}, post, reset, setData: vi.fn()})}));
afterEach(() => { cleanup(); vi.clearAllMocks(); vi.restoreAllMocks(); data.reset = false; });
it('reveals code only after successful password-verified request and clears password', () => {
 render(<WechatBindingCode url="/admin/users/2/wechat/code" />);
 expect(screen.queryByRole('status')).toBeNull();
 fireEvent.submit(screen.getByRole('form'));
 expect(post).toHaveBeenCalledWith('/admin/users/2/wechat/code', expect.any(Object));
 act(() => post.mock.calls[0][1].onSuccess({code:'1234567890ABCDEF'}));
 expect(screen.getByText('1234567890ABCDEF')).toBeTruthy(); expect(reset).toHaveBeenCalled();
 fireEvent.submit(screen.getByRole('form'));
 act(() => post.mock.calls[1][1].onError());
 expect(screen.queryByText('1234567890ABCDEF')).toBeNull(); expect(screen.getByRole('alert')).toBeTruthy();
});
it('requires explicit reset confirmation and never calls unbind', () => {
 data.reset = true;
 const confirm = vi.spyOn(window,'confirm').mockReturnValue(false);
 render(<WechatBindingCode url="/settings/wechat/code" />);
 fireEvent.submit(screen.getByRole('form')); expect(post).not.toHaveBeenCalled();
 confirm.mockReturnValue(true); fireEvent.submit(screen.getByRole('form'));
 expect(post).toHaveBeenCalledWith('/settings/wechat/code', expect.any(Object));
});
