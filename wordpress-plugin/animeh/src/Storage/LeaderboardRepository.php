<?php
/**
 * The standings.
 *
 * Four boards. Three are a GROUP BY across the whole history table, which is
 * the most expensive query in the plugin, so the result is cached for a few
 * minutes: a board is a thing people look at, not a thing they refresh, and
 * being four minutes out of date costs nobody anything. The fourth reads the
 * points ledger instead — see [METRIC_POINTS].
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
	 * Most points earned, anime and manga together.
	 *
	 * The one board manga counts on. An episode is worth twice a chapter
	 * (see `Points::rate()`) — a judgement this app already makes once, for
	 * the wallet — so reusing it here is what lets a reader stand on the
	 * same board as a viewer without also reopening the reason the other
	 * three boards are anime-only: mixing a page into "en çok saat izleyen"
	 * is the exact mistake `UserDataRepository::stats()` was split apart to
	 * stop making, and merging kinds into those three would make it again.
	 * Spending is not: buying a frame in the shop should not cost anyone
	 * their place, so this ranks what was earned, same as the wallet's own
	 * "total earned" figure, not the balance left after the shop.
	 */
	public const METRIC_POINTS = 'points';

	/**
	 * The four boards.
	 *
	 * @var string[]
	 */
	public const METRICS = array(
		self::METRIC_WORKS,
		self::METRIC_SECONDS,
		self::METRIC_EPISODES,
		self::METRIC_POINTS,
	);

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
			// Qualified: every one of these is read alongside a join onto
			// `works`, and an unqualified name is one added column away from
			// becoming ambiguous without anybody noticing.
			case self::METRIC_WORKS:
				return 'COUNT(DISTINCT h.work_id)';
			case self::METRIC_EPISODES:
				return 'SUM(h.completed)';
			default:
				return 'SUM(h.watched_seconds)';
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
		if ( ! self::valid( $metric ) ) {
			return array();
		}

		$limit  = max( 1, min( self::MAX_ROWS, $limit ) );
		$key    = 'animeh_board_' . $metric . '_' . $limit;
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows  = self::METRIC_POINTS === $metric
			? self::points_rows( $limit )
			: self::history_rows( $metric, $limit );
		$board = self::rank( $rows );

		set_transient( $key, $board, self::CACHE_SECONDS );

		return $board;
	}

	/**
	 * Rows for one of the three watch-history boards.
	 *
	 * @param string $metric One of [METRIC_WORKS], [METRIC_SECONDS], [METRIC_EPISODES].
	 * @param int    $limit  How many rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function history_rows( string $metric, int $limit ): array {
		global $wpdb;

		$history    = CatalogSchema::history();
		$works      = CatalogSchema::works();
		$anime      = CatalogSchema::KIND_ANIME;
		$expression = self::expression( $metric );

		// `MIN(updated_at)` breaks ties: two people on forty episodes are not
		// equal, and the one who got there first is ahead. Without it the
		// order is whatever the engine felt like, and the board reshuffles
		// between two refreshes that show the same numbers.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT h.user_id, {$expression} AS value, MIN(h.updated_at) AS first_seen
				 FROM {$history} h
				 INNER JOIN {$works} w ON w.id = h.work_id
				 WHERE h.user_id > 0 AND w.kind = '{$anime}'
				 GROUP BY h.user_id
				 HAVING value > 0
				 ORDER BY value DESC, first_seen ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows for the points board — the ledger directly, nothing to join.
	 *
	 * `delta > 0` is what makes this "earned" rather than "balance": see
	 * [METRIC_POINTS] for why spending must not cost anyone their place.
	 *
	 * @param int $limit How many rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function points_rows( int $limit ): array {
		global $wpdb;

		$points = CatalogSchema::points();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT user_id,
					SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) AS value,
					MIN(CASE WHEN delta > 0 THEN created_at END) AS first_seen
				 FROM {$points}
				 WHERE user_id > 0
				 GROUP BY user_id
				 HAVING value > 0
				 ORDER BY value DESC, first_seen ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
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
		if ( ! self::valid( $metric ) || $user_id <= 0 ) {
			return array(
				'rank'  => 0,
				'value' => 0,
				'total' => 0,
			);
		}

		return self::METRIC_POINTS === $metric
			? self::points_standing( $user_id )
			: self::history_standing( $metric, $user_id );
	}

	/**
	 * One person's place on one of the three watch-history boards.
	 *
	 * @param string $metric  One of [METRIC_WORKS], [METRIC_SECONDS], [METRIC_EPISODES].
	 * @param int    $user_id User.
	 * @return array{rank: int, value: int, total: int}
	 */
	private static function history_standing( string $metric, int $user_id ): array {
		global $wpdb;

		$history    = CatalogSchema::history();
		$works      = CatalogSchema::works();
		$anime      = CatalogSchema::KIND_ANIME;
		$expression = self::expression( $metric );

		$value = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT {$expression}
				 FROM {$history} h
				 INNER JOIN {$works} w ON w.id = h.work_id
				 WHERE h.user_id = %d AND w.kind = '{$anime}'",
				$user_id
			)
		);

		$totals = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN value > %d THEN 1 ELSE 0 END) AS ahead
				 FROM (
					SELECT h.user_id, {$expression} AS value
					FROM {$history} h
					INNER JOIN {$works} w ON w.id = h.work_id
					WHERE h.user_id > 0 AND w.kind = '{$anime}'
					GROUP BY h.user_id
					HAVING value > 0
				 ) standings",
				$value
			),
			ARRAY_A
		);

		return self::finish_standing( $value, $totals );
	}

	/**
	 * One person's place on the points board.
	 *
	 * @param int $user_id User.
	 * @return array{rank: int, value: int, total: int}
	 */
	private static function points_standing( int $user_id ): array {
		global $wpdb;

		$points = CatalogSchema::points();

		$value = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) FROM {$points} WHERE user_id = %d",
				$user_id
			)
		);

		$totals = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN value > %d THEN 1 ELSE 0 END) AS ahead
				 FROM (
					SELECT user_id, SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) AS value
					FROM {$points}
					WHERE user_id > 0
					GROUP BY user_id
					HAVING value > 0
				 ) standings",
				$value
			),
			ARRAY_A
		);

		return self::finish_standing( $value, $totals );
	}

	/**
	 * The tail shared by [history_standing] and [points_standing]: a value
	 * plus a COUNT/ahead row becomes the {rank, value, total} shape either
	 * one returns.
	 *
	 * @param int        $value  This person's number on the metric asked for.
	 * @param mixed      $totals The COUNT/ahead row from `$wpdb->get_row`, or null.
	 * @return array{rank: int, value: int, total: int}
	 */
	private static function finish_standing( int $value, $totals ): array {
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
