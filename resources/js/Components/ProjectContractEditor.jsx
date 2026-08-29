import { Plus, Trash2 } from 'lucide-react';

const attachmentFields = [
    ['processing_letter_attachments', 'existing_processing_letter_attachments', '加工函附件'],
    ['contract_attachments', 'existing_contract_attachments', '合同附件'],
    ['statement_attachments', 'existing_statement_attachments', '对账单附件'],
];

export function emptyProjectContract() {
    return {
        status: '未签署',
        ctype: '',
        amount: '',
        signed_date: '',
        contract_chase_record: '',
        contract_qty: '',
        remark: '',
        processing_letter_attachments: [],
        contract_attachments: [],
        statement_attachments: [],
    };
}

export function projectContractsForEdit(records = []) {
    return records.map((record) => ({
        ...emptyProjectContract(),
        id: record.id,
        code: record.code,
        status: record.payload?.status || '未签署',
        ctype: record.payload?.ctype || '',
        amount: record.payload?.amount ?? '',
        signed_date: record.payload?.signed_date || '',
        contract_chase_record: record.payload?.contract_chase_record || '',
        contract_qty: record.payload?.contract_qty ?? '',
        remark: record.payload?.remark || '',
        existing_processing_letter_attachments: record.payload?.processing_letter_attachments || [],
        existing_contract_attachments: record.payload?.contract_attachments || [],
        existing_statement_attachments: record.payload?.statement_attachments || [],
    }));
}

export function projectContractSubmission(contracts = []) {
    return contracts.map((contract) => ({
        ...(contract.id ? { id: contract.id } : {}),
        status: contract.status || '未签署',
        ctype: contract.ctype || '',
        amount: contract.amount,
        signed_date: contract.signed_date || '',
        contract_chase_record: contract.contract_chase_record || '',
        contract_qty: contract.contract_qty,
        remark: contract.remark || '',
        processing_letter_attachments: contract.processing_letter_attachments || [],
        contract_attachments: contract.contract_attachments || [],
        statement_attachments: contract.statement_attachments || [],
    }));
}

