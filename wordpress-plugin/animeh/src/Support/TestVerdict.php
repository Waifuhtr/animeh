<?php
/**
 * Turns a run's checks and measurements into one verdict.
 *
 * Free of any WordPress dependency so the thresholds can be unit tested.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Summarises a player test run.
 */
final class TestVerdict {

	public const OK      = 'ok';
	public const WARN    = 'warn';
	public const BAD     = 'bad';
	public const PENDING = 'pending';

	/**
	 * Startup beyond this is worth flagging, in milliseconds.
	 *
	 * Four seconds is roughly where viewers start abandoning a stream, so it is
	 * the number the buffering policy is tuned against.
	 */
	public const SLOW_STARTUP_MS = 4000;

	/**
	 * More rebuffers than this in a run means the policy is not holding.
	 */
	public const REBUFFER_LIMIT = 2;

	/**
	 * Reduce a set of check states to the worst one.
	 *
	 * A failure outranks everything; a still-pending check outranks a warning,
	 * because an unfinished run has not earned a pass.
	 *
	 * @param string[] $states Check states.
	 */
	public static function from_states( array $states ): string {
		if ( in_array( self::BAD, $states, true ) ) {
			return self::BAD;
		}
		if ( in_array( self::PENDING, $states, true ) ) {
			return self::PENDING;
		}
		if ( in_array( self::WARN, $states, true ) ) {
			return self::WARN;
		}
		return array() === $states ? self::PENDING : self::OK;
	}

	/**
	 * Observations worth surfacing about a run's measurements.
	 *
	 * Returned as machine-readable codes; the panel renders the wording.
	 *
	 * @param array<string, mixed> $metrics Metrics matching the player's PlaybackStats.
	 * @return string[]
	 */
	public static function notes( array $metrics ): array {
		$notes = array();

		$startup = $metrics['startupTimeMs'] ?? null;
		if ( is_numeric( $startup ) && (int) $startup > self::SLOW_STARTUP_MS ) {
			$notes[] = 'slow_startup';
		}

		$rebuffers = $metrics['rebufferCount'] ?? 0;
		if ( is_numeric( $rebuffers ) && (int) $rebuffers > self::REBUFFER_LIMIT ) {
			$notes[] = 'frequent_rebuffering';
		}

		$dropped = $metrics['droppedFrames'] ?? 0;
		$switches = $metrics['qualitySwitches'] ?? 0;
		// Dropped frames only mean something once there were frames to drop;
		// a handful during startup is normal.
		if ( is_numeric( $dropped ) && (int) $dropped > 60 ) {
			$notes[] = 'dropped_frames';
		}
		if ( is_numeric( $switches ) && (int) $switches > 6 ) {
			$notes[] = 'unstable_quality';
		}

		$errors = $metrics['errors'] ?? array();
		if ( is_array( $errors ) && count( $errors ) > 0 ) {
			$notes[] = 'errors_logged';
		}

		return $notes;
	}

	/**
	 * Combine check states and measurements into the stored verdict.
	 *
	 * @param string[]             $states  Check states.
	 * @param array<string, mixed> $metrics Metrics matching the player's PlaybackStats.
	 */
	public static function decide( array $states, array $metrics ): string {
		$verdict = self::from_states( $states );
		if ( self::OK !== $verdict ) {
			return $verdict;
		}
		// Every check passed, but the numbers can still say the run was rough.
		return array() === self::notes( $metrics ) ? self::OK : self::WARN;
	}
}
