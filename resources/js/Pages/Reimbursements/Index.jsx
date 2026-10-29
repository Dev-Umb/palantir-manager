import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Components/Layout';
import { types, statuses, money, Status, Pages } from './shared';

export default function Index({ records, filters, capabilities, pending_count = 0 }) {
    const [form, setForm] = useState(filters);
    const field = (key) => ({ value: form[key] || '', onChange: (e) => setForm({ ...form, [key]: e.target.value }) });
    const selectStatus = (status) => {
        const next = { ...form, status };
        setForm(next);
        router.get('/reimbursements', next);
    };
    return <Layout hideHeader><Head title="报销审批" /><main className="oa oa-list">
        <header className="oa-head">
            <div><h1>报销审批</h1><p>每一笔费用，清晰有据</p></div>
            <div className="oa-list-tools">
                <span className="oa-pending" title="当前范围与筛选条件下的待审批单据，不代表全部需要由你处理">{pending_count} 笔待审批</span>
                <div className="oa-actions"><Link href="/reimbursements/drafts">我的草稿</Link><Link href="/reimbursements/notices">报销通知</Link>{capabilities.configure && <Link href="/reimbursements/workflow">流程配置</Link>}</div>
            </div>
        </header>
        <nav className="oa-tabs" aria-label="审批状态">
            {[['', '全部申请'], ['pending', '待审批'], ['approved', '已通过'], ['rejected', '已驳回']].map(([value, label]) => <button key={value} type="button" aria-current={(filters.status || '') === value ? 'page' : undefined} onClick={() => selectStatus(value)}>{label}</button>)}
        </nav>
        <section className="oa-panel oa-list-panel" aria-label="报销申请列表">
            <form className="oa-list-filters" onSubmit={(e) => { e.preventDefault(); router.get('/reimbursements', form); }}>
                <label>发起人<input {...field('owner')} placeholder="输入姓名" /></label>
                <label>报销类型<select {...field('type')}><option value="">全部类型</option>{types.map((type) => <option key={type}>{type}</option>)}</select></label>
                <label>开始日期<input type="date" {...field('from')} /></label>
                <label>结束日期<input type="date" {...field('to')} min={form.from || undefined} /></label>
                <label>状态<select {...field('status')}><option value="">全部状态</option>{Object.entries(statuses).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
                <label>范围<select {...field('scope')}><option value="mine">我的报销</option><option value="review">分配给我的审批</option><option value="cc">知会我的</option>{capabilities.manage && <option value="all">全部报销</option>}</select></label>
                <div className="oa-filter-actions"><button className="oa-primary">查询</button><button type="button" className="oa-secondary" onClick={() => { setForm({ scope: filters.scope }); router.get('/reimbursements', { scope: filters.scope }); }}>重置</button></div>
            </form>
            <div className="oa-table"><table><thead><tr>{['申请 / 单号', '发起人', '类型', '报销金额', '提交时间', '状态', '操作'].map((x) => <th key={x}>{x}</th>)}</tr></thead>
                <tbody>{records.data.map((r) => <tr key={r.id}>
                    <td><strong>{r.title}</strong><small>BX-{r.reimbursement_id} · V{r.number}</small></td>
                    <td><span className="oa-owner"><span className="oa-avatar" aria-hidden="true">{Array.from(r.owner?.name || '?')[0]}</span>{r.owner?.name}</span></td>
                    <td>{r.type}</td><td><strong>¥{money(r.total_cents)}</strong></td>
                    <td><time dateTime={r.created_at} title={new Date(r.created_at).toLocaleString('zh-CN')}>{new Date(r.created_at).toLocaleDateString('sv-SE')}</time></td>
                    <td><Status value={r.status} />{r.blocked && <small role="alert">审批人失效，流程阻塞</small>}</td>
                    <td><Link href={r.url}>{r.can_decide ? '处理审批' : '查看详情'} ›</Link></td>
                </tr>)}</tbody>
            </table>{records.data.length === 0 && <p className="oa-empty">暂无符合条件的报销申请</p>}</div>
            <Pages data={records} />
        </section>
    </main></Layout>;
}
