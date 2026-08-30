<?php
/**
 * A fixed-window rate limiter's arithmetic.
 *
 * Login and registration are the endpoints where an unlimited request rate is
 * the vulnerability: a password that would take a year to guess at one attempt
 * per second takes an afternoon at ten thousand. The counting rules live here,
 * free of WordPress, so they can be tested without a clock or a cache.
 *
 * A fixed window rather than a sliding one: the worst case is 2x the limit
 * across a window boundary, which for "how many passwords may you try" is not
 * a meaningful weakening, and it costs one integer instead of a list of
 * timestamps per key.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Decides whether an attempt is allowed and what to tell the caller.
 */
final class RateLimit {

	/**
	 * The window's start, aligned so every caller agrees on it.
	 *
	 * @param int $window_seconds Window length.
	 * @param int $now            Current Unix time.
	 */
	public static function window_start( int $window_seconds, int $now ): int {
		$window_seconds = max( 1, $window_seconds );
		return (int) ( floor( $now / $window_seconds ) * $window_seconds );
	}

	/**
	 * The cache key for one actor in one window.
	 *
	 * The window start is part of the key, which is what makes expiry
	 * automatic: a new window is simply a different key.
	 *
	 * @param string $bucket         What is being limited, e.g. "login".
	 * @param string $actor          Who — an IP, a username, or both.
	 * @param int    $window_seconds Window length.
	 * @param int    $now            Current Unix time.
	 */
	public static function key( string $bucket, string $actor, int $window_seconds, int $now ): string {
		// Hashed so an IP address is not sitting in plain text in the options
		// table, and so the key length is bounded whatever the actor string is.
		return 'animeh_rl_' . $bucket . '_' . substr( hash( 'sha256', $actor ), 0, 20 ) . '_' . self::window_start( $window_seconds, $now );
	}

	/**
	 * Whether an attempt is within the limit.
	 *
	 * @param int $used  Attempts already made in this window.
	 * @param int $limit Attempts permitted.
	 */
	public static function allows( int $used, int $limit ): bool {
		return $used < max( 1, $limit );
	}

	/**
	 * Seconds until the window resets, for a Retry-After header.
	 *
	 * @param int $window_seconds Window length.
	 * @param int $now            Current Unix time.
	 */
	public static function retry_after( int $window_seconds, int $now ): int {
		$window_seconds = max( 1, $window_seconds );
		$elapsed        = $now - self::window_start( $window_seconds, $now );

		// Never zero: a Retry-After of 0 invites an immediate retry, which is
		// the thing being prevented.
		return max( 1, $window_seconds - $elapsed );
	}
}
