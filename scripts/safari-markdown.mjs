export function safariMarkdown() {
    return {
        name: 'safari-markdown',
        enforce: 'pre',
        transform(code, id) {
            if (!id.endsWith('/mdast-util-gfm-autolink-literal/lib/index.js')) return;
            const boundary = String.raw`(?<=^|\s|\p{P}|\p{S})`;
            if (!code.includes(boundary)) throw new Error('Review Safari Markdown compatibility after dependency update');
            // previous(match, true) already checks this boundary; findAndReplace retries rejected matches.
            return { code: code.replace(boundary, ''), map: null };
        },
    };
}
