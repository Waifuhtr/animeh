<?php
/**
 * When an episode counts as watched.
 *
 * Kept free of WordPress so the rule can be tested directly: it is the one
 * piece of this feature a user will notice being wrong, in either direction.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * The completion rule, and the arithmetic behind it.
 */
final class WatchProgress {

	/**
	 * How much of an episode has to be seen, as a percentage.
	 *
	 * Seventy percent puts a 24-minute episode at about 17 minutes and a
	 * 10-minute one at 7 — the two cases the brief names. Credits run long
	 * enough that demanding much more would leave episodes people finished
	 * sitting in "continue watching".
	 *
	 * Held as a whole number rather than 0.7 so the threshold can be worked out
	 * without floating point: 1440 * 0.7 is 1007.9999…, which floors to a
	 * second earlier than intended.
	 */
	public const COMPLETE_PERCENT = 70;

	/**
	 * Seconds of an episode that have to be played for it to count.
	 *
	 * @param int $duration Episode length in seconds.
	 * @return int Zero when the length is unknown, in which case nothing counts.
	 */
	public static function threshold( int $duration ): int {
		if ( $duration <= 0 ) {
			return 0;
		}

		return intdiv( $duration * self::COMPLETE_PERCENT, 100 );
	}

	/**
	 * Whether an episode has been watched.
	 *
	 * Deliberately takes seconds played rather than the playhead. Skipping to
	 * the end leaves a position of nearly the full duration having watched
	 * none of it, and counting that is what the brief asks to avoid.
	 *
	 * @param int $watched  Seconds genuinely played.
	 * @param int $duration Episode length in seconds.
	 */
	public static function is_complete( int $watched, int $duration ): bool {
		$threshold = self::threshold( $duration );

		if ( 0 === $threshold ) {
			return false;
		}

		return $watched >= $threshold;
	}

	/**
	 * Add a played stretch to a running total.
	 *
	 * The player reports where it was and where it is. A step forward larger
	 * than one report interval is a seek rather than playback, and credits
	 * nothing; a step backwards is a rewind, which also credits nothing
	 * because that stretch was already counted the first time through.
	 *
	 * @param int $watched  Total so far.
	 * @param int $from     Position at the previous report.
	 * @param int $to       Position now.
	 * @param int $interval Seconds between reports, plus a little slack.
	 * @return int The new total.
	 */
	public static function accumulate( int $watched, int $from, int $to, int $interval ): int {
		$step = $to - $from;

		if ( $step <= 0 || $step > max( 1, $interval ) ) {
			return max( 0, $watched );
		}

		return max( 0, $watched ) + $step;
	}
}
