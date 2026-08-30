package com.animeh.app.player

import com.animeh.app.domain.Markers
import com.animeh.app.domain.Progress
import org.junit.Assert.*
import org.junit.Test

class PlayerStateTest {

    @Test
    fun `phase classification is exhaustive and consistent`() {
        assertTrue(PlaybackPhase.Playing.isPlaying)
        assertTrue(PlaybackPhase.Buffering().isLoading)
        assertTrue(PlaybackPhase.Reconnecting(1).isLoading)
        assertTrue(PlaybackPhase.Preparing.isLoading)
        assertTrue(PlaybackPhase.Completed.isTerminal)
        assertFalse(PlaybackPhase.Paused.isLoading)
        assertFalse(PlaybackPhase.Paused.isPlaying)
    }

    @Test
    fun `progress fractions are clamped and safe when the length is unknown`() {
        // A duration of zero is the normal state before the manifest is read;
        // dividing by it would put NaN into the seek bar.
        assertEquals(0f, PlayerUiState(positionMs = 5_000, durationMs = 0).progressFraction, 0.0001f)
        assertEquals(0.5f, PlayerUiState(positionMs = 5_000, durationMs = 10_000).progressFraction, 0.0001f)
        assertEquals(1f, PlayerUiState(positionMs = 99_000, durationMs = 10_000).progressFraction, 0.0001f)
    }

    @Test
    fun `auto quality reports what it actually resolved to`() {
        val auto = PlayerUiState(quality = QualitySelection.Auto, activeHeight = 720)
        assertEquals("Auto · 720p", auto.activeQualityLabel)

        val notYet = PlayerUiState(quality = QualitySelection.Auto, activeHeight = 0)
        assertEquals("Auto", notYet.activeQualityLabel)

        val fixed = PlayerUiState(quality = QualitySelection.Fixed(1080))
        assertEquals("1080p", fixed.activeQualityLabel)
    }

    @Test
    fun `markers distinguish unset from a marker at zero`() {
        // -1 is "not marked"; 0 is a real marker at the very start, and
        // conflating them either hides a skip button or shows a false one.
        val unset = Markers()
        assertFalse(unset.isInIntro(0))
        assertFalse(unset.isInOutro(0))

        val marked = Markers(introStart = 0, introEnd = 90, outroStart = 1300)
        assertTrue(marked.isInIntro(0))
        assertTrue(marked.isInIntro(89))
        assertFalse(marked.isInIntro(90))
        assertTrue(marked.isInOutro(1300))
        assertFalse(marked.isInOutro(1299))
    }

    @Test
    fun `resume is only offered past the threshold`() {
        assertFalse(Progress(10, 1400, false).isResumable)
        assertTrue(Progress(400, 1400, false).isResumable)
        // A finished episode resumes from the start, not from the credits.
        assertFalse(Progress(1390, 1400, true).isResumable)
    }

    @Test
    fun `bandwidth is labelled in the unit a person reads`() {
        assertEquals("—", PlaybackStats().bandwidthLabel)
        assertEquals("700 kbps", PlaybackStats(bandwidthBps = 700_000).bandwidthLabel)
        assertEquals("3.5 Mbps", PlaybackStats(bandwidthBps = 3_500_000).bandwidthLabel)
    }
}
