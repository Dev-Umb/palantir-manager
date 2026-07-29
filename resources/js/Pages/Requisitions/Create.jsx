import { Head, useForm, usePage } from '@inertiajs/react';
import ComboBox from '../../Components/ComboBox';
import Layout from '../../Components/Layout';

export default function Create({
    materials,
    projects,
    materialSearchUrl = '',
    projectSearchUrl = '',
    submitUrl = '/requests',
    publicForm = false,
}) {
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
                    <label><span>需求方</span><ComboBox value={form.data.requester} onChange={(value) => form.setData('requester', value)} options={options(['生产', '技术', '业务'])} /></label>
                    <label><span>物料</span><ComboBox value={form.data.material_id} onChange={(value) => form.setData('material_id', value)} options={materials.map((item) => ({ value: item.id, label: item.label }))} searchUrl={materialSearchUrl} /></label>
                    <label>
                        <span>需求数量<b>*</b></span>
                        <input type="number" step="any" min="0.01" value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} required />
                    </label>
                    <label><span>单位</span><ComboBox value={form.data.unit} onChange={(value) => form.setData('unit', value)} options={options(['吨', 'kg', '张', '根'])} /></label>
                    {!publicForm && <label><span>关联项目</span><ComboBox value={form.data.project_id} onChange={(value) => form.setData('project_id', value)} options={[{ value: '', label: '不关联' }, ...projects.map((item) => ({ value: item.id, label: item.label }))]} searchUrl={projectSearchUrl} /></label>}
                    <label><span>紧急程度</span><ComboBox value={form.data.urgency} onChange={(value) => form.setData('urgency', value)} options={options(['普通', '紧急', '特急'])} /></label>
                    <label className="wide">
                        <span>申请原因 <em>建议填写</em></span>
                        <textarea
                            value={form.data.reason}
                            maxLength={500}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            placeholder="说明为什么需要采购，审批人会更快判断。例如：生产现场缺料，需在 7 月 30 日前到货"
                        />
                        <small className="field-hint">写清用途和期望时间，可减少审批往返。</small>
                    </label>
                    {Object.values(form.errors).map((error) => <p key={error} className="form-error" role="alert">{error}</p>)}
                    <div className="form-actions request-form-actions">
                        <span>提交后将进入采购审批，审批前请确认物料、数量和项目。</span>
                        <button type="submit" disabled={form.processing}>{form.processing ? '正在提交...' : '确认并提交申请'}</button>
                    </div>
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
