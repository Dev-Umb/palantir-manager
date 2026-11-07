import { Head, Link } from '@inertiajs/react';
import Layout from '../../Components/Layout';
export default function Draft({ draft }) {
    const p = draft.payload;
    return <Layout hideHeader><Head title="报销草稿" /><main className="oa">
        <header className="oa-head"><div><h1>{p.title || '未命名报销'}</h1><p>草稿 · 仅自己可见 · 请在 APP 或小程序继续编辑</p></div><Link href="/reimbursements/drafts">返回我的草稿</Link></header>
        <section className="oa-panel"><p>保存时间：{new Date(draft.updated_at).toLocaleString('zh-CN')}</p><p>报销类型：{p.type || '未填写'}</p><p style={{ whiteSpace: 'pre-wrap' }}>报销说明：{p.note || '未填写'}</p>
        {p.items.map((i, n) => <section key={n} className="oa-panel"><h2>费用 {n + 1}</h2><p>金额：{i.amount || '未填写'}</p><p>开票日期：{i.invoice_date || '未填写'}</p>{i.evidence_source && <p>凭证来源：第 {i.evidence_source.candidate_id.split('-')[0]} 页 · 凭证 {i.evidence_source.candidate_id.split('-')[1]}</p>}<p style={{ whiteSpace: 'pre-wrap' }}>费用说明：{i.description || '未填写'}</p>{i.attachments.map(a => <p key={a.id}><a href={a.url}>{a.name}</a>{a.evidence_status && <span role="status"> · {{queued:'等待识别',processing:'识别中',completed:'已识别',attention:'🔴 待核对',failed:'🔴 识别失败',reviewed:'已手工核对'}[a.evidence_status]}{a.evidence_status !== 'reviewed' && a.evidence_result?.issues?.map(issue => `；第 ${issue.page} 页：${issue.message}`).join('')}</span>}</p>)}</section>)}</section>
    </main></Layout>;
}
