package com.animeh.app.ui.screens.shorts

import android.content.Context
import androidx.annotation.OptIn
import androidx.media3.common.MediaItem
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.database.StandaloneDatabaseProvider
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.cache.CacheDataSource
import androidx.media3.datasource.cache.LeastRecentlyUsedCacheEvictor
import androidx.media3.datasource.cache.SimpleCache
import androidx.media3.datasource.okhttp.OkHttpDataSource
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import okhttp3.OkHttpClient
import java.io.File

/**
 * The feed's engine.
 *
 * Its own class rather than four lines inside the composable, because every
 * line in it is a measured decision about the one number that matters: how long
 * after a swipe the first frame appears. Built as `ExoPlayer.Builder(context)`
 * with nothing set, that number was ten to thirty seconds on a phone
 * connection. Four things caused it.
 *
 * **1. The default start threshold is 2.5 seconds of media.** ExoPlayer will
 * not show a frame until `bufferForPlaybackMs` is buffered, and the default is
 * 2500. A phone-recorded clip runs at 10–25 Mbps, so 2.5 seconds of it is three
 * to eight megabytes — twenty seconds of downloading before anything appears.
 * Here it is 250 ms. A short is fifteen seconds long and starts again by
 * itself; there is nothing to protect with a deep pre-roll, and a stall costs
 * less than never starting.
 *
 * **2. `REPEAT_MODE_ONE` switched off all preloading.** ExoPlayer buffers ahead
 * into the *next window of the timeline*, and under repeat-one the next window
 * is the current one — so it never touched the following video and every swipe
 * began from nothing. That was the reason the feed was slow on the second video
 * as well as the first, which is the part that made it feel broken rather than
 * merely slow. Repeat is off now and looping is done by seeking back to zero at
 * the end, which leaves the playlist free to buffer forward.
 *
 * **3. Nothing was kept.** Swiping back re-downloaded a video that had just
 * been watched. A small disk cache makes going back instant and makes a second
 * pass over the same feed free.
 *
 * **4. A fresh connection per video.** The default data source opens its own
 * sockets; sharing the app's OkHttp client reuses the pool and the TLS session
 * that the feed request itself just established.
 */
@OptIn(UnstableApi::class)
class ShortsPlayer(
    context: Context,
    httpClient: OkHttpClient,
) {

    /**
     * The engine the feed draws.
     *
     * Exposed rather than wrapped: the screen needs to hand it to a PlayerView
     * and nothing is gained by proxying twenty methods.
     */
    val exo: ExoPlayer

    private val looper = object : Player.Listener {
        override fun onPlaybackStateChanged(state: Int) {
            // A short loops until it is swiped away. Done here rather than with
            // REPEAT_MODE_ONE, which would be one line and would also switch
            // off preloading of the next video — see the note above.
            if (state == Player.STATE_ENDED) {
                exo.seekTo(0)
                exo.play()
            }
        }
    }

    init {
        val loadControl = DefaultLoadControl.Builder()
            .setBufferDurationsMs(
                MIN_BUFFER_MS,
                MAX_BUFFER_MS,
                BUFFER_FOR_PLAYBACK_MS,
                BUFFER_AFTER_REBUFFER_MS,
            )
            // Seconds rather than bytes, so a 20 Mbps clip and a 2 Mbps one
            // both start after the same amount of *video* rather than the same
            // number of megabytes.
            .setPrioritizeTimeOverSizeThresholds(true)
            .build()

        val http = OkHttpDataSource.Factory(httpClient).setUserAgent(USER_AGENT)

        val cached = CacheDataSource.Factory()
            .setCache(cacheOf(context))
            .setUpstreamDataSourceFactory(DefaultDataSource.Factory(context, http))
            // A half-written entry must never be served as if it were whole:
            // on a swipe mid-download the write is abandoned, and without this
            // the next view would read a truncated file as a complete one.
            .setFlags(CacheDataSource.FLAG_IGNORE_CACHE_ON_ERROR)

        exo = ExoPlayer.Builder(context)
            .setLoadControl(loadControl)
            .setMediaSourceFactory(DefaultMediaSourceFactory(cached))
            // Otherwise the end of one video runs straight into the next one
            // under the viewer's thumb, which the pager has not moved to.
            .setPauseAtEndOfMediaItems(true)
            .build()
            .apply {
                repeatMode = Player.REPEAT_MODE_OFF
                playWhenReady = true
                addListener(looper)
            }
    }

    /**
     * Point the playlist at this feed.
     *
     * Appends rather than replaces when the list has only grown, so paging in
     * the next ten videos does not interrupt the one playing.
     */
    fun setFeed(urls: List<String>, startAt: Int) {
        if (urls.isEmpty()) {
            exo.clearMediaItems()
            return
        }

        if (exo.mediaItemCount == 0) {
            exo.setMediaItems(urls.map(MediaItem::fromUri), startAt.coerceIn(0, urls.lastIndex), 0L)
            exo.prepare()
            return
        }

        if (exo.mediaItemCount < urls.size) {
            exo.addMediaItems(urls.drop(exo.mediaItemCount).map(MediaItem::fromUri))
        }
    }

    /** Move to one video and play it. */
    fun playPage(page: Int) {
        if (page !in 0 until exo.mediaItemCount) return

        if (exo.currentMediaItemIndex != page) {
            exo.seekToDefaultPosition(page)
        }

        exo.playWhenReady = true
    }

    fun release() {
        exo.removeListener(looper)
        exo.release()
    }

    companion object {
        private const val USER_AGENT = "Animeh/1.0 (Android)"

        /**
         * Time-to-first-frame, in milliseconds of buffered media.
         *
         * A quarter of a second. The default is ten times this, and that
         * default is written for a two-hour film where one extra second before
         * the titles is invisible and a stall in the middle is not.
         */
        private const val BUFFER_FOR_PLAYBACK_MS = 250

        /** After a stall, a little more, so it does not stutter twice. */
        private const val BUFFER_AFTER_REBUFFER_MS = 1_000

        /**
         * How far ahead to keep.
         *
         * Small on purpose. A short is seconds long, so buffering a minute of
         * it means buffering the whole thing — and the bytes that would go to
         * it are better spent preloading the *next* video, which is what the
         * viewer's thumb is about to ask for.
         */
        private const val MIN_BUFFER_MS = 2_000
        private const val MAX_BUFFER_MS = 15_000

        /** 256 MB of feed, roughly a long session before anything is evicted. */
        private const val CACHE_BYTES = 256L * 1024 * 1024

        /**
         * One cache per process.
         *
         * SimpleCache locks its directory, so a second instance over the same
         * folder throws. The feed screen is created and destroyed as the user
         * enters and leaves the mode, so this cannot live with the player.
         */
        @Volatile
        private var shared: SimpleCache? = null

        private fun cacheOf(context: Context): SimpleCache =
            shared ?: synchronized(this) {
                shared ?: SimpleCache(
                    File(context.cacheDir, "animehtok"),
                    LeastRecentlyUsedCacheEvictor(CACHE_BYTES),
                    StandaloneDatabaseProvider(context),
                ).also { shared = it }
            }
    }
}
