<?php
/**
 * Where issued tokens live.
 *
 * A row per token, holding only its hash. Two consequences that are the point
 * of the design: a database dump does not yield working sessions, and signing
 * out actually signs out — the row goes away, and the next request with that
 * token fails at the lookup rather than at a signature check that cannot know
 * the session was ended.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\ApiToken;

/**
 * Issues, verifies and revokes API tokens.
 */
final class TokenRepository {

	/**
	 * Mint an access/refresh pair for one device.
	 *
	 * @param int    $user_id User.
	 * @param string $device  Device label, for the sessions list.
	 * @return array{access: string, refresh: string, expires_in: int, refresh_expires_in: int}
	 */
	public function issue_pair( int $user_id, string $device = '' ): array {
		$family  = ApiToken::family();
		$access  = ApiToken::generate();
		$refresh = ApiToken::generate();

		$this->store( $user_id, $access, 'access', $family, $device, ApiToken::ACCESS_TTL );
		$this->store( $user_id, $refresh, 'refresh', $family, $device, ApiToken::REFRESH_TTL );

		return array(
			'access'             => $access,
			'refresh'            => $refresh,
			'expires_in'         => ApiToken::ACCESS_TTL,
			'refresh_expires_in' => ApiToken::REFRESH_TTL,
		);
	}

	/**
	 * Resolve a presented token to a user id, or 0.
	 *
	 * @param string $token Token as presented.
	 * @param string $kind  Which kind it must be.
	 */
	public function user_for( string $token, string $kind = 'access' ): int {
		global $wpdb;

		// Shape first: an endpoint under attack should not turn every garbage
		// string into an indexed lookup.
		if ( ! ApiToken::looks_valid( $token ) ) {
			return 0;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT user_id, expires_at, revoked_at FROM ' . CatalogSchema::tokens() . ' WHERE token_hash = %s AND kind = %s',
				ApiToken::hash( $token ),
				$kind
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || null !== $row['revoked_at'] ) {
			return 0;
		}

		$expires = strtotime( (string) $row['expires_at'] . ' UTC' );
		if ( false === $expires || ApiToken::is_expired( $expires ) ) {
			return 0;
		}

		return (int) $row['user_id'];
	}

	/**
	 * Exchange a refresh token for a fresh pair.
	 *
	 * The old family is revoked in full, so a refresh token is single-use: if
	 * one is replayed after a legitimate refresh, it is already dead, and that
	 * failure is the signal that it leaked.
	 *
	 * @param string $refresh Refresh token as presented.
	 * @param string $device  Device label.
	 * @return array{access: string, refresh: string, expires_in: int, refresh_expires_in: int}|null
	 */
	public function rotate( string $refresh, string $device = '' ): ?array {
		global $wpdb;

		if ( ! ApiToken::looks_valid( $refresh ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT user_id, family, expires_at, revoked_at, device FROM ' . CatalogSchema::tokens() . " WHERE token_hash = %s AND kind = 'refresh'",
				ApiToken::hash( $refresh )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || null !== $row['revoked_at'] ) {
			return null;
		}

		$expires = strtotime( (string) $row['expires_at'] . ' UTC' );
		if ( false === $expires || ApiToken::is_expired( $expires ) ) {
			return null;
		}

		$this->revoke_family( (string) $row['family'] );

		return $this->issue_pair(
			(int) $row['user_id'],
			'' !== $device ? $device : (string) $row['device']
		);
	}

	/**
	 * Sign out the device a token belongs to.
	 *
	 * The whole family goes, not just the presented token: signing out with an
	 * access token must also kill the refresh token, or the session comes back.
	 *
	 * @param string $token Token as presented.
	 */
	public function revoke_session( string $token ): bool {
		global $wpdb;

		if ( ! ApiToken::looks_valid( $token ) ) {
			return false;
		}

		$family = $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT family FROM ' . CatalogSchema::tokens() . ' WHERE token_hash = %s',
				ApiToken::hash( $token )
			)
		);

		if ( null === $family ) {
			return false;
		}

		$this->revoke_family( (string) $family );

		return true;
	}

	/**
	 * Sign out everywhere — used on a password change.
	 *
	 * @param int $user_id User.
	 */
	public function revoke_all( int $user_id ): void {
		global $wpdb;

		$table = CatalogSchema::tokens();

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL",
				current_time( 'mysql', true ),
				$user_id
			)
		);
	}

	/**
	 * Devices a user is signed in on.
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public function sessions( int $user_id ): array {
		global $wpdb;

		$table = CatalogSchema::tokens();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT family, device, MAX(issued_at) AS issued_at, MAX(expires_at) AS expires_at
				 FROM {$table}
				 WHERE user_id = %d AND kind = 'refresh' AND revoked_at IS NULL
				 GROUP BY family, device
				 ORDER BY issued_at DESC",
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete rows that expired long enough ago to be of no interest.
	 *
	 * Run from cron: without it the table grows forever, and an access token
	 * that expired an hour into a session is not evidence of anything a week
	 * later.
	 */
	public function prune(): int {
		global $wpdb;

		$table  = CatalogSchema::tokens();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", $cutoff )
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Write one token row.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Token string.
	 * @param string $kind    access or refresh.
	 * @param string $family  Session family.
	 * @param string $device  Device label.
	 * @param int    $ttl     Lifetime in seconds.
	 */
	private function store( int $user_id, string $token, string $kind, string $family, string $device, int $ttl ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::tokens(),
			array(
				'user_id'    => $user_id,
				'token_hash' => ApiToken::hash( $token ),
				'kind'       => $kind,
				'family'     => $family,
				// Trimmed rather than rejected: a device label is decoration on
				// a sessions list, not something to fail a login over.
				'device'     => mb_substr( $device, 0, 190 ),
				'issued_at'  => current_time( 'mysql', true ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			)
		);
	}

	/**
	 * Revoke every token in a session family.
	 *
	 * @param string $family Family id.
	 */
	private function revoke_family( string $family ): void {
		global $wpdb;

		if ( '' === $family ) {
			return;
		}

		$table = CatalogSchema::tokens();

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE family = %s AND revoked_at IS NULL",
				current_time( 'mysql', true ),
				$family
			)
		);
	}
}
