import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { releaseManifest } from './prepare-update.mjs';

const metadata = { code: 8, name: '1.0.6', url: 'https://palantir.umb.ink/app-updates/android.apk', notes: '更新与附件修复' };

test('release metadata binds exact bytes and explicit version', () => {
    const bytes = Buffer.from('signed APK fixture');
    const manifest = releaseManifest(bytes, metadata);
    assert.equal(manifest.sha256, createHash('sha256').update(bytes).digest('hex'));
    assert.equal(manifest.sizeBytes, bytes.length);
    assert.equal(manifest.versionCode, 8);
    assert.equal(manifest.notes, metadata.notes);
});

test('publishing rejects untrusted origins, missing bytes and invalid versions', () => {
    for (const changed of [{ code: 0 }, { code: '8' }, { url: 'https://other.test/app.apk' }, { url: 'http://palantir.umb.ink/app.apk' }, { url: 'https://user@palantir.umb.ink/app.apk' }]) {
        assert.throws(() => releaseManifest(Buffer.from('APK'), { ...metadata, ...changed }));
    }
    assert.throws(() => releaseManifest(Buffer.alloc(0), metadata));
});
