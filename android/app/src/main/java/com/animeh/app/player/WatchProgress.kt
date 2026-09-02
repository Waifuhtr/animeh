package com.animeh.app.player

/**
 * How much of an episode was actually watched.
 *
 * The playhead cannot answer this. Dragging the scrubber to the credits leaves
 * a position of nearly the full duration having seen none of it, and treating
 * that as finished is what makes a watch total meaningless. So time is counted
 * a tick at a time, and only stretches the player genuinely played through.
 *
 * The same rule lives server-side in `Support/WatchProgress.php`, which has the
 * final say — this copy is what lets the player show the right thing before the
 * next report lands, and it is kept deliberately small so the two cannot drift
 * far.
 */
object WatchProgress {

    /** Seventy percent, matching the server. */
    const val COMPLETE_PERCENT = 70

    /**
     * The largest forward step still treated as playback.
     *
     * A tick is 500ms, but the loop can be late — a slow frame, the app
     * backgrounded briefly — so the allowance is generous. Anything past it is
     * a seek, and seeks credit nothing.
     */
    const val MAX_STEP_MS = 2_000L

    /** Seconds of an episode that have to be played for it to count. */
    fun thresholdSeconds(durationSeconds: Int): Int =
        if (durationSeconds <= 0) 0 else durationSeconds * COMPLETE_PERCENT / 100

    fun isComplete(watchedSeconds: Int, durationSeconds: Int): Boolean {
        val threshold = thresholdSeconds(durationSeconds)
        return threshold > 0 && watchedSeconds >= threshold
    }

    /**
     * Add the stretch between two ticks to a running total.
     *
     * A step backwards is a rewind: that ground was credited the first time
     * through and is not counted twice. A step too large to be playback is a
     * seek and credits nothing.
     *
     * @return the new total in milliseconds.
     */
    fun accumulateMs(watchedMs: Long, fromMs: Long, toMs: Long): Long {
        val step = toMs - fromMs

        if (step <= 0L || step > MAX_STEP_MS) return watchedMs.coerceAtLeast(0L)

        return watchedMs.coerceAtLeast(0L) + step
    }
}
