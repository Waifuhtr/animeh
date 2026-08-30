package com.animeh.app.player

import com.animeh.app.domain.MediaSource

/**
 * How the player decides what to download.
 *
 * §11 is the longest section of the brief and this is its implementation. The
 * governing idea: on a weak mobile connection the wrong first choice costs more
 * than the whole rest of the session, because a stall in the first five seconds
 * is when a viewer leaves.
 *
 * Pure functions, no Android types, so the rules are testable.
 */
object QualityPolicy {

    /** Heights offered in the quality menu. */
    val LADDER = listOf(1080, 720, 480, 360)

    /**
     * Bits per second a height needs, with headroom.
     *
     * Deliberately above the nominal encode bitrate: a stream that exactly
     * matches the available bandwidth never fills its buffer, so it stalls on
     * the first fluctuation. The multiplier is the margin.
     */
    private val REQUIRED_BPS = mapOf(
        1080 to 5_000_000L,
        720 to 2_800_000L,
        480 to 1_400_000L,
        360 to 700_000L,
    )

    /** How much more bandwidth than the stream needs before stepping up. */
    private const val UPSWITCH_MARGIN = 1.4

    /** How little before stepping down. Lower than the up margin on purpose:
     *  switching down must be quicker than switching up, or the buffer drains
     *  while the policy deliberates. */
    private const val DOWNSWITCH_MARGIN = 1.0

    /**
     * The first rendition to request, before any bandwidth is measured.
     *
     * Conservative by design. Guessing high and being wrong means the viewer
     * watches a spinner; guessing low and being wrong means one quality switch
     * a few seconds in, which nobody notices.
     */
    fun initialHeight(
        available: List<Int>,
        connection: ConnectionClass,
        dataSaver: Boolean,
        preferred: QualitySelection,
    ): Int {
        if (preferred is QualitySelection.Fixed) {
            return nearest(available, preferred.height)
        }

        val ceiling = when {
            dataSaver -> 480
            connection == ConnectionClass.WIFI -> 1080
            connection == ConnectionClass.CELLULAR_FAST -> 720
            connection == ConnectionClass.CELLULAR_SLOW -> 360
            else -> 480
        }

        return nearest(available, ceiling)
    }

    /**
     * Whether to switch, given measured throughput.
     *
     * @param currentHeight what is playing.
     * @param bandwidthBps  measured throughput.
     * @param bufferedMs    how much runway is in the buffer.
     * @return the height to switch to, or null to stay.
     */
    fun nextHeight(
        available: List<Int>,
        currentHeight: Int,
        bandwidthBps: Long,
        bufferedMs: Long,
        dataSaver: Boolean,
    ): Int? {
        if (available.size <= 1 || bandwidthBps <= 0) return null

        val ceiling = if (dataSaver) 480 else Int.MAX_VALUE
        val sorted = available.filter { it <= ceiling }.sortedDescending()
        if (sorted.isEmpty()) return null

        val currentNeed = requiredBps(currentHeight)

        // Down first, and without waiting for the buffer to be healthy: a
        // stall costs more than a resolution drop, and by the time the buffer
        // is empty it is too late to avoid one.
        if (bandwidthBps < currentNeed * DOWNSWITCH_MARGIN) {
            val lower = sorted.firstOrNull { it < currentHeight && bandwidthBps >= requiredBps(it) }
                ?: sorted.last()
            return lower.takeIf { it != currentHeight }
        }

        // Up only with both bandwidth headroom and buffer runway. Stepping up
        // on bandwidth alone is how a player oscillates: it switches up, the
        // higher bitrate drains the buffer, and it switches straight back.
        //
        // `firstOrNull` on a descending list is the *best* affordable rung, not
        // the next one up. Creeping one rung at a time would be several switch
        // cycles to reach the top, and every switch re-opens the media item.
        // Jumping is safe because each rung's check already carries the margin.
        if (bufferedMs >= UPSWITCH_MIN_BUFFER_MS) {
            val higher = sorted.firstOrNull { it > currentHeight && bandwidthBps >= requiredBps(it) * UPSWITCH_MARGIN }
            if (higher != null) return higher
        }

        return null
    }

    /** Buffer sizing, which differs by how good the connection is. */
    fun bufferProfile(connection: ConnectionClass): BufferProfile = when (connection) {
        // A big buffer on a slow link is the wrong trade: it delays the first
        // frame to protect against a stall that a smaller buffer would have
        // recovered from anyway.
        ConnectionClass.CELLULAR_SLOW -> BufferProfile(
            minBufferMs = 15_000,
            maxBufferMs = 60_000,
            bufferForPlaybackMs = 1_500,
            bufferForPlaybackAfterRebufferMs = 3_000,
        )
        ConnectionClass.CELLULAR_FAST -> BufferProfile(
            minBufferMs = 25_000,
            maxBufferMs = 90_000,
            bufferForPlaybackMs = 2_000,
            bufferForPlaybackAfterRebufferMs = 4_000,
        )
        // On wifi, buffer generously: the bytes are cheap and a large buffer
        // rides out a lift or a tunnel without a stall.
        ConnectionClass.WIFI -> BufferProfile(
            minBufferMs = 50_000,
            maxBufferMs = 180_000,
            bufferForPlaybackMs = 2_500,
            bufferForPlaybackAfterRebufferMs = 5_000,
        )
        ConnectionClass.UNKNOWN -> BufferProfile(
            minBufferMs = 25_000,
            maxBufferMs = 90_000,
            bufferForPlaybackMs = 2_000,
            bufferForPlaybackAfterRebufferMs = 4_000,
        )
    }

    /**
     * Backoff before retry n.
     *
     * Exponential with a ceiling, as §11 asks. The ceiling matters: without it
     * a long outage pushes the next retry hours out and the app appears dead
     * after the connection returns.
     */
    fun retryDelayMs(attempt: Int): Long {
        val base = 1_000L shl (attempt.coerceIn(0, 5))
        return base.coerceAtMost(MAX_RETRY_DELAY_MS)
    }

    /** Heights on offer, highest first, deduplicated. */
    fun availableHeights(sources: List<MediaSource>): List<Int> =
        sources.map { it.height }.filter { it > 0 }.distinct().sortedDescending()

    /** The closest offered height at or below [wanted]. */
    fun nearest(available: List<Int>, wanted: Int): Int {
        if (available.isEmpty()) return 0
        return available.filter { it <= wanted }.maxOrNull() ?: available.min()
    }

    private fun requiredBps(height: Int): Long =
        REQUIRED_BPS[height] ?: REQUIRED_BPS.entries.minByOrNull { kotlin.math.abs(it.key - height) }?.value
        ?: 1_400_000L

    /** Runway required before stepping up. */
    const val UPSWITCH_MIN_BUFFER_MS = 20_000L
    const val MAX_RETRY_DELAY_MS = 30_000L
    const val MAX_RETRIES = 5
}

/** How good the connection is, as coarsely as is useful. */
enum class ConnectionClass {
    WIFI, CELLULAR_FAST, CELLULAR_SLOW, UNKNOWN
}

data class BufferProfile(
    val minBufferMs: Int,
    val maxBufferMs: Int,
    val bufferForPlaybackMs: Int,
    val bufferForPlaybackAfterRebufferMs: Int,
)
