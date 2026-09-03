<?php
/**
 * Reviews and the votes cast on them.
 *
 * A review is a score out of ten with optional prose. The score is what feeds
 * a work's rating and, through it, what gets recommended; the prose is what
 * other readers vote on.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Reads and writes reviews.
 */
final class ReviewRepository {

	/**
	 * Lowest and highest score a review may carry.
	 */
	public const MIN_SCORE = 1;

	/**
	 * Ten, as the brief asks.
	 */
	public const MAX_SCORE = 10;

	/**
	 * One user's review of one work, or null.
	 *
	 * @param int $work_id Work.
	 * @param int $user_id User.
	 * @return array<string, mixed>|null
	 */
	public function mine( int $work_id, int $user_id ): ?array {
		global $wpdb;

		$table = CatalogSchema::reviews();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE work_id = %d AND user_id = %d", $work_id, $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * A review by id.
	 *
	 * @param int $id Review id.
	 * @return array<string, mixed>|null
	 */
	public function by_id( int $id ): ?array {
		global $wpdb;

		$table = CatalogSchema::reviews();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * A page of a work's reviews.
	 *
	 * Ordered by how useful readers found them rather than by date: a review
	 * people agreed with is the one worth showing first, and recency alone puts
	 * the least-read review at the top.
	 *
	 * @param int    $work_id  Work.
	 * @param int    $page     1-based.
	 * @param int    $per_page Page size.
	 * @param string $sort     'useful' or 'recent'.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function for_work( int $work_id, int $page = 1, int $per_page = 20, string $sort = 'useful' ): array {
		global $wpdb;

		$table  = CatalogSchema::reviews();
		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		$order = 'recent' === $sort
			? 'created_at DESC'
			: '(CAST(up_votes AS SIGNED) - CAST(down_votes AS SIGNED)) DESC, created_at DESC';

		$items = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table} WHERE work_id = %d ORDER BY {$order} LIMIT %d OFFSET %d",
				$work_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE work_id = %d", $work_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Somebody's reviews, newest first, joined to what they are about.
	 *
	 * Shown on their profile and readable by anyone: a review is a public act,
	 * and the whole point of the section is that other people read it.
	 *
	 * @param int $user_id Whose.
	 * @param int $limit   How many.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_user( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$reviews = CatalogSchema::reviews();
		$works   = CatalogSchema::works();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT r.*, w.title AS work_title, w.slug AS work_slug, w.poster_url AS work_poster
				 FROM {$reviews} r
				 INNER JOIN {$works} w ON w.id = r.work_id
				 WHERE r.user_id = %d AND w.published = 1
				 ORDER BY r.updated_at DESC
				 LIMIT %d",
				$user_id,
				max( 1, min( $limit, 100 ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create or replace this user's review of a work.
	 *
	 * @param int    $work_id Work.
	 * @param int    $user_id Author.
	 * @param int    $score   1..10.
	 * @param string $body    Prose, may be empty.
	 * @param bool   $spoiler Whether the prose gives the story away.
	 * @return int Review id, or 0 when the write failed.
	 */
	public function save( int $work_id, int $user_id, int $score, string $body, bool $spoiler ): int {
		global $wpdb;

		$table = CatalogSchema::reviews();
		$now   = current_time( 'mysql', true );
		$score = max( self::MIN_SCORE, min( self::MAX_SCORE, $score ) );

		$existing = $this->mine( $work_id, $user_id );

		if ( null !== $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'score'      => $score,
					'body'       => $body,
					'spoiler'    => $spoiler ? 1 : 0,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%d', '%s', '%d', '%s' ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'work_id'    => $work_id,
				'user_id'    => $user_id,
				'score'      => $score,
				'body'       => $body,
				'spoiler'    => $spoiler ? 1 : 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove a review and the votes attached to it.
	 *
	 * @param int $id Review id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( CatalogSchema::review_votes(), array( 'review_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::reviews(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Record agreement or disagreement with a review.
	 *
	 * Passing the vote already held clears it, so the same tap undoes itself.
	 *
	 * @param int $review_id Review.
	 * @param int $user_id   Voter.
	 * @param int $vote      1, -1, or 0 to withdraw.
	 */
	public function vote( int $review_id, int $user_id, int $vote ): void {
		global $wpdb;

		$votes = CatalogSchema::review_votes();
		$vote  = max( -1, min( 1, $vote ) );

		$current = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT vote FROM {$votes} WHERE review_id = %d AND user_id = %d", $review_id, $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		if ( 0 === $vote || $current === $vote ) {
			$wpdb->delete( $votes, array( 'review_id' => $review_id, 'user_id' => $user_id ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$votes,
				array(
					'review_id'  => $review_id,
					'user_id'    => $user_id,
					'vote'       => $vote,
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}

		$this->recount( $review_id );
	}

	/**
	 * How this user voted on each of the given reviews.
	 *
	 * @param int[] $review_ids Reviews.
	 * @param int   $user_id    Voter.
	 * @return array<int, int> Review id to -1, 0 or 1.
	 */
	public function votes_by( array $review_ids, int $user_id ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $review_ids ) ) );
		if ( array() === $ids || $user_id <= 0 ) {
			return array();
		}

		$votes        = CatalogSchema::review_votes();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( $ids, array( $user_id ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT review_id, vote FROM {$votes} WHERE review_id IN ({$placeholders}) AND user_id = %d",
				...$params
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['review_id'] ] = (int) $row['vote'];
		}

		return $map;
	}

	/**
	 * A work's rating as its readers scored it.
	 *
	 * @param int $work_id Work.
	 * @return array{average: float, count: int}
	 */
	public function rating( int $work_id ): array {
		global $wpdb;

		$table = CatalogSchema::reviews();

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT AVG(score) AS average, COUNT(*) AS total FROM {$table} WHERE work_id = %d AND score > 0",
				$work_id
			),
			ARRAY_A
		);

		return array(
			'average' => round( (float) ( $row['average'] ?? 0 ), 2 ),
			'count'   => (int) ( $row['total'] ?? 0 ),
		);
	}

	/**
	 * Ratings for several works at once, for a list that shows them.
	 *
	 * @param int[] $work_ids Works.
	 * @return array<int, array{average: float, count: int}>
	 */
	public function ratings( array $work_ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $work_ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$table        = CatalogSchema::reviews();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT work_id, AVG(score) AS average, COUNT(*) AS total
				 FROM {$table} WHERE work_id IN ({$placeholders}) AND score > 0
				 GROUP BY work_id",
				...$ids
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['work_id'] ] = array(
				'average' => round( (float) $row['average'], 2 ),
				'count'   => (int) $row['total'],
			);
		}

		return $map;
	}

	/**
	 * Refresh a review's stored vote counters from the votes table.
	 *
	 * @param int $review_id Review.
	 */
	private function recount( int $review_id ): void {
		global $wpdb;

		$votes   = CatalogSchema::review_votes();
		$reviews = CatalogSchema::reviews();

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT
					SUM(CASE WHEN vote > 0 THEN 1 ELSE 0 END) AS ups,
					SUM(CASE WHEN vote < 0 THEN 1 ELSE 0 END) AS downs
				 FROM {$votes} WHERE review_id = %d",
				$review_id
			),
			ARRAY_A
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$reviews,
			array(
				'up_votes'   => (int) ( $row['ups'] ?? 0 ),
				'down_votes' => (int) ( $row['downs'] ?? 0 ),
			),
			array( 'id' => $review_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}
}
