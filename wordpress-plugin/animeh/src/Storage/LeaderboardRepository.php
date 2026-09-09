<?php
/**
 * The standings.
 *
 * Three boards over one table. Everything here is a GROUP BY across the whole
 * history table, which is the most expensive query in the plugin, so the
 * result is cached for a few minutes: a board is a thing people look at, not
 * a thing they refresh, and being four minutes out of date costs nobody
 * anything.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Ranked lists of viewers.
 */
final class LeaderboardRepository {

	/** Most distinct series watched. */
	public const METRIC_WORKS = 'works';

	/** Most seconds actually played. */
	public const METRIC_SECONDS = 'seconds';

	/** Most episodes finished. */
	public const METRIC_EPISODES = 'episodes';

	/**
	 * The three boards.
	 *
	 * @var string[]
	 */
	public const METRICS = array( self::METRIC_WORKS, self::METRIC_SECONDS, self::METRIC_EPISODES );

	/**
	 * How long a computed board is reused.
	 */
	private const CACHE_SECONDS = 300;

	/**
	 * How deep the board goes.
	 *
	 * Enough that a regular viewer can find themselves on it, short enough
	 * that the payload stays small and the query stays quick.
	 */
	public const MAX_ROWS = 50;

	/**
	 * Whether a metric is one of ours.
	 *
	 * @param string $metric Candidate.
	 * @return bool
	 */
	public static function valid( string $metric ): bool {
		return in_array( $metric, self::METRICS, true );
	}

	/**
	 * The SQL expression a metric ranks by.
	 *
	 * Never interpolated from user input: the caller has to pass a metric that
	 * [self::valid] accepted, and this switch is what turns it into SQL.
	 *
	 * @param string $metric Metric.
	 * @return string
	 */
	private static function expression( string $metric ): string {
		switch ( $metric ) {
			case self::METRIC_WORKS:
				return 'COUNT(DISTINCT work_id)';
			case self::METRIC_EPISODES:
				return 'SUM(completed)';
			default:
				return 'SUM(watched_seconds)';
		}
	}

	/**
	 * One board, ranked, ties broken by whoever got there first.
	 *
	 * @param string $metric One of [self::METRICS].
	 * @param int    $limit  How many rows.
	 * @return array<int, array{user_id: int, value: int, rank: int}>
	 */
	public static function board( string $metric, int $limit = self::MAX_ROWS ): array {
		global $wpdb;

		if ( ! self::valid( $metric ) ) {
			return array();
		}

		$limit = max( 1, min( self::MAX_ROWS, $limit ) );
		$key   = 'animeh_board_' . $metric . '_' . $limit;

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$history    = CatalogSchema::history();
		$expression = self::expression( $metric );

		// `MIN(updated_at)` breaks ties: two people on forty episodes are not
		// equal, and the one who got there first is ahead. Without it the
		// order is whatever the engine felt like, and the board reshuffles
		// between two refreshes that show the same numbers.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT user_id, {$expression} AS value, MIN(updated_at) AS first_seen
				 FROM {$history}
				 WHERE user_id > 0
				 GROUP BY user_id
				 HAVING value > 0
				 ORDER BY value DESC, first_seen ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$board = self::rank( is_array( $rows ) ? $rows : array() );

		set_transient( $key, $board, self::CACHE_SECONDS );

		return $board;
	}

	/**
	 * Where one person stands, whether or not they are on the visible board.
	 *
	 * Counts how many people are ahead rather than reading a position out of
	 * the board, so somebody in four hundredth place still gets a number.
	 *
	 * @param string $metric  Metric.
	 * @param int    $user_id User.
	 * @return array{rank: int, value: int, total: int}
	 */
	public static function standing( string $metric, int $user_id ): array {
		global $wpdb;

		$empty = array(
			'rank'  => 0,
			'value' => 0,
			'total' => 0,
		);

		if ( ! self::valid( $metric ) || $user_id <= 0 ) {
			return $empty;
		}

		$history    = CatalogSchema::history();
		$expression = self::expression( $metric );

		$value = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT {$expression} FROM {$history} WHERE user_id = %d",
				$user_id
			)
		);

		$totals = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN value > %d THEN 1 ELSE 0 END) AS ahead
				 FROM (
					SELECT user_id, {$expression} AS value
					FROM {$history}
					WHERE user_id > 0
					GROUP BY user_id
					HAVING value > 0
				 ) standings",
				$value
			),
			ARRAY_A
		);

		if ( ! is_array( $totals ) || $value <= 0 ) {
			return array(
				'rank'  => 0,
				'value' => $value,
				'total' => (int) ( $totals['total'] ?? 0 ),
			);
		}

		return array(
			'rank'  => (int) $totals['ahead'] + 1,
			'value' => $value,
			'total' => (int) $totals['total'],
		);
	}

	/**
	 * Throw away every cached board.
	 *
	 * Called when something has moved a number by more than watching would —
	 * a user deleted, say. Ordinary watching does not: the board catching up
	 * within five minutes is the design.
	 */
	public static function flush(): void {
		foreach ( self::METRICS as $metric ) {
			for ( $limit = 1; $limit <= self::MAX_ROWS; $limit++ ) {
				delete_transient( 'animeh_board_' . $metric . '_' . $limit );
			}
		}
	}

	/**
	 * Turn ordered rows into ranked ones.
	 *
	 * Equal values share a rank — two people on forty episodes are both
	 * second, and the next is fourth. The order within a tie is already
	 * decided by the query; this only decides what number is printed.
	 *
	 * Pure, so it is the part of this file the tests can reach.
	 *
	 * @param array<int, array<string, mixed>> $rows Ordered rows.
	 * @return array<int, array{user_id: int, value: int, rank: int}>
	 */
	public static function rank( array $rows ): array {
		$ranked   = array();
		$position = 0;
		$rank     = 0;
		$previous = null;

		foreach ( $rows as $row ) {
			++$position;
			$value = (int) ( $row['value'] ?? 0 );

			if ( null === $previous || $value !== $previous ) {
				$rank     = $position;
				$previous = $value;
			}

			$ranked[] = array(
				'user_id' => (int) ( $row['user_id'] ?? 0 ),
				'value'   => $value,
				'rank'    => $rank,
			);
		}

		return $ranked;
	}
}
