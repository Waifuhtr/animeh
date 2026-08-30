<?php
/**
 * Watch history and the personal library.
 *
 * These are the two tables the app writes to most often — a progress ping
 * every few seconds while an episode plays — so the write path is a single
 * upsert rather than a read followed by an insert or update. The read-then-
 * write version has a race: two devices playing the same episode produce two
 * inserts and the unique key rejects the second.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Per-user progress and lists.
 */
final class UserDataRepository {

	/**
	 * How far into an episode counts as "finished".
	 *
	 * Ninety percent, because credits are not worth making someone sit through
	 * for an episode to leave "continue watching".
	 */
	private const COMPLETE_RATIO = 0.9;

	/**
	 * Record where a user is in an episode.
	 *
	 * @param int $user_id  User.
	 * @param int $work_id  Work.
	 * @param int $episode_id Episode.
	 * @param int $position Seconds into the episode.
	 * @param int $duration Episode length in seconds, 0 when unknown.
	 */
	public function record_progress( int $user_id, int $work_id, int $episode_id, int $position, int $duration ): bool {
		global $wpdb;

		$position  = max( 0, $position );
		$duration  = max( 0, $duration );
		$completed = $duration > 0 && $position >= (int) floor( $duration * self::COMPLETE_RATIO ) ? 1 : 0;
		$now       = current_time( 'mysql', true );
		$table     = CatalogSchema::history();

		// ON DUPLICATE KEY makes this one statement against the unique
		// (user_id, episode_id) index. `completed` is sticky: finishing an
		// episode and then scrubbing back should not mark it unwatched.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (user_id, work_id, episode_id, position_seconds, duration_seconds, completed, updated_at)
			 VALUES (%d, %d, %d, %d, %d, %d, %s)
			 ON DUPLICATE KEY UPDATE
			   position_seconds = VALUES(position_seconds),
			   duration_seconds = GREATEST(duration_seconds, VALUES(duration_seconds)),
			   completed = GREATEST(completed, VALUES(completed)),
			   work_id = VALUES(work_id),
			   updated_at = VALUES(updated_at)",
			$user_id,
			$work_id,
			$episode_id,
			$position,
			$duration,
			$completed,
			$now
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A user's position in one episode.
	 *
	 * @param int $user_id    User.
	 * @param int $episode_id Episode.
	 * @return array<string, mixed>|null
	 */
	public function progress( int $user_id, int $episode_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::history() . ' WHERE user_id = %d AND episode_id = %d',
				$user_id,
				$episode_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Recent history, newest first, joined to what the app needs to draw a row.
	 *
	 * @param int $user_id  User.
	 * @param int $limit    How many.
	 * @param int $offset   Where to start.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$history  = CatalogSchema::history();
		$episodes = CatalogSchema::episodes();
		$works    = CatalogSchema::works();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT h.*, e.number AS episode_number, e.season_number, e.title AS episode_title,
					e.thumbnail_url, w.title AS work_title, w.slug AS work_slug, w.poster_url
				 FROM {$history} h
				 INNER JOIN {$episodes} e ON e.id = h.episode_id
				 INNER JOIN {$works} w ON w.id = h.work_id
				 WHERE h.user_id = %d
				 ORDER BY h.updated_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				max( 1, min( $limit, CatalogRepository::MAX_PER_PAGE ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * "Continue watching": the newest unfinished episode of each work.
	 *
	 * The subquery picks one row per work rather than letting a series with
	 * fifty watched episodes fill the whole rail.
	 *
	 * @param int $user_id User.
	 * @param int $limit   How many works.
	 * @return array<int, array<string, mixed>>
	 */
	public function continue_watching( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$history  = CatalogSchema::history();
		$episodes = CatalogSchema::episodes();
		$works    = CatalogSchema::works();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT h.*, e.number AS episode_number, e.season_number, e.title AS episode_title,
					e.thumbnail_url, w.title AS work_title, w.slug AS work_slug, w.poster_url
				 FROM {$history} h
				 INNER JOIN {$episodes} e ON e.id = h.episode_id
				 INNER JOIN {$works} w ON w.id = h.work_id
				 INNER JOIN (
					SELECT work_id, MAX(updated_at) AS latest
					FROM {$history}
					WHERE user_id = %d AND completed = 0 AND position_seconds > 30
					GROUP BY work_id
				 ) newest ON newest.work_id = h.work_id AND newest.latest = h.updated_at
				 WHERE h.user_id = %d AND h.completed = 0 AND w.published = 1
				 ORDER BY h.updated_at DESC
				 LIMIT %d",
				$user_id,
				$user_id,
				max( 1, min( $limit, CatalogRepository::MAX_PER_PAGE ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Forget one history row.
	 *
	 * @param int $user_id    User.
	 * @param int $episode_id Episode.
	 */
	public function forget( int $user_id, int $episode_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::history(),
			array(
				'user_id'    => $user_id,
				'episode_id' => $episode_id,
			)
		);
	}

	/**
	 * Forget everything, for the settings screen's "clear history".
	 *
	 * @param int $user_id User.
	 */
	public function clear_history( int $user_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( CatalogSchema::history(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Add a work to one of the user's lists.
	 *
	 * @param int    $user_id User.
	 * @param int    $work_id Work.
	 * @param string $list    favorite or watchlist.
	 */
	public function add_to_list( int $user_id, int $work_id, string $list = 'favorite' ): bool {
		global $wpdb;

		$table = CatalogSchema::library();

		// Adding twice is not an error — the app retries an unacknowledged tap
		// — so the duplicate key is absorbed rather than reported.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (user_id, work_id, list, created_at)
			 VALUES (%d, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE created_at = created_at",
			$user_id,
			$work_id,
			$list,
			current_time( 'mysql', true )
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remove a work from a list.
	 *
	 * @param int    $user_id User.
	 * @param int    $work_id Work.
	 * @param string $list    favorite or watchlist.
	 */
	public function remove_from_list( int $user_id, int $work_id, string $list = 'favorite' ): bool {
		global $wpdb;

		return false !== $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::library(),
			array(
				'user_id' => $user_id,
				'work_id' => $work_id,
				'list'    => $list,
			)
		);
	}

	/**
	 * Whether a work is on a list, for the detail screen's heart icon.
	 *
	 * @param int    $user_id User.
	 * @param int    $work_id Work.
	 * @param string $list    favorite or watchlist.
	 */
	public function in_list( int $user_id, int $work_id, string $list = 'favorite' ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT id FROM ' . CatalogSchema::library() . ' WHERE user_id = %d AND work_id = %d AND list = %s',
				$user_id,
				$work_id,
				$list
			)
		);

		return null !== $found;
	}

	/**
	 * A user's list, with the works joined in.
	 *
	 * @param int    $user_id User.
	 * @param string $list    favorite or watchlist.
	 * @param int    $limit   How many.
	 * @param int    $offset  Where to start.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_works( int $user_id, string $list, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$library = CatalogSchema::library();
		$works   = CatalogSchema::works();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT w.*, l.created_at AS added_at
				 FROM {$library} l
				 INNER JOIN {$works} w ON w.id = l.work_id
				 WHERE l.user_id = %d AND l.list = %s AND w.published = 1
				 ORDER BY l.created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$list,
				max( 1, min( $limit, CatalogRepository::MAX_PER_PAGE ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Numbers for the profile screen.
	 *
	 * @param int $user_id User.
	 * @return array<string, int>
	 */
	public function stats( int $user_id ): array {
		global $wpdb;

		$history = CatalogSchema::history();
		$library = CatalogSchema::library();

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT
					COUNT(*) AS episodes_started,
					SUM(completed) AS episodes_completed,
					SUM(position_seconds) AS seconds_watched,
					COUNT(DISTINCT work_id) AS works_started
				 FROM {$history} WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		$favorites = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$library} WHERE user_id = %d AND list = 'favorite'", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		return array(
			'episodes_started'   => (int) ( $row['episodes_started'] ?? 0 ),
			'episodes_completed' => (int) ( $row['episodes_completed'] ?? 0 ),
			'seconds_watched'    => (int) ( $row['seconds_watched'] ?? 0 ),
			'works_started'      => (int) ( $row['works_started'] ?? 0 ),
			'favorites'          => $favorites,
		);
	}

	/**
	 * Drop everything belonging to a deleted user.
	 *
	 * @param int $user_id User.
	 */
	public function purge_user( int $user_id ): void {
		global $wpdb;

		$wpdb->delete( CatalogSchema::history(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::library(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::tokens(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
