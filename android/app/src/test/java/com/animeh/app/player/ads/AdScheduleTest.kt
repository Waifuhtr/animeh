package com.animeh.app.player.ads

import org.junit.Assert.*
import org.junit.Test

/**
 * The ad cadence.
 *
 * Every case here is a way the schedule can be wrong that nobody would notice
 * reading it: firing twice at the same instant, firing again after a rewind,
 * firing four times in a row after a long seek, or firing over the credits.
 */
class AdScheduleTest {

    private val interval = 240_000L      // four minutes
    private val episode = 24L * 60_000L  // a normal episode

    private fun due(position: Long, lastPlayed: Int = -1, preroll: Boolean = false) =
        AdSchedule.isDue(position, episode, interval, preroll, lastPlayed)

    @Test
    fun `without a pre-roll nothing interrupts the opening`() {
        assertFalse(due(0))
        assertFalse(due(1_000))
        assertFalse(due(interval - 1))
    }

    @Test
    fun `the first break lands on the interval`() {
        assertTrue(due(interval))
        assertEquals(0, AdSchedule.indexAt(interval, interval, preroll = false))
    }

    @Test
    fun `a break does not fire twice while the position sits inside it`() {
        val played = AdSchedule.consume(interval, interval, preroll = false, lastPlayed = -1)

        assertEquals(0, played)
        assertFalse(due(interval, lastPlayed = played))
        assertFalse(due(interval + 30_000, lastPlayed = played))
        assertFalse(due(2 * interval - 1, lastPlayed = played))

        // And the next one does.
        assertTrue(due(2 * interval, lastPlayed = played))
    }

    @Test
    fun `rewinding does not replay an ad already shown`() {
        val played = AdSchedule.consume(3 * interval, interval, preroll = false, lastPlayed = -1)

        assertEquals(2, played)
        assertFalse(due(interval, lastPlayed = played))
        assertFalse(due(2 * interval, lastPlayed = played))
        assertFalse(due(3 * interval, lastPlayed = played))
    }

    @Test
    fun `seeking over several breaks interrupts once, not once each`() {
        // Ten minutes in one jump, with a four minute interval: two breaks
        // were passed. One ad, and the schedule picks up from there.
        assertTrue(due(600_000))

        val played = AdSchedule.consume(600_000, interval, preroll = false, lastPlayed = -1)

        assertEquals(1, played)
        assertFalse(due(600_000, lastPlayed = played))
        assertFalse(due(700_000, lastPlayed = played))
        assertTrue(due(3 * interval, lastPlayed = played))
    }

    @Test
    fun `a pre-roll fires at zero and shifts the rest`() {
        assertTrue(due(0, preroll = true))
        assertEquals(0, AdSchedule.indexAt(0, interval, preroll = true))
        assertEquals(1, AdSchedule.indexAt(interval, interval, preroll = true))

        val played = AdSchedule.consume(0, interval, preroll = true, lastPlayed = -1)
        assertFalse(due(1_000, lastPlayed = played, preroll = true))
        assertTrue(due(interval, lastPlayed = played, preroll = true))
    }

    @Test
    fun `nothing fires over the closing credits`() {
        // The last break before the end is fine.
        assertTrue(AdSchedule.isDue(episode - 60_000, episode, interval, false, -1))

        // Inside the tail it is not: the viewer has already reached for the
        // next episode and the impression is spent on nobody.
        assertFalse(AdSchedule.isDue(episode - 29_000, episode, interval, false, -1))
        assertFalse(AdSchedule.isDue(episode, episode, interval, false, -1))
    }

    @Test
    fun `an unknown duration holds everything except the pre-roll`() {
        // Before the first frame the length is zero, and a break placed
        // against a length of zero is a break placed against a guess.
        assertFalse(AdSchedule.isDue(interval, 0, interval, preroll = false, lastPlayed = -1))
        assertTrue(AdSchedule.isDue(0, 0, interval, preroll = true, lastPlayed = -1))
    }

    @Test
    fun `nonsense settings interrupt nobody`() {
        assertEquals(-1, AdSchedule.indexAt(10_000, 0, preroll = false))
        assertEquals(-1, AdSchedule.indexAt(-5, interval, preroll = true))
        assertFalse(AdSchedule.isDue(10_000, episode, 0, false, -1))
    }
}
