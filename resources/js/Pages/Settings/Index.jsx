import { Head, useForm, usePage } from '@inertiajs/react';
import Layout from '../../Components/Layout';
import WechatBindingCode from '../../Components/WechatBindingCode';
import PasswordFields from '../../Components/PasswordFields';

export default function Index({ updateEmailUrl, updatePasswordUrl, wechat }) {
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
                {wechat && <WechatBinding state={wechat} />}
                <section className="surface">
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
                </section>
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

function WechatBinding({ state }) {
    const unbind = useForm({ current_password: '' });
    function submit(event) {
        event.preventDefault();
        if (!window.confirm('确认解绑微信？已登录的小程序会话将失效，后台账号和固定绑定码保留。')) return;
        unbind.delete('/settings/wechat', { preserveScroll: true, onSuccess: () => unbind.reset() });
    }
    return <section className="surface">
        <div className="section-head"><h2>微信登录</h2><span className="pill">{state.bound ? '已绑定' : '未绑定'}</span></div>
        {!state.configured && <p role="status">微信登录尚未配置，请联系管理员。</p>}
        {state.configured && <WechatBindingCode url="/settings/wechat/code" />}
        {state.bound && <form className="account-form" aria-label="管理微信绑定" onSubmit={submit}>
            <label>解绑验证密码<input type="password" autoComplete="current-password" required value={unbind.data.current_password} onChange={e => unbind.setData('current_password', e.target.value)} /></label>
            {Object.values(unbind.errors).map((message, i) => <p role="alert" key={i}>{message}</p>)}
            <div><button disabled={unbind.processing}>解绑微信</button></div>
        </form>}
    </section>;
}
