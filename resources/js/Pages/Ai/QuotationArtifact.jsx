import { useEffect, useState } from 'react';

const blankItem = () => ({ name: '', unit: '', price: '', material_price: '', processing_price: '' });
const defaults = (data) => ({
    title: data.title || '', date: data.date || '', contact: data.contact || '', phone: data.phone || '',
    tax_rate: data.tax_rate ?? '13', shipping: data.shipping ?? '含运费',
    items: (data.items?.length ? data.items : [blankItem()]).map((item) => ({ ...blankItem(), ...Object.fromEntries(Object.entries(item).map(([key, value]) => [key, value ?? ''])) })),
});

export default function QuotationArtifact({ artifact, runId, canAct = true }) {
    const [values, setValues] = useState(() => defaults(artifact.data || {}));
    const [result, setResult] = useState(artifact);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        if (artifact.data?.generated) {
            setResult(artifact);
            setValues(defaults(artifact.data));
        }
    }, [artifact]);
    const generated = result.data?.generated;
    const endpoint = `/ai/runs/${encodeURIComponent(runId)}/quotations/${encodeURIComponent(artifact.id)}`;
    const disabled = !canAct || processing || generated;
    const set = (key, value) => setValues((current) => ({ ...current, [key]: value }));
    const setItem = (index, key, value) => setValues((current) => ({ ...current, items: current.items.map((item, i) => i === index ? { ...item, [key]: value } : item) }));

    async function generate(event) {
        event.preventDefault();
        setError('');
        if ([values.title, values.date, values.contact, values.phone].some((value) => !String(value).trim()) || values.items.some((item) => !item.name.trim() || !item.unit || item.price === '')) {
            setError('请补齐报价标题、日期、联系人、电话及每项产品的名称、计价单位和综合单价。');
            return;
        }
        if (String(values.tax_rate) !== '13' || values.shipping !== '含运费') {
            setError('原模板固定为含税13%、含运费。当前要求有冲突，请先核对税运口径。');
            return;
        }
        setProcessing(true);
        try {
            const response = await fetch(endpoint, {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ ...values, items: values.items.map((item) => ({ ...item, material_price: item.material_price === '' ? null : item.material_price, processing_price: item.processing_price === '' ? null : item.processing_price })) }),
            });
            const body = await response.json();
            if (!response.ok) throw new Error(Object.values(body.errors || {}).flat().join('；') || body.message || '报价生成失败，请重试。');
            setResult(body.artifact);
        } catch (requestError) {
            setError(requestError.message || '报价生成失败，请重试。已填写内容保留。');
        } finally {
            setProcessing(false);
        }
    }

    return (
        <section className="ai-artifact ai-form-artifact" aria-label="固定模板报价单">
            <div className="ai-artifact-heading"><strong>固定模板报价单</strong><span>{generated ? '已生成' : '待核对'}</span></div>
            {generated && <div className="ai-choice-body" aria-label="已生成报价文件">
                <strong>报价文件：quotation.docx</strong>
                <a href={`${endpoint}/download`} download style={{ display: 'inline-flex', width: 'fit-content', padding: '10px 16px', borderRadius: '6px', background: 'var(--steel)', color: '#fff', textDecoration: 'none', fontWeight: 650 }}>下载盖章报价单 DOCX</a>
                <small>点击下载到本机；也可从个人历史对话重新下载。</small>
            </div>}
            <form className="ai-form-body" onSubmit={generate} noValidate>
                <p>文水县鑫源昌钢结构有限公司 · 原模板及公章保持不变</p>
                <div className="ai-form-fields">
                    {[['title', '报价标题', 'text'], ['date', '报价日期', 'date'], ['contact', '联系人', 'text'], ['phone', '联系电话', 'tel']].map(([key, label, type]) => (
                        <label key={key}><span>{label}</span><input aria-label={label} type={type} value={values[key]} disabled={disabled} onChange={(event) => set(key, event.target.value)} /></label>
                    ))}
                </div>
                {values.items.map((item, index) => (
                    <fieldset key={index} disabled={disabled}>
                        <legend>产品 {index + 1}</legend>
                        <div className="ai-form-fields">
                            <label><span>物资名称</span><input aria-label={`产品${index + 1}名称`} value={item.name} onChange={(event) => setItem(index, 'name', event.target.value)} /></label>
                            <label><span>综合单价</span><input aria-label={`产品${index + 1}综合单价`} type="number" min="0" step="0.01" value={item.price} onChange={(event) => setItem(index, 'price', event.target.value)} /></label>
                            <label><span>计价单位</span><select aria-label={`产品${index + 1}计价单位`} value={item.unit} onChange={(event) => setItem(index, 'unit', event.target.value)}><option value="">请选择</option>{['吨', '套', '吨日', '件', '台', '米', '平方米'].map((unit) => <option key={unit} value={unit}>元/{unit}</option>)}</select></label>
                        </div>
                        <details><summary>材料费、加工费（可选）</summary><div className="ai-form-fields">{[['material_price', '材料费'], ['processing_price', '加工费']].map(([key, label]) => <label key={key}><span>{label}</span><input aria-label={`产品${index + 1}${label}`} type="number" min="0" step="0.01" value={item[key]} onChange={(event) => setItem(index, key, event.target.value)} /></label>)}</div></details>
                        {!generated && values.items.length > 1 && <button type="button" disabled={disabled} onClick={() => setValues((current) => ({ ...current, items: current.items.filter((_, i) => i !== index) }))}>删除产品{index + 1}</button>}
                    </fieldset>
                ))}
                {!generated && values.items.length < 3 && <button type="button" disabled={disabled} onClick={() => setValues((current) => ({ ...current, items: [...current.items, blankItem()] }))}>添加产品</button>}
                <p>单页最多三条明细。固定备注：含税含运费，税率13%。未提供的材料费、加工费填“—”。</p>
                {(String(values.tax_rate) !== '13' || values.shipping !== '含运费') && <div className="ai-form-fields"><label><span>当前税率（须核对）</span><input aria-label="当前税率" value={values.tax_rate} disabled={disabled} onChange={(event) => set('tax_rate', event.target.value)} /></label><label><span>当前运费口径（须核对）</span><input aria-label="当前运费口径" value={values.shipping} disabled={disabled} onChange={(event) => set('shipping', event.target.value)} /></label></div>}
                {error && <p role="alert">{error}</p>}
                {!generated && <button className="action-button" type="submit" disabled={!canAct || processing || !runId}>{processing ? '正在生成…' : '生成盖章报价单'}</button>}
                {generated && <p>报价文件已保存到本次对话，可从个人历史对话重新下载。</p>}
            </form>
        </section>
    );
}
