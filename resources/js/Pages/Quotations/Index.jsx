import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../../Components/Layout';

const contextFields = ['product', 'spec', 'unit', 'tax_rate', 'tax_basis', 'shipping', 'price_date', 'market', 'material', 'steel_spec'];
const names = { project_name: '项目名称', customer: '客户名称', product: '产品名称', spec: '规格 / 构造', mode: '计价方式', unit: '计价单位', quantity: '数量', days: '租期天数', tax_rate: '税率', tax_basis: '基价税口径', shipping: '运费口径', destination: '交付地点', terms: '报价说明', extra_fee: '额外费用', price_date: '价格日期', market: '市场', material: '材质', steel_spec: '钢材规格', fee_amount: '加工费', steel_amount: '材料基价' };
const money = (cents) => cents === null ? '仅出单价' : '¥ ' + (cents / 100).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export function adoptionKey() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}
export function emptyParameters(today) {
    return { project_id: '', project_name: '', customer: '', product: '', spec: '', mode: 'total', unit: '吨', quantity: '', days: '', tax_rate: '', tax_basis: '', shipping: '', destination: '', terms: '仅供参考；最终按确认图纸及实际计价数量结算。', extra_fee: '0', price_date: today, market: '', material: '', steel_spec: '' };
}
export async function quoteRequest(url, data, method = 'POST') {
    const multipart = data instanceof FormData;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 240000);
    try {
        const response = await fetch(url, {
            method, signal: controller.signal, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', ...(multipart || method === 'GET' ? {} : { 'Content-Type': 'application/json' }) },
            ...(method === 'GET' ? {} : { body: multipart ? data : JSON.stringify(data) }),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(response.status === 419 || response.status === 401 ? '登录已过期，请刷新并重新登录。' : Object.entries(result.errors || {}).flatMap(([key, values]) => values.map((v) => v.startsWith('validation.') ? (names[key.replace(/^params\./, '')] || (key.startsWith('context') ? '对话内容' : '输入信息')) + '需要补充或核对' : v)).join('；') || result.message || '操作未完成，请稍后重试。');
        return result;
    } catch (error) {
        if (error.name === 'AbortError') throw new Error('请求超时，已有输入保留，请稍后重试。');
        throw error;
    } finally { clearTimeout(timer); }
}

export default function QuotationIndex({ projects = [], owner, today, urls, archives: initialArchives }) {
    const page = usePage();
    const [params, setParams] = useState(() => emptyParameters(today));
    const [messages, setMessages] = useState([]);
    const [message, setMessage] = useState('');
    const [files, setFiles] = useState([]);
    const [filePreviews, setFilePreviews] = useState([]);
    const attachmentUrls = useRef([]);
    useEffect(() => () => attachmentUrls.current.forEach((url) => URL.revokeObjectURL(url)), []);
    const [proposals, setProposals] = useState([]);
    const [prices, setPrices] = useState({ fee: null, steel: null });
    const [amounts, setAmounts] = useState({ fee: '', steel: '' });
    const [notes, setNotes] = useState({ fee: '', steel: '' });
    const [history, setHistory] = useState(null);
    const [market, setMarket] = useState(null);
    const [preview, setPreview] = useState(null);
    const [archives, setArchives] = useState(initialArchives || { data: [], total: 0, current_page: 1, last_page: 1 });
    const [sidebarOpen, setSidebarOpen] = useState(() => window.innerWidth > 820);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [adoption, setAdoption] = useState(null);
    const [detail, setDetail] = useState(null);
    const [search, setSearch] = useState('');
    const [saved, setSaved] = useState(null);
    const fileInput = useRef(null);
    const conversationStart = useRef(null);
    useEffect(() => { conversationStart.current?.scrollIntoView?.({ block: 'start', behavior: 'smooth' }); }, [messages]);
    const dialog = useRef(null);
    async function action(name, work) {
        if (busy) return;
        setBusy(name); setError(''); setNotice('');
        try { await work(); } catch (e) { setError(e.message); } finally { setBusy(''); }
    }
    function patch(field, value) {
        setParams((current) => ({ ...current, [field]: value }));
        setPreview(null); setSaved(null);
        if (contextFields.includes(field)) { setPrices({ fee: null, steel: null }); setHistory(null); setMarket(null); }
    }
    function fresh() {
        attachmentUrls.current.forEach((url) => URL.revokeObjectURL(url)); attachmentUrls.current = []; setFilePreviews([]);
        setParams(emptyParameters(today)); setMessages([]); setProposals([]); setPrices({ fee: null, steel: null }); setAmounts({ fee: '', steel: '' }); setNotes({ fee: '', steel: '' });
        setPreview(null); setSaved(null); setHistory(null); setMarket(null); setFiles([]); setMessage(''); setError(''); setNotice('');
        if (fileInput.current) fileInput.current.value = '';
    }
    async function send() {
        const text = message.trim();
        if (!text && !files.length) return;
        const form = new FormData();
        form.append('message', text || '请分析附件，提取明确的报价参数并指出需要核对的字段。');
        messages.filter((m) => typeof m.content === 'string' && m.content.trim()).slice(-24).forEach((m, i) => { form.append('context[' + i + '][role]', m.role); form.append('context[' + i + '][content]', m.content.slice(0, 4000)); });
        Object.entries(params).forEach(([key, value]) => form.append('params[' + key + ']', String(value ?? '')));
        files.forEach((file) => form.append('attachments[]', file));
        await action('chat', async () => {
            const result = await quoteRequest(urls.chat, form);
            setMessages((items) => [...items, { role: 'user', content: text || '分析所附图片 / PDF', files: files.map((f) => f.name), previews: filePreviews }, { role: 'assistant', content: result.answer, questions: result.questions }]);
            setProposals(result.proposals || []); setMessage(''); setFiles([]); setFilePreviews([]);
            if (fileInput.current) fileInput.current.value = '';
        });
    }
    async function confirm(kind, suggestion = null) {
        await action(kind, async () => {
            const result = await quoteRequest(urls.price, { params, kind, source: suggestion ? suggestion.price?.source || 'internet' : 'user', amount: suggestion ? null : amounts[kind], source_note: notes[kind], suggestion_token: suggestion?.suggestion_token, confirmed: true });
            setPrices((current) => ({ ...current, [kind]: result })); setPreview(null); setSaved(null); setNotice((kind === 'fee' ? '加工费' : '材料基价') + '已独立确认');
        });
    }
    async function calculate() {
        await action('calculate', async () => {
            const result = await quoteRequest(urls.calculate, { params, fee_token: prices.fee?.token, steel_token: prices.steel?.token, params_confirmed: true });
            setPreview(result); setSaved(null); setNotice('已生成参考报价，请核对后采纳。');
        });
    }
    async function loadArchives(nextPage = 1) {
        const result = await quoteRequest(urls.archives + '?search=' + encodeURIComponent(search) + '&page=' + nextPage, null, 'GET');
        setArchives(result.archives);
    }
    function openAdoption() {
        setDetail(null);
        setAdoption({ key: adoptionKey(), title: (params.project_name + ' · ' + params.product).slice(0, 180) });
        dialog.current.showModal();
    }
    async function save() {
        await action('adopt', async () => {
            const result = await quoteRequest(urls.adopt, { title: adoption.title, adoption_key: adoption.key, preview_token: preview.preview_token });
            setSaved(result.archive); dialog.current.close(); setAdoption(null); setNotice('已永久保存采纳时的参数、价格来源与计算快照。'); await loadArchives();
        });
    }
    async function showArchive(row) {
        await action('detail', async () => { const result = await quoteRequest(row.show_url, null, 'GET'); setAdoption(null); setDetail(result); dialog.current.showModal(); });
    }
    function reuse() {
        attachmentUrls.current.forEach((url) => URL.revokeObjectURL(url)); attachmentUrls.current = []; setFilePreviews([]);
        const values = detail.snapshot.params;
        setParams({ ...emptyParameters(today), ...Object.fromEntries(Object.keys(emptyParameters(today)).map((key) => [key, values[key] === null ? '' : String(values[key] ?? '')])), price_date: today });
        setPreview(null); setSaved(null); setPrices({ fee: null, steel: null }); setAmounts({ fee: '', steel: '' }); setNotes({ fee: '', steel: '' }); setHistory(null); setMarket(null); setMessages([]); setProposals([]);
        setFiles([]); setMessage(''); if (fileInput.current) fileInput.current.value = '';
        dialog.current.close(); setDetail(null); setNotice('已复制参数；请重新确认加工费、当天材料基价和项目适用性。原档案保持不变。');
    }
    async function exportPreview() {
        await action('export', async () => {
            const response = await fetch(urls.export, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'text/csv, application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ preview_token: preview.preview_token }),
            });
            if (!response.ok) throw new Error('下载未完成，当前报价已保留，请重试。');
            const downloadUrl = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = downloadUrl; link.download = 'reference-quotation.csv';
            document.body.append(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(downloadUrl), 1000);
        });
    }
    function field(key, choices = null, options = {}) {
        return <label key={key} className={options.full ? 'q-full' : ''}>{names[key]}{choices ? <select aria-label={names[key]} value={params[key]} onChange={(e) => patch(key, e.target.value)}><option value="">请选择</option>{choices.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select> : <input aria-label={names[key]} type={options.type || 'text'} step={options.step} min={options.type === 'number' ? 0 : undefined} value={params[key]} onChange={(e) => patch(key, e.target.value)} disabled={key === 'quantity' && params.mode === 'unit'} />}</label>;
    }
    const missingPriceFields = [...new Set(['project_name', 'customer', 'product', 'spec', ...contextFields, 'destination', 'terms'])].filter((key) => params[key] === '');
    const canCalculate = prices.fee && prices.steel && !busy;
    return <Layout title="参考报价" immersive hideHeader><div className="q-shell">
        <Head title="参考报价" />
        <aside className={'q-rail q-quote-sidebar' + (!sidebarOpen ? ' q-sidebar-collapsed' : '')} aria-label="报价菜单">
            <button className="q-sidebar-toggle" aria-label={sidebarOpen ? '收起报价菜单' : '展开报价菜单'} aria-expanded={sidebarOpen} onClick={() => setSidebarOpen(!sidebarOpen)}>{sidebarOpen ? '‹' : '☰'}</button>
            <div className="q-sidebar-content">
                <button className="q-new-quote" disabled={!!busy} onClick={fresh}>＋ 新建参考报价</button>
                <details className="q-archive-menu" open><summary>我的报价档案（{archives.total}）</summary>
                    <label className="q-sidebar-search"><input aria-label="搜索档案名称" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="搜索报价" onKeyDown={(e) => { if (e.key === 'Enter') action('archives', () => loadArchives()); }} /></label><button disabled={!!busy} onClick={() => action('archives', () => loadArchives())}>搜索</button>
                    <div className="q-sidebar-archives">{archives.data.map((row) => <button key={row.id} disabled={!!busy} onClick={() => showArchive(row)}><strong>{row.title}</strong><small>{money(row.total_cents)} · {new Date(row.created_at).toLocaleDateString('zh-CN')}</small></button>)}{!archives.data.length && <p className="q-muted">暂无报价档案</p>}</div>
                    <div className="q-history-pagination"><button aria-label="上一页报价档案" disabled={!!busy || archives.current_page <= 1} onClick={() => action('archives', () => loadArchives(archives.current_page - 1))}>‹</button><small>{archives.current_page} / {archives.last_page}</small><button aria-label="下一页报价档案" disabled={!!busy || archives.current_page >= archives.last_page} onClick={() => action('archives', () => loadArchives(archives.current_page + 1))}>›</button></div>
                </details>

                <footer>{owner.name}<small>个人报价档案</small></footer>
            </div>
        </aside>
        <main className={'q-main q-chat-workspace' + (!sidebarOpen ? ' q-sidebar-hidden' : '') + (detail ? ' q-archive-open' : '')}>
            {error && <div className="q-error" role="alert">{error}</div>}{notice && <div className="q-success" role="status">{notice}</div>}
            {busy && <p role="status" className="q-processing">{busy === 'chat' ? 'AI 正在理解资料并整理需要确认的问题…' : busy === 'market' ? '正在检索公开网价并核对原文、日期与规格…' : '正在处理，请稍候…'}</p>}
            <>
                <fieldset disabled={!!busy} className="q-conversation">
                    <section className="q-chat">
                        <div className="q-thread-messages">
                            <div ref={conversationStart} className="q-messages" aria-live="polite">{messages.length ? messages.map((m, i) => <div key={i} className={'q-bubble ' + m.role}><strong>{m.role === 'user' ? '我' : '报价助手'}</strong><p>{m.content}</p>{m.files?.map((f) => <small key={f}>{f}</small>)}{m.previews?.map((f) => <a key={f.url} href={f.url} target="_blank" rel="noreferrer">{f.image ? <img className="q-upload-preview" src={f.url} alt={f.name} /> : <span>打开原 PDF：{f.name}</span>}</a>)}{m.questions?.length > 0 && <ul>{m.questions.map((q, j) => <li key={j}>{q}</li>)}</ul>}</div>) : <div className="q-bubble">你可以说：“需要现浇梁模板，预计74吨，请先帮我整理报价所需参数。”</div>}</div>
                            {proposals.length > 0 && <div className="q-proposals"><h3>AI 提取候选 · 请逐项核对</h3>{proposals.map((p, i) => <div key={i}><strong>{names[p.field]}：{p.value}</strong><p className="q-muted">{p.confidence === 'low' ? '识别不确定，请核对原图。' : '待你确认。'}依据：{p.evidence}</p><button onClick={() => { if (['fee_amount', 'steel_amount'].includes(p.field)) { const kind = p.field === 'fee_amount' ? 'fee' : 'steel'; setAmounts((a) => ({ ...a, [kind]: p.value })); setNotes((n) => ({ ...n, [kind]: '用户资料：' + p.evidence })); setPrices((a) => ({ ...a, [kind]: null })); setPreview(null); setSaved(null); } else patch(p.field, p.value); setProposals((items) => items.filter((_, index) => index !== i)); }}>确认填入</button></div>)}</div>}

                        </div>


                        <details className="q-card q-dialogue-card"><summary>补充 / 核对报价参数</summary><small>报价助手 · 参数确认卡</small><h2>补齐本次报价信息</h2><p className="q-muted">可通过对话提取，也可在卡片中补充；确认后的信息才用于计算。</p><div className="q-fields"><label className="q-full">关联平台项目（可选）<select value={params.project_id} onChange={(e) => { const project = projects.find((p) => p.id === e.target.value); setParams((p) => ({ ...p, project_id: e.target.value, project_name: project?.title || p.project_name })); setPreview(null); setSaved(null); }}><option value="">独立填写，不关联项目</option>{projects.map((p) => <option key={p.id} value={p.id}>{p.title} · {p.code}</option>)}</select></label>
                            <details className="q-full q-input-card"><summary>项目、产品与计价数量</summary><div className="q-fields">{field('project_name')}{field('customer')}{field('product')}{field('spec', null, { full: true })}
                            {field('mode', [['total', '计算参考总价'], ['unit', '未知数量，仅出单价']])}{field('unit', [['吨', '吨'], ['套', '套'], ['吨日', '吨日（租赁）']])}
                            {field('quantity', null, { type: 'number', step: '.001' })}{params.unit === '吨日' && field('days', null, { type: 'number', step: '1' })}
                            </div></details><details className="q-full q-input-card"><summary>税费与运输口径</summary><div className="q-fields">{field('tax_rate', [0, 1, 3, 6, 9, 13].map((v) => [String(v), v + '%']))}{field('tax_basis', [['含税', '所填基价含税'], ['未税', '所填基价未税']])}
                            {field('shipping', [['含运费', '含运费'], ['不含运费', '不含运费']])}{field('extra_fee', null, { type: 'number', step: '.01' })}
                            <p className="q-full q-muted">额外费用按所选基价税口径单列，须由你确认。租赁材料基价也必须以元/吨日填写；网价元/吨不能直接混用。</p>
                            </div></details><details className="q-full q-input-card"><summary>当天材料市场与交付要求</summary><div className="q-fields">{field('price_date', null, { type: 'date' })}{field('market')}{field('material')}{field('steel_spec')}{field('destination', null, { full: true })}{field('terms', null, { full: true })}</div></details>
                        </div><p className="q-muted">规格、单位、税运或价格日期变化会取消价格确认。数量未知时不推断总金额。</p></details>
                                                <details className="q-card q-prices-card"><summary>确认加工费与材料基价</summary><small>报价助手 · 价格确认卡</small><h2>价格分别确认</h2>{missingPriceFields.length > 0 && <p className="q-muted">请先补充：{missingPriceFields.map((key) => names[key]).join('、')}。</p>}<p className="q-muted">先在参数卡中补齐口径。每个价格都需确认；用户提供优先，推荐价不会自动替换。</p>
                            {['fee', 'steel'].map((kind) => <section key={kind} className="q-price"><header><h3>{kind === 'fee' ? '加工费' : '材料基价'}（元/{params.unit || '单位'}）</h3><span className={prices[kind] ? 'q-confirmed' : 'q-pending'}>{prices[kind] ? '已确认' : '待确认'}</span></header><label>我提供的{kind === 'fee' ? '加工费' : '材料基价'}<input type="number" min="0" step=".01" value={amounts[kind]} onChange={(e) => { setAmounts((a) => ({ ...a, [kind]: e.target.value })); setPrices((a) => ({ ...a, [kind]: null })); setPreview(null); setSaved(null); }} /></label><label>价格来源说明<input value={notes[kind]} placeholder="例如：供应商报价、用户指定价格" onChange={(e) => { setNotes((a) => ({ ...a, [kind]: e.target.value })); setPrices((a) => ({ ...a, [kind]: null })); setPreview(null); setSaved(null); }} /></label><button disabled={amounts[kind] === '' || !notes[kind].trim() || missingPriceFields.length > 0} onClick={() => confirm(kind)}>确认使用我的价格</button>{prices[kind] && <p className="q-muted">采用 {prices[kind].price.amount} 元/{params.unit} · {prices[kind].price.source_name} · {prices[kind].price.date}</p>}
                            {kind === 'fee' ? <><button onClick={() => action('history', async () => setHistory(await quoteRequest(urls.history, { params })))}>推荐我的历史加工费</button>{history && <><p className="q-muted">{history.message}</p>{history.suggestions.map((s, i) => <div key={i} className="q-source"><strong>{s.price.amount} 元/{params.unit}</strong><p>{s.title} · {s.date}</p><button disabled={amounts.fee !== ''} onClick={() => confirm('fee', s)}>确认采纳历史建议</button>{amounts.fee !== '' && <small>已提供价格，清空后才可采纳推荐。</small>}</div>)}</>}</> : <><button onClick={() => action('market', async () => setMarket(await quoteRequest(urls.market, { params })))}>从互联网获取网价建议</button>{market && <><p className="q-muted">{market.limitations?.join('；')}</p>{market.candidates.map((s, i) => <div key={i} className="q-source"><strong>{s.amount} 元/吨 · {s.date}</strong><p>{s.market} · {s.material} · {s.spec} · {s.tax_basis} · 品牌{s.brand || '未明确'}</p><a href={s.url} target="_blank" rel="noreferrer">{s.source_name}</a><blockquote>{s.quote}</blockquote><button disabled={amounts.steel !== ''} onClick={() => confirm('steel', s)}>单独确认并使用此网价</button>{amounts.steel !== '' && <small>已提供价格，清空后才可采纳推荐。</small>}</div>)}{market.sources?.length > 0 && <details><summary>已检索的公开来源</summary>{market.sources.map((s) => <p key={s.url}><a href={s.url} target="_blank" rel="noreferrer">{s.title}</a></p>)}</details>}</>}</>}
                            </section>)}
                            <button className="q-primary" disabled={!canCalculate} onClick={calculate}>确认以上参数并计算</button>
                        </details>
                        {preview && <div className="q-card q-paper"><h2>参考报价单</h2>{preview ? <QuotePaper snapshot={preview.snapshot} /> : <p className="q-muted">确认参数与两个价格后生成参考报价预览。</p>}<div className="q-actions"><button className="q-primary" disabled={!preview || !!saved} onClick={openAdoption}>{saved ? '已保存到我的档案' : '采纳并独立留档'}</button><button disabled={!preview} onClick={exportPreview}>下载 Excel 兼容 CSV</button><button disabled={!preview} onClick={() => window.print()}>打印 / 存为 PDF</button></div></div>}
                    </section>
                </fieldset>
                <div className="q-composer q-card">                            <label>补充文本<textarea value={message} maxLength={4000} rows={2} onChange={(e) => setMessage(e.target.value)} placeholder="请输入产品、数量、尺寸、价格或补充要求" /></label>
                            <details className="q-attachments"><summary>添加图片 / PDF</summary>                            <label>图片 / PDF（最多3份，每份8MB，总共16MB）<input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple onChange={(e) => { const chosen = [...e.target.files]; if (chosen.length > 3 || chosen.some((f) => f.size > 8 * 1024 * 1024) || chosen.reduce((sum, f) => sum + f.size, 0) > 16 * 1024 * 1024) { setError('最多3份附件，每份不超过8MB，总共不超过16MB。'); e.target.value = ''; setFiles([]); return; } setFiles(chosen); setFilePreviews(chosen.map((f) => { const url = URL.createObjectURL(f); attachmentUrls.current.push(url); return { name: f.name, url, image: f.type.startsWith('image/') }; })); }} /></label>{files.map((f) => <small key={f.name}>{f.name} · 待发送</small>)}{filePreviews.map((f) => f.image ? <img key={f.url} className="q-upload-preview" src={f.url} alt={f.name} /> : <a key={f.url} href={f.url} target="_blank" rel="noreferrer">打开原 PDF：{f.name}</a>)}</details>
                            <button className="q-primary" disabled={(!message.trim() && !files.length) || !!busy} onClick={send}>发送给报价助手</button>
                    <small className="q-composer-note">临时对话不留档 · 确认后生成参考报价 · 采纳后保存个人快照</small>
                </div>
            </>
            <dialog ref={dialog} className="q-dialog" onCancel={(e) => { if (busy) e.preventDefault(); }}>
                {adoption ? <><h2>采纳并保存报价快照</h2><p>归属：{owner.name}。保存当前已计算结果、参数、价格来源和确认时间。</p><label>档案名称<input disabled={!!busy} value={adoption.title} maxLength={180} onChange={(e) => setAdoption((a) => ({ ...a, title: e.target.value }))} /></label><p>{preview && money(preview.snapshot.calculation.total_cents)}</p><button className="q-primary" disabled={!!busy || !adoption.title.trim()} onClick={save}>确认采纳并永久留档</button></> : detail && <><h2>{detail.archive.title}</h2><p className="q-muted">采纳时快照 · {detail.archive.id}</p><QuotePaper snapshot={detail.snapshot} /><div className="q-actions"><button className="q-primary" disabled={!!busy} onClick={reuse}>以此重新报价</button><a href={detail.archive.download_url}>下载 Excel 兼容 CSV</a><button onClick={() => window.print()}>打印 / 存为 PDF</button></div></>}
                {error && <p role="alert" className="q-error">{error}</p>}<p><button disabled={!!busy} onClick={() => { dialog.current.close(); setAdoption(null); setDetail(null); }}>关闭</button></p>
            </dialog>
        </main>
    </div></Layout>;
}
export function QuotePaper({ snapshot }) {
    const { params: p, calculation: c } = snapshot;
    return <div className="q-document"><p><strong>{p.project_name}</strong> · {p.customer}</p><small>业务员：{snapshot.owner.name} ｜生成时间：{new Date(snapshot.generated_at).toLocaleString('zh-CN')}</small><div className="q-table-wrap"><table><thead><tr><th>产品 / 规格</th><th>数量</th><th>含税单价</th><th>含税金额</th></tr></thead><tbody><tr><td>{p.product}<small>{p.spec}</small></td><td>{c.quantity_millis === null ? '未知' : (c.quantity_millis / 1000) + ' ' + (p.unit === '吨日' ? '吨' : p.unit)}{p.unit === '吨日' && ' × ' + p.days + '天'}</td><td>{money(c.unit_price_cents)} / {p.unit}</td><td>{money(c.total_cents)}</td></tr></tbody></table></div><div className="q-total">{money(c.total_cents === null ? c.unit_price_cents : c.total_cents)}{c.total_cents === null && ' / ' + p.unit}</div>{c.total_cents !== null && <p className="q-muted">未税金额 {money(c.net_cents)} ｜税额 {money(c.tax_cents)}</p>}<p>基价{p.tax_basis} · 税率{p.tax_rate}% · {p.shipping} · 单列额外费用{p.extra_fee}元</p><p>交付地点：{p.destination}</p><p>{p.terms}</p><details className="q-calculation"><summary>计算依据与价格来源</summary><p>{c.formula}</p>{['fee', 'steel'].map((kind) => { const price = snapshot[kind]; return <div key={kind}><strong>{kind === 'fee' ? '加工费' : '材料基价'} {price.amount} 元/{p.unit}</strong><p>{price.source_name} · 来源日期{price.date} · 确认时间{new Date(price.confirmed_at).toLocaleString('zh-CN')}</p>{price.url && <a href={price.url} target="_blank" rel="noreferrer">原始来源</a>}{price.quote && <blockquote>{price.quote}</blockquote>}</div>; })}</details><p className="q-muted">临时参考文件，不代表正式合同、成交或任何审批结果。</p></div>;
}
