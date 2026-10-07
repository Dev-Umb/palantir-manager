package cn.xinyuanchang.palantir_mobile;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.Context;
import android.os.Bundle;
import java.io.File;
import java.nio.file.Files;
import java.security.MessageDigest;
import java.util.HashMap;
import java.util.Map;

/** Uses an isolated cache; never starts installation or accesses account cookies. */
public class UpdateInstrumentation extends Instrumentation {
    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments); start(); }

    private void rejected(AppUpdater updater, File file, Map<String, Object> metadata) {
        try { updater.validate(file, metadata); throw new AssertionError("Invalid package accepted"); }
        catch (IllegalArgumentException | IllegalStateException expected) { }
    }

    @Override public void onStart() {
        final Context context = getTargetContext();
        final File directory = new File(context.getCacheDir(), "update-instrumentation");
        Bundle output = new Bundle();
        try {
            final Activity[] isolated = new Activity[1];
            runOnMainSync(() -> isolated[0] = new Activity() {
                { attachBaseContext(context); }
                @Override public File getCacheDir() { return directory; }
            });
            AppUpdater updater = new AppUpdater(isolated[0]);
            if (((Number) updater.info().get("versionCode")).longValue() <= 0) throw new AssertionError();
            File apk = new File(directory, "updates/update.apk");
            apk.getParentFile().mkdirs();
            byte[] bytes = new byte[]{1, 2, 3};
            Files.write(apk.toPath(), bytes);
            Map<String, Object> metadata = new HashMap<>();
            metadata.put("sizeBytes", 3L);
            metadata.put("sha256", new String(new char[64]).replace("\0", "0"));
            rejected(updater, apk, metadata);
            metadata.put("sizeBytes", 4L);
            rejected(updater, apk, metadata);
            metadata.put("sizeBytes", 3L);
            StringBuilder hash = new StringBuilder();
            for (byte value : MessageDigest.getInstance("SHA-256").digest(bytes)) hash.append(String.format("%02x", value));
            metadata.put("sha256", hash.toString());
            rejected(updater, apk, metadata);
            rejected(updater, new File(directory, "elsewhere.apk"), metadata);
            updater.cancel();
            output.putString("stream", "5 update checks passed: version, integrity, size, invalid archive, private path");
            finish(Activity.RESULT_OK, output);
        } catch (Throwable error) {
            output.putString("stream", "Update checks failed: " + error.getClass().getSimpleName());
            finish(Activity.RESULT_CANCELED, output);
        } finally {
            new File(directory, "updates/update.apk").delete();
            new File(directory, "updates").delete();
            directory.delete();
        }
    }
}
