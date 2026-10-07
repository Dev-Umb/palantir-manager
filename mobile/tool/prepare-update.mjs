import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

export function releaseManifest(bytes, { code, name, url, notes }) {
    const address = new URL(url);
    if (!Number.isInteger(code) || code <= 0 || !name || typeof notes !== 'string'
        || address.origin !== 'https://palantir.umb.ink' || address.username || address.password
        || address.hash || !address.pathname.endsWith('.apk') || !bytes.length || bytes.length > 150 * 1024 * 1024) {
        throw new Error('Invalid release metadata');
    }
    return {
        versionCode: code, versionName: name, notes, apkUrl: address.href,
        sha256: createHash('sha256').update(bytes).digest('hex'), sizeBytes: bytes.length,
        packageName: 'cn.xinyuanchang.palantir_mobile',
    };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const [apk, output, code, name, url, notes] = process.argv.slice(2);
    if (!apk || !output || !code || !name || !url || !notes) {
        throw new Error('Usage: node prepare-update.mjs APK OUTPUT VERSION_CODE VERSION_NAME HTTPS_APK_URL RELEASE_NOTES');
    }
    const metadata = releaseManifest(await readFile(apk), { code: Number(code), name, url, notes });
    await writeFile(output, `${JSON.stringify(metadata, null, 2)}\n`, { flag: 'wx' });
    console.log(`Prepared ${name} (${code}); verify APK version/signature before publishing ${output}.`);
}
