// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AiIndex from './Index';
import { HtmlArtifact, htmlDocument } from './Artifacts';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

vi.mock('../../Components/Layout', () => ({
    default: ({ children, hideHeader = false, title }) => (
        <div data-testid="layout" data-hide-header={String(hideHeader)} data-title={title}>{children}</div>
    ),
}));

vi.mock('../../echo', () => ({
    getEcho: () => null,
}));

describe('AI assistant layout', () => {
    it('hides only the outer page title and keeps the conversation toolbar title', () => {
        window.HTMLElement.prototype.scrollIntoView = vi.fn();
        render(<AiIndex conversations={[]} />);

        expect(screen.getByTestId('layout').getAttribute('data-hide-header')).toBe('true');
        expect(screen.getByTestId('layout').getAttribute('data-title')).toBe('AI 数据助手');
        expect(screen.getByText('AI 数据助手')).not.toBeNull();
        expect(screen.getByRole('button', { name: /新对话/ })).not.toBeNull();
    });
});

describe('AI HTML artifact', () => {
    it('renders only inside a sandboxed iframe with a restrictive CSP', () => {
        render(<HtmlArtifact artifact={{
            id: 'html-1',
            type: 'html',
            title: '静态报告',
            data: { html: '<h2 onclick="alert(1)">报告</h2><script>alert(1)</script>' },
        }} />);

        const frame = screen.getByTitle('静态报告');
        expect(frame.getAttribute('sandbox')).toBe('');
        expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
        expect(frame.getAttribute('srcdoc')).toContain("default-src 'none'");
        expect(frame.getAttribute('srcdoc')).not.toContain('<script');
        expect(frame.getAttribute('srcdoc')).not.toContain('onclick');
    });

    it('removes remote executable markup before building the iframe document', () => {
        const document = htmlDocument('<img src="https://evil.example/x"><iframe src="https://evil.example"></iframe>');

        expect(document).not.toContain('<iframe');
        expect(document).toContain("img-src data:");
    });
});
