<?php
/**
 * One-time pairing codes for moving a site.
 *
 * A new installation has no credentials on the old one, so the endpoint it
 * calls to collect the data cannot require a login. What stands in for one is a
 * short-lived, single-use code an administrator generates on the old site and
 * types into the new one. That code is the only thing between a stranger and
 * the library's metadata, so it is treated accordingly: high entropy, stored
 * only as a hash, compared in constant time, and expired quickly.
 *
 * Free of any WordPress dependency, so the rules are tested directly.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Issues and checks migration pairing codes.
 */
final class MigrationCode {

	/**
	 * Alphabet for the printed code.
	 *
	 * Crockford-style: no `I`, `L`, `O` or `U`, so a code read off one screen
	 * and typed into another cannot be transcribed wrong.
	 */
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Characters of entropy, printed in four groups of five.
	 *
	 * Twenty characters of a 32-symbol alphabet is 100 bits — far beyond
	 * guessing, which matters because the endpoint it guards is unauthenticated
	 * by necessity.
	 */
	private const LENGTH = 20;

	/**
	 * How long a code stays valid.
	 *
	 * Long enough to install a plugin on another host, short enough that a code
	 * left on a screen is not a standing invitation.
	 */
	public const TTL_SECONDS = 1800;

	/**
	 * Generate a code, formatted for reading aloud.
	 */
	public static function generate(): string {
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$code     = '';
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= $alphabet[ random_int( 0, $max ) ];
		}
		return implode( '-', str_split( $code, 5 ) );
	}

	/**
	 * Reduce a typed code to its canonical form.
	 *
	 * Groups, spaces and case are presentation; someone retyping a code should
	 * not have to reproduce them exactly.
	 *
	 * @param string $code Code as entered.
	 */
	public static function normalise( string $code ): string {
		$upper = strtoupper( trim( $code ) );
		$clean = preg_replace( '/[^0-9A-Z]/', '', $upper ) ?? '';

		// The substitutions a human reliably makes when copying, which is why
		// those letters are not in the alphabet in the first place.
		return strtr( $clean, array( 'I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V' ) );
	}

	/**
	 * Hash a code for storage.
	 *
	 * The code itself is never persisted: a database read must not yield a
	 * working credential.
	 *
	 * @param string $code   Code, in any form.
	 * @param string $secret Installation-specific key material.
	 */
	public static function hash( string $code, string $secret ): string {
		return hash_hmac( 'sha256', self::normalise( $code ), $secret );
	}

	/**
	 * Check a submitted code against a stored hash.
	 *
	 * @param string   $submitted Code as typed on the new site.
	 * @param string   $stored    Hash produced by {@see self::hash()}.
	 * @param string   $secret    The same key material used to hash.
	 * @param int      $issued_at When the code was issued, as a Unix time.
	 * @param int|null $now       Current time; injected by tests.
	 */
	public static function verify( string $submitted, string $stored, string $secret, int $issued_at, ?int $now = null ): bool {
		$now ??= time();

		if ( '' === $stored || '' === $submitted ) {
			return false;
		}
		if ( $now < $issued_at || $now - $issued_at > self::TTL_SECONDS ) {
			return false;
		}

		// Constant time: a comparison that returns early leaks the code one
		// character at a time to anyone who can measure the response.
		return hash_equals( $stored, self::hash( $submitted, $secret ) );
	}

	/**
	 * Seconds left before a code expires.
	 *
	 * @param int      $issued_at Issue time.
	 * @param int|null $now       Current time; injected by tests.
	 */
	public static function remaining( int $issued_at, ?int $now = null ): int {
		$now ??= time();
		return (int) max( 0, self::TTL_SECONDS - ( $now - $issued_at ) );
	}
}
