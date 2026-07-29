// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    usePage: () => ({ props: { auth: { permissions: ['object.project.view'] } } }),
}));

vi.mock('../Components/Layout', () => ({
    default: ({ children }) => <main>{children}</main>,
}));

vi.mock('../Components/ComboBox', () => ({
    default: () => <div data-testid="project-picker" />,
}));

afterEach(cleanup);

describe('Dashboard shipment and payment parallel flow', () => {
    it('shows both active nodes with distinct colors and the derived shipment weight', () => {
        render(
            <Dashboard
                stats={[]}
                boards={[{
                    title: '经营大盘',
                    desc: '项目流转',
                    type: 'flow',
                    items: [],
                }]}
                projectFlows={[{
                    id: 'project-1',
                    label: 'XYC-001 · 并行项目',
                    current_step: '发货、回款并行',
                    shipped_weight_ton: 5.75,
                    steps: [
                        { label: '客户', status: 'done' },
                        { label: '合同', status: 'done' },
                        { label: '项目', status: 'done' },
                        { label: '图纸', status: 'done' },
                        { label: '生产', status: 'done' },
                        { label: '发货', status: 'parallel-shipment' },
                        { label: '回款', status: 'parallel-payment' },
                    ],
                }]}
                recentProjects={[]}
                stockRisks={[]}
                notificationSummary={{}}
                notificationRisks={[]}
            />,
        );

        expect(screen.getByText('累计发货重量：5.75 吨')).toBeInTheDocument();
        expect(screen.getByText('发货').closest('.flow-node')).toHaveClass('parallel-shipment');
        expect(screen.getByText('回款').closest('.flow-node')).toHaveClass('parallel-payment');
        expect(screen.getAllByText('进行中')).toHaveLength(2);
    });
});
