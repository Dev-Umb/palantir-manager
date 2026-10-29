import { Head, Link } from '@inertiajs/react';
import Layout from '../../Components/Layout';
export default function Draft({ draft }) {
    const p = draft.payload;
    return <Layout hideHeader><Head title="报销草稿" /><main className="oa">
        <header className="oa-head"><div><h1>{p.title || '未命名报销'}</h1><p>草稿 · 仅自己可见 · 请在 APP 或小程序继续编辑</p></div><Link href="/reimbursements/drafts">返回我的草稿</Link></header>
        <section className="oa-panel"><p>保存时间：{new Date(draft.updated_at).toLocaleString('zh-CN')}</p><p>报销类型：{p.type || '未填写'}</p><p style={{ whiteSpace: 'pre-wrap' }}>报销说明：{p.note || '未填写'}</p>
        {p.items.map((i, n) => <section key={n} className="oa-panel"><h2>费用 {n + 1}</h2><p>金额：{i.amount || '未填写'}</p><p>开票日期：{i.invoice_date || '未填写'}</p><p style={{ whiteSpace: 'pre-wrap' }}>费用说明：{i.description || '未填写'}</p>{i.attachments.map(a => <p key={a.id}><a href={a.url}>{a.name}</a></p>)}</section>)}</section>
    </main></Layout>;
}
