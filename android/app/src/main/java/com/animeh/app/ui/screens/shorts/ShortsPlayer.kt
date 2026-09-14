package com.animeh.app.ui.screens.shorts

import android.content.Context
import android.net.Uri
import androidx.annotation.OptIn
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.database.StandaloneDatabaseProvider
import androidx.media3.datasource.DataSpec
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.HttpDataSource
import androidx.media3.datasource.cache.CacheDataSource
import androidx.media3.datasource.cache.CacheKeyFactory
import androidx.media3.datasource.cache.CacheWriter
import androidx.media3.datasource.cache.LeastRecentlyUsedCacheEvictor
import androidx.media3.datasource.cache.SimpleCache
import androidx.media3.datasource.okhttp.OkHttpDataSource
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import java.io.File
import java.util.Collections

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
 * pass over the same feed free — but only once it is keyed on something that
 * stays still; see [PathCacheKeys].
 *
 * **4. A fresh connection per video.** The default data source opens its own
 * sockets; sharing the app's OkHttp client reuses the pool and the TLS session
 * that the feed request itself just established.
 */
/**
 * What the disk cache files a video under.
 *
 * The default is the whole URI, and a video's URI is a presigned link: it
 * carries the moment it was signed and a signature over that. Ask for the same
 * feed twice and every URL is different, so a cache keyed on the URI stored
 * every video twice and served none of them from disk — the cache was pure
 * cost. Keyed on host and path instead, one object in the bucket is one entry
 * for as long as it sits there.
 *
 * Safe because the path is what identifies the object: the query string is
 * proof of permission, and permission is checked when the bytes are fetched,
 * not when they are read back from a cache the app already wrote.
 */
