import { Head, useForm, usePage } from '@inertiajs/react';
import ComboBox from '../../Components/ComboBox';
import Layout from '../../Components/Layout';

export default function Create({ materials, projects, submitUrl = '/requests', publicForm = false }) {
    const { flash } = usePage().props;
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    const form = useForm({
        requester: params.get('requester') || '生产',
        material_id: params.get('material_id') || materials[0]?.id || '',
        qty: params.get('qty') || '',
        unit: '吨',
        project_id: params.get('project_id') || projects[0]?.id || '',
        urgency: '普通',
        reason: params.get('reason') || '',
    });

    function submit(event) {
        event.preventDefault();
        form.post(submitUrl);
    }

    const content = (
        <>
            <Head title="提交采购申请" />
            <section className="surface request-surface">
                <div className="section-head">
                    <div>
                        <p>{publicForm ? '公开问卷' : '工作台申请'}</p>
                        <h2>采购申请</h2>
                    </div>
                    <span className="pill">提交后等待采购审批</span>
                </div>
                {publicForm && flash?.status && <div className="notice">{flash.status}</div>}
                <form className="form-grid" onSubmit={submit}>
                    <label><span>需求方</span><ComboBox value={form.data.requester} onChange={(value) => form.setData('requester', value)} options={options(['生产', '技术', '库管', '业务'])} /></label>
                    <label><span>物料</span><ComboBox value={form.data.material_id} onChange={(value) => form.setData('material_id', value)} options={materials.map((item) => ({ value: item.id, label: item.label }))} /></label>
                    <label><span>需求数量</span><input type="number" step="any" value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} required /></label>
                    <label><span>单位</span><ComboBox value={form.data.unit} onChange={(value) => form.setData('unit', value)} options={options(['吨', 'kg', '张', '根'])} /></label>
                    <label><span>关联项目</span><ComboBox value={form.data.project_id} onChange={(value) => form.setData('project_id', value)} options={[{ value: '', label: '不关联' }, ...projects.map((item) => ({ value: item.id, label: item.label }))]} /></label>
                    <label><span>紧急程度</span><ComboBox value={form.data.urgency} onChange={(value) => form.setData('urgency', value)} options={options(['普通', '紧急', '特急'])} /></label>
                    <label className="wide"><span>原因</span><textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="如：生产急需、盘库缺料" /></label>
                    {Object.values(form.errors).map((error) => <p key={error} className="form-error">{error}</p>)}
                    <button type="submit" disabled={form.processing}>{form.processing ? '提交中...' : '提交申请'}</button>
                </form>
            </section>
        </>
    );

    if (publicForm) {
        return <main className="public-page">{content}</main>;
    }

    return (
        <Layout title="提交采购申请" eyebrow="采购申请">
            {content}
        </Layout>
    );
}

function options(items) {
    return items.map((item) => ({ value: item, label: item }));
}
