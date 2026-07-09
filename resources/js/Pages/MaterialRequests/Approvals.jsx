import { Head, router } from '@inertiajs/react';
import { Check, XCircle } from 'lucide-react';
import Layout from '../../Components/Layout';

export default function Approvals({ pending, processed }) {
    return (
        <Layout title="领料审批" eyebrow="库管审批">
            <Head title="领料审批" />
            <section className="surface">
                <div className="section-head">
                    <div>
                        <p>待审批</p>
                        <h2>领料申请</h2>
                    </div>
                    <span className="pill">{pending.length} 条待处理</span>
                </div>
                {pending.length ? (
                    <div className="approval-list">
                        {pending.map((record) => <ApprovalCard key={record.id} record={record} actions />)}
                    </div>
                ) : <p className="muted">暂无待审批领料申请。</p>}
            </section>

            <section className="surface">
                <div className="section-head">
                    <div>
                        <p>已处理</p>
                        <h2>审批记录</h2>
                    </div>
                </div>
                {processed.length ? (
                    <div className="approval-list compact">
                        {processed.map((record) => <ApprovalCard key={record.id} record={record} />)}
                    </div>
                ) : <p className="muted">暂无已处理记录。</p>}
            </section>
        </Layout>
    );
}

function ApprovalCard({ record, actions = false }) {
    const payload = record.payload || {};
    const display = record.display || {};

    function approve() {
        router.post(`/material-requests/${record.id}/approve`, {}, { preserveScroll: true });
    }

    function reject() {
        router.post(`/material-requests/${record.id}/reject`, {}, { preserveScroll: true });
    }

    return (
        <article className="approval-card">
            <div>
                <span className="mono">{record.code}</span>
                <h3>{display.material_id || '未选择物料'}</h3>
                <p>{payload.reason || '未填写用途'}</p>
            </div>
            <dl>
                <div><dt>申请人</dt><dd>{payload.requester || '-'}</dd></div>
                <div><dt>数量</dt><dd>{payload.qty || '-'} {payload.unit || ''}</dd></div>
                <div><dt>项目</dt><dd>{display.project_id || '不关联'}</dd></div>
                <div><dt>班组</dt><dd>{payload.team || '-'}</dd></div>
                <div><dt>状态</dt><dd><span className="pill">{display.status || payload.status || '-'}</span></dd></div>
                {display.outbound_id && <div><dt>出库单</dt><dd>{display.outbound_id}</dd></div>}
            </dl>
            {actions && (
                <div className="approval-actions">
                    <button type="button" className="icon-success" onClick={approve} title="通过">
                        <Check size={16} /> 通过并出库
                    </button>
                    <button type="button" className="icon-warning" onClick={reject} title="驳回">
                        <XCircle size={16} /> 驳回
                    </button>
                </div>
            )}
        </article>
    );
}