@OptIn(UnstableApi::class)
private object PathCacheKeys : CacheKeyFactory {
    override fun buildCacheKey(dataSpec: DataSpec): String {
        val path = dataSpec.uri.path

        return if (path.isNullOrEmpty()) {
            dataSpec.uri.toString()
        } else {
            "${dataSpec.uri.host.orEmpty()}$path"
        }
    }
}

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

    /**
     * Why the current video is not playing, when it is not.
     *
     * A feed with no error surface is a feed that answers every failure with
     * the same black rectangle: a video whose URL the bucket refused, one this
     * phone cannot decode, and one still arriving all look identical, and the
     * only thing left to do about any of them is wait. This carries the reason
     * to the screen, HTTP status included, so the answer to "it never loads"
     * is a sentence rather than a guess.
     */
    private val _failure = MutableStateFlow<String?>(null)
    val failure: StateFlow<String?> = _failure.asStateFlow()

    private val looper = object : Player.Listener {
        override fun onPlaybackStateChanged(state: Int) {
            if (state == Player.STATE_READY) _failure.value = null

            // A short loops until it is swiped away. Done here rather than with
            // REPEAT_MODE_ONE, which would be one line and would also switch
            // off preloading of the next video — see the note above.
            if (state == Player.STATE_ENDED) {
                exo.seekTo(0)
                exo.play()
            }
        }

        override fun onPlayerError(error: PlaybackException) {
            _failure.value = explain(error)
        }
    }

    /* ── Warming the ones that have not been swiped to yet ───────────── */

    /**
     * Held rather than left inside the scope.
     *
     * Cancelling through it is a member call, so the one `cancel()` in this
     * file that matters — the writer's — has exactly one candidate. Importing
     * the coroutine `cancel` extension would put two more in scope for every
     * receiver in the file, which is a footgun for a line that must work.
     */
    private val warmingJob = SupervisorJob()

    private val warming = CoroutineScope(Dispatchers.IO + warmingJob)

    /** Keys already pulled down, so a scroll back up does not fetch again. */
    private val warmed: MutableSet<String> = Collections.synchronizedSet(mutableSetOf())

    private var warmJob: Job? = null

    /** Cancellable from any thread, which is the point of holding it. */
    @Volatile
    private var writer: CacheWriter? = null

    private lateinit var cacheSource: CacheDataSource.Factory

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
            .setCacheKeyFactory(PathCacheKeys)
            .setUpstreamDataSourceFactory(DefaultDataSource.Factory(context, http))
            // A half-written entry must never be served as if it were whole:
            // on a swipe mid-download the write is abandoned, and without this
            // the next view would read a truncated file as a complete one.
            .setFlags(CacheDataSource.FLAG_IGNORE_CACHE_ON_ERROR)

        // The same cache and the same keys the player reads through, so a head
        // pulled down early is a head the player finds already there.
        cacheSource = cached

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
            _failure.value = null
            exo.seekToDefaultPosition(page)
        }

        exo.playWhenReady = true
    }

    /** Try the current video again after it failed. */
    fun retry() {
        _failure.value = null
        exo.prepare()
        exo.playWhenReady = true
    }

    /**
     * Pull the front of the next few videos down before they are asked for.
     *
     * ExoPlayer buffers ahead into the next item on its own, but only into the
     * *next* one and only once the current one is full. That is enough for a
     * steady scroll and not for a fast one: three flicks in two seconds and the
     * fourth video starts from nothing, which is the moment a feed stops
     * feeling instant.
     *
     * So the head of each of the next few is written straight into the same
     * cache the player reads through. Only the head — a megabyte is several
     * seconds of a 720p short, far past the quarter-second the player needs to
     * show a frame, and the rest arrives while it plays.
     *
     * Everything here is best-effort. A failure means the video will be
     * fetched the ordinary way, which is what happened before this existed.
     */
    fun warm(urls: List<String>) {
        warmJob?.cancel()
        writer?.cancel()

        warmJob = warming.launch {
            // A breath first. The video on screen has the connection to itself
            // until it has a frame to show; only then do the next ones start
            // competing for it. Cancelled by the next swipe like everything
            // else here, so a fast scroll never pays for this wait.
            delay(WARM_DELAY_MS)

            for (url in urls.take(WARM_AHEAD)) {
                ensureActive()

                if (url.isBlank()) continue

                val spec = DataSpec.Builder().setUri(Uri.parse(url)).setLength(WARM_BYTES).build()

                // Keyed the same way the player keys it, so "already warmed"
                // means the same thing to both.
                if (!warmed.add(PathCacheKeys.buildCacheKey(spec))) continue

                runCatching {
                    val writing = CacheWriter(cacheSource.createDataSource(), spec, null, null)
                    writer = writing
                    writing.cache()
                }
            }

            writer = null
        }
    }

    fun release() {
        warmJob?.cancel()
        writer?.cancel()
        warmingJob.cancel()

        exo.removeListener(looper)
        exo.release()
    }

    /**
     * A playback failure in a sentence, with the number that identifies it.
     *
     * The HTTP status is the part worth carrying: a bucket refusing a signed
     * link and a bucket that no longer holds the file are the same black
     * rectangle on screen and two completely different faults underneath.
     */
    private fun explain(failure: PlaybackException): String {
        val cause = failure.cause

        return when {
            cause is HttpDataSource.InvalidResponseCodeException ->
                "Video sunucudan alınamadı (HTTP ${cause.responseCode})."

            cause is HttpDataSource.HttpDataSourceException ->
                "Videoya ulaşılamadı. Bağlantını kontrol et."

            failure.errorCode == PlaybackException.ERROR_CODE_DECODING_FAILED ->
                "Bu video bu cihazda çözülemedi."

            // The file is shorter than its own header says it is, which is a
            // video that was stored incomplete rather than anything the player
            // can retry its way out of. Saying so is the only useful thing
            // here: "try again" on this one will fail the same way forever.
            failure.errorCode == PlaybackException.ERROR_CODE_IO_READ_POSITION_OUT_OF_RANGE ->
                "Bu video eksik yüklenmiş: dosya kendi başlığının söylediğinden kısa. Silip yeniden yüklemek gerekiyor."

            else -> "Video oynatılamadı (${failure.errorCodeName})."
        }
    }

    companion object {
        private const val USER_AGENT = "Animeh/1.0 (Android)"

        /**
         * How many videos ahead of the current one are pulled down early.
         *
         * Three. Far enough that a burst of flicks stays ahead of the thumb,
         * few enough that opening the feed is not a download of the whole page
         * on somebody's data plan.
         */
        private const val WARM_AHEAD = 3

        /**
         * How much of each, in bytes.
         *
         * A megabyte, which at the bitrate a 720p short is re-encoded to is
         * several seconds of video — many times the quarter-second the player
         * waits for before showing a frame. The rest arrives while it plays.
         */
        private const val WARM_BYTES = 1024L * 1024

        /** How long the video on screen gets the connection to itself. */
        private const val WARM_DELAY_MS = 800L

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
