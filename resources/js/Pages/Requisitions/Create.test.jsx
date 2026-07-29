// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
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
            };
        },
    };
});

vi.mock('../../Components/Layout', () => ({
    default: ({ children }) => <main>{children}</main>,
}));

vi.mock('../../Components/ComboBox', () => ({
    default: ({ searchUrl = '' }) => <div data-testid="combo" data-search-url={searchUrl} />,
}));

afterEach(cleanup);

describe('purchase request relation choices', () => {
    it('uses authenticated server search for material and visible projects', () => {
        render(
            <Create
                materials={[{ id: 'material-1', label: '钢板' }]}
                projects={[{ id: 'project-1', label: '甲项目' }]}
                materialSearchUrl="/relation-options?source_object=requisition&field=material_id"
                projectSearchUrl="/relation-options?source_object=requisition&field=project_id"
            />,
        );

        expect(screen.getByText('物料').closest('label').querySelector('[data-search-url]'))
            .toHaveAttribute('data-search-url', '/relation-options?source_object=requisition&field=material_id');
        expect(screen.getByText('关联项目').closest('label').querySelector('[data-search-url]'))
            .toHaveAttribute('data-search-url', '/relation-options?source_object=requisition&field=project_id');
    });

    it('does not render any project picker on the public form', () => {
        render(
            <Create
                materials={[{ id: 'material-1', label: '钢板' }]}
                projects={[]}
                publicForm
            />,
        );

        expect(screen.queryByText('关联项目')).not.toBeInTheDocument();
    });

    it('explains required data and the approval consequence before submission', () => {
        render(
            <Create
                materials={[{ id: 'material-1', label: '钢板' }]}
                projects={[{ id: 'project-1', label: '甲项目' }]}
            />,
        );

        const quantity = screen.getByRole('spinbutton');
        expect(quantity).toHaveAttribute('required');
        expect(quantity).toHaveAttribute('min', '0.01');
        expect(screen.getByText('写清用途和期望时间，可减少审批往返。')).toBeInTheDocument();
        expect(screen.getByText(/提交后将进入采购审批/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '确认并提交申请' })).toBeInTheDocument();
    });
});
