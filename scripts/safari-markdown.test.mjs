import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import { describe, expect, it } from 'vitest';
import { fromMarkdown } from 'mdast-util-from-markdown';
import { gfm } from 'micromark-extension-gfm';
import { gfmFromMarkdown } from 'mdast-util-gfm';
import { safariMarkdown } from './safari-markdown.mjs';

const require = createRequire(import.meta.url);
const id = require.resolve('mdast-util-gfm-autolink-literal').replace(/index.js$/, 'lib/index.js');
const source = readFileSync(id, 'utf8');
const patched = safariMarkdown().transform(source, id).code;
const load = (code) => import(/* @vite-ignore */ `data:text/javascript;base64,${Buffer.from(code.replace(/^import (.+) from '([^']+)'/gm, (_, bindings, name) => `import ${bindings} from '${pathToFileURL(require.resolve(name)).href}'`)).toString('base64')}`);

describe('Safari 16.2 Markdown compatibility', () => {
    it('removes unsupported lookbehind without changing autolinks or text boundaries', async () => {
        expect(patched).not.toMatch(/\(\?<[=!]/);
        const original = (await load(source)).gfmAutolinkLiteralFromMarkdown().transforms[0];
        const compatible = (await load(patched)).gfmAutolinkLiteralFromMarkdown().transforms[0];
        for (const prefix of ['', ' ', '\n', '/', '中', 'a', '-', '.', '+', '_', '(', '€', '😀', ':', '\u00a0']) {
            for (const email of ['a@example.com', 'first.last+tag@example.co.uk', 'a@b.c', 'a@b.c_', 'a@b.c-', 'a@b.123']) {
                const value = `${prefix}${email} and www.example.com ${email}`;
                const tree = { type: 'root', children: [{ type: 'paragraph', children: [{ type: 'text', value }] }] };
                const expected = structuredClone(tree);
                original(expected);
                compatible(tree);
                expect(tree, value).toEqual(expected);
            }
        }
    });

    it('preserves GFM tables, task lists and strikethrough', async () => {
        const extension = gfmFromMarkdown();
        extension[0] = (await load(patched)).gfmAutolinkLiteralFromMarkdown();
        const markdown = '| Name | Days |\n| --- | --- |\n| 张三 | 1 |\n\n- [x] done\n\n~~old~~ contact a@example.com';
        expect(fromMarkdown(markdown, { extensions: [gfm()], mdastExtensions: [extension] }))
            .toEqual(fromMarkdown(markdown, { extensions: [gfm()], mdastExtensions: [gfmFromMarkdown()] }));
    });

    it('leaves unrelated modules untouched and fails closed on dependency changes', () => {
        expect(safariMarkdown().transform(source, '/other/index.js')).toBeUndefined();
        expect(() => safariMarkdown().transform('changed dependency', id)).toThrow('Review Safari');
    });
});
