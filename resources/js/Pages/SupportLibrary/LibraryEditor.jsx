import { useState } from 'react';
const fields = {
    parts: [['id', '构件编号'], ['kind', '类别', ['钢柱', '柱撑']], ['spec', '规格', ['φ273', 'φ530', 'C']], ['length_mm', '长度mm', 'int'], ['unit_weight_kg', '单重kg（空＝未知）', 'optionalNumber'], ['bolts_per_piece_raw', '配套螺栓数量', 'optionalInt'], ['hole', '中孔', ['未注明中孔', '带中孔']], ['note', '备注']],
    frames: [['id', '搭配编号'], ['support_use', '用途', ['吊装', '顶推']], ['diameter_mm', '钢柱直径mm', [273, 530]], ['height_mm', '高度mm', 'int'], ['spacing_mm', '间距mm', 'int']],
    composition: [['frame_id', '搭配编号', 'frames'], ['part_id', '柱撑编号', 'parts'], ['per_frame', '每个柱撑面数量', 'int']],
};
export default function LibraryEditor({ draft, savedLibrary, onChange, onSave, onCancel, busy, message }) {
    const [section, setSection] = useState('parts');
    function update(index, key, value, type) {
        const numeric = ['int', 'optionalInt', 'optionalNumber'].includes(type) || key === 'diameter_mm';
        const next = JSON.parse(JSON.stringify(draft));
        next[section][index][key] = numeric ? (value === '' ? null : Number(value)) : value;
        onChange(next);
    }
    function add() {
        const ids = draft[section].map((row) => row.id);
        let n = 1; while (ids.includes(`NEW${n}`)) n++;
        const row = section === 'parts' ? { id: `NEW${n}`, kind: '柱撑', spec: 'C', length_mm: null, unit_weight_kg: null, bolts_per_piece_raw: null, hole: '未注明中孔', note: '' }
            : section === 'frames' ? { id: `NEW${n}`, support_use: '吊装', diameter_mm: 273, height_mm: null, spacing_mm: null }
                : { frame_id: '', part_id: '', per_frame: null };
        onChange({ ...draft, [section]: [...draft[section], row] });
    }
    return <section className="support-card support-editor" aria-label="修改当前数据库"><h2>修改当前数据库</h2><p>先维护构件，再填写搭配条件和每面用量。长度与间距均为mm；C同时适用于C14A/C16A。已有编号不可修改。未保存的草稿不参与检索。</p>
        <div className="support-editor-toolbar" role="group" aria-label="支架库编辑操作"><label>编辑区段<select value={section} onChange={(e) => setSection(e.target.value)}><option value="parts">构件库</option><option value="frames">支架类型</option><option value="composition">搭配组成</option></select></label><div className="support-actions"><button type="button" disabled={busy} onClick={add}>添加一行</button><button type="button" disabled={busy} className="primary" onClick={onSave}>{busy ? '正在保存…' : '保存并更新检索'}</button><button type="button" disabled={busy} onClick={onCancel}>取消</button></div></div>
        <p role="status" aria-live="polite">{message || '修改后点击保存并更新检索；取消会丢弃草稿。'}</p>
        <div className="support-table-wrap" role="region" aria-label="支架库编辑表" tabIndex={0}><table><thead><tr>{fields[section].map(([, label]) => <th key={label}>{label}</th>)}<th>操作</th></tr></thead><tbody>
            {draft[section].map((row, index) => <tr key={`${section}-${index}`}>{fields[section].map(([key, label, type]) => {
                let options = Array.isArray(type) ? type : type === 'frames' ? draft.frames.map((r) => r.id) : type === 'parts' ? draft.parts.filter((r) => r.kind === '柱撑').map((r) => r.id) : null;
                const readonly = key === 'id' && savedLibrary[section].some((r) => r.id === row.id);
                return <td key={key}>{options ? <select disabled={busy} aria-label={`${label} 第${index + 1}行`} value={row[key] ?? ''} onChange={(e) => update(index, key, e.target.value, type)}><option value="">请选择</option>{options.map((v) => <option key={v} value={v}>{v}</option>)}</select>
                    : <input disabled={busy} readOnly={readonly} aria-label={`${label} 第${index + 1}行`} type={type ? 'number' : 'text'} step={type === 'optionalNumber' ? 'any' : '1'} value={row[key] ?? ''} onChange={(e) => update(index, key, e.target.value, type)} />}</td>;
            })}<td><button type="button" disabled={busy} onClick={() => onChange({ ...draft, [section]: draft[section].filter((_, i) => i !== index) })}>删除</button></td></tr>)}
        </tbody></table></div>
    </section>;
}
