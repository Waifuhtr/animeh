package com.animeh.app.player.ads

import android.content.Context
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import com.animeh.app.core.ClientLog
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import javax.inject.Inject
import javax.inject.Singleton

/**
 * What the viewer is looking at while an ad break runs.
 *
 * [Loading] exists as a state rather than a moment because it is one the
 * viewer can be stuck in: the network is being asked for an ad and might not
 * answer. It is deliberately brief — the request gives up after a few seconds
 * — and while it lasts the episode is already paused, so something has to say
 * why.
 */
sealed interface AdBreakState {
    /** No break. The episode plays. */
    data object Idle : AdBreakState

    /** An ad has been asked for and the episode is held. */
    data object Loading : AdBreakState

    /** An ad is on screen. */
    data class Showing(
        val ad: VastAd,
        val positionMs: Long,
        val durationMs: Long,
        /** When skipping becomes possible, or null when it never does. */
        val skipAtMs: Long?,
    ) : AdBreakState {
        val remainingMs: Long get() = (durationMs - positionMs).coerceAtLeast(0)
        val canSkip: Boolean get() = skipAtMs != null && positionMs >= skipAtMs
        /** Seconds still to wait before the skip button means anything. */
        val skipInSeconds: Int
            get() = skipAtMs?.let { ((it - positionMs + 999) / 1000).coerceAtLeast(0).toInt() } ?: 0
    }
}

/**
 * One ad break, start to finish.
 *
 * Owns a second [ExoPlayer] rather than borrowing the episode's. The episode's
 * player carries a position, a subtitle track, a font set and a quality
 * selection that all have to survive the interruption intact, and handing it
 * a different media item is how a viewer comes back from an ad to the top of
 * the episode with the subtitles gone. Two players, one surface: the view is
 * pointed at whichever one is currently meant to be seen.
 *
 * Everything here fails towards the episode. A network that does not answer,
 * a response that will not parse, a creative the decoder refuses — each ends
 * the break immediately and silently. The viewer came for the episode; an
 * error message about an advertisement is worse than the advertisement not
 * having appeared.
 */
