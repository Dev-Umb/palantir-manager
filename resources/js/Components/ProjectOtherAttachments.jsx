import AttachmentTray from './AttachmentTray';

export default function ProjectOtherAttachments({ record, payload, onChange, errors = {} }) {
    const files = record?.attachment_previews?.other_attachments || [];
    const tokens = record?.attachment_tokens?.other_attachments || [];
    const removed = payload.removed_other_attachment_tokens || [];
    const pending = (payload.other_attachments || []).filter((item) => typeof File !== 'undefined' && item instanceof File);
    function toggle(token) {
        onChange('removed_other_attachment_tokens', removed.includes(token)
            ? removed.filter((value) => value !== token)
            : [...removed, token]);
    }
    return <div className="form-field wide">
        <span>其他附件（担保书等）</span>
        <small>付款担保书等辅助资料，不计作合同或加工函。</small>
        {files.map((file, index) => <div key={file.info_url} className="flex items-center gap-3">
            <AttachmentTray files={[file]} label="其他附件" />
            {tokens[index] && <button type="button" onClick={() => toggle(tokens[index])} aria-label={`${removed.includes(tokens[index]) ? '撤销移除' : '移除'} ${file.name}`}>
                {removed.includes(tokens[index]) ? '撤销移除' : '移除'}
            </button>}
            {removed.includes(tokens[index]) && <small>保存后移除本项目关联</small>}
        </div>)}
        <input name="other_attachments" aria-label="上传其他附件" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => onChange('other_attachments', Array.from(event.target.files || []))} />
        {pending.length > 0 && <small>本次新增 {pending.length} 个附件，保存后叠加到历史附件。</small>}
        {errors['payload.other_attachments'] && <p role="alert">{errors['payload.other_attachments']}</p>}
    </div>;
}
