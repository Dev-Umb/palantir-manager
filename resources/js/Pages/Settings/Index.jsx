import { useState } from 'react';
import { Head, useForm, useHttp, usePage } from '@inertiajs/react';
import Layout from '../../Components/Layout';
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
    const [binding, setBinding] = useState(null);
    const [error, setError] = useState('');
    const request = useHttp({ current_password: '' });
    const unbind = useForm({ current_password: '' });
    const form = state.bound ? unbind : request;
    function submit(event) {
        event.preventDefault();
        setError('');
        if (state.bound) {
            if (!window.confirm('确认解绑微信？已登录的小程序会话将失效，后台账号保留。')) return;
            unbind.delete('/settings/wechat', { preserveScroll: true, onSuccess: () => { unbind.reset(); setBinding(null); } });
        } else {
            setBinding(null);
            request.post('/settings/wechat/code', {
                onSuccess: (data) => { setBinding(data); request.reset(); },
                onError: () => setError('生成失败，请检查密码或稍后重试。'),
            });
        }
    }
    return <section className="surface">
        <div className="section-head"><h2>微信登录</h2><span className="pill">{state.bound ? '已绑定' : '未绑定'}</span></div>
        <p className="muted">{state.bound ? '小程序可直接使用已绑定微信登录。' : '生成绑定码后，在本人手机小程序中点击“首次绑定”，输入绑定码即可关联当前账号。'}</p>
        {!state.configured && <p role="status">微信登录尚未配置，请联系管理员。</p>}
        {(state.configured || state.bound) && <form className="account-form" aria-label="管理微信绑定" onSubmit={submit}>
            <label>验证当前账号密码<input type="password" autoComplete="current-password" required value={form.data.current_password} onChange={e => form.setData('current_password', e.target.value)} /></label>
            {Object.values(form.errors).map((message, i) => <p role="alert" key={i}>{message}</p>)}
            {error && <p role="alert">{error}</p>}
            <div><button disabled={form.processing}>{form.processing ? '处理中…' : state.bound ? '解绑微信' : '生成一次性绑定码'}</button></div>
        </form>}
        {binding && !state.bound && <div role="status"><p>绑定码：<strong style={{ letterSpacing: 2, overflowWrap: 'anywhere' }}>{binding.code}</strong></p><p className="muted">10 分钟有效，仅显示本次。重新生成后旧码作废；不要转发给他人。手机绑定后刷新本页查看状态。</p></div>}
    </section>;
}
