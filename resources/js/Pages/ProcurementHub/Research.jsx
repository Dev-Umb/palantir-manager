import { useForm, usePoll } from '@inertiajs/react';
import { Shell, Panel, Empty, Field, Errors, Report, Pages, inputClass, primary, root } from './shared';

export default function Research({ runs = { data: [] } }) {
    const form = useForm({ query: '' });
    usePoll(10000, { only: ['runs'] });
    return <Shell title="招采数据分析"><Panel title="研究一个集团、产品或项目"><p className="mb-4 text-sm text-slate-500">检索已收录证据，并由团队补查公开来源、分析历史价格和企业适配程度。</p><form className="flex flex-col gap-3 md:flex-row md:items-end" onSubmit={(e) => { e.preventDefault(); form.post(`${root}/research`, { onSuccess: () => form.reset() }); }}><div className="grow"><Field label="研究关键词"><input className={inputClass} value={form.data.query} onChange={(e) => form.setData('query', e.target.value)} placeholder="例如：中铁十八局、钢模板" required /></Field></div><button className={primary} disabled={form.processing}>开始研究</button></form><Errors errors={form.errors} /></Panel>{runs.data.length ? runs.data.map((r) => <Report key={r.id} run={r} />) : <Empty>还没有研究任务。提交关键词后，可在这里查看进度、分析结论和证据。</Empty>}<Pages links={runs.links} /></Shell>;
}
