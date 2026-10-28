package cn.xinyuanchang.palantir_mobile

import android.app.Activity
import android.content.ClipData
import androidx.core.content.FileProvider
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.pdf.PdfRenderer
import android.net.Uri
import android.os.ParcelFileDescriptor
import android.provider.OpenableColumns
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.UUID
import java.util.concurrent.Executors

class MainActivity : FlutterActivity() {
    private val reimbursementNotifications by lazy { ReimbursementNotifications(this) }
    override fun onNewIntent(intent: Intent) { super.onNewIntent(intent); setIntent(intent) }
    private val updater by lazy { AppUpdater(this) }
    private val updateWorker = Executors.newSingleThreadExecutor()
    private val sessionStore by lazy { SessionStore(this) }
    private var saver: MethodChannel.Result? = null
    private var saveBytes: ByteArray? = null
    private var picker: MethodChannel.Result? = null
    private val worker = Executors.newSingleThreadExecutor()
    private val documents = mutableMapOf<String, Pair<PdfRenderer, File>>()

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        File(cacheDir, "pdf").deleteRecursively()
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "palantir/android").setMethodCallHandler { call, result ->
            when (call.method) {
                "reimbursementAccount" -> { reimbursementNotifications.account(call.argument<String>("account") ?: ""); result.success(null) }
                "reimbursementNotifications" -> {
                    try {
                        @Suppress("UNCHECKED_CAST")
                        val notices = call.argument<List<Map<String, Any?>>>("notices") ?: emptyList()
                        result.success(reimbursementNotifications.show(call.argument<String>("account") ?: "", notices))
                    } catch (_: Exception) { result.error("notifications", "本地通知不可用，请在应用内查看", null) }
                }
                "consumeReimbursementNotification" -> result.success(reimbursementNotifications.consume(call.argument<String>("account") ?: ""))
                "updateInfo" -> result.success(updater.info())
                "updateProgress" -> result.success(mapOf("bytes" to updater.bytes))
                "cancelUpdate" -> { updater.cancel(); result.success(null) }
                "downloadUpdate" -> {
                    try { updater.start() } catch (e: Exception) { result.error("update", e.message, null); return@setMethodCallHandler }
                    updateWorker.execute {
                        try {
                            @Suppress("UNCHECKED_CAST")
                            val path = updater.download(call.arguments as Map<String, Any?>)
                            runOnUiThread { result.success(path) }
                        } catch (e: Exception) { runOnUiThread { result.error("update", e.message ?: "下载失败，请重试", null) } }
                    }
                }
                "installUpdate" -> updateWorker.execute {
                    try {
                        @Suppress("UNCHECKED_CAST")
                        val args = call.arguments as Map<String, Any?>
                        updater.validate(File(args["path"] as String), args)
                        runOnUiThread {
                            try { result.success(updater.install(args)) }
                            catch (e: Exception) { result.error("update", e.message ?: "无法打开安装器", null) }
                        }
                    } catch (e: Exception) { runOnUiThread { result.error("update", e.message ?: "安装包校验失败", null) } }
                }
                "readSession", "writeSession", "clearSession" -> worker.execute {
                    try {
                        val value = when (call.method) {
                            "readSession" -> sessionStore.read()
                            "writeSession" -> { sessionStore.write(call.argument<String>("value")!!); null }
                            else -> { sessionStore.clear(); null }
                        }
                        runOnUiThread { result.success(value) }
                    } catch (_: Exception) {
                        runOnUiThread { result.error("session_storage", "无法访问设备上的登录信息，请重试", null) }
                    }
                }
                "saveFile", "saveReimbursementFile" -> {
                    if (saver != null) { result.error("busy", "保存窗口已打开", null) }
                    else {
                        try {
                            val bytes = call.argument<ByteArray>("bytes")!!
                            val mime = call.argument<String>("mime")!!
                            val name = call.argument<String>("name")!!
                            val reimbursement = call.method == "saveReimbursementFile"
                            require(bytes.isNotEmpty() && bytes.size <= (if (reimbursement) 210 else 20) * 1024 * 1024)
                            require(mime in (if (reimbursement) listOf("application/pdf", "image/jpeg", "image/png", "application/zip") else listOf("application/pdf", "application/vnd.openxmlformats-officedocument.wordprocessingml.document")))
                            require(!name.contains('/') && !name.contains('\\'))
                            saver = result
                            saveBytes = bytes
                            startActivityForResult(Intent(Intent.ACTION_CREATE_DOCUMENT).apply {
                                addCategory(Intent.CATEGORY_OPENABLE)
                                type = mime
                                putExtra(Intent.EXTRA_TITLE, name)
                            }, 72)
                        } catch (_: Exception) { saver = null; saveBytes = null; result.error("save", "无法打开保存窗口", null) }
                    }
                }
                "shareFile" -> worker.execute {
                    try {
                        val bytes = call.argument<ByteArray>("bytes")!!
                        val name = call.argument<String>("name")!!
                        val mime = call.argument<String>("mime")!!
                        require(bytes.isNotEmpty() && bytes.size <= 20 * 1024 * 1024)
                        require(name.isNotBlank() && !name.contains('/') && !name.contains('\\') && name != "." && name != "..")
                        require(mime in listOf("application/pdf", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"))
                        val root = File(cacheDir, "quotations").apply { mkdirs() }
                        root.listFiles()?.filter { System.currentTimeMillis() - it.lastModified() > 86400000L }?.forEach { it.deleteRecursively() }
                        val directory = File(root, UUID.randomUUID().toString()).apply { mkdirs() }
                        val file = File(directory, name).apply { writeBytes(bytes) }
                        val uri = FileProvider.getUriForFile(this, "$packageName.quotations", file)
                        val intent = Intent(Intent.ACTION_SEND).apply {
                            type = mime
                            putExtra(Intent.EXTRA_STREAM, uri)
                            clipData = ClipData.newRawUri(name, uri)
                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                        }
                        runOnUiThread {
                            try {
                                startActivity(Intent.createChooser(intent, "分享报价单"))
                                result.success(null)
                            } catch (_: Exception) { result.error("share", "无法打开分享面板，请重试", null) }
                        }
                    } catch (_: Exception) { runOnUiThread { result.error("share", "报价文件无法分享，请重试", null) } }
                }
                "pickFile" -> {
                    if (picker != null) { result.error("busy", "文件选择器已打开", null) }
                    else {
                        picker = result
                        try {
                            val intent = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
                                addCategory(Intent.CATEGORY_OPENABLE)
                                type = "*/*"
                                putExtra(Intent.EXTRA_MIME_TYPES, arrayOf("application/pdf", "image/jpeg", "image/png"))
                            }
                            startActivityForResult(intent, 71)
                        } catch (_: Exception) { picker = null; result.error("picker", "无法打开系统文件选择器", null) }
                    }
                }
                "openExternal" -> {
                    try {
                        val uri = Uri.parse(call.argument<String>("url"))
                        require(uri.scheme in listOf("https", "http", "tel"))
                        startActivity(Intent(if (uri.scheme == "tel") Intent.ACTION_DIAL else Intent.ACTION_VIEW, uri))
                        result.success(null)
                    } catch (_: Exception) { result.error("external", "没有可以打开此链接的应用", null) }
                }
                "openPdf" -> worker.execute {
                    var file: File? = null
                    try {
                        val bytes = call.argument<ByteArray>("bytes")!!
                        val dir = File(cacheDir, "pdf").apply { mkdirs() }
                        val id = UUID.randomUUID().toString()
                        file = File(dir, "$id.pdf").apply { writeBytes(bytes) }
                        val renderer = PdfRenderer(ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY))
                        documents[id] = Pair(renderer, file)
                        runOnUiThread { result.success(mapOf("id" to id, "pages" to renderer.pageCount)) }
                    } catch (_: Exception) { file?.delete(); runOnUiThread { result.error("pdf", "PDF 无法打开，文件可能损坏或已加密", null) } }
                }
                "renderPdf" -> worker.execute {
                    try {
                        val renderer = documents[call.argument<String>("id")]!!.first
                        val bytes = renderer.openPage(call.argument<Int>("page")!!).use { page ->
                            val scale = minOf(2.0, 1800.0 / maxOf(page.width, page.height))
                            val bitmap = Bitmap.createBitmap(maxOf(1, (page.width * scale).toInt()), maxOf(1, (page.height * scale).toInt()), Bitmap.Config.ARGB_8888)
                            try {
                                bitmap.eraseColor(Color.WHITE)
                                page.render(bitmap, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                                ByteArrayOutputStream().use { stream -> bitmap.compress(Bitmap.CompressFormat.PNG, 100, stream); stream.toByteArray() }
                            } finally { bitmap.recycle() }
                        }
                        runOnUiThread { result.success(bytes) }
                    } catch (_: Exception) { runOnUiThread { result.error("pdf", "此页加载失败，请重试", null) } }
                }
                "closePdf" -> worker.execute {
                    documents.remove(call.argument<String>("id"))?.let { it.first.close(); it.second.delete() }
                    runOnUiThread { result.success(null) }
                }
                else -> result.notImplemented()
            }
        }
    }

    @Deprecated("Used for the Flutter host activity's system document picker")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == 72) {
            val result = saver ?: return
            val bytes = saveBytes
            saver = null
            saveBytes = null
            val uri = data?.data
            if (resultCode != Activity.RESULT_OK || uri == null || bytes == null) { result.success(false); return }
            worker.execute {
                try {
                    contentResolver.openOutputStream(uri, "w")!!.use { it.write(bytes) }
                    runOnUiThread { result.success(true) }
                } catch (_: Exception) { runOnUiThread { result.error("save", "文件保存失败，请重试", null) } }
            }
            return
        }
        if (requestCode != 71) return
        val result = picker ?: return
        picker = null
        val uri = data?.data
        if (resultCode != Activity.RESULT_OK || uri == null) { result.success(null); return }
        worker.execute {
            try {
                var name = "附件"
                contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { cursor ->
                    if (cursor.moveToFirst()) name = cursor.getString(0)
                }
                val bytes = contentResolver.openInputStream(uri)!!.use { input ->
                    val stream = ByteArrayOutputStream()
                    val buffer = ByteArray(8192)
                    while (true) {
                        val count = input.read(buffer)
                        if (count < 0) break
                        require(stream.size() + count <= 20 * 1024 * 1024) { "文件超过 20 MB" }
                        stream.write(buffer, 0, count)
                    }
                    stream.toByteArray()
                }
                runOnUiThread { result.success(mapOf("name" to name, "bytes" to bytes)) }
            } catch (_: Exception) { runOnUiThread { result.error("file", "无法读取文件，请检查格式及大小（最大 20 MB）", null) } }
        }
    }

    override fun onDestroy() {
        saver?.error("cancelled", "保存已取消", null)
        saver = null
        saveBytes = null
        picker?.error("cancelled", "文件选择已取消", null)
        picker = null
        worker.execute { documents.values.forEach { it.first.close(); it.second.delete() }; documents.clear() }
        worker.shutdown()
        super.onDestroy()
    }
}
