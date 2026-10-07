package cn.xinyuanchang.palantir_mobile;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.ContextWrapper;
import android.os.Bundle;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.util.Arrays;

/** Tests the real release crypto with an isolated key and directory. */
public class SessionStoreInstrumentation extends Instrumentation {
    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments); start(); }

    private void verify(boolean condition) {
        if (!condition) { throw new AssertionError(); }
    }

    private byte[] bytes(File file) throws Exception {
        try (FileInputStream input = new FileInputStream(file);
             ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096];
            int count;
            while ((count = input.read(buffer)) != -1) { output.write(buffer, 0, count); }
            return output.toByteArray();
        }
    }

    @Override public void onStart() {
        final File directory = new File(getTargetContext().getNoBackupFilesDir(), "session-test");
        directory.mkdirs();
        ContextWrapper isolated = new ContextWrapper(getTargetContext()) {
            @Override public File getNoBackupFilesDir() { return directory; }
        };
        String alias = "palantir.session.instrumentation";
        SessionStore store = new SessionStore(isolated, alias);
        File encrypted = new File(directory, "session.enc");
        int checks = 0;
        int result = Activity.RESULT_CANCELED;
        Bundle output = new Bundle();
        try {
            store.clear();
            verify(store.read() == null);
            checks++;
            String fixture = "{\"origin\":\"https://fixture.example\",\"cookies\":[\"session=fixture-secret\"]}";
            store.write(fixture);
            byte[] first = bytes(encrypted);
            verify(!new String(first, StandardCharsets.ISO_8859_1).contains("fixture-secret"));
            verify(fixture.equals(new SessionStore(isolated, alias).read()));
            checks++;
            store.write(fixture);
            verify(!Arrays.equals(first, bytes(encrypted)));
            checks++;
            KeyStore keys = KeyStore.getInstance("AndroidKeyStore");
            keys.load(null);
            verify(keys.getKey(alias, null).getEncoded() == null);
            checks++;
            byte[] altered = bytes(encrypted);
            altered[altered.length - 1] ^= 1;
            try (FileOutputStream file = new FileOutputStream(encrypted)) { file.write(altered); }
            verify(store.read() == null && !encrypted.exists());
            checks++;
            store.write(fixture);
            keys.deleteEntry(alias);
            verify(store.read() == null && !encrypted.exists());
            checks++;
            store.write(fixture);
            store.clear();
            verify(store.read() == null && !encrypted.exists() && !keys.containsAlias(alias));
            checks++;
            output.putString("stream", "PASS: " + checks + " native checks: empty, encrypted roundtrip, fresh IV, non-exportable key, tamper rejection, missing-key rejection, logout removal.");
            result = Activity.RESULT_OK;
        } catch (Throwable error) {
            output.putString("stream", "FAIL after " + checks + " checks: " + error);
        } finally {
            store.clear();
            directory.delete();
        }
        finish(result, output);
    }
}
