// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Index from './Index';

const { post, remove } = vi.hoisted(() => ({ post: vi.fn(), remove: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { password_only: true, user: { name: '工日簿专员', email: 'operator@example.test' } } } }),
    useForm: (data) => ({ data, errors: {}, setData: vi.fn(), put: vi.fn(), delete: remove }),
    useHttp: (data) => ({ data, errors: {}, setData: vi.fn(), post }),
}));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
afterEach(cleanup);

it('shows both own email and password forms for the dedicated operator', () => {
    render(<Index updateEmailUrl="/settings/email" updatePasswordUrl="/settings/password" />);
    expect(screen.getByRole('form', { name: '修改登录邮箱' })).toBeTruthy();
    expect(screen.getByRole('form', { name: '修改密码' })).toBeTruthy();
    expect(screen.getByRole('textbox', { name: '登录邮箱' }).value).toBe('operator@example.test');
});

it('shows binding code flow without removing account settings', () => {
 render(<Index wechat={{bound:false,configured:true}} />);
 expect(screen.getByRole('form',{name:'修改密码'})).toBeTruthy();
 fireEvent.submit(screen.getByRole('form',{name:'固定微信绑定码'}));
 expect(post).toHaveBeenCalledWith('/settings/wechat/code', expect.any(Object));
 expect(screen.queryByRole('button',{name:'解绑微信'})).toBeNull();
});
it('does not offer generating a binding code while unconfigured', () => {
 render(<Index wechat={{bound:false,configured:false}} />);
 expect(screen.getByText('微信登录尚未配置，请联系管理员。')).toBeTruthy();
 expect(screen.queryByRole('form',{name:'管理微信绑定'})).toBeNull();
});
it('requires confirmation for self unbinding', () => {
 render(<Index wechat={{bound:true,configured:true}} />);
 const confirm=vi.spyOn(window,'confirm').mockReturnValue(false);
 fireEvent.submit(screen.getByRole('form',{name:'管理微信绑定'}));expect(remove).not.toHaveBeenCalled();
 confirm.mockReturnValue(true);fireEvent.submit(screen.getByRole('form',{name:'管理微信绑定'}));
 expect(remove).toHaveBeenCalledWith('/settings/wechat', expect.any(Object));confirm.mockRestore();
});
