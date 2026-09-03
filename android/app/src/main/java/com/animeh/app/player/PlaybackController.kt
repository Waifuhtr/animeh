package com.animeh.app.player

import android.content.Context
import android.graphics.Typeface
import androidx.annotation.OptIn
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.TrackSelectionParameters
import androidx.media3.common.text.Cue
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.okhttp.OkHttpDataSource
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import androidx.media3.exoplayer.trackselection.DefaultTrackSelector
import androidx.media3.exoplayer.upstream.DefaultBandwidthMeter
import com.animeh.app.core.AppError
import com.animeh.app.domain.MediaSource
import com.animeh.app.domain.Playback
import com.animeh.app.player.ass.AssParser
import com.animeh.app.player.ass.FontResolver
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton

/**
 * The player's brain.
 *
 * ExoPlayer is used strictly as the media engine, per §1 and §4: it demuxes,
 * decodes and renders video. Everything above that — which rendition to
 * request, when to switch, how to recover from a dropped connection, which
 * address to try when one refuses, when the controls hide, what counts as
 * "finished" — is here, and none of it is ExoPlayer's default behaviour.
 *
 * Three things this owns that a stock `PlayerView` does not do:
 *
 * 1. **Address failover.** A source arrives with a list of URLs (the friendly
 *    CDN address, then the direct S3 one). A failure before the first frame
 *    moves to the next address silently; a failure after it is a network
 *    problem and gets a retry with backoff instead, because switching sources
 *    mid-episode would restart playback for no benefit.
 * 2. **Network-aware quality.** The first rendition is chosen from the
 *    connection class before any bandwidth is known, then [QualityPolicy]
 *    decides switches from measured throughput and buffer runway.
 * 3. **Recovery.** A lost connection is a [PlaybackPhase.Reconnecting] with
 *    exponential backoff, not an error dialog, until the retries run out.
 */
