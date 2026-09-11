<?php
/**
 * The points ledger.
 *
 * Balances are summed from rows, never stored. A stored balance is a number
 * that can disagree with the history behind it, and when it does there is no
 * way to tell which of the two is wrong. Summing is a little more work per
 * read and it cannot drift.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\Points;

/**
 * Reads and writes point movements.
 */
final class PointsRepository {

	/**
	 * Record a movement.
	 *
	 * Returns false when the unique `(user_id, award_key)` index refuses the
	 * row — which is not a failure but the whole point: it is how an episode
	 * pays once however many times it is reported finished.
	 *
	 * @param int         $user_id    Who.
	 * @param int         $delta      How much, negative to take away.
	 * @param string      $reason     One of [Points::REASONS].
	 * @param string|null $award_key  Idempotency key, null when repeatable.
	 * @param string      $note       Free text shown in the viewer's history.
	 * @param int         $granted_by Administrator, for a gift.
	 * @return bool Whether a row was written.
	 */
	public static function record( int $user_id, int $delta, string $reason, ?string $award_key = null, string $note = '', int $granted_by = 0 ): bool {
		global $wpdb;

		if ( $user_id <= 0 || 0 === $delta ) {
			return false;
		}

		// `insert()` reports a duplicate key as false, having already sent the
		// error to the log. Suppressing the log here keeps a debug install
		// from filling up with the messages this design produces on purpose.
		$previous = $wpdb->suppress_errors( true );

		$written = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::points(),
			array(
				'user_id'    => $user_id,
				'delta'      => $delta,
				'reason'     => $reason,
				'award_key'  => $award_key,
				'note'       => $note,
				'granted_by' => $granted_by,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		$wpdb->suppress_errors( $previous );

		return false !== $written;
	}

	/**
	 * Pay for a finished episode.
	 *
	 * @param int $user_id    Viewer.
	 * @param int $episode_id Episode.
	 * @return bool Whether this was the first time.
	 */
	public static function award_episode( int $user_id, int $episode_id, string $kind = 'anime' ): bool {
		if ( $episode_id <= 0 ) {
			return false;
		}

		$manga = 'manga' === $kind;

		return self::record(
			$user_id,
			Points::per_finish( $kind ),
			$manga ? Points::REASON_CHAPTER : Points::REASON_EPISODE,
			// The key stays the episode's either way: a row is paid for once,
			// and which shelf it is on does not make it two rows.
			Points::episode_key( $episode_id ),
			''
		);
	}

	/**
	 * What somebody has.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function balance( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT COALESCE(SUM(delta), 0) FROM ' . CatalogSchema::points() . ' WHERE user_id = %d',
				$user_id
			)
		);
	}

	/**
	 * What somebody has earned in total, ignoring what they have spent.
	 *
	 * Shown next to the balance because the two say different things: one is
	 * what you can spend, the other is what you have done.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function earned( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT COALESCE(SUM(delta), 0) FROM ' . CatalogSchema::points() . ' WHERE user_id = %d AND delta > 0',
				$user_id
			)
		);
	}

	/**
	 * Balances for a set of users, in one query.
	 *
	 * The leaderboard draws fifty rows; fifty separate sums would be fifty
	 * round trips for one screen.
	 *
	 * @param int[] $user_ids Users.
	 * @return array<int, int> User id to balance.
	 */
	public static function balances( array $user_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT user_id, COALESCE(SUM(delta), 0) AS balance
				 FROM ' . CatalogSchema::points() . "
				 WHERE user_id IN ({$placeholders})
				 GROUP BY user_id",
				...$ids
			),
			ARRAY_A
		);

		$balances = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$balances[ (int) $row['user_id'] ] = (int) $row['balance'];
		}

		return $balances;
	}

	/**
	 * A viewer's own history, newest first.
	 *
	 * @param int $user_id User.
	 * @param int $limit   How many.
	 * @param int $offset  Where from.
	 * @return array<int, array<string, mixed>>
	 */
	public static function history( int $user_id, int $limit = 30, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::points() . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
				$user_id,
				max( 1, min( 100, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Take points for something, but only if they are there.
	 *
	 * Wrapped in a transaction with the balance read `FOR UPDATE`, so two
	 * purchases started at the same moment cannot both see the same money.
	 * The unique key on `award_key` covers the commoner race — the same thing
	 * bought twice by a double tap — and the transaction covers the rarer one:
	 * two different things bought at once with only enough for one.
	 *
	 * @param int    $user_id   Buyer.
	 * @param int    $price     Cost, positive.
	 * @param string $reason    One of [Points::REASONS].
	 * @param string $award_key Idempotency key.
	 * @param string $note      Free text.
	 * @return string Empty on success; 'insufficient' or 'duplicate' otherwise.
	 */
	public static function spend( int $user_id, int $price, string $reason, string $award_key, string $note = '' ): string {
		global $wpdb;

		if ( $price <= 0 ) {
			// Free is not a purchase to record; the caller still gets its
			// ownership row.
			return '';
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$balance = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT COALESCE(SUM(delta), 0) FROM ' . CatalogSchema::points() . ' WHERE user_id = %d FOR UPDATE',
				$user_id
			)
		);

		if ( ! Points::affordable( $balance, $price ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 'insufficient';
		}

		if ( ! self::record( $user_id, -$price, $reason, $award_key, $note ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 'duplicate';
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return '';
	}

	/**
	 * Drop a deleted user's ledger.
	 *
	 * @param int $user_id User.
	 */
	public static function purge_user( int $user_id ): void {
		global $wpdb;

		$wpdb->delete( CatalogSchema::points(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
