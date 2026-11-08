import { useEffect, useRef, useState } from 'react';

async function request(path, method = 'GET', signal) {
    const response = await fetch(path, { method, signal, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } });
    const data = await response.json();
    if (!response.ok) throw new Error(response.status === 410 ? '二维码已过期，请刷新' : Object.values(data.errors || {}).flat().join('；') || data.message || '登录服务暂不可用');
    return data;
}

export default function WechatScanLogin() {
    const [code, setCode] = useState(null), [message, setMessage] = useState(''), [busy, setBusy] = useState(false), [ended, setEnded] = useState(false);
    const generation = useRef(0);
    useEffect(() => () => { generation.current++; }, []);
    async function create() {
        const current = ++generation.current;
        setBusy(true); setCode(null); setMessage('正在生成二维码…'); setEnded(false);
        try {
            const result = await request('/login/wechat', 'POST');
            if (current === generation.current) { setCode(result); setMessage('请用微信扫码，并在小程序内确认登录'); }
        } catch (error) { if (current === generation.current) setMessage(error.message); }
        finally { if (current === generation.current) setBusy(false); }
    }
    useEffect(() => {
        if (!code || ended) return;
        const controller = new AbortController();
        let timer;
        async function poll() {
            try {
                const result = await request(`/login/wechat/${code.id}`, 'GET', controller.signal);
                if (controller.signal.aborted) return;
                if (result.status === 'confirmed') {
                    const login = await request(`/login/wechat/${code.id}/consume`, 'POST', controller.signal);
                    if (!controller.signal.aborted) window.location.assign(login.redirect);
                    return;
                }
                if (result.status === 'cancelled') { setMessage('已取消登录，请刷新二维码重试'); setEnded(true); return; }
                timer = setTimeout(poll, 2000);
            } catch (error) { if (!controller.signal.aborted) { setMessage(error.message); setEnded(true); } }
        }
        timer = setTimeout(poll, 2000);
        return () => { controller.abort(); clearTimeout(timer); };
    }, [code, ended]);
    return <section aria-label="微信扫码登录">
        <button type="button" disabled={busy} onClick={create}>{code ? '刷新二维码' : '微信扫码登录'}</button>
        {code && !ended && <img src={code.image} alt="使用微信扫描此码登录" width="240" height="240" style={{ display: 'block', maxWidth: '100%', margin: '16px auto' }} />}
        <p role="status" aria-live="polite">{message}</p>
    </section>;
}
