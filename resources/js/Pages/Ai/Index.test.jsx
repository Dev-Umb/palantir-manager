// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { HtmlArtifact, htmlDocument } from './Artifacts';

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