export default function ProjectContractEditor({ contracts, onChange, deletedContractIds, onDeletedContractIdsChange, errors = {} }) {
    function update(index, key, value) {
        onChange(contracts.map((contract, contractIndex) => contractIndex === index
            ? { ...contract, [key]: value }
            : contract));
    }

    function remove(index) {
        const contract = contracts[index];
        if (contract.id && !window.confirm(`确定删除合同 ${contract.code || ''} 吗？保存项目后将同步删除合同表记录。`)) {
            return;
        }
        if (contract.id) {
            onDeletedContractIdsChange([...deletedContractIds, contract.id]);
        }
        onChange(contracts.filter((_, contractIndex) => contractIndex !== index));
    }

    return (
        <section className="project-contract-editor wide" aria-label="合同明细">
            <div className="project-contract-editor-head">
                <div>
                    <strong>合同明细</strong>
                    <span>合同表将按此处保存结果同步，历史附件只追加、不覆盖。</span>
                </div>
                <button type="button" className="secondary-button small-action" onClick={() => onChange([...contracts, emptyProjectContract()])}>
                    <Plus size={14} /> 添加合同
                </button>
            </div>

            {contracts.length === 0 && <p className="empty-value">当前项目暂无合同，可按需添加多份合同。</p>}
            {contracts.map((contract, index) => (
                <fieldset className="project-contract-card" key={contract.id || `new-contract-${index}`}>
                    <legend>{contract.code || `新合同 ${index + 1}`}</legend>
                    <div className="project-contract-card-actions">
                        <button type="button" className="icon-link danger" onClick={() => remove(index)} aria-label={`删除${contract.code || `新合同 ${index + 1}`}`}>
                            <Trash2 size={15} />
                        </button>
                    </div>
                    <div className="project-contract-fields">
                        <label>
                            <span>合同状态<b>*</b></span>
                            <select value={contract.status} onChange={(event) => update(index, 'status', event.target.value)}>
                                <option value="未签署">未签署</option>
                                <option value="已有加工函">已有加工函</option>
                                <option value="已签署">已签署</option>
                            </select>
                        </label>
                        <label>
                            <span>合同类型</span>
                            <select value={contract.ctype} onChange={(event) => update(index, 'ctype', event.target.value)}>
                                <option value="">未选择</option>
                                <option value="销售合同">销售合同</option>
                                <option value="加工合同">加工合同</option>
                                <option value="补充协议">补充协议</option>
                            </select>
                        </label>
                        <label>
                            <span>合同金额<b>*</b></span>
                            <input type="number" step="0.01" value={contract.amount} onChange={(event) => update(index, 'amount', event.target.value)} required />
                        </label>
                        <label>
                            <span>合同数量</span>
                            <input type="number" step="any" value={contract.contract_qty} onChange={(event) => update(index, 'contract_qty', event.target.value)} />
                        </label>
                        <label>
                            <span>签订日期</span>
                            <input type="date" value={contract.signed_date} onChange={(event) => update(index, 'signed_date', event.target.value)} />
                        </label>
                        <label>
                            <span>合同催要记录</span>
                            <input type="text" value={contract.contract_chase_record} onChange={(event) => update(index, 'contract_chase_record', event.target.value)} />
                        </label>
                        <label className="wide">
                            <span>备注</span>
                            <input type="text" value={contract.remark} onChange={(event) => update(index, 'remark', event.target.value)} />
                        </label>
                        {attachmentFields.map(([field, existingField, label]) => (
                            <div className="project-contract-attachment wide" key={field}>
                                <span>{label}</span>
                                {(contract[existingField] || []).length > 0 && (
                                    <div className="attachment-list">
                                        {contract[existingField].map((url, attachmentIndex) => (
                                            <a key={`${url}-${attachmentIndex}`} href={url} target="_blank" rel="noreferrer">
                                                历史附件 {attachmentIndex + 1}
                                            </a>
                                        ))}
                                    </div>
                                )}
                                {(contract[field] || []).length > 0 && <small>本次新增 {contract[field].length} 个附件</small>}
                                <input
                                    type="file"
                                    multiple
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    onChange={(event) => update(index, field, Array.from(event.target.files || []))}
                                />
                                {errors[`contracts.${index}.${field}`] && <p className="form-error">{errors[`contracts.${index}.${field}`]}</p>}
                            </div>
                        ))}
                        {Object.entries(errors)
                            .filter(([key]) => key.startsWith(`contracts.${index}.`) && !attachmentFields.some(([field]) => key === `contracts.${index}.${field}`))
                            .map(([key, error]) => <p className="form-error wide" key={key}>{error}</p>)}
                    </div>
                </fieldset>
            ))}
        </section>
    );
}

export function ProjectContractsDetail({ contracts = [] }) {
    return (
        <section className="project-contract-detail" aria-label="项目合同明细">
            <div className="project-contract-editor-head">
                <div><strong>合同明细</strong><span>共 {contracts.length} 份</span></div>
            </div>
            {contracts.length === 0 && <p className="empty-value">暂无合同</p>}
            {contracts.map((contract) => (
                <article className="project-contract-detail-card" key={contract.id}>
                    <div><strong>{contract.code}</strong><span>{contract.payload?.status || '未签署'} · {contract.payload?.ctype || '未分类'}</span></div>
                    <dl>
                        <div><dt>合同金额</dt><dd>{contract.payload?.amount ?? '未填写'}</dd></div>
                        <div><dt>合同数量</dt><dd>{contract.payload?.contract_qty ?? '未填写'}</dd></div>
                        <div><dt>签订日期</dt><dd>{contract.payload?.signed_date || '未填写'}</dd></div>
                        <div><dt>合同催要记录</dt><dd>{contract.payload?.contract_chase_record || '未填写'}</dd></div>
                        <div><dt>备注</dt><dd>{contract.payload?.remark || '未填写'}</dd></div>
                    </dl>
                    {attachmentFields.map(([field, , label]) => (contract.payload?.[field] || []).length > 0 && (
                        <div className="attachment-list" key={field}>
                            <span>{label}</span>
                            {contract.payload[field].map((url, index) => <a key={`${url}-${index}`} href={url} target="_blank" rel="noreferrer">附件 {index + 1}</a>)}
                        </div>
                    ))}
                </article>
            ))}
        </section>
    );
}