@Singleton
class AdBreakController @Inject constructor(
    @ApplicationContext private val context: Context,
    private val client: VastClient,
    private val gate: AdGate,
) {

    private val _state = MutableStateFlow<AdBreakState>(AdBreakState.Idle)
    val state: StateFlow<AdBreakState> = _state.asStateFlow()

    /** The player the surface should show while a break runs, else null. */
    var player: ExoPlayer? = null
        private set

    /**
     * The highest break index already shown for the episode on screen.
     *
     * Reset by [reset] when a different episode starts, because break three
     * of the last one says nothing about this one.
     */
    private var lastPlayed = -1

    private var watcher: Job? = null

    /** Progress URLs not yet fired, in order. Drained as the ad advances. */
    private var pending = mutableListOf<VastTracking>()

    private var impressionFired = false

    /** Forget the schedule. Call when the episode changes. */
    fun reset() {
        lastPlayed = -1
        finish()
    }

    /**
     * Start a break if one is due.
     *
     * Called from the position loop that already runs for the episode, so it
     * is asked several times a second and has to be cheap to say no to —
     * which is why the schedule is arithmetic and the plan is a field.
     *
     * @return true when a break started and the episode should be paused.
     */
    fun dueNow(positionMs: Long, durationMs: Long): Boolean {
        val plan = gate.current ?: return false

        if (_state.value != AdBreakState.Idle) return false

        if (!AdSchedule.isDue(positionMs, durationMs, plan.intervalMs, plan.preroll, lastPlayed)) {
            return false
        }

        lastPlayed = AdSchedule.consume(positionMs, plan.intervalMs, plan.preroll, lastPlayed)

        return true
    }

    /**
     * Fetch and play. Must be called from the main thread, as must [finish].
     *
     * The scope belongs to the caller — the player screen — so that leaving
     * the screen takes the request with it rather than leaving one in flight
     * against a player that is gone.
     */
    fun begin(scope: CoroutineScope, onEnded: () -> Unit) {
        val plan = gate.current ?: return onEnded()

        _state.value = AdBreakState.Loading

        scope.launch {
            val ad = client.request(plan.tag)

            if (ad == null) {
                // No fill, no network, nothing parseable — all the same from
                // here, and all of them mean the episode resumes now.
                finish()
                onEnded()

                return@launch
            }

            show(ad, plan, scope, onEnded)
        }
    }

    private fun show(ad: VastAd, plan: AdPlan, scope: CoroutineScope, onEnded: () -> Unit) {
        val exo = runCatching { ExoPlayer.Builder(context).build() }.getOrNull()

        if (exo == null) {
            client.reportError(ad.errors, VastError.MEDIA_NOT_PLAYABLE)
            finish()
            onEnded()

            return
        }

        pending = ad.progress.toMutableList()
        impressionFired = false

        exo.addListener(object : Player.Listener {
            override fun onPlaybackStateChanged(state: Int) {
                if (state == Player.STATE_ENDED) {
                    client.report(ad.complete)
                    finish()
                    onEnded()
                }
            }

            override fun onPlayerError(failure: PlaybackException) {
                // The file was reachable and would not decode. Reported,
                // because a network never told keeps sending it.
                ClientLog.record("Reklam oynatılamadı", failure.errorCodeName)
                client.reportError(ad.errors, VastError.MEDIA_NOT_PLAYABLE)
                finish()
                onEnded()
            }
        })

        exo.setMediaItem(MediaItem.Builder().setUri(ad.media).build())
        exo.prepare()
        exo.playWhenReady = true

        player = exo

        _state.value = AdBreakState.Showing(
            ad = ad,
            positionMs = 0,
            durationMs = ad.durationMs,
            skipAtMs = plan.skipAtMs(ad.skipOffsetMs),
        )

        watcher = scope.launch {
            while (isActive) {
                val current = player ?: break
                val showing = _state.value as? AdBreakState.Showing ?: break

                val position = current.currentPosition.coerceAtLeast(0)

                // The duration the ad declared is what the countdown and the
                // skip offset are measured against, but a creative can run a
                // little longer or shorter than it said. Once the player knows
                // better, believe the player.
                val duration = current.duration
                    .takeIf { it > 0 }
                    ?: showing.durationMs

                if (!impressionFired && position > 0) {
                    impressionFired = true
                    client.report(ad.impressions)
                }

                // Drained rather than searched: the list is sorted, so
                // everything at the front that the position has passed fires
                // now and never again.
                while (pending.isNotEmpty() && pending.first().atMs <= position) {
                    client.report(listOf(pending.removeAt(0).url))
                }

                _state.value = showing.copy(positionMs = position, durationMs = duration)

                delay(TICK_MS)
            }
        }
    }

    /**
     * The viewer skipped.
     *
     * Reported before the teardown: the network pays for a skipped ad
     * differently from one that ran, and it can only know which this was if
     * it is told.
     */
    fun skip() {
        (_state.value as? AdBreakState.Showing)?.let { client.report(it.ad.skipped) }

        finish()
    }

    /**
     * The viewer tapped the ad.
     *
     * @return the address to open, or null when the ad carries none.
     */
    fun clicked(): String? {
        val showing = _state.value as? AdBreakState.Showing ?: return null

        client.report(showing.ad.clickTracking)
        showing.ad.cta?.clickUrl?.let { client.report(listOf(it)) }

        return showing.ad.clickThrough
    }

    /** Tear the break down, whatever state it was in. */
    fun finish() {
        watcher?.cancel()
        watcher = null

        player?.let { exo ->
            exo.stop()
            exo.release()
        }
        player = null

        pending.clear()
        _state.value = AdBreakState.Idle
    }

    private companion object {
        /**
         * How often the ad's position is read, in milliseconds.
         *
         * A quarter second. The countdown has to look like a countdown and a
         * tracking offset has to fire near where it was asked for; a whole
         * second would make the last number sit there and a pixel land up to
         * a second late.
         */
        const val TICK_MS = 250L
    }
}
