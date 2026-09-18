// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Index from './Index';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { password_only: true, user: { name: '工日簿专员', email: 'operator@example.test' } } } }),
    useForm: (data) => ({ data, errors: {}, setData: vi.fn(), put: vi.fn() }),
}));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
afterEach(cleanup);

it('shows both own email and password forms for the dedicated operator', () => {
    render(<Index updateEmailUrl="/settings/email" updatePasswordUrl="/settings/password" />);
    expect(screen.getByRole('form', { name: '修改登录邮箱' })).toBeTruthy();
    expect(screen.getByRole('form', { name: '修改密码' })).toBeTruthy();
    expect(screen.getByRole('textbox', { name: '登录邮箱' }).value).toBe('operator@example.test');
});
