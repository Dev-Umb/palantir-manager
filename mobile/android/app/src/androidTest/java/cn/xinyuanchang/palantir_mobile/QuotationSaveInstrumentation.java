package cn.xinyuanchang.palantir_mobile;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import io.flutter.plugin.common.MethodChannel;
import java.io.File;
import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.nio.file.Files;
import java.util.Arrays;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;

public class QuotationSaveInstrumentation extends Instrumentation {
    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments); start(); }
    private void check(boolean condition) { if (!condition) throw new AssertionError(); }
    private static class Result implements MethodChannel.Result {
        final CountDownLatch done = new CountDownLatch(1);
        Object value;
        String error;
        @Override public void success(Object value) { this.value = value; done.countDown(); }
        @Override public void error(String code, String message, Object details) { error = code; done.countDown(); }
        @Override public void notImplemented() { error = "unimplemented"; done.countDown(); }
    }
    @Override public void onStart() {
        Bundle output = new Bundle();
        Activity activity = null;
        File file = null;
        try {
            Intent launch = new Intent(getTargetContext(), MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            activity = startActivitySync(launch);
            final Activity host = activity;
            Field saver = MainActivity.class.getDeclaredField("saver"); saver.setAccessible(true);
            Field bytes = MainActivity.class.getDeclaredField("saveBytes"); bytes.setAccessible(true);
            Method callback = MainActivity.class.getDeclaredMethod("onActivityResult", int.class, int.class, Intent.class); callback.setAccessible(true);
            Result cancelled = new Result();
            runOnMainSync(() -> {
                try { saver.set(host, cancelled); bytes.set(host, new byte[]{80,75}); callback.invoke(host, 72, Activity.RESULT_CANCELED, null); }
                catch (Exception e) { throw new RuntimeException(e); }
            });
            check(cancelled.done.await(5, TimeUnit.SECONDS) && Boolean.FALSE.equals(cancelled.value));
            check(saver.get(host) == null && bytes.get(host) == null);
            file = new File(getTargetContext().getCacheDir(), "updates/quote-save-test.docx");
            file.getParentFile().mkdirs(); file.createNewFile();
            Uri uri = Uri.parse("content://" + getTargetContext().getPackageName() + ".updates/updates/quote-save-test.docx");
            byte[] expected = new byte[]{80,75,3,4,10,11};
            Result saved = new Result();
            runOnMainSync(() -> {
                try { saver.set(host, saved); bytes.set(host, expected); callback.invoke(host, 72, Activity.RESULT_OK, new Intent().setData(uri)); }
                catch (Exception e) { throw new RuntimeException(e); }
            });
            check(saved.done.await(10, TimeUnit.SECONDS) && saved.error == null && Boolean.TRUE.equals(saved.value));
            check(Arrays.equals(expected, Files.readAllBytes(file.toPath())));
            output.putString("stream", "Quotation save passed: cancellation, payload cleanup, content URI write and exact bytes");
            finish(Activity.RESULT_OK, output);
        } catch (Throwable e) {
            output.putString("stream", "Quotation save failed: " + e.toString());
            finish(Activity.RESULT_CANCELED, output);
        } finally {
            if (file != null) file.delete();
            if (activity != null) { final Activity host = activity; runOnMainSync(host::finish); }
        }
    }
}
