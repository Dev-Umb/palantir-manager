import { Head, Link } from '@inertiajs/react';
import Layout from '../../Components/Layout';
import { Pages } from './shared';
export default function Drafts({ records }) {
    return <Layout hideHeader><Head title="我的报销草稿" /><main className="oa oa-list">
        <header className="oa-head"><div><h1>我的草稿</h1><p>未提交，仅自己可见；请在 APP 或小程序继续编辑。</p></div><Link href="/reimbursements">返回报销清单</Link></header>
        <section className="oa-panel"><div className="oa-table"><table><thead><tr><th>报销标题</th><th>类型</th><th>附件</th><th>保存时间</th><th>操作</th></tr></thead><tbody>{records.data.map(d => <tr key={d.id}><td>{d.payload.title || '未命名报销'}</td><td>{d.payload.type || '未选择'}</td><td>{d.payload.items.reduce((n, i) => n + i.attachments.length, 0)} 个</td><td>{new Date(d.updated_at).toLocaleString('zh-CN')}</td><td><Link href={d.url}>查看草稿</Link></td></tr>)}</tbody></table></div>{!records.data.length && <p className="oa-empty">暂无草稿</p>}<Pages data={records} /></section>
    </main></Layout>;
}
