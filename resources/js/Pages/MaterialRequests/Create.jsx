import { Head, useForm, usePage } from '@inertiajs/react';
import ComboBox from '../../Components/ComboBox';

export default function Create({ materials, projects, submitUrl = '/material-request' }) {
    const { flash } = usePage().props;
    const form = useForm({
        requester: '',
        material_id: materials[0]?.id || '',
        project_id: projects[0]?.id || '',
        qty: '',
        unit: '张',
        team: '下料班组',
        apply_date: new Date().toISOString().slice(0, 10),
        reason: '',
    });

    function submit(event) {
        event.preventDefault();
        form.post(submitUrl);
    }

    return (
        <main className="public-page">
            <Head title="领料申请" />
            <section className="surface request-surface">
                <div className="section-head">
                    <div>
                        <p>公开问卷</p>
                        <h2>领料申请</h2>
                    </div>
                    <span className="pill">提交后等待库管审批</span>
                </div>
                {flash?.status && <div className="notice">{flash.status}</div>}
                <form className="form-grid" onSubmit={submit}>
                    <label><span>申请人/部门</span><input value={form.data.requester} onChange={(e) => form.setData('requester', e.target.value)} required /></label>
                    <label><span>物料</span><ComboBox value={form.data.material_id} onChange={(value) => form.setData('material_id', value)} options={materials.map((item) => ({ value: item.id, label: item.label }))} /></label>
                    <label><span>关联项目</span><ComboBox value={form.data.project_id} onChange={(value) => form.setData('project_id', value)} options={[{ value: '', label: '不关联' }, ...projects.map((item) => ({ value: item.id, label: item.label }))]} /></label>
                    <label><span>申请数量</span><input type="number" step="any" value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} required /></label>
                    <label><span>单位</span><ComboBox value={form.data.unit} onChange={(value) => form.setData('unit', value)} options={options(['张', '根', 'kg', '吨', '桶', '盒'])} /></label>
                    <label><span>班组</span><ComboBox value={form.data.team} onChange={(value) => form.setData('team', value)} options={options(['下料班组', '焊接班组', '装配班组'])} /></label>
                    <label><span>申请日期</span><input type="date" value={form.data.apply_date} onChange={(e) => form.setData('apply_date', e.target.value)} /></label>
                    <label className="wide"><span>用途</span><textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="如：项目下料、补料、返工" /></label>
                    {Object.values(form.errors).map((error) => <p key={error} className="form-error">{error}</p>)}
                    <button type="submit" disabled={form.processing}>{form.processing ? '提交中...' : '提交申请'}</button>
                </form>
            </section>
        </main>
    );
}

function options(items) {
    return items.map((item) => ({ value: item, label: item }));
}
