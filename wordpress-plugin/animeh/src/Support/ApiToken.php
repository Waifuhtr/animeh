<?php
/**
 * Bearer tokens for the mobile app.
 *
 * WordPress authenticates the browser with cookies and a nonce, which a native
 * app has no way to hold. What it can hold is an opaque bearer token, and this
 * class is the whole of that scheme: how one is minted, how it is stored, and
 * how a presented string is turned back into a user id.
 *
 * The token is opaque rather than a JWT on purpose. A JWT's selling point is
 * that the server need not look it up — but revoking a stolen session then
 * requires a blocklist that has to be looked up anyway, so the saving is
 * imaginary and the cost is a signature scheme to get wrong. An opaque random
 * string checked against a table revokes instantly and has no algorithm field
 * for anyone to set to `none`.
 *
 * Free of any WordPress dependency, so the rules are tested directly.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Mints and checks API tokens.
 */
final class ApiToken {

	/**
	 * Bytes of randomness in a token.
	 *
	 * 32 bytes is 256 bits. The token is the entire credential, so it is sized
	 * as one rather than as an identifier.
	 */
	private const ENTROPY_BYTES = 32;

	/**
	 * How long an access token lives.
	 *
	 * Short enough that a leaked token expires on its own; long enough that an
	 * app is not refreshing during a single episode.
	 */
	public const ACCESS_TTL = 3600;

	/**
	 * How long a refresh token lives.
	 *
	 * Thirty days: the point of "stay logged in" is that the user does not
	 * retype a password every week.
	 */
	public const REFRESH_TTL = 2592000;

	/**
	 * Prefix on the printed token, so a leaked string is recognisable.
	 *
	 * Secret scanners match on prefixes; a token that looks like random base64
	 * is one nobody can grep for.
	 */
	public const PREFIX = 'ahp_';

	/**
	 * Generate a token string.
	 *
	 * URL-safe base64 without padding: the token travels in an Authorization
	 * header, but also in the odd query string, and `+` and `/` do not survive
	 * that intact.
	 */
	public static function generate(): string {
		$raw = random_bytes( self::ENTROPY_BYTES );
		return self::PREFIX . rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Group id tying an access token to the refresh token that minted it.
	 *
	 * Revoking a device means revoking the family, not hunting for rows.
	 */
	public static function family(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Hash a token for storage.
	 *
	 * Plain sha256, not password_hash: the input already has 256 bits of
	 * entropy, so there is nothing for a slow hash to protect against, and a
	 * fast hash is what makes an indexed unique lookup possible.
	 *
	 * @param string $token Token string.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Pull the token out of an Authorization header.
	 *
	 * Returns an empty string for anything that is not a bearer token, so a
	 * caller never has to distinguish "absent" from "malformed".
	 *
	 * @param string $header Raw header value.
	 */
	public static function from_header( string $header ): string {
		$header = trim( $header );
		if ( '' === $header ) {
			return '';
		}

		// The scheme is case-insensitive per RFC 7235, and clients differ.
		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return '';
		}

		$token = trim( $matches[1] );

		return self::looks_valid( $token ) ? $token : '';
	}

	/**
	 * Whether a string is shaped like one of our tokens.
	 *
	 * Checked before the database is touched: a login page under attack should
	 * not turn every garbage string into a query.
	 *
	 * @param string $token Candidate.
	 */
	public static function looks_valid( string $token ): bool {
		if ( ! str_starts_with( $token, self::PREFIX ) ) {
			return false;
		}

		$body = substr( $token, strlen( self::PREFIX ) );

		// 32 bytes base64url with no padding is exactly 43 characters.
		return 43 === strlen( $body ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $body );
	}

	/**
	 * Whether a stored expiry has passed.
	 *
	 * @param int      $expires_at Expiry as a Unix time.
	 * @param int|null $now        Current time; injected by tests.
	 */
	public static function is_expired( int $expires_at, ?int $now = null ): bool {
		return ( $now ?? time() ) >= $expires_at;
	}

	/**
	 * Mask a token for a log line or an admin listing.
	 *
	 * @param string $token Token string.
	 */
	public static function mask( string $token ): string {
		if ( strlen( $token ) < 12 ) {
			return '••••';
		}
		return substr( $token, 0, strlen( self::PREFIX ) + 4 ) . '…' . substr( $token, -4 );
	}
}
