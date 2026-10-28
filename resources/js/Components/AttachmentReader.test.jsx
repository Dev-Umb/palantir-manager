// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import AttachmentReader from './AttachmentReader';

afterEach(() => { cleanup(); vi.unstubAllGlobals(); });
import { isTrustedStorageUrl } from './AttachmentReader';

describe('private attachment preview source', () => {
    it('allows the approved signed HTTPS bucket and rejects other origins or credentials', () => {
        const url = 'https://xyc-palantir-contracts-prod.tos-s3-cn-beijing.volces.com/attachments/contract.pdf?X-Amz-Signature=example';
        expect(isTrustedStorageUrl(url)).toBe(true);
        for (const invalid of [url.replace('https:', 'http:'), url.replace('xyc-palantir-contracts-prod', 'other-bucket'), url.replace('.com/', '.com:444/'), url.split('?')[0], `${url}#fragment`, url.replace('https://', 'https://user:password@'), '/login']) {
            expect(isTrustedStorageUrl(invalid)).toBe(false);
        }
    });
});

describe('direct private preview transfer', () => {
    it('requests the authorized TOS content without platform credentials or custom headers', async () => {
        const url = 'https://xyc-palantir-contracts-prod.tos-s3-cn-beijing.volces.com/attachments/image.png?X-Amz-Signature=example';
        const fetcher = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ mime_type: 'image/png', content_url: url }) })
            .mockResolvedValueOnce({ ok: true, headers: new Headers({ 'content-type': 'image/png' }), blob: async () => new Blob(['image']) });
        vi.stubGlobal('fetch', fetcher);
        URL.createObjectURL = vi.fn(() => 'blob:preview');
        URL.revokeObjectURL = vi.fn();
        render(<AttachmentReader file={{ info_url: '/info', content_url: '/content', download_url: '/download', name: 'image.png' }} />);
        await screen.findByAltText('image.png');
        expect(fetcher.mock.calls[0][1].credentials).toBe('same-origin');
        expect(fetcher.mock.calls[1][0]).toBe(url);
        expect(fetcher.mock.calls[1][1]).toMatchObject({ credentials: 'omit', headers: {}, redirect: 'error' });
    });
    it('stops at denied authorization before requesting any file content', async () => {
        const fetcher = vi.fn().mockResolvedValue({ status: 403 });
        vi.stubGlobal('fetch', fetcher);
        render(<AttachmentReader file={{ info_url: '/info', content_url: '/content', download_url: '/download' }} />);
        await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('没有查看权限'));
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(screen.queryByText('下载原文件')).toBeNull();
    });
});
