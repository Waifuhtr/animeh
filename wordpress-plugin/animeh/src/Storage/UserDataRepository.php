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

use Animeh\Support\WatchProgress;

/**
 * Per-user progress and lists.
 */
final class UserDataRepository {

	/**
	 * Record where a user is in an episode, and how much of it they saw.
	 *
	 * @param int $user_id    User.
	 * @param int $work_id    Work.
	 * @param int $episode_id Episode.
	 * @param int $position   Seconds into the episode — the resume point.
	 * @param int $duration   Episode length in seconds, 0 when unknown.
	 * @param int $watched    Seconds genuinely played, seeks excluded.
	 */
	public function record_progress( int $user_id, int $work_id, int $episode_id, int $position, int $duration, int $watched = 0 ): bool {
		global $wpdb;

		$position = max( 0, $position );
		$duration = max( 0, $duration );
		$watched  = max( 0, $watched );

		$existing = $this->progress( $user_id, $episode_id );

		// Zero means "the file has not said how long it is yet", never "zero
		// seconds long", so a stored length stands. A reported length always
		// wins over the stored one, and never the larger of the two: the
		// catalog carries an estimate — twenty-four minutes for a series whose
		// episode is ninety seconds — and keeping the larger locked that guess
		// in for good, so nothing was ever complete and nothing resumable.
		if ( $duration <= 0 && null !== $existing ) {
			$duration = (int) $existing['duration_seconds'];
		}

		// Only ever grows, so a report that arrives out of order, or a fresh
		// session that starts its own count at zero, cannot take away time
		// already earned.
		if ( null !== $existing ) {
			$watched = max( $watched, (int) $existing['watched_seconds'] );
		}

		// Never more than the episode is long: a client that miscounts, or one
		// reporting a rewatch, must not inflate the profile's total.
		if ( $duration > 0 ) {
			$watched = min( $watched, $duration );
		}

		$completed = WatchProgress::is_complete( $watched, $duration ) ? 1 : 0;
		$now       = current_time( 'mysql', true );
		$table     = CatalogSchema::history();

		// ON DUPLICATE KEY makes this one statement against the unique
		// (user_id, episode_id) index. The merge above already resolved the
		// length and the running total, so the values written are the merged
		// ones. `completed` stays sticky in SQL: finishing an episode and then
		// scrubbing back should not mark it unwatched.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (user_id, work_id, episode_id, position_seconds, duration_seconds, watched_seconds, completed, updated_at)
			 VALUES (%d, %d, %d, %d, %d, %d, %d, %s)
			 ON DUPLICATE KEY UPDATE
			   position_seconds = VALUES(position_seconds),
			   duration_seconds = VALUES(duration_seconds),
			   watched_seconds = VALUES(watched_seconds),
			   completed = GREATEST(completed, VALUES(completed)),
			   work_id = VALUES(work_id),
			   updated_at = VALUES(updated_at)",
			$user_id,
			$work_id,
			$episode_id,
			$position,
			$duration,
			$watched,
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

		$resumable   = self::resumable_sql( '' );
		$h_resumable = self::resumable_sql( 'h.' );

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
	/**
	 * The "worth continuing" test, as SQL.
	 *
	 * Both bounds are a share of the episode rather than fixed seconds, which
	 * is what the app's own rule uses. Fixed ones were the bug: thirty seconds
	 * in and forty-five off the end leaves a ninety-second episode resumable
	 * for thirteen of its ninety seconds, so short uploads looked like the
	 * feature was simply broken.
	 *
	 * The tail is written as an addition rather than `duration - margin`
	 * because `duration_seconds` is UNSIGNED, and a very short episode would
	 * underflow the subtraction.
	 *
	 * `completed` is not part of it. It means "watched enough of it to count",
	 * which happens at seventy percent, and someone who stopped there still has
	 * minutes left to continue into.
	 *
	 * @param string $prefix Table alias with its dot, or an empty string.
	 */
	private static function resumable_sql( string $prefix ): string {
		$position = $prefix . 'position_seconds';
		$duration = $prefix . 'duration_seconds';

		// Read off WatchProgress rather than written out, so the rule the app
		// applies and the rule the rail is built from cannot drift apart.
		$share      = (int) ( 100 / WatchProgress::MARGIN_PERCENT );
		$min_start  = WatchProgress::MIN_RESUME;
		$max_start  = WatchProgress::MAX_RESUME;
		$min_margin = WatchProgress::MIN_END_MARGIN;
		$max_margin = WatchProgress::MAX_END_MARGIN;

		return "{$position} >= GREATEST({$min_start}, LEAST({$max_start}, {$duration} DIV {$share}))
					  AND ({$duration} = 0
						   OR {$position} + GREATEST({$min_margin}, LEAST({$max_margin}, {$duration} DIV {$share})) < {$duration})";
	}

	/**
	 * Being counted as watched no longer hides a row from this list.
	 *
	 * Completion is seventy percent, so an episode can be "watched" with
	 * minutes still to go, and excluding those is what emptied the rail for
	 * someone who had been watching all evening. What does exclude a row is
	 * sitting at the very end, where there is nothing to continue into.
	 */
	public function continue_watching( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$history  = CatalogSchema::history();
		$episodes = CatalogSchema::episodes();
		$works    = CatalogSchema::works();

		$resumable   = self::resumable_sql( '' );
		$h_resumable = self::resumable_sql( 'h.' );

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
					WHERE user_id = %d
					  AND {$resumable}
					GROUP BY work_id
				 ) newest ON newest.work_id = h.work_id AND newest.latest = h.updated_at
				 WHERE h.user_id = %d
				   AND {$h_resumable}
				   AND w.published = 1
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
	 * The works someone has watched, newest first, one row each.
	 *
	 * Feeds both halves of a profile: the "son izledikleri" rail draws the
	 * first few, and the genre wheel is counted across the whole list.
	 *
	 * @param int $user_id Whose.
	 * @param int $limit   How many works.
	 * @return array<int, array<string, mixed>>
	 */
	public function watched_works( int $user_id, int $limit = 60 ): array {
		global $wpdb;

		$history = CatalogSchema::history();
		$works   = CatalogSchema::works();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT w.id, w.slug, w.title, w.title_english, w.poster_url, w.genres, w.adult,
					MAX(h.updated_at) AS last_watched
				 FROM {$history} h
				 INNER JOIN {$works} w ON w.id = h.work_id
				 WHERE h.user_id = %d AND w.published = 1
				 GROUP BY w.id
				 ORDER BY last_watched DESC
				 LIMIT %d",
				$user_id,
				max( 1, min( $limit, 200 ) )
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
	 * Everyone who asked to hear about a work.
	 *
	 * The other side of the bell: [in_list] answers "am I following this",
	 * this answers "who is", which is the question publishing an episode asks.
	 *
	 * Capped, because a notification fan-out is a loop of HTTP requests and a
	 * popular series should not turn one publish into a request that times
	 * out. A series with more followers than this needs a queue, which is a
	 * different piece of work and is not pretended at here.
	 *
	 * @param int $work_id Work.
	 * @param int $limit   Most followers to return.
	 * @return int[] User ids.
	 */
	public function followers( int $work_id, int $limit = 500 ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT user_id FROM ' . CatalogSchema::library() .
				" WHERE work_id = %d AND list = 'follow' ORDER BY created_at ASC LIMIT %d",
				$work_id,
				max( 1, $limit )
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
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

		$episodes = CatalogSchema::episodes();

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT
					COUNT(*) AS episodes_started,
					SUM(completed) AS episodes_completed,
					SUM(watched_seconds) AS seconds_watched,
					COUNT(DISTINCT work_id) AS works_started
				 FROM {$history} WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		$favorites = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$library} WHERE user_id = %d AND list = 'favorite'", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		// A series counts as finished when every published episode of it is
		// finished. Compared against the episode table rather than the work's
		// `total_episodes`, which is what the source announced and is often
		// ahead of what has actually been added here.
		$works_completed = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT COUNT(*) FROM (
					SELECT h.work_id, COUNT(*) AS done
					FROM {$history} h
					WHERE h.user_id = %d AND h.completed = 1
					GROUP BY h.work_id
				 ) watched
				 INNER JOIN (
					SELECT work_id, COUNT(*) AS total
					FROM {$episodes} WHERE published = 1
					GROUP BY work_id
				 ) published ON published.work_id = watched.work_id
				 WHERE published.total > 0 AND watched.done >= published.total",
				$user_id
			)
		);

		return array(
			'episodes_started'   => (int) ( $row['episodes_started'] ?? 0 ),
			'episodes_completed' => (int) ( $row['episodes_completed'] ?? 0 ),
			'seconds_watched'    => (int) ( $row['seconds_watched'] ?? 0 ),
			'works_started'      => (int) ( $row['works_started'] ?? 0 ),
			'works_completed'    => $works_completed,
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
		$wpdb->delete( CatalogSchema::devices(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::room_members(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Both directions of every friendship, not just the rows they own:
		// leaving the other half behind puts a ghost in someone's friend list.
		$friends = CatalogSchema::friends();
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$friends} WHERE user_id = %d OR friend_id = %d", $user_id, $user_id )
		);

		// And the rooms they were hosting, which nobody else can close.
		( new SocialRepository() )->close_rooms_hosted_by( $user_id );
	}
}
