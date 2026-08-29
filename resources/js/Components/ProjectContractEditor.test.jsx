// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import ProjectContractEditor, {
    ProjectContractsDetail,
    emptyProjectContract,
    projectContractSubmission,
    projectContractsForEdit,
} from './ProjectContractEditor';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('adds multiple contract details and reports an existing contract for explicit deletion', () => {
    const existing = {
        ...emptyProjectContract(),
        id: 'contract-1',
        code: 'HT-001',
        amount: '1000',
    };
    let contracts = [existing];
    let deletedContractIds = [];
    const onChange = vi.fn((next) => { contracts = next; });
    const onDeletedContractIdsChange = vi.fn((next) => { deletedContractIds = next; });
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    const { rerender } = render(
        <ProjectContractEditor
            contracts={contracts}
            onChange={onChange}
            deletedContractIds={deletedContractIds}
            onDeletedContractIdsChange={onDeletedContractIdsChange}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: '添加合同' }));
    expect(onChange).toHaveBeenLastCalledWith([existing, expect.objectContaining({ status: '未签署' })]);

    rerender(
        <ProjectContractEditor
            contracts={contracts}
            onChange={onChange}
            deletedContractIds={deletedContractIds}
            onDeletedContractIdsChange={onDeletedContractIdsChange}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: '删除HT-001' }));
    expect(window.confirm).toHaveBeenCalled();
    expect(onDeletedContractIdsChange).toHaveBeenCalledWith(['contract-1']);
    expect(onChange).toHaveBeenLastCalledWith([expect.objectContaining({ status: '未签署' })]);
});

it('keeps historical attachment links out of the submitted file arrays', () => {
    const records = [{
        id: 'contract-1',
        code: 'HT-001',
        payload: {
            status: '已签署',
            amount: 120000,
            processing_letter_attachments: ['/attachments/contract-1/processing_letter_attachments/0'],
            contract_attachments: ['/attachments/contract-1/contract_attachments/0'],
            statement_attachments: ['/attachments/contract-1/statement_attachments/0'],
        },
    }];

    const editable = projectContractsForEdit(records);
    expect(editable[0].existing_contract_attachments).toHaveLength(1);
    expect(editable[0].contract_attachments).toEqual([]);
    expect(projectContractSubmission(editable)[0]).not.toHaveProperty('existing_contract_attachments');
    expect(projectContractSubmission(editable)[0].contract_attachments).toEqual([]);

    render(<ProjectContractsDetail contracts={records} />);
    const detail = screen.getByRole('region', { name: '项目合同明细' });
    expect(within(detail).getByText('加工函附件')).toBeInTheDocument();
    expect(within(detail).getByText('合同附件')).toBeInTheDocument();
    expect(within(detail).getByText('对账单附件')).toBeInTheDocument();
    expect(within(detail).getAllByRole('link')).toHaveLength(3);
});
