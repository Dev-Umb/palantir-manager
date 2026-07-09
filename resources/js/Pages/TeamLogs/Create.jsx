import { Head, useForm, usePage } from '@inertiajs/react';
import ComboBox from '../../Components/ComboBox';

export default function Create({ workOrders, submitUrl = '/team-log' }) {
    const { flash } = usePage().props;
    const form = useForm({
        work_order_id: workOrders[0]?.id || '',
        part_name: '',
        team: '班组A',
        real_qty: '',
        work_date: new Date().toISOString().slice(0, 10),
    });

    function submit(event) {
        event.preventDefault();
        form.post(submitUrl);
    }

    return (
        <main className="public-page">
            <Head title="班组日报" />
            <section className="surface request-surface">
                <div className="section-head">
                    <div>
                        <p>公开问卷</p>
                        <h2>班组日报</h2>
                    </div>
                    <span className="pill">生产进度收集</span>
                </div>
                {flash?.status && <div className="notice">{flash.status}</div>}
                <form className="form-grid" onSubmit={submit}>
                    <label><span>生产任务</span><ComboBox value={form.data.work_order_id} onChange={(value) => form.setData('work_order_id', value)} options={workOrders.map((item) => ({ value: item.id, label: item.label }))} /></label>
                    <label><span>加工产品/部件</span><input value={form.data.part_name} onChange={(e) => form.setData('part_name', e.target.value)} /></label>
                    <label><span>班组</span><ComboBox value={form.data.team} onChange={(value) => form.setData('team', value)} options={options(['班组A', '班组B', '班组C'])} /></label>
                    <label><span>实际加工数量</span><input type="number" step="any" value={form.data.real_qty} onChange={(e) => form.setData('real_qty', e.target.value)} required /></label>
                    <label><span>加工日期</span><input type="date" value={form.data.work_date} onChange={(e) => form.setData('work_date', e.target.value)} /></label>
                    {Object.values(form.errors).map((error) => <p key={error} className="form-error">{error}</p>)}
                    <button type="submit" disabled={form.processing}>{form.processing ? '提交中...' : '提交日报'}</button>
                </form>
            </section>
        </main>
    );
}

function options(items) {
    return items.map((item) => ({ value: item, label: item }));
}
