import { Head, useForm, usePage } from '@inertiajs/react';
import Layout from '../../Components/Layout';
import PasswordFields from '../../Components/PasswordFields';

export default function Index({ updateEmailUrl, updatePasswordUrl }) {
    const { auth } = usePage().props;
    const emailForm = useForm({ email: auth.user.email, current_password: '' });
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

    function updateEmail(event) {
        event.preventDefault();
        emailForm.put(updateEmailUrl, {
            preserveScroll: true,
            onSuccess: () => emailForm.reset('current_password'),
        });
    }

    function updatePassword(event) {
        event.preventDefault();
        passwordForm.put(updatePasswordUrl, {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    }

    return (
        <Layout title="用户设置" eyebrow="个人账号">
            <Head title="用户设置" />
            <div className="account-settings">
                {!auth.password_only && <section className="surface">
                    <div className="section-head"><h2>登录账号</h2></div>
                    <p className="muted">{auth.user.name}，您可以修改自己的登录邮箱。角色和业务权限保持不变。</p>
                    <form className="account-form" aria-label="修改登录邮箱" onSubmit={updateEmail}>
                        <label htmlFor="account-email">
                            <span id="account-email-label">登录邮箱</span>
                            <input id="account-email" aria-labelledby="account-email-label" type="email" autoComplete="username" required value={emailForm.data.email} onChange={(event) => emailForm.setData('email', event.target.value)} aria-invalid={Boolean(emailForm.errors.email)} aria-describedby={emailForm.errors.email ? 'account-email-error' : undefined} />
                            {emailForm.errors.email && <p id="account-email-error" className="form-error" role="alert">{emailForm.errors.email}</p>}
                        </label>
                        <label htmlFor="account-current-password">
                            <span id="account-current-password-label">当前密码</span>
                            <input id="account-current-password" aria-labelledby="account-current-password-label" type="password" autoComplete="current-password" required value={emailForm.data.current_password} onChange={(event) => emailForm.setData('current_password', event.target.value)} aria-invalid={Boolean(emailForm.errors.current_password)} aria-describedby={emailForm.errors.current_password ? 'account-current-password-error' : undefined} />
                            {emailForm.errors.current_password && <p id="account-current-password-error" className="form-error" role="alert">{emailForm.errors.current_password}</p>}
                        </label>
                        <div><button type="submit" disabled={emailForm.processing}>{emailForm.processing ? '保存中...' : '保存登录邮箱'}</button></div>
                        {emailForm.recentlySuccessful && <p role="status">登录邮箱已更新。</p>}
                    </form>
                </section>}
                <section className="surface" id="password">
                    <div className="section-head"><h2>修改密码</h2></div>
                    <p className="muted">新密码至少 8 位，不能与当前密码相同。</p>
                    <form className="account-form" aria-label="修改密码" onSubmit={updatePassword}>
                        <PasswordFields form={passwordForm} prefix="account-password" />
                        <div><button type="submit" disabled={passwordForm.processing}>{passwordForm.processing ? '保存中...' : '保存新密码'}</button></div>
                        {passwordForm.recentlySuccessful && <p role="status">密码已修改。</p>}
                    </form>
                </section>
            </div>
        </Layout>
    );
}
