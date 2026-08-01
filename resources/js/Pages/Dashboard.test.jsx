// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
}));

vi.mock('../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));

afterEach(cleanup);

describe('Dashboard business and contract overview', () => {
    it('shows the approved project statuses and reminders without hidden operational entries', () => {
        render(<Dashboard
            stats={[{ label: '业务项目', value: 4, unit: '个' }]}
            statusSummary={{ 投标中: 1, 已中标: 1, 已拿到加工函: 1, 合同签署: 1 }}
            recentProjects={[{
                id: 'project-1', code: 'XYC-001', title: '智能物流中心',
                payload: { project_no: 'XYC-001', overall_status: '已拿到加工函', contract_status: '部分签署', payment_status: '部分回款' },
                display: { business_owner_user_id: '业务员甲' },
            }]}
            notificationRisks={[{ id: 1, type: 'payment', type_label: '回款提醒', project_no: 'XYC-001', project_name: '智能物流中心', project_url: '/objects/project?record=project-1&mode=detail', read: false }]}
        />);

        expect(screen.getAllByText('已拿到加工函')).toHaveLength(2);
        expect(screen.getByText('部分签署')).toBeInTheDocument();
        expect(screen.getByText('业务员甲')).toBeInTheDocument();
        expect(screen.getByText('回款提醒')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /进入项目表/ })).toHaveAttribute('href', '/objects/project');
        expect(screen.getByRole('link', { name: /合同表/ })).toHaveAttribute('href', '/objects/contract');
        expect(screen.queryByText('图纸')).not.toBeInTheDocument();
        expect(screen.queryByText('生产')).not.toBeInTheDocument();
        expect(screen.queryByText('发货')).not.toBeInTheDocument();
    });
});
