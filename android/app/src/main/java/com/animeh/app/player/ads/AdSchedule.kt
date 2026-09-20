package com.animeh.app.player.ads

/**
 * When an ad break is due.
 *
 * Pure arithmetic on the playback position, kept apart from everything that
 * plays anything, because the cadence is the part that is easy to get subtly
 * and expensively wrong: a rule that fires twice at the same moment shows two
 * ads back to back, and a rule that fires on every position update shows an
 * ad every frame. Neither is visible in a code review and both are obvious in
 * one test.
 *
 * Breaks sit at fixed positions rather than being counted from the last one.
 * Counting drifts — an ad that took thirty seconds to load pushes the next
 * one thirty seconds late, and by the fifth break the schedule the operator
 * set is no longer the schedule anyone is on.
 */
object AdSchedule {

    /**
     * How close to the end an ad stops being worth showing, in milliseconds.
     *
     * Thirty seconds. A break that lands in the closing credits interrupts
     * nothing anybody is watching: the viewer has already reached for the
     * next episode, so the impression is spent on a screen nobody is looking
     * at and the interruption is remembered anyway.
     */
    const val TAIL_MS = 30_000L

    /**
     * The index of the break that should have played by [positionMs], or -1.
     *
     * With a pre-roll, break 0 sits at zero and break *i* at `i × interval`.
     * Without one, break 0 sits at the first interval and break *i* at
     * `(i + 1) × interval`.
     */
    fun indexAt(positionMs: Long, intervalMs: Long, preroll: Boolean): Int {
        if (intervalMs <= 0 || positionMs < 0) return -1

        val elapsed = if (preroll) positionMs else positionMs - intervalMs

        if (elapsed < 0) return -1

        return (elapsed / intervalMs).toInt()
    }

    /**
     * Whether to interrupt now.
     *
     * [lastPlayed] is the highest index already shown, and -1 before the
     * first. Comparing against it rather than against a timestamp is what
     * makes seeking behave: jumping backwards re-passes breaks that have
     * already been shown and shows none of them again, and jumping forwards
     * over several shows *one*, because the index moves in a single step. A
     * viewer who skips ten minutes has still only interrupted once, which is
     * the difference between an app with ads and an app nobody keeps.
     *
     * @param durationMs total length, or zero when it is not known yet.
     */
    fun isDue(
        positionMs: Long,
        durationMs: Long,
        intervalMs: Long,
        preroll: Boolean,
        lastPlayed: Int,
    ): Boolean {
        val index = indexAt(positionMs, intervalMs, preroll)

        if (index <= lastPlayed) return false

        // Nothing at all until the duration is known, unless this is the
        // pre-roll: a break placed against a length of zero is a break placed
        // against a guess.
        if (durationMs <= 0) return preroll && index == 0 && positionMs <= 0

        return durationMs - positionMs > TAIL_MS
    }

    /**
     * What [lastPlayed] becomes once a break at this position has been shown.
     *
     * Every index up to here, not just the one that fired. A viewer who
     * seeked past four breaks watched one ad and owes nothing for the three
     * they skipped over; leaving those unmarked would fire them one after
     * another as the position advanced.
     */
    fun consume(positionMs: Long, intervalMs: Long, preroll: Boolean, lastPlayed: Int): Int =
        maxOf(lastPlayed, indexAt(positionMs, intervalMs, preroll))
}
