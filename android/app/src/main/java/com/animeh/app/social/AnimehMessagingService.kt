package com.animeh.app.social

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.animeh.app.MainActivity
import com.animeh.app.R
import com.animeh.app.core.ClientLog
import com.animeh.app.data.repository.SocialRepository
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import javax.inject.Inject

/**
 * Invitations and friend requests arriving while the app is closed.
 *
 * A watch-party invite is worthless if it is only seen the next time somebody
 * opens the app — the point is that two people watch the same thing at the
 * same time. So it goes through the notification tray, and tapping it opens
 * the room.
 */
@AndroidEntryPoint
class AnimehMessagingService : FirebaseMessagingService() {

    @Inject lateinit var social: SocialRepository

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    /**
     * A new token, which happens on install, on restore and whenever Firebase
     * decides to rotate one.
     *
     * Sent to the server only when somebody is signed in; a token with no
     * account behind it has nothing to be addressed to. The token is
     * re-registered on every sign-in for exactly that reason.
     */
    override fun onNewToken(token: String) {
        scope.launch { social.registerDevice(token) }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val notification = message.notification

        val title = notification?.title ?: data["title"] ?: getString(R.string.app_name)
        val body = notification?.body ?: data["body"].orEmpty()

        if (body.isBlank()) return

        // The room code travels in the data half rather than the notification
        // half: the notification is what the tray draws, and the data is what
        // survives the tap.
        val roomCode = data["room_code"].orEmpty()

        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP

            if (roomCode.isNotBlank()) {
                action = Intent.ACTION_VIEW
                this.data = Uri.parse("animeh://oda/$roomCode")
            }
        }

        val pending = PendingIntent.getActivity(
            this,
            roomCode.hashCode(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        ensureChannel()

        val built = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.stat_notify_chat)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setContentIntent(pending)
            .build()

        // Android 13 can refuse: posting without the runtime permission throws
        // rather than failing quietly, and there is nothing to do about it
        // here beyond not crashing.
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ActivityCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
        ) {
            NotificationManagerCompat.from(this).notify(roomCode.hashCode(), built)
        } else {
            ClientLog.record("Bildirim gösterilemedi", "POST_NOTIFICATIONS izni yok")
        }
    }

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = getSystemService(NotificationManager::class.java) ?: return

        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_ID,
                getString(R.string.notify_channel_social),
                NotificationManager.IMPORTANCE_HIGH,
            )
        )
    }

    private companion object {
        const val CHANNEL_ID = "animeh_social"
    }
}
