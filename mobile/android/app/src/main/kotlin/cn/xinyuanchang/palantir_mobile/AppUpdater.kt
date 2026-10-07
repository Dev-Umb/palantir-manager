package cn.xinyuanchang.palantir_mobile

import android.app.Activity
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.util.concurrent.atomic.AtomicBoolean

class AppUpdater(private val activity: Activity) {
    private val cancelled = AtomicBoolean(false)
    @Volatile var bytes: Long = 0
        private set
    @Volatile private var active = false
    @Volatile private var connection: HttpURLConnection? = null
    private val apk get() = File(activity.cacheDir, "updates/update.apk")

    @Suppress("DEPRECATION")
    private fun installed(): PackageInfo = activity.packageManager.getPackageInfo(activity.packageName, flags())

    private fun flags() = if (Build.VERSION.SDK_INT >= 28) PackageManager.GET_SIGNING_CERTIFICATES else PackageManager.GET_SIGNATURES

    @Suppress("DEPRECATION")
    private fun version(info: PackageInfo): Long = if (Build.VERSION.SDK_INT >= 28) info.longVersionCode else info.versionCode.toLong()

    fun info(): Map<String, Any> = installed().let { mapOf("versionCode" to version(it), "versionName" to (it.versionName ?: "")) }

    fun cancel() { cancelled.set(true); connection?.disconnect() }

    @Suppress("DEPRECATION")
    private fun signers(info: PackageInfo): Set<String> {
        val signatures = if (Build.VERSION.SDK_INT >= 28) info.signingInfo?.apkContentsSigners else info.signatures
        return signatures?.map { signature ->
            MessageDigest.getInstance("SHA-256").digest(signature.toByteArray()).joinToString("") { "%02x".format(it) }
        }?.toSet() ?: emptySet()
    }

    fun validate(file: File, args: Map<String, Any?>) {
        require(file.canonicalPath == apk.canonicalPath && file.isFile) { "更新文件不存在，请重新下载" }
        require(file.length() == (args["sizeBytes"] as Number).toLong()) { "安装包大小不符，请重新下载" }
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(65536)
            while (true) { val count = input.read(buffer); if (count < 0) break; digest.update(buffer, 0, count) }
        }
        val hash = digest.digest().joinToString("") { "%02x".format(it) }
        require(hash == args["sha256"]) { "安装包校验失败，请重新下载" }
        @Suppress("DEPRECATION")
        val archive = activity.packageManager.getPackageArchiveInfo(file.path, flags()) ?: error("安装包无效")
        require(archive.packageName == activity.packageName && args["packageName"] == activity.packageName) { "安装包不属于当前应用" }
        require(version(archive) == (args["versionCode"] as Number).toLong() && version(archive) > version(installed())) { "安装包版本无效" }
        val currentSigners = signers(installed())
        require(currentSigners.isNotEmpty() && signers(archive) == currentSigners) { "安装包签名不符" }
    }

    @Synchronized
    fun start() {
        check(!active) { "已有下载正在进行" }
        active = true; cancelled.set(false); bytes = 0
    }

    fun download(args: Map<String, Any?>): String {
        val partial = File(activity.cacheDir, "updates/update.part")
        try {
            check(!cancelled.get()) { "下载已取消" }
            val url = URL(args["apkUrl"] as String)
            require(url.protocol == "https" && url.host == "palantir.umb.ink" && url.port in listOf(-1, 443) && url.userInfo == null && url.ref == null) { "更新来源无效" }
            val size = (args["sizeBytes"] as Number).toLong()
            require(size in 1..(150L * 1024 * 1024)) { "安装包大小无效" }
            partial.parentFile!!.mkdirs()
            apk.delete(); partial.delete()
            val request = (url.openConnection() as HttpURLConnection).apply {
                instanceFollowRedirects = false; connectTimeout = 15000; readTimeout = 20000
                setRequestProperty("Accept-Encoding", "identity")
            }
            connection = request
            check(!cancelled.get()) { "下载已取消" }
            require(request.responseCode == 200) { "下载失败，请稍后重试" }
            request.inputStream.use { input -> partial.outputStream().use { output ->
                val buffer = ByteArray(65536)
                while (true) {
                    check(!cancelled.get()) { "下载已取消" }
                    val count = input.read(buffer)
                    if (count < 0) break
                    bytes += count
                    require(bytes <= size) { "安装包大小不符" }
                    output.write(buffer, 0, count)
                }
            } }
            check(!cancelled.get()) { "下载已取消" }
            check(partial.renameTo(apk)) { "无法保存安装包" }
            validate(apk, args)
            return apk.path
        } catch (e: Exception) {
            partial.delete(); apk.delete()
            if (cancelled.get()) error("下载已取消")
            throw e
        } finally { connection?.disconnect(); connection = null; active = false }
    }

    fun install(args: Map<String, Any?>): String {
        val file = File(args["path"] as String)
        if (Build.VERSION.SDK_INT >= 26 && !activity.packageManager.canRequestPackageInstalls()) {
            activity.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${activity.packageName}")))
            return "permission_required"
        }
        val uri = FileProvider.getUriForFile(activity, "${activity.packageName}.updates", file)
        activity.startActivity(Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        })
        return "installer_opened"
    }
}
