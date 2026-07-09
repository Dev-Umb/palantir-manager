import { Head, Link, useForm } from '@inertiajs/react';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    function submit(event) {
        event.preventDefault();
        form.post('/login');
    }

    return (
        <main className="auth-page">
            <Head title="登录" />
            <section className="auth-panel">
                <div className="auth-copy">
                    <span className="brand-mark">鑫</span>
                    <h1>鑫源昌智造中枢</h1>
                    <p>用账号密码进入本体工作台。基础角色默认只能看大盘和提交采购申请。</p>
                </div>
                <form onSubmit={submit} className="auth-form">
                    <label><span>邮箱</span><input value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} type="email" required autoFocus /></label>
                    <label><span>密码</span><input value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} type="password" required /></label>
                    {form.errors.email && <p className="form-error">{form.errors.email}</p>}
                    <button disabled={form.processing}>登录</button>
                    <Link href="/register">还没有账号？注册</Link>
                </form>
            </section>
        </main>
    );
}