@OptIn(UnstableApi::class)
@Singleton
class PlaybackController @Inject constructor(
    @ApplicationContext private val context: Context,
    @Named("base_client") private val httpClient: OkHttpClient,
    private val networkMonitor: NetworkMonitor,
    private val fontResolver: FontResolver,
) {

    private val _state = MutableStateFlow(PlayerUiState())
    val state: StateFlow<PlayerUiState> = _state.asStateFlow()

    private val _cues = MutableStateFlow<List<Cue>>(emptyList())
    val cues: StateFlow<List<Cue>> = _cues.asStateFlow()

    private val _typefaces = MutableStateFlow<Map<String, Typeface>>(emptyMap())
    val typefaces: StateFlow<Map<String, Typeface>> = _typefaces.asStateFlow()

    /**
     * The family the current script sets its dialogue in.
     *
     * Held beside the typefaces because the renderer needs to know which of
     * them is the one sentences are written in — see [AssParser.primaryFont].
     */
    private val _primaryFont = MutableStateFlow<String?>(null)
    val primaryFont: StateFlow<String?> = _primaryFont.asStateFlow()

    private var exoPlayer: ExoPlayer? = null
    private var trackSelector: DefaultTrackSelector? = null
    private var bandwidthMeter: DefaultBandwidthMeter? = null
    private var scope: CoroutineScope? = null

    private var progressJob: Job? = null
    private var retryJob: Job? = null
    private var controlsJob: Job? = null

    private var playback: Playback? = null
    private var videoAddresses: List<String> = emptyList()
    private var addressIndex = 0
    private var retryAttempt = 0
    private var sawFirstFrame = false
    private var preparedAt = 0L
    private var bufferingSince = 0L

    /** Called when a position should be persisted. Set by the ViewModel. */
    var onProgress: ((positionSeconds: Int, durationSeconds: Int, watchedSeconds: Int) -> Unit)? = null

    /**
     * Milliseconds of this episode genuinely played, seeks excluded.
     *
     * Seeded from what the server already knows when an episode opens, so the
     * total keeps growing across sessions instead of restarting each time.
     */
    private var watchedMs: Long = 0L

    /** Where the last tick found the playhead, for measuring the step. */
    private var lastTickMs: Long = -1L

    /**
     * The length the media itself reported, zero until it has.
     *
     * Deliberately not the catalog's number. The episode row carries an
     * estimate — twenty-four minutes for a series whose test upload is ninety
     * seconds — and reporting that as fact taught the server the wrong length,
     * which then decided both completion and whether resuming was offered. The
     * only length worth persisting is the one the demuxer read off the file.
     */
    private var measuredDurationMs: Long = 0L

    /** Called when the episode finishes and autoplay should advance. */
    var onCompleted: (() -> Unit)? = null

    val player: Player? get() = exoPlayer

    /** Build the engine. Idempotent. */
    fun attach(coroutineScope: CoroutineScope) {
        scope = coroutineScope
        if (exoPlayer != null) return

        val connection = networkMonitor.current()
        val profile = QualityPolicy.bufferProfile(connection)

        val meter = DefaultBandwidthMeter.Builder(context).build()
        bandwidthMeter = meter

        val selector = DefaultTrackSelector(context).apply {
            // Constrained here rather than by picking a URL, so ABR still
            // operates inside the ceiling for an HLS source.
            parameters = buildUponParameters()
                .setForceHighestSupportedBitrate(false)
                .build()
        }
        trackSelector = selector

        val loadControl = DefaultLoadControl.Builder()
            .setBufferDurationsMs(
                profile.minBufferMs,
                profile.maxBufferMs,
                profile.bufferForPlaybackMs,
                profile.bufferForPlaybackAfterRebufferMs,
            )
            // Prioritise time over size: an episode at 360p and one at 1080p
            // should buffer the same number of seconds, not the same bytes.
            .setPrioritizeTimeOverSizeThresholds(true)
            .build()

        // OkHttp for media too, so the connection pool, timeouts and TLS
        // configuration are the same ones the API uses.
        val dataSourceFactory = DefaultDataSource.Factory(
            context,
            OkHttpDataSource.Factory(httpClient).setUserAgent(USER_AGENT),
        )

        exoPlayer = ExoPlayer.Builder(context)
            .setTrackSelector(selector)
            .setLoadControl(loadControl)
            .setBandwidthMeter(meter)
            .setMediaSourceFactory(DefaultMediaSourceFactory(dataSourceFactory))
            .setSeekBackIncrementMs(SEEK_STEP_MS)
            .setSeekForwardIncrementMs(SEEK_STEP_MS)
            .build()
            .apply {
                addListener(listener)
                playWhenReady = true
            }

        startProgressLoop()
    }

    /** Load an episode and start it. */
    fun load(
        source: Playback,
        preferredQuality: QualitySelection,
        subtitlesEnabled: Boolean,
        subtitleLanguage: String,
        dataSaver: Boolean,
        autoplayNext: Boolean,
        speed: Float,
        startPositionSeconds: Int,
        alreadyWatchedSeconds: Int = 0,
    ) {
        playback = source
        addressIndex = 0
        retryAttempt = 0
        sawFirstFrame = false
        preparedAt = System.currentTimeMillis()

        // Continue the count rather than restarting it: someone who watched
        // half an episode yesterday should finish it today, not have to watch
        // seventy percent again in one sitting.
        watchedMs = alreadyWatchedSeconds.coerceAtLeast(0) * 1000L
        lastTickMs = -1L
        measuredDurationMs = 0L

        val heights = QualityPolicy.availableHeights(source.videos)
        val connection = networkMonitor.current()
        val chosenHeight = QualityPolicy.initialHeight(heights, connection, dataSaver, preferredQuality)

        val video = pickSource(source.videos, chosenHeight)
        videoAddresses = video?.allUrls.orEmpty()

        val subtitle = pickSubtitle(source.subtitles, subtitleLanguage)

        _state.update {
            it.copy(
                phase = PlaybackPhase.Preparing,
                work = source.work,
                episode = source.episode,
                next = source.next,
                previous = source.previous,
                videoSources = source.videos,
                subtitleSources = source.subtitles,
                fonts = source.fonts,
                quality = preferredQuality,
                activeHeight = chosenHeight,
                speed = speed,
                selectedSubtitleId = subtitle?.id,
                subtitlesEnabled = subtitlesEnabled,
                markers = source.markers,
                autoplayNext = autoplayNext,
                positionMs = startPositionSeconds * 1000L,
                durationMs = source.episode.durationSeconds * 1000L,
                stats = PlaybackStats(),
                controlsVisible = true,
                locked = false,
            )
        }

        openAddress(0, startPositionSeconds * 1000L, subtitle, subtitlesEnabled)
        resolveFonts(source, subtitle)
        scheduleControlsHide()
    }

    /** Open one of the addresses for the current source. */
    private fun openAddress(
        index: Int,
        positionMs: Long,
        subtitle: MediaSource?,
        subtitlesEnabled: Boolean,
    ) {
        val player = exoPlayer ?: return
        val url = videoAddresses.getOrNull(index) ?: run {
            fail(AppError.Video("oynatılabilir adres yok"))
            return
        }

        addressIndex = index

        val builder = MediaItem.Builder().setUri(url)

        if (subtitle != null && subtitlesEnabled && subtitle.url.isNotBlank()) {
            // Side-loaded rather than muxed: the subtitle is a separate object
            // in storage, and ExoPlayer's SSA parser handles it as a track.
            builder.setSubtitleConfigurations(
                listOf(
                    MediaItem.SubtitleConfiguration.Builder(android.net.Uri.parse(subtitle.url))
                        .setMimeType(mimeFor(subtitle))
                        .setLanguage(subtitle.language.ifBlank { "tr" })
                        .setSelectionFlags(C.SELECTION_FLAG_DEFAULT)
                        .build()
                )
            )
        }

        player.setMediaItem(builder.build(), positionMs)
        player.prepare()
        player.playWhenReady = true
        player.setPlaybackSpeed(_state.value.speed)
    }

    /**
     * Move to the next address for this source.
     *
     * @return true when there was one to move to.
     */
    private fun tryNextAddress(): Boolean {
        // Only before the first frame. After it, the address demonstrably
        // works and the problem is the connection, so a retry is right and a
        // source switch would just restart the episode.
        if (sawFirstFrame) return false
        if (addressIndex + 1 >= videoAddresses.size) return false

        val subtitle = currentSubtitle()
        openAddress(addressIndex + 1, _state.value.positionMs, subtitle, _state.value.subtitlesEnabled)

        return true
    }

    fun play() {
        exoPlayer?.playWhenReady = true
        if (_state.value.phase is PlaybackPhase.Paused) {
            _state.update { it.copy(phase = PlaybackPhase.Playing) }
        }
    }

    fun pause() {
        exoPlayer?.playWhenReady = false
        _state.update { it.copy(phase = PlaybackPhase.Paused, controlsVisible = true) }
        persistProgress()
    }

    fun togglePlayPause() {
        if (_state.value.phase.isPlaying) pause() else play()
    }

    fun seekTo(positionMs: Long) {
        val clamped = positionMs.coerceIn(0L, _state.value.durationMs.coerceAtLeast(0L))
        exoPlayer?.seekTo(clamped)
        _state.update { it.copy(positionMs = clamped) }
        scheduleControlsHide()
    }

    fun seekBy(deltaMs: Long) = seekTo(_state.value.positionMs + deltaMs)

    fun skipIntro() {
        val end = _state.value.markers.introEnd
        if (end > 0) seekTo(end * 1000L)
    }

    fun setSpeed(speed: Float) {
        exoPlayer?.setPlaybackSpeed(speed)
        _state.update { it.copy(speed = speed) }
    }

    /** Change rendition, keeping the position. */
    fun setQuality(selection: QualitySelection) {
        val source = playback ?: return
        val heights = QualityPolicy.availableHeights(source.videos)

        val height = when (selection) {
            is QualitySelection.Auto -> QualityPolicy.initialHeight(
                heights, networkMonitor.current(), dataSaver = false, preferred = selection,
            )
            is QualitySelection.Fixed -> QualityPolicy.nearest(heights, selection.height)
        }

        val video = pickSource(source.videos, height) ?: return
        val position = _state.value.positionMs

        videoAddresses = video.allUrls
        // A deliberate quality change re-arms failover: the new address has not
        // proved itself yet.
        sawFirstFrame = false

        _state.update {
            it.copy(
                quality = selection,
                activeHeight = height,
                stats = it.stats.copy(switchCount = it.stats.switchCount + 1),
            )
        }

        openAddress(0, position, currentSubtitle(), _state.value.subtitlesEnabled)
    }

    fun setSubtitle(sourceId: Long?) {
        val source = playback ?: return
        val subtitle = source.subtitles.firstOrNull { it.id == sourceId }
        val enabled = sourceId != null

        _state.update { it.copy(selectedSubtitleId = sourceId, subtitlesEnabled = enabled) }

        if (!enabled) _cues.value = emptyList()

        // ExoPlayer resolves side-loaded subtitles at prepare time, so changing
        // one means re-opening the item at the current position.
        openAddress(addressIndex, _state.value.positionMs, subtitle, enabled)
    }

    fun toggleControls() {
        // Tapping empty space only shows or hides the controls; it never
        // pauses. Pausing belongs to the pause button alone.
        val visible = !_state.value.controlsVisible
        _state.update { it.copy(controlsVisible = visible) }
        if (visible) scheduleControlsHide()
    }

    fun showControls() {
        _state.update { it.copy(controlsVisible = true) }
        scheduleControlsHide()
    }

    fun setLocked(locked: Boolean) {
        _state.update { it.copy(locked = locked, controlsVisible = !locked) }
    }

    /** Retry after a failure the viewer chose to retry. */
    fun retry() {
        retryAttempt = 0
        sawFirstFrame = false
        openAddress(0, _state.value.positionMs, currentSubtitle(), _state.value.subtitlesEnabled)
    }

    fun release() {
        persistProgress()
        progressJob?.cancel()
        retryJob?.cancel()
        controlsJob?.cancel()
        exoPlayer?.removeListener(listener)
        exoPlayer?.release()
        exoPlayer = null
        trackSelector = null
        bandwidthMeter = null
        _cues.value = emptyList()
        _state.value = PlayerUiState()
    }

    private val listener = object : Player.Listener {

        override fun onPlaybackStateChanged(state: Int) {
            when (state) {
                Player.STATE_BUFFERING -> {
                    if (bufferingSince == 0L) bufferingSince = System.currentTimeMillis()
                    _state.update {
                        it.copy(phase = PlaybackPhase.Buffering(isInitial = !sawFirstFrame))
                    }
                }

                Player.STATE_READY -> {
                    recordRebuffer()
                    retryAttempt = 0

                    val player = exoPlayer
                    val known = player?.duration?.takeIf { d -> d != C.TIME_UNSET && d > 0L }
                    if (known != null) measuredDurationMs = known

                    _state.update {
                        it.copy(
                            phase = if (player?.playWhenReady == true) PlaybackPhase.Playing else PlaybackPhase.Paused,
                            durationMs = known ?: it.durationMs,
                        )
                    }
                }

                Player.STATE_ENDED -> {
                    _state.update { it.copy(phase = PlaybackPhase.Completed, controlsVisible = true) }
                    persistProgress()
                    onCompleted?.invoke()
                }

                Player.STATE_IDLE -> Unit
            }
        }

        override fun onRenderedFirstFrame() {
            if (!sawFirstFrame) {
                sawFirstFrame = true
                val startup = System.currentTimeMillis() - preparedAt
                _state.update { it.copy(stats = it.stats.copy(startupMs = startup)) }
            }
        }

        override fun onCues(cueGroup: androidx.media3.common.text.CueGroup) {
            _cues.value = if (_state.value.subtitlesEnabled) cueGroup.cues else emptyList()
        }

        override fun onPlayerError(error: PlaybackException) {
            handleError(error)
        }

        override fun onIsPlayingChanged(isPlaying: Boolean) {
            if (isPlaying) {
                _state.update { it.copy(phase = PlaybackPhase.Playing) }
            }
        }
    }

    private fun handleError(error: PlaybackException) {
        // A different address for the same content is the cheapest fix, and
        // only makes sense before anything has played.
        if (tryNextAddress()) return

        val recoverable = error.errorCode in RECOVERABLE_CODES

        if (recoverable && retryAttempt < QualityPolicy.MAX_RETRIES) {
            retryAttempt++
            _state.update { it.copy(phase = PlaybackPhase.Reconnecting(retryAttempt)) }

            retryJob?.cancel()
            retryJob = scope?.launch {
                delay(QualityPolicy.retryDelayMs(retryAttempt))

                // Nothing to retry against until the network is back; waiting
                // here beats burning the retry budget on a flight-mode phone.
                if (!networkMonitor.isOnline()) {
                    _state.update { it.copy(phase = PlaybackPhase.Reconnecting(retryAttempt)) }
                    return@launch
                }

                exoPlayer?.let {
                    it.seekTo(_state.value.positionMs)
                    it.prepare()
                }
            }
            return
        }

        fail(
            when {
                error.errorCode == PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_FAILED ->
                    AppError.Network(error.errorCodeName)
                error.errorCode == PlaybackException.ERROR_CODE_IO_BAD_HTTP_STATUS ->
                    AppError.Storage(error.errorCodeName)
                else -> AppError.Video("${error.errorCodeName}: ${error.message}")
            }
        )
    }

    private fun fail(error: AppError) {
        _state.update {
            it.copy(phase = PlaybackPhase.Failed(error, canRetry = error.isRetryable), controlsVisible = true)
        }
    }

    private fun startProgressLoop() {
        progressJob?.cancel()
        progressJob = scope?.launch {
            var sinceSave = 0L

            while (true) {
                delay(TICK_MS)

                val player = exoPlayer ?: continue
                val position = player.currentPosition
                val duration = player.duration.takeIf { it != C.TIME_UNSET } ?: 0L
                if (duration > 0L) measuredDurationMs = duration
                val buffered = player.bufferedPosition
                val bandwidth = bandwidthMeter?.bitrateEstimate ?: 0L

                _state.update { current ->
                    val markers = current.markers
                    val seconds = (position / 1000).toInt()

                    current.copy(
                        positionMs = position,
                        durationMs = if (duration > 0) duration else current.durationMs,
                        bufferedMs = buffered,
                        showSkipIntro = markers.isInIntro(seconds),
                        // The up-next card appears over the outro so the viewer
                        // can act before the episode ends, not after.
                        showUpNext = current.next != null &&
                            (markers.isInOutro(seconds) ||
                                (duration > 0 && position > duration - UP_NEXT_LEAD_MS)),
                        stats = current.stats.copy(
                            bandwidthBps = bandwidth,
                            droppedFrames = current.stats.droppedFrames,
                        ),
                    )
                }

                maybeSwitchQuality(buffered - position, bandwidth)

                // Count watched time on the tick rather than at save time: a
                // 500ms window is tight enough to tell playback from a drag of
                // the scrubber, which ten seconds is not.
                if (_state.value.phase.isPlaying) {
                    if (lastTickMs >= 0L) {
                        watchedMs = WatchProgress.accumulateMs(watchedMs, lastTickMs, position)
                    }
                    lastTickMs = position
                } else {
                    // Paused: the next step would span the whole pause, which
                    // is not watching. Start measuring again on resume.
                    lastTickMs = -1L
                }

                sinceSave += TICK_MS
                if (sinceSave >= SAVE_INTERVAL_MS && _state.value.phase.isPlaying) {
                    sinceSave = 0
                    persistProgress()
                }
            }
        }
    }

    /** Ask the policy whether to change rendition, and act on it. */
    private fun maybeSwitchQuality(bufferedAheadMs: Long, bandwidthBps: Long) {
        val current = _state.value
        if (current.quality !is QualitySelection.Auto) return
        if (!current.phase.isPlaying) return

        val heights = QualityPolicy.availableHeights(current.videoSources)
        val next = QualityPolicy.nextHeight(
            available = heights,
            currentHeight = current.activeHeight,
            bandwidthBps = bandwidthBps,
            bufferedMs = bufferedAheadMs,
            dataSaver = false,
        ) ?: return

        val video = pickSource(current.videoSources, next) ?: return
        val position = current.positionMs

        videoAddresses = video.allUrls
        _state.update {
            it.copy(activeHeight = next, stats = it.stats.copy(switchCount = it.stats.switchCount + 1))
        }

        openAddress(0, position, currentSubtitle(), current.subtitlesEnabled)
    }

    private fun recordRebuffer() {
        if (bufferingSince == 0L) return

        val duration = System.currentTimeMillis() - bufferingSince
        bufferingSince = 0L

        // Only mid-episode stalls count. Start-up buffering is expected and
        // lumping it in makes the figure meaningless.
        if (!sawFirstFrame) return

        _state.update {
            it.copy(
                stats = it.stats.copy(
                    rebufferCount = it.stats.rebufferCount + 1,
                    rebufferMs = it.stats.rebufferMs + duration,
                )
            )
        }
    }

    private fun persistProgress() {
        val current = _state.value
        val position = current.positionSeconds
        if (position <= 0) return

        // Zero when the file has not said how long it is yet. The server keeps
        // whatever it already had rather than believing a guess.
        onProgress?.invoke(position, (measuredDurationMs / 1000).toInt(), (watchedMs / 1000).toInt())
    }

    private fun scheduleControlsHide() {
        controlsJob?.cancel()
        controlsJob = scope?.launch {
            delay(CONTROLS_TIMEOUT_MS)
            // Only while playing: hiding the controls on a paused player leaves
            // the viewer with no way back except another tap.
            if (_state.value.phase.isPlaying) {
                _state.update { it.copy(controlsVisible = false) }
            }
        }
    }

    private fun resolveFonts(source: Playback, subtitle: MediaSource?) {
        // Cleared before the new script is fetched rather than after it
        // resolves: a track switched mid-episode would otherwise keep drawing
        // in the last script's font until the download finished.
        _typefaces.value = emptyMap()
        _primaryFont.value = null

        scope?.launch {
            if (subtitle == null) return@launch

            val script = downloadSubtitle(subtitle) ?: return@launch
            val required = AssParser.requiredFonts(script)

            _primaryFont.value = AssParser.primaryFont(script)

            if (required.isEmpty()) return@launch

            val resolution = fontResolver.resolve(required, source.fonts)

            _typefaces.value = resolution.typefaces
            _state.update { it.copy(missingFonts = resolution.missing) }
        }
    }

    private suspend fun downloadSubtitle(subtitle: MediaSource): String? =
        kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
            for (url in subtitle.allUrls) {
                try {
                    httpClient.newCall(okhttp3.Request.Builder().url(url).build()).execute().use { response ->
                        if (response.isSuccessful) {
                            return@withContext response.body?.string()
                        }
                    }
                } catch (error: Exception) {
                    // Try the next address; a missing subtitle must not stop
                    // the episode.
                    continue
                }
            }
            null
        }

    private fun currentSubtitle(): MediaSource? =
        playback?.subtitles?.firstOrNull { it.id == _state.value.selectedSubtitleId }

    /** The rendition at or below [height], preferring an exact match. */
    private fun pickSource(sources: List<MediaSource>, height: Int): MediaSource? {
        if (sources.isEmpty()) return null

        return sources.firstOrNull { it.height == height }
            ?: sources.filter { it.height in 1..height }.maxByOrNull { it.height }
            // A source with no height is an HLS master playlist, where the
            // ladder is inside the manifest and ABR handles it.
            ?: sources.firstOrNull { it.height == 0 }
            ?: sources.minByOrNull { it.height }
    }

    private fun pickSubtitle(subtitles: List<MediaSource>, language: String): MediaSource? =
        subtitles.firstOrNull { it.language.equals(language, ignoreCase = true) }
            ?: subtitles.firstOrNull { it.isDefault }
            ?: subtitles.firstOrNull()

    private fun mimeFor(source: MediaSource): String = when {
        source.mime.isNotBlank() -> source.mime
        source.url.endsWith(".ass", true) || source.url.endsWith(".ssa", true) -> MimeTypes.TEXT_SSA
        source.url.endsWith(".vtt", true) -> MimeTypes.TEXT_VTT
        source.url.endsWith(".srt", true) -> MimeTypes.APPLICATION_SUBRIP
        else -> MimeTypes.TEXT_SSA
    }

    private companion object {
        const val TICK_MS = 500L
        const val SAVE_INTERVAL_MS = 10_000L
        const val CONTROLS_TIMEOUT_MS = 3_500L
        const val SEEK_STEP_MS = 10_000L
        const val UP_NEXT_LEAD_MS = 25_000L
        const val USER_AGENT = "Animeh/0.1 (Android)"

        /** Codes worth retrying: transport failures, not a broken file. */
        val RECOVERABLE_CODES = setOf(
            PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_FAILED,
            PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_TIMEOUT,
            PlaybackException.ERROR_CODE_IO_UNSPECIFIED,
            PlaybackException.ERROR_CODE_BEHIND_LIVE_WINDOW,
        )
    }
}
