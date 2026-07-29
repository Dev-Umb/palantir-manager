import { Head, useForm, usePage } from '@inertiajs/react';
import ComboBox from '../../Components/ComboBox';

const statuses = ['开始生产', '生产中', '异常暂停', '完成任务'];
const processes = ['切割', '焊接', '总装', '打磨', '其他'];
const exceptions = ['无', '缺料', '图纸问题', '设备故障', '质量问题', '人员不足', '其他'];

export default function Create({
    projects,
    teams,
    materials,
    searchUrls = {},
    submitUrl = '/team-log',
    publicForm = false,
}) {
    const { flash } = usePage().props;
    const form = useForm({
        project_id: projects[0]?.id || '',
        team_id: teams[0]?.id || '',
        status: '生产中',
        process: '切割',
        completed_qty: '',
        unit: '件',
        exception_type: '无',
        part_name: '',
        work_date: new Date().toISOString().slice(0, 10),
        remark: '',
        attachment: null,
        shortage_material_id: materials[0]?.id || '',
        shortage_qty: '',
        shortage_unit: '张',
    });

    function submit(event) {
        event.preventDefault();
        form.post(submitUrl, {
            onSuccess: () => form.reset('completed_qty', 'exception_type', 'remark', 'attachment', 'shortage_qty'),
        });
    }
    const selectedTeam = teams.find((team) => team.id === form.data.team_id);
    const hasException = form.data.exception_type !== '无';

    return (
        <main className="public-page">
            <Head title="现场报工" />
            <section className="surface request-surface shop-floor-surface">
                <div className="section-head">
                    <div>
                        <p>{publicForm ? '无需登录 · 扫码即填' : '手机端 · 一分钟完成'}</p>
                        <h2>现场报工</h2>
                    </div>
                    <span className="pill">{publicForm ? '提交后可继续填写下一条' : '项目、班组和日期自动带出'}</span>
                </div>
                {flash?.status && <div className="notice">{flash.status}</div>}
                <form className="form-grid" onSubmit={submit}>
                    <label><span>项目</span><ComboBox value={form.data.project_id} onChange={(value) => form.setData('project_id', value)} options={projects.map(option)} searchUrl={searchUrls.project_id} /></label>
                    <label><span>班组</span><ComboBox value={form.data.team_id} onChange={(value) => form.setData('team_id', value)} options={teams.map(option)} searchUrl={searchUrls.team_id} /></label>
                    <label><span>班组负责人</span><input value={selectedTeam?.meta?.leader_name || '暂未配置'} readOnly /></label>
                    <fieldset className="wide quick-choice">
                        <legend>当前状态</legend>
                        <div className="quick-choice-grid">
                            {statuses.map((status) => (
                                <button
                                    className={form.data.status === status ? 'selected' : ''}
                                    key={status}
                                    type="button"
                                    onClick={() => form.setData('status', status)}
                                >
                                    {status}
                                </button>
                            ))}
                        </div>
                    </fieldset>
                    <label><span>当前工序</span><ComboBox value={form.data.process} onChange={(value) => form.setData('process', value)} options={processes.map(valueOption)} /></label>
                    <label><span>加工产品/部件</span><input value={form.data.part_name} onChange={(event) => form.setData('part_name', event.target.value)} /></label>
                    <label><span>本次完成数量</span><input min="0" step="any" type="number" value={form.data.completed_qty} onChange={(event) => form.setData('completed_qty', event.target.value)} /></label>
                    <label><span>单位</span><ComboBox value={form.data.unit} onChange={(value) => form.setData('unit', value)} options={['件', '套', 'kg', '吨', '张', '根'].map(valueOption)} /></label>
                    <label><span>报工日期</span><input type="date" value={form.data.work_date} onChange={(event) => form.setData('work_date', event.target.value)} /></label>
                    <fieldset className="wide quick-choice exception-choice">
                        <legend>是否有异常</legend>
                        <div className="quick-choice-grid">
                            {exceptions.map((exception) => (
                                <button
                                    className={form.data.exception_type === exception ? 'selected' : ''}
                                    key={exception}
                                    type="button"
                                    onClick={() => form.setData('exception_type', exception)}
                                >
                                    {exception}
                                </button>
                            ))}
                        </div>
                    </fieldset>
                    {form.data.exception_type === '缺料' && (
                        <section className="wide shortage-fields">
                            <strong>缺料信息</strong>
                            <p>提交报工后，系统会自动生成一张紧急采购申请。</p>
                            <div>
                                <label><span>所缺物料</span><ComboBox value={form.data.shortage_material_id} onChange={(value) => form.setData('shortage_material_id', value)} options={materials.map(option)} searchUrl={searchUrls.material_id} /></label>
                                <label><span>需求数量</span><input min="0.01" step="any" type="number" value={form.data.shortage_qty} onChange={(event) => form.setData('shortage_qty', event.target.value)} required /></label>
                                <label><span>单位</span><ComboBox value={form.data.shortage_unit} onChange={(value) => form.setData('shortage_unit', value)} options={['吨', 'kg', '张', '根'].map(valueOption)} /></label>
                            </div>
                        </section>
                    )}
                    <label className="wide"><span>{hasException ? '异常说明' : '备注（可选）'}</span><textarea required={hasException} value={form.data.remark} onChange={(event) => form.setData('remark', event.target.value)} placeholder={hasException ? '简单说明现场情况' : '没有需要说明的内容可以不填'} /></label>
                    <label className="wide"><span>现场照片（可选）</span><input accept=".pdf,.jpg,.jpeg,.png" type="file" onChange={(event) => form.setData('attachment', event.target.files[0] || null)} /></label>
                    {Object.values(form.errors).map((error) => <p key={error} className="form-error">{error}</p>)}
                    <button className="wide shop-floor-submit" type="submit" disabled={form.processing}>{form.processing ? '提交中...' : form.data.exception_type === '缺料' ? '提交报工并申请采购' : '提交现场报工'}</button>
                </form>
            </section>
        </main>
    );
}

function option(item) {
    return { value: item.id, label: item.label };
}

function valueOption(value) {
    return { value, label: value };
}
