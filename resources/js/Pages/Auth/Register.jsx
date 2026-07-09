import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/register');
    }

    return (
        <main className="auth-page">
            <Head title="注册" />
            <section className="auth-panel">
                <div className="auth-copy">
                    <span className="brand-mark">鑫</span>
                    <h1>创建账号</h1>
                    <p>注册后自动获得基础角色，可查看大盘并提交采购申请；其它功能由管理员配置。</p>
                </div>
                <form onSubmit={submit} className="auth-form">
                    <label><span>姓名</span><input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required autoFocus /></label>
                    <label><span>邮箱</span><input value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} type="email" required /></label>
                    <label><span>密码</span><input value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} type="password" required /></label>
                    <label><span>确认密码</span><input value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} type="password" required /></label>
                    {Object.values(form.errors).map((error) => <p key={error} className="form-error">{error}</p>)}
                    <button disabled={form.processing}>注册并进入</button>
                    <Link href="/login">已有账号？登录</Link>
                </form>
            </section>
        </main>
    );
}
