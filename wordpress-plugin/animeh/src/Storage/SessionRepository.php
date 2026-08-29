<?php
/**
 * Test run storage.
 *
 * A run records what was tested, what the player measured, and what the panel
 * showed — enough to compare two runs later and say whether a change to the
 * buffering policy actually helped.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Reads and writes player test runs.
 */
final class SessionRepository {

	/**
	 * Longest event log kept per run.
	 *
	 * A run behind a flaky connection can otherwise accumulate thousands of
	 * lines, none of which add anything after the first few dozen.
	 */
	private const MAX_EVENTS = 500;

	/**
	 * Start a run.
	 *
	 * @param array<string, mixed> $data    Source description.
	 * @param int                  $user_id Owner.
	 * @return int|null Row id, or null when the insert failed.
	 */
	public static function create( array $data, int $user_id ): ?int {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::sessions_table(),
			array(
				'created_by'    => $user_id,
				'created_at'    => $now,
				'updated_at'    => $now,
				'source_url'    => (string) ( $data['source_url'] ?? '' ),
				'source_type'   => (string) ( $data['source_type'] ?? '' ),
				'subtitle_url'  => (string) ( $data['subtitle_url'] ?? '' ),
				'throttle_kbps' => (int) ( $data['throttle_kbps'] ?? 0 ),
				'verdict'       => 'pending',
				'metrics'       => '{}',
				'font_report'   => '{}',
				'events'        => '[]',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : null;
	}

	/**
	 * Append measurements and log lines to a run.
	 *
	 * @param int                  $id      Run id.
	 * @param array<string, mixed> $updates Fields to merge.
	 */
	public static function update( int $id, array $updates ): bool {
		$existing = self::find( $id );
		if ( null === $existing ) {
			return false;
		}

		$fields  = array( 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s' );

		if ( isset( $updates['verdict'] ) ) {
			$fields['verdict'] = (string) $updates['verdict'];
			$formats[]         = '%s';
		}
		if ( isset( $updates['metrics'] ) && is_array( $updates['metrics'] ) ) {
			$fields['metrics'] = (string) wp_json_encode( $updates['metrics'] );
			$formats[]         = '%s';
		}
		if ( isset( $updates['font_report'] ) && is_array( $updates['font_report'] ) ) {
			$fields['font_report'] = (string) wp_json_encode( $updates['font_report'] );
			$formats[]             = '%s';
		}
		if ( isset( $updates['events'] ) && is_array( $updates['events'] ) ) {
			// Events accumulate across calls; the tail is what matters, so the
			// oldest lines are dropped once the cap is reached.
			$events = array_merge( $existing['events'], array_values( $updates['events'] ) );
			if ( count( $events ) > self::MAX_EVENTS ) {
				$events = array_slice( $events, -self::MAX_EVENTS );
			}
			$fields['events'] = (string) wp_json_encode( $events );
			$formats[]        = '%s';
		}

		global $wpdb;
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::sessions_table(),
			$fields,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		// `update` returns 0 when the row matched but nothing changed, which is
		// a success as far as the caller is concerned.
		return false !== $updated;
	}

	/**
	 * One run.
	 *
	 * @param int $id Run id.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . Schema::sessions_table() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::sessions_table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( self::class, 'shape' ), $rows ) : array();
	}

	/**
	 * Total number of stored runs.
	 */
	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::sessions_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete a run.
	 *
	 * @param int $id Run id.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::sessions_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Shape a database row for the API.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'created_by'    => (int) $row['created_by'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
			'source_url'    => (string) $row['source_url'],
			'source_type'   => (string) $row['source_type'],
			'subtitle_url'  => (string) $row['subtitle_url'],
			'throttle_kbps' => (int) $row['throttle_kbps'],
			'verdict'       => (string) $row['verdict'],
			'metrics'       => self::decode( (string) $row['metrics'], array() ),
			'font_report'   => self::decode( (string) $row['font_report'], array() ),
			'events'        => self::decode( (string) $row['events'], array() ),
		);
	}

	/**
	 * Decode a stored JSON column, falling back when it is unreadable.
	 *
	 * @param string $json     Stored value.
	 * @param mixed  $fallback Value to use when decoding fails.
	 * @return mixed
	 */
	private static function decode( string $json, $fallback ) {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : $fallback;
	}
}
