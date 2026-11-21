// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Demos from './Demos';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Form: ({ action, children }) => <form action={action}>{children({ processing: false, errors: {} })}</form>,
}));
vi.mock('../../Components/Layout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('../../Components/SteelGirderNavigation', () => ({ default: () => <nav /> }));

it('accepts the original ZIP on the platform and only shows active share links', () => {
    render(<Demos deployments={[
        { id: '1', name: '吊装演示.zip', url: 'https://site.umb.ink/d/1/demo.html', withdrawing: false },
        { id: '2', name: '旧演示.zip', url: 'https://site.umb.ink/d/2/index.html', withdrawing: true },
    ]} />);
    const upload = screen.getByLabelText(/HTML 离线包/);
    expect(upload.accept).toBe('.zip');
    expect(upload.form.getAttribute('action')).toBe('/steel-girder-projects/demos');
    expect(screen.getAllByRole('link')).toHaveLength(1);
    expect(screen.getByRole('button', { name: '检查撤下状态' })).toBeTruthy();
});
