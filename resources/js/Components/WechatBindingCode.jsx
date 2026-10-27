import { useState } from 'react';
import { useHttp } from '@inertiajs/react';

export default function WechatBindingCode({ url }) {
    const [code, setCode] = useState('');
    const [error, setError] = useState('');
    const request = useHttp({ current_password: '', reset: false });
    function submit(event) {
        event.preventDefault();
        if (request.data.reset && !window.confirm('确认重置固定绑定码？旧码立即失效，已绑定微信和登录状态保持不变。')) return;
        setCode('');
        setError('');
        request.post(url, {
            onSuccess: (data) => { setCode(data.code); request.reset(); },
            onError: () => setError('获取失败，请检查当前账号密码或稍后重试。'),
        });
    }
    return <form className="account-form" aria-label="固定微信绑定码" onSubmit={submit}>
        <p className="muted">首次使用小程序时输入固定绑定码，以后直接微信登录。绑定码长期有效。</p>
        <label>验证当前账号密码<input type="password" autoComplete="current-password" required value={request.data.current_password} onChange={e => request.setData('current_password', e.target.value)} /></label>
        <label><input type="checkbox" checked={request.data.reset} onChange={e => request.setData('reset', e.target.checked)} />重置绑定码（旧码失效）</label>
        {Object.values(request.errors).map((message, i) => <p role="alert" key={i}>{message}</p>)}
        {error && <p role="alert">{error}</p>}
        <div><button disabled={request.processing}>{request.processing ? '处理中…' : request.data.reset ? '重置固定绑定码' : '查看固定绑定码'}</button></div>
        {code && <div role="status"><p>固定绑定码：<strong style={{ letterSpacing: 2, overflowWrap: 'anywhere' }}>{code}</strong></p><p className="muted">仅交给此账号使用者；普通解绑不会更换此码。</p></div>}
    </form>;
}
