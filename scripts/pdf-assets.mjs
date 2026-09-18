import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const packagePath = require.resolve('pdfjs-dist/package.json');
const { version } = JSON.parse(readFileSync(packagePath, 'utf8'));
const root = path.dirname(packagePath);

export function pdfAssets() {
    const assets = new Map();
    for (const folder of ['cmaps', 'standard_fonts', 'wasm', 'iccs']) {
        for (const entry of readdirSync(path.join(root, folder), { withFileTypes: true })) {
            if (entry.isFile()) assets.set(`pdfjs/${version}/${folder}/${entry.name}`, path.join(root, folder, entry.name));
        }
    }
    return {
        name: 'local-pdf-assets',
        configureServer(server) {
            server.middlewares.use((request, response, next) => {
                const url = request.url?.split('?')[0] || '';
                const asset = assets.get(url.slice(server.config.base.length));
                if (!asset) return next();
                response.setHeader('Content-Type', asset.endsWith('.wasm') ? 'application/wasm' : 'application/octet-stream');
                response.end(readFileSync(asset));
            });
        },
        generateBundle() {
            for (const [fileName, asset] of assets) this.emitFile({ type: 'asset', fileName, source: readFileSync(asset) });
        },
    };
}
