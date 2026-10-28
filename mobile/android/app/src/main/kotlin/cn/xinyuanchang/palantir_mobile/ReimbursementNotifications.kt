package cn.xinyuanchang.palantir_mobile

import android.Manifest
import android.app.Activity
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat

class ReimbursementNotifications(private val activity: Activity) {
    private val manager = activity.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
    private val preferences = activity.getSharedPreferences("reimbursement_notifications", Context.MODE_PRIVATE)
    private val channel = "reimbursement_results"

    fun account(id: String) {
        val old = preferences.getString("account", "") ?: ""
        if (old != id) {
            // Notifications contain private reimbursement titles; remove them on account changes.
            preferences.getStringSet("shown:$old", emptySet())!!.forEach { manager.cancel("reimbursement", it.hashCode()) }
            preferences.edit().putString("account", id).apply()
        }
    }

    fun show(id: String, notices: List<Map<String, Any?>>): String {
        if (id.isBlank() || preferences.getString("account", "") != id) return "stale"
        if (Build.VERSION.SDK_INT >= 26) manager.createNotificationChannel(NotificationChannel(channel, "报销审批结果", NotificationManager.IMPORTANCE_DEFAULT))
        if (Build.VERSION.SDK_INT >= 33 && activity.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            if (!preferences.getBoolean("permissionAsked", false)) {
                preferences.edit().putBoolean("permissionAsked", true).apply()
                activity.requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), 83)
            }
            return "denied"
        }
        if (Build.VERSION.SDK_INT >= 24 && !manager.areNotificationsEnabled()) return "denied"
        val shown = preferences.getStringSet("shown:$id", emptySet())!!.toMutableSet()
        for (notice in notices) {
            val noticeId = notice["id"].toString()
            val version = (notice["version_id"] as? Number)?.toLong() ?: continue
            if (noticeId in shown || notice["event"] !in listOf("approved", "rejected")) continue
            val intent = Intent(activity, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
                putExtra("reimbursementAccount", id)
                putExtra("reimbursementVersion", version)
            }
            val pending = PendingIntent.getActivity(activity, "$id:$noticeId".hashCode(), intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val notification = NotificationCompat.Builder(activity, channel)
                .setSmallIcon(android.R.drawable.ic_dialog_info).setContentTitle("报销审批结果")
                .setContentText(notice["message"]?.toString() ?: "报销状态已更新")
                .setContentIntent(pending).setAutoCancel(true).setVisibility(NotificationCompat.VISIBILITY_PRIVATE).build()
            manager.notify("reimbursement", noticeId.hashCode(), notification)
            shown.add(noticeId)
        }
        preferences.edit().putStringSet("shown:$id", shown).apply()
        return "enabled"
    }

    fun consume(id: String): Long? {
        val intent = activity.intent ?: return null
        val account = intent.getStringExtra("reimbursementAccount")
        val version = intent.getLongExtra("reimbursementVersion", 0)
        intent.removeExtra("reimbursementAccount"); intent.removeExtra("reimbursementVersion")
        return if (account == id && version > 0) version else null
    }
}
