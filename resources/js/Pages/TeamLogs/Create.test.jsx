// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Create from './Create';

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        usePage: () => ({ props: { flash: {} } }),
        useForm: (initial) => {
            const [data, setFormData] = React.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key, value) => setFormData((current) => ({ ...current, [key]: value })),
                post: vi.fn(),
                reset: vi.fn(),
            };
        },
    };
});

vi.mock('../../Components/ComboBox', () => ({
    default: ({ searchUrl = '' }) => <div data-testid="combo" data-search-url={searchUrl} />,
}));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('shop floor reporting', () => {
    it('uses simple status buttons and reveals purchase fields for material shortages', () => {
        render(
            <Create
                projects={[{ id: 'project-1', label: '甲项目' }]}
                teams={[{ id: 'team-1', label: '下料班组', meta: { leader_name: '张三' } }]}
                materials={[{ id: 'material-1', label: 'Q235B 钢板' }]}
                searchUrls={{ material_id: '/relation-options?source_object=requisition&field=material_id' }}
            />,
        );

        expect(screen.getByRole('button', { name: '生产中' })).toHaveClass('selected');
        expect(screen.queryByText('所缺物料')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '缺料' }));

        expect(screen.getByText('所缺物料')).toBeInTheDocument();
        expect(screen.getByText('提交报工后，系统会自动生成一张紧急采购申请。')).toBeInTheDocument();
        expect(screen.getByText('所缺物料').closest('label').querySelector('[data-search-url]'))
            .toHaveAttribute('data-search-url', '/relation-options?source_object=requisition&field=material_id');
        expect(screen.getByRole('button', { name: '提交报工并申请采购' })).toBeInTheDocument();
    });

    it('labels the signed public form as no-login', () => {
        render(
            <Create
                projects={[{ id: 'project-1', label: '甲项目' }]}
                teams={[{ id: 'team-1', label: '下料班组' }]}
                materials={[]}
                publicForm
            />,
        );

        expect(screen.getByText('无需登录 · 扫码即填')).toBeInTheDocument();
        expect(screen.getByText('提交后可继续填写下一条')).toBeInTheDocument();
    });

    it('uses the local calendar date instead of the UTC date', () => {
        const originalTimezone = process.env.TZ;
        process.env.TZ = 'Asia/Taipei';
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-30T18:00:00.000Z'));

        render(<Create projects={[]} teams={[]} materials={[]} />);

        expect(screen.getByLabelText('报工日期')).toHaveValue('2026-07-31');
        process.env.TZ = originalTimezone;
    });

    it('shows and clears the selected attachment with Chinese controls', () => {
        render(<Create projects={[]} teams={[]} materials={[]} />);
        const input = screen.getByLabelText('选择照片');
        const attachment = new File(['photo'], '现场照片.png', { type: 'image/png' });

        expect(input).toHaveAttribute('accept', '.pdf,.jpg,.jpeg,.png');
        expect(screen.getByText('暂未选择照片')).toBeInTheDocument();

        fireEvent.change(input, { target: { files: [attachment] } });
        expect(screen.getByText('现场照片.png')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '清除' }));
        expect(screen.getByText('暂未选择照片')).toBeInTheDocument();
        expect(screen.queryByText('现场照片.png')).not.toBeInTheDocument();
    });
});
