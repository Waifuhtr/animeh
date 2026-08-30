<?php
/**
 * The application log and announcements.
 *
 * §25 asks for a machine-readable error vocabulary, and §22 for a log screen
 * in the admin panel. Both want the same thing: a structured row rather than a
 * line of prose, so the app can group by code and the operator can filter.
 *
 * Nothing written here may contain a credential. The helpers take a context
 * array, and the redaction below is what keeps a bearer token or an
 * application key from ending up in a table the admin panel renders.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Structured logging and announcement storage.
 */
final class LogRepository {

	/**
	 * How long a log row is kept.
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * Keys whose values are replaced before anything is written.
	 *
	 * Matched as substrings, so `refresh_token` and `X-Api-Key` are both hit.
	 *
	 * @var string[]
	 */
	private const SECRET_KEYS = array( 'token', 'secret', 'password', 'pass', 'key', 'authorization', 'cookie', 'signature' );

	/**
	 * Record an event.
	 *
	 * @param string               $level   debug, info, warning or error.
	 * @param string               $code    Machine-readable code from §25.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Extra detail.
	 * @param int                  $user_id Who it concerns, 0 for nobody.
	 */
	public function record( string $level, string $code, string $message, array $context = array(), int $user_id = 0 ): void {
		global $wpdb;

		$encoded = wp_json_encode( self::redact( $context ) );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::logs(),
			array(
				'level'      => $level,
				'code'       => $code,
				// Truncated rather than rejected: losing the tail of a message
				// is better than losing the record that something failed.
				'message'    => mb_substr( $message, 0, 1000 ),
				'context'    => false === $encoded ? '{}' : $encoded,
				'user_id'    => $user_id,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Shorthand for the common case.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Summary.
	 * @param array<string, mixed> $context Detail.
	 * @param int                  $user_id User.
	 */
	public function error( string $code, string $message, array $context = array(), int $user_id = 0 ): void {
		$this->record( 'error', $code, $message, $context, $user_id );
	}

	/**
	 * A page of log rows.
	 *
	 * @param array<string, mixed> $args level, code, search, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function entries( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		$level = trim( (string) ( $args['level'] ?? '' ) );
		if ( '' !== $level ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		$code = trim( (string) ( $args['code'] ?? '' ) );
		if ( '' !== $code ) {
			$where[]  = 'code = %s';
			$params[] = $code;
		}

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$table    = CatalogSchema::logs();
		$clause   = 'WHERE ' . implode( ' AND ', $where );
		$per_page = max( 1, min( (int) ( $args['per_page'] ?? 50 ), 200 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$clause}", $params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table} {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Error counts by code over a window, for the dashboard.
	 *
	 * @param int $days How far back.
	 * @return array<int, array{code: string, count: int}>
	 */
	public function error_summary( int $days = 7 ): array {
		global $wpdb;

		$table = CatalogSchema::logs();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT code, COUNT(*) AS count FROM {$table}
				 WHERE level = 'error' AND created_at >= %s
				 GROUP BY code ORDER BY count DESC LIMIT 20",
				$since
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'code'  => (string) $row['code'],
				'count' => (int) $row['count'],
			);
		}

		return $out;
	}

	/**
	 * Delete rows past the retention window. Run from cron.
	 */
	public function prune(): int {
		global $wpdb;

		$table  = CatalogSchema::logs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Empty the log entirely.
	 */
	public function clear(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . CatalogSchema::logs() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Announcements a user should currently see.
	 *
	 * @param bool $is_admin Whether the reader is an administrator.
	 * @return array<int, array<string, mixed>>
	 */
	public function announcements( bool $is_admin = false ): array {
		global $wpdb;

		$table = CatalogSchema::announcements();
		$now   = current_time( 'mysql', true );

		// An admin-only announcement is not hidden from admins, and an expired
		// one is hidden from everyone.
		$audience = $is_admin ? "('all','admin')" : "('all')";

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table}
				 WHERE published = 1
				   AND audience IN {$audience}
				   AND starts_at <= %s
				   AND (ends_at IS NULL OR ends_at >= %s)
				 ORDER BY starts_at DESC LIMIT 20",
				$now,
				$now
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every announcement, for the admin list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_announcements(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . CatalogSchema::announcements() . ' ORDER BY id DESC LIMIT 200', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create or update an announcement.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @param int                  $id   Existing id, or 0 to insert.
	 */
	public function save_announcement( array $data, int $id = 0 ): int {
		global $wpdb;

		$row = array(
			'title'     => mb_substr( (string) ( $data['title'] ?? '' ), 0, 250 ),
			'body'      => (string) ( $data['body'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'audience'  => in_array( $data['audience'] ?? 'all', array( 'all', 'admin' ), true ) ? (string) $data['audience'] : 'all',
			'published' => ! empty( $data['published'] ) ? 1 : 0,
			'starts_at' => (string) ( $data['starts_at'] ?? current_time( 'mysql', true ) ),
			'ends_at'   => '' === (string) ( $data['ends_at'] ?? '' ) ? null : (string) $data['ends_at'],
		);

		if ( $id > 0 ) {
			$wpdb->update( CatalogSchema::announcements(), $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}

		$row['created_by'] = get_current_user_id();
		$row['created_at'] = current_time( 'mysql', true );

		$wpdb->insert( CatalogSchema::announcements(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove an announcement.
	 *
	 * @param int $id Announcement id.
	 */
	public function delete_announcement( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( CatalogSchema::announcements(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Replace anything that looks like a credential.
	 *
	 * Recursive, because the interesting one is always nested — a header bag
	 * inside a request dump inside the context.
	 *
	 * @param mixed $value Any context value.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$name = strtolower( (string) $key );

			$secret = false;
			foreach ( self::SECRET_KEYS as $needle ) {
				if ( str_contains( $name, $needle ) ) {
					$secret = true;
					break;
				}
			}

			$out[ $key ] = $secret ? '[redacted]' : self::redact( $item );
		}

		return $out;
	}
}
