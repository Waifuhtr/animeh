package com.animeh.app.player

import com.animeh.app.domain.MediaSource
import org.junit.Assert.*
import org.junit.Test

/**
 * The ABR rules, which are the ones that decide whether a weak connection
 * stalls. Pure functions, so they are tested directly rather than by watching
 * a video and hoping.
 */
class QualityPolicyTest {

    private val ladder = listOf(1080, 720, 480, 360)

    @Test
    fun `starts low on a slow cellular connection`() {
        // Guessing high and being wrong means the viewer watches a spinner;
        // guessing low means one switch nobody notices.
        val height = QualityPolicy.initialHeight(
            ladder, ConnectionClass.CELLULAR_SLOW, dataSaver = false, preferred = QualitySelection.Auto,
        )
        assertEquals(360, height)
    }

    @Test
    fun `starts high on wifi`() {
        val height = QualityPolicy.initialHeight(
            ladder, ConnectionClass.WIFI, dataSaver = false, preferred = QualitySelection.Auto,
        )
        assertEquals(1080, height)
    }

    @Test
    fun `data saver caps the start regardless of connection`() {
        val height = QualityPolicy.initialHeight(
            ladder, ConnectionClass.WIFI, dataSaver = true, preferred = QualitySelection.Auto,
        )
        assertEquals(480, height)
    }

    @Test
    fun `an explicit choice beats every heuristic`() {
        val height = QualityPolicy.initialHeight(
            ladder, ConnectionClass.CELLULAR_SLOW, dataSaver = true,
            preferred = QualitySelection.Fixed(720),
        )
        assertEquals(720, height)
    }

    @Test
    fun `switches down as soon as bandwidth drops, without waiting for the buffer`() {
        // By the time the buffer is empty it is too late to avoid a stall.
        val next = QualityPolicy.nextHeight(
            available = ladder,
            currentHeight = 1080,
            bandwidthBps = 900_000,
            bufferedMs = 40_000,
            dataSaver = false,
        )
        assertNotNull(next)
        assertTrue("beklenen düşüş, gelen $next", next!! < 1080)
    }

    @Test
    fun `does not switch up without buffer runway`() {
        // Bandwidth alone is how a player oscillates: it steps up, the higher
        // bitrate drains the buffer, and it steps straight back down.
        val next = QualityPolicy.nextHeight(
            available = ladder,
            currentHeight = 480,
            bandwidthBps = 20_000_000,
            bufferedMs = 5_000,
            dataSaver = false,
        )
        assertNull(next)
    }

    @Test
    fun `switches up with both bandwidth and runway`() {
        val next = QualityPolicy.nextHeight(
            available = ladder,
            currentHeight = 480,
            bandwidthBps = 20_000_000,
            bufferedMs = 60_000,
            dataSaver = false,
        )
        assertEquals(1080, next)
    }

    @Test
    fun `data saver blocks an upswitch past its ceiling`() {
        val next = QualityPolicy.nextHeight(
            available = ladder,
            currentHeight = 480,
            bandwidthBps = 20_000_000,
            bufferedMs = 60_000,
            dataSaver = true,
        )
        assertNull(next)
    }

    @Test
    fun `stays put when nothing has changed`() {
        val next = QualityPolicy.nextHeight(
            available = ladder,
            currentHeight = 720,
            bandwidthBps = 3_200_000,
            bufferedMs = 10_000,
            dataSaver = false,
        )
        assertNull(next)
    }

    @Test
    fun `a single rendition is never switched`() {
        assertNull(
            QualityPolicy.nextHeight(listOf(720), 720, 100, 0, false)
        )
    }

    @Test
    fun `no bandwidth estimate means no decision`() {
        // Acting on a zero estimate would drop everyone to 360p at start-up.
        assertNull(QualityPolicy.nextHeight(ladder, 1080, 0, 60_000, false))
    }

    @Test
    fun `backoff grows and then stops growing`() {
        assertEquals(2_000L, QualityPolicy.retryDelayMs(1))
        assertEquals(4_000L, QualityPolicy.retryDelayMs(2))
        assertEquals(8_000L, QualityPolicy.retryDelayMs(3))
        // Capped, or a long outage pushes the next attempt so far out that the
        // app looks dead after the connection returns.
        assertEquals(QualityPolicy.MAX_RETRY_DELAY_MS, QualityPolicy.retryDelayMs(20))
    }

    @Test
    fun `buffer profiles trade start-up against stall protection`() {
        val slow = QualityPolicy.bufferProfile(ConnectionClass.CELLULAR_SLOW)
        val wifi = QualityPolicy.bufferProfile(ConnectionClass.WIFI)

        // A big buffer on a slow link delays the first frame to protect against
        // a stall a smaller buffer would have recovered from.
        assertTrue(slow.bufferForPlaybackMs < wifi.bufferForPlaybackMs)
        assertTrue(slow.maxBufferMs < wifi.maxBufferMs)
        assertTrue(slow.bufferForPlaybackAfterRebufferMs > slow.bufferForPlaybackMs)
    }

    @Test
    fun `heights come back highest first and deduplicated`() {
        val sources = listOf(
            source(720), source(1080), source(720), source(0),
        )
        assertEquals(listOf(1080, 720), QualityPolicy.availableHeights(sources))
    }

    @Test
    fun `nearest never returns something above what was asked for`() {
        assertEquals(720, QualityPolicy.nearest(ladder, 900))
        assertEquals(1080, QualityPolicy.nearest(ladder, 1080))
        // Below every rung: the lowest is better than nothing.
        assertEquals(360, QualityPolicy.nearest(ladder, 100))
        assertEquals(0, QualityPolicy.nearest(emptyList(), 720))
    }

    private fun source(height: Int) = MediaSource(
        id = height.toLong(),
        label = "${height}p",
        language = "",
        mime = "video/mp4",
        height = height,
        sizeBytes = 0,
        isDefault = false,
        url = "https://example.test/$height.mp4",
    )
}
