package com.animeh.app.player

import com.animeh.app.core.AppError
import com.animeh.app.domain.Episode
import com.animeh.app.domain.MediaSource
import com.animeh.app.domain.Markers
import com.animeh.app.domain.SubtitleFont
import com.animeh.app.domain.Work

/**
 * The player's state, modelled explicitly.
 *
 * §12 asks for this and for engine state and UI state not to be muddled. So
 * there are two types here, deliberately:
 *
 * - [PlaybackPhase] is what the media engine is doing. It changes because
 *   ExoPlayer said so.
 * - [PlayerUiState] is everything the controls need to draw. It changes because
 *   the user tapped something, or because the phase changed.
 *
 * Keeping them apart is what stops "the controls are visible" from being
 * something the decoder can accidentally alter.
 */
sealed interface PlaybackPhase {
    /** Nothing loaded. */
    data object Idle : PlaybackPhase

    /** A source is being opened; no frame yet. */
    data object Preparing : PlaybackPhase

    /** Decoding and advancing. */
    data object Playing : PlaybackPhase

    /** Deliberately stopped by the viewer. */
    data object Paused : PlaybackPhase

    /**
     * Stalled waiting for data.
     *
     * [isInitial] separates "still starting up" from "ran dry mid-episode":
     * the first is expected and the second is the one worth counting against
     * playback quality.
     */
    data class Buffering(val isInitial: Boolean = false) : PlaybackPhase

    /** Connection lost; retrying with backoff. */
    data class Reconnecting(val attempt: Int) : PlaybackPhase

    /** Reached the end. */
    data object Completed : PlaybackPhase

    /** Gave up. [canRetry] decides whether the UI offers a retry button. */
    data class Failed(val error: AppError, val canRetry: Boolean = true) : PlaybackPhase

    val isPlaying: Boolean get() = this is Playing
    val isLoading: Boolean get() = this is Preparing || this is Buffering || this is Reconnecting
    val isTerminal: Boolean get() = this is Completed || this is Failed
}

/** Which rendition the viewer asked for. */
sealed interface QualitySelection {
    /** Let the ABR policy choose. */
    data object Auto : QualitySelection

    /** Pin to one height, e.g. 720. */
    data class Fixed(val height: Int) : QualitySelection

    val label: String get() = when (this) {
        is Auto -> "Auto"
        is Fixed -> "${height}p"
    }
}

/**
 * Everything the controls render.
 *
 * One immutable snapshot rather than a dozen separate flows, so a recomposition
 * can never show a mix of two moments — the seek bar at one position and the
 * time label at another.
 */
data class PlayerUiState(
    val phase: PlaybackPhase = PlaybackPhase.Idle,
    val work: Work? = null,
    val episode: Episode? = null,
    val next: Episode? = null,
    val previous: Episode? = null,

    val positionMs: Long = 0,
    val durationMs: Long = 0,
    val bufferedMs: Long = 0,

    val videoSources: List<MediaSource> = emptyList(),
    val subtitleSources: List<MediaSource> = emptyList(),
    val fonts: List<SubtitleFont> = emptyList(),
    val missingFonts: List<String> = emptyList(),

    val quality: QualitySelection = QualitySelection.Auto,
    /** What ABR actually picked, so "Auto" can show what it resolved to. */
    val activeHeight: Int = 0,
    val speed: Float = 1.0f,
    val selectedSubtitleId: Long? = null,
    val subtitlesEnabled: Boolean = true,

    val markers: Markers = Markers(),
    val controlsVisible: Boolean = true,
    val locked: Boolean = false,
    val isFullscreen: Boolean = true,

    val showSkipIntro: Boolean = false,
    val showUpNext: Boolean = false,
    val autoplayNext: Boolean = true,

    val stats: PlaybackStats = PlaybackStats(),
) {
    val canSeek: Boolean get() = durationMs > 0 && !phase.isTerminal

    val progressFraction: Float
        get() = if (durationMs <= 0) 0f else (positionMs.toFloat() / durationMs).coerceIn(0f, 1f)

    val bufferedFraction: Float
        get() = if (durationMs <= 0) 0f else (bufferedMs.toFloat() / durationMs).coerceIn(0f, 1f)

    val positionSeconds: Int get() = (positionMs / 1000).toInt()
    val durationSeconds: Int get() = (durationMs / 1000).toInt()

    /** The rendition currently on screen, for the quality label. */
    val activeQualityLabel: String
        get() = when (quality) {
            is QualitySelection.Fixed -> quality.label
            is QualitySelection.Auto -> if (activeHeight > 0) "Auto · ${activeHeight}p" else "Auto"
        }
}

/**
 * What actually happened during playback.
 *
 * The same figures the WordPress test panel reports, so a problem seen on the
 * phone can be described in the same terms as one seen on the web player.
 */
data class PlaybackStats(
    val startupMs: Long = 0,
    val rebufferCount: Int = 0,
    val rebufferMs: Long = 0,
    val bandwidthBps: Long = 0,
    val droppedFrames: Int = 0,
    val switchCount: Int = 0,
) {
    val bandwidthLabel: String
        get() = when {
            bandwidthBps <= 0 -> "—"
            bandwidthBps >= 1_000_000 -> "%.1f Mbps".format(bandwidthBps / 1_000_000.0)
            else -> "${bandwidthBps / 1000} kbps"
        }
}
