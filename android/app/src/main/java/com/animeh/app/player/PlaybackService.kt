package com.animeh.app.player

import androidx.annotation.OptIn
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.session.MediaSession
import androidx.media3.session.MediaSessionService
import dagger.hilt.android.AndroidEntryPoint

/**
 * Keeps playback alive when the activity is not in the foreground.
 *
 * Registered as a `mediaPlayback` foreground service so Android does not
 * suspend the decoder the moment the screen goes off, and so the notification
 * carries transport controls. Without it, backgrounding the app mid-episode
 * stops playback and loses the position.
 *
 * This deliberately holds only a session: all the policy — quality, recovery,
 * progress — stays in [PlaybackController], and the service is the piece the
 * platform needs rather than a second place decisions get made.
 */
@OptIn(UnstableApi::class)
@AndroidEntryPoint
class PlaybackService : MediaSessionService() {

    private var mediaSession: MediaSession? = null

    override fun onCreate() {
        super.onCreate()

        val player = ExoPlayer.Builder(this).build()
        mediaSession = MediaSession.Builder(this, player).build()
    }

    override fun onGetSession(controllerInfo: MediaSession.ControllerInfo): MediaSession? = mediaSession

    override fun onTaskRemoved(rootIntent: android.content.Intent?) {
        // Swiping the app away should stop playback; leaving audio running
        // from a dismissed task is the behaviour users report as a bug.
        val player = mediaSession?.player
        if (player == null || !player.playWhenReady || player.mediaItemCount == 0) {
            stopSelf()
        }
    }

    override fun onDestroy() {
        mediaSession?.run {
            player.release()
            release()
        }
        mediaSession = null
        super.onDestroy()
    }
}
