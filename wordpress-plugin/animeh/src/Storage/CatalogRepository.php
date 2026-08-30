<?php
/**
 * Reads and writes the catalog.
 *
 * Everything that touches `$wpdb` for works, seasons, episodes and sources
 * lives here, so the controllers stay about HTTP and the SQL stays in one file
 * where its indexes can be reasoned about.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\StorageKey;
use WP_Error;

/**
 * Catalog persistence.
 */
final class CatalogRepository {

	/**
	 * Largest page an endpoint will return.
	 *
	 * A cap rather than a suggestion: without one, `per_page=100000` is a free
	 * denial of service against the database.
	 */
	public const MAX_PER_PAGE = 50;

	/**
	 * Insert or update a work.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @param int                  $id   Existing id, or 0 to insert.
	 * @return int|WP_Error The work id.
	 */
	public function save_work( array $data, int $id = 0 ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->work_defaults( $data );

		if ( '' === $data['slug'] ) {
			$data['slug'] = $this->unique_slug( StorageKey::slug( (string) $data['title'], $id ), $id );
		} else {
			$data['slug'] = $this->unique_slug( StorageKey::slug( (string) $data['slug'], $id ), $id );
		}

		$data['updated_at'] = $now;

		if ( $id > 0 ) {
			$updated = $wpdb->update( CatalogSchema::works(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $updated ) {
				return $this->db_error( 'animeh_work_update_failed' );
			}
			return $id;
		}

		$data['created_at'] = $now;

		$inserted = $wpdb->insert( CatalogSchema::works(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $inserted ) {
			return $this->db_error( 'animeh_work_insert_failed' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One work by id.
	 *
	 * @param int $id Work id.
	 * @return array<string, mixed>|null
	 */
	public function work( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . CatalogSchema::works() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * One work by slug, which is what a deep link carries.
	 *
	 * @param string $slug Work slug.
	 * @return array<string, mixed>|null
	 */
	public function work_by_slug( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . CatalogSchema::works() . ' WHERE slug = %s', $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A work previously imported from Tenrai, so an import updates in place.
	 *
	 * @param int $tenrai_id Upstream id.
	 * @return array<string, mixed>|null
	 */
	public function work_by_tenrai_id( int $tenrai_id ): ?array {
		global $wpdb;

		if ( $tenrai_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . CatalogSchema::works() . ' WHERE tenrai_id = %d', $tenrai_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A page of works.
	 *
	 * @param array<string, mixed> $args search, genre, year, season, status, sort, page, per_page, include_unpublished.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function works( array $args = array() ): array {
		global $wpdb;

		$where  = array( 'kind = %s' );
		$params = array( (string) ( $args['kind'] ?? CatalogSchema::KIND_ANIME ) );

		// Unpublished rows are invisible unless an admin endpoint asks for
		// them, so a half-imported title never reaches the app.
		if ( empty( $args['include_unpublished'] ) ) {
			$where[] = 'published = 1';
		}

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(title LIKE %s OR title_english LIKE %s OR title_japanese LIKE %s OR synonyms LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$genre = trim( (string) ( $args['genre'] ?? '' ) );
		if ( '' !== $genre ) {
			// genres is a JSON array of names; the quoted match keeps "Action"
			// from also matching "Action Adventure".
			$where[]  = 'genres LIKE %s';
			$params[] = '%"' . $wpdb->esc_like( $genre ) . '"%';
		}

		$year = (int) ( $args['year'] ?? 0 );
		if ( $year > 0 ) {
			$where[]  = 'year = %d';
			$params[] = $year;
		}

		$season = trim( (string) ( $args['season'] ?? '' ) );
		if ( '' !== $season ) {
			$where[]  = 'season = %s';
			$params[] = $season;
		}

		$status = trim( (string) ( $args['status'] ?? '' ) );
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$min_score = (float) ( $args['min_score'] ?? 0 );
		if ( $min_score > 0 ) {
			$where[]  = 'score >= %f';
			$params[] = $min_score;
		}

		$clause   = 'WHERE ' . implode( ' AND ', $where );
		$order    = $this->work_order( (string) ( $args['sort'] ?? 'recent' ) );
		$per_page = $this->per_page( $args );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table = CatalogSchema::works();

		// Two statements rather than SQL_CALC_FOUND_ROWS, which MySQL 8
		// deprecated and which forces the optimiser to materialise the whole
		// result set to answer a count.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$clause}", $params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table} {$clause} ORDER BY {$order} LIMIT %d OFFSET %d",
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
	 * Delete a work and everything hanging off it.
	 *
	 * @param int $id Work id.
	 */
	public function delete_work( int $id ): bool {
		global $wpdb;

		$episodes = CatalogSchema::episodes();
		$sources  = CatalogSchema::sources();

		// Sources first: after the episodes are gone their ids cannot be found.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sources} WHERE work_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$episodes} WHERE work_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::seasons(), array( 'work_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::history(), array( 'work_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::library(), array( 'work_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $wpdb->delete( CatalogSchema::works(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Insert or update an episode.
	 *
	 * @param int                  $work_id Owning work.
	 * @param array<string, mixed> $data    Column values.
	 * @param int                  $id      Existing id, or 0 to insert.
	 * @return int|WP_Error
	 */
	public function save_episode( int $work_id, array $data, int $id = 0 ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->episode_defaults( $data );

		$data['work_id']    = $work_id;
		$data['updated_at'] = $now;

		if ( $id > 0 ) {
			$updated = $wpdb->update( CatalogSchema::episodes(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $updated ) {
				return $this->db_error( 'animeh_episode_update_failed' );
			}
			$this->ensure_season( $work_id, (int) $data['season_number'] );
			return $id;
		}

		// A re-import must not create a second row for the same episode; the
		// unique key would reject it, so the existing row is updated instead.
		$existing = $this->episode_by_number( $work_id, (int) $data['season_number'], (int) $data['number'] );
		if ( null !== $existing ) {
			return $this->save_episode( $work_id, $data, (int) $existing['id'] );
		}

		$data['created_at'] = $now;

		$inserted = $wpdb->insert( CatalogSchema::episodes(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $inserted ) {
			return $this->db_error( 'animeh_episode_insert_failed' );
		}

		$this->ensure_season( $work_id, (int) $data['season_number'] );

		return (int) $wpdb->insert_id;
	}

	/**
	 * One episode by id.
	 *
	 * @param int $id Episode id.
	 * @return array<string, mixed>|null
	 */
	public function episode( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . CatalogSchema::episodes() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * One episode by its position in the work.
	 *
	 * @param int $work_id Work id.
	 * @param int $season  Season number.
	 * @param int $number  Episode number.
	 * @return array<string, mixed>|null
	 */
	public function episode_by_number( int $work_id, int $season, int $number ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::episodes() . ' WHERE work_id = %d AND season_number = %d AND number = %d',
				$work_id,
				$season,
				$number
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Episodes of a work, in broadcast order.
	 *
	 * @param int  $work_id             Work id.
	 * @param int  $season              Season, or 0 for every season.
	 * @param bool $include_unpublished Whether drafts are included.
	 * @return array<int, array<string, mixed>>
	 */
	public function episodes( int $work_id, int $season = 0, bool $include_unpublished = false ): array {
		global $wpdb;

		$where  = array( 'work_id = %d' );
		$params = array( $work_id );

		if ( $season > 0 ) {
			$where[]  = 'season_number = %d';
			$params[] = $season;
		}
		if ( ! $include_unpublished ) {
			$where[] = 'published = 1';
		}

		$table  = CatalogSchema::episodes();
		$clause = 'WHERE ' . implode( ' AND ', $where );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$clause} ORDER BY season_number ASC, number ASC", $params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The episode before or after this one, for the player's next/prev.
	 *
	 * Resolved in SQL rather than by loading the season: a 1000-episode series
	 * should not cost a thousand rows to answer "what is next".
	 *
	 * @param array<string, mixed> $episode   The current episode row.
	 * @param int                  $direction 1 for next, -1 for previous.
	 * @return array<string, mixed>|null
	 */
	public function adjacent_episode( array $episode, int $direction ): ?array {
		global $wpdb;

		$table   = CatalogSchema::episodes();
		$work_id = (int) $episode['work_id'];
		$season  = (int) $episode['season_number'];
		$number  = (int) $episode['number'];

		// Ordering is (season, number), so "after" means a later number in this
		// season or any episode in a later season.
		if ( $direction >= 0 ) {
			$sql = "SELECT * FROM {$table}
				WHERE work_id = %d AND published = 1
				  AND (season_number > %d OR (season_number = %d AND number > %d))
				ORDER BY season_number ASC, number ASC LIMIT 1";
		} else {
			$sql = "SELECT * FROM {$table}
				WHERE work_id = %d AND published = 1
				  AND (season_number < %d OR (season_number = %d AND number < %d))
				ORDER BY season_number DESC, number DESC LIMIT 1";
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, $work_id, $season, $season, $number ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete an episode and its sources.
	 *
	 * @param int $id Episode id.
	 */
	public function delete_episode( int $id ): bool {
		global $wpdb;

		$wpdb->delete( CatalogSchema::sources(), array( 'episode_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::history(), array( 'episode_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $wpdb->delete( CatalogSchema::episodes(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Attach a media source to an episode.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @param int                  $id   Existing id, or 0 to insert.
	 * @return int|WP_Error
	 */
	public function save_source( array $data, int $id = 0 ) {
		global $wpdb;

		$row = array(
			'episode_id'   => (int) ( $data['episode_id'] ?? 0 ),
			'work_id'      => (int) ( $data['work_id'] ?? 0 ),
			'kind'         => (string) ( $data['kind'] ?? 'video' ),
			'label'        => (string) ( $data['label'] ?? '' ),
			'language'     => (string) ( $data['language'] ?? '' ),
			'storage_key'  => (string) ( $data['storage_key'] ?? '' ),
			'external_url' => (string) ( $data['external_url'] ?? '' ),
			'mime'         => (string) ( $data['mime'] ?? '' ),
			'height'       => (int) ( $data['height'] ?? 0 ),
			'size_bytes'   => (int) ( $data['size_bytes'] ?? 0 ),
			'is_default'   => ! empty( $data['is_default'] ) ? 1 : 0,
			'sort_order'   => (int) ( $data['sort_order'] ?? 0 ),
		);

		if ( $id > 0 ) {
			$updated = $wpdb->update( CatalogSchema::sources(), $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $updated ) {
				return $this->db_error( 'animeh_source_update_failed' );
			}
			$this->settle_default( $row, $id );
			return $id;
		}

		$row['created_at'] = current_time( 'mysql', true );

		$inserted = $wpdb->insert( CatalogSchema::sources(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $inserted ) {
			return $this->db_error( 'animeh_source_insert_failed' );
		}

		$new_id = (int) $wpdb->insert_id;
		$this->settle_default( $row, $new_id );

		return $new_id;
	}

	/**
	 * Sources attached to an episode.
	 *
	 * @param int    $episode_id Episode id.
	 * @param string $kind       video, subtitle or font; empty for all.
	 * @return array<int, array<string, mixed>>
	 */
	public function sources( int $episode_id, string $kind = '' ): array {
		global $wpdb;

		$table = CatalogSchema::sources();

		if ( '' === $kind ) {
			$sql    = "SELECT * FROM {$table} WHERE episode_id = %d ORDER BY kind ASC, sort_order ASC, height DESC";
			$params = array( $episode_id );
		} else {
			$sql    = "SELECT * FROM {$table} WHERE episode_id = %d AND kind = %s ORDER BY sort_order ASC, height DESC";
			$params = array( $episode_id, $kind );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fonts registered against a work, shared by all its episodes.
	 *
	 * @param int $work_id Work id.
	 * @return array<int, array<string, mixed>>
	 */
	public function work_fonts( int $work_id ): array {
		global $wpdb;

		$table = CatalogSchema::sources();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE work_id = %d AND kind = 'font' ORDER BY label ASC", $work_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One source by id.
	 *
	 * @param int $id Source id.
	 * @return array<string, mixed>|null
	 */
	public function source( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . CatalogSchema::sources() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Remove a source.
	 *
	 * @param int $id Source id.
	 */
	public function delete_source( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( CatalogSchema::sources(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Seasons of a work, with their episode counts.
	 *
	 * @param int $work_id Work id.
	 * @return array<int, array<string, mixed>>
	 */
	public function seasons( int $work_id ): array {
		global $wpdb;

		$seasons  = CatalogSchema::seasons();
		$episodes = CatalogSchema::episodes();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT s.number, s.title,
					(SELECT COUNT(*) FROM {$episodes} e WHERE e.work_id = s.work_id AND e.season_number = s.number AND e.published = 1) AS episode_count
				 FROM {$seasons} s WHERE s.work_id = %d ORDER BY s.number ASC",
				$work_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Episodes published most recently, for the home screen's "new episodes".
	 *
	 * @param int $limit How many.
	 * @return array<int, array<string, mixed>>
	 */
	public function latest_episodes( int $limit = 20 ): array {
		global $wpdb;

		$episodes = CatalogSchema::episodes();
		$works    = CatalogSchema::works();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT e.*, w.title AS work_title, w.slug AS work_slug, w.poster_url AS work_poster
				 FROM {$episodes} e
				 INNER JOIN {$works} w ON w.id = e.work_id
				 WHERE e.published = 1 AND w.published = 1
				 ORDER BY e.published_at DESC, e.id DESC
				 LIMIT %d",
				max( 1, min( $limit, self::MAX_PER_PAGE ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Genres present in the catalog, with how many works carry each.
	 *
	 * Computed from the rows rather than kept in a table: the list is small,
	 * it changes on every import, and a stale genre filter is worse than a
	 * query.
	 *
	 * @return array<int, array{name: string, count: int}>
	 */
	public function genres(): array {
		global $wpdb;

		$rows = $wpdb->get_col( 'SELECT genres FROM ' . CatalogSchema::works() . ' WHERE published = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $json ) {
			$names = json_decode( (string) $json, true );
			if ( ! is_array( $names ) ) {
				continue;
			}
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) || '' === $name ) {
					continue;
				}
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );

		$out = array();
		foreach ( $counts as $name => $count ) {
			$out[] = array(
				'name'  => (string) $name,
				'count' => (int) $count,
			);
		}

		return $out;
	}

	/**
	 * Row counts for the admin dashboard.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;

		$count = static function ( string $table, string $clause = '' ) use ( $wpdb ): int {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$clause}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		};

		return array(
			'works'               => $count( CatalogSchema::works() ),
			'works_published'     => $count( CatalogSchema::works(), 'WHERE published = 1' ),
			'episodes'            => $count( CatalogSchema::episodes() ),
			'episodes_published'  => $count( CatalogSchema::episodes(), 'WHERE published = 1' ),
			'video_sources'       => $count( CatalogSchema::sources(), "WHERE kind = 'video'" ),
			'subtitle_sources'    => $count( CatalogSchema::sources(), "WHERE kind = 'subtitle'" ),
			'users'               => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Make sure a season row exists for an episode's season number.
	 *
	 * @param int $work_id Work id.
	 * @param int $number  Season number.
	 */
	private function ensure_season( int $work_id, int $number ): void {
		global $wpdb;

		$number = max( 1, $number );

		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . CatalogSchema::seasons() . ' WHERE work_id = %d AND number = %d', $work_id, $number ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		if ( null !== $exists ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::seasons(),
			array(
				'work_id'    => $work_id,
				'number'     => $number,
				'title'      => '',
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Keep at most one default per episode and kind.
	 *
	 * Two defaults means the player picks arbitrarily, which shows up later as
	 * "the subtitle language changes on its own".
	 *
	 * @param array<string, mixed> $row  The saved row.
	 * @param int                  $id   Its id.
	 */
	private function settle_default( array $row, int $id ): void {
		global $wpdb;

		if ( empty( $row['is_default'] ) ) {
			return;
		}

		$table = CatalogSchema::sources();

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET is_default = 0 WHERE episode_id = %d AND kind = %s AND id <> %d",
				(int) $row['episode_id'],
				(string) $row['kind'],
				$id
			)
		);
	}

	/**
	 * A slug nobody else is using.
	 *
	 * @param string $slug    Desired slug.
	 * @param int    $exclude Work id to ignore, when updating.
	 */
	private function unique_slug( string $slug, int $exclude = 0 ): string {
		global $wpdb;

		$slug = '' === $slug ? 'anime' : $slug;
		$base = $slug;
		$table = CatalogSchema::works();

		for ( $suffix = 2; $suffix < 100; $suffix++ ) {
			$taken = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", $slug, $exclude ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			);

			if ( null === $taken ) {
				return $slug;
			}

			$slug = $base . '-' . $suffix;
		}

		// A hundred titles slugging the same way is not a naming collision any
		// more; fall back to something guaranteed unique.
		return $base . '-' . wp_generate_password( 6, false, false );
	}

	/**
	 * ORDER BY for a sort name, from a fixed list.
	 *
	 * Never interpolated from input: this is the one place a sort parameter
	 * could otherwise reach SQL.
	 *
	 * @param string $sort Requested sort.
	 */
	private function work_order( string $sort ): string {
		return match ( $sort ) {
			'score'      => 'score DESC, popularity ASC, id DESC',
			'popular'    => 'popularity ASC, score DESC, id DESC',
			'title'      => 'title ASC, id ASC',
			'year'       => 'year DESC, title ASC',
			'oldest'     => 'created_at ASC, id ASC',
			default      => 'updated_at DESC, id DESC',
		};
	}

	/**
	 * A page size within the cap.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function per_page( array $args ): int {
		$requested = (int) ( $args['per_page'] ?? 20 );
		return max( 1, min( $requested, self::MAX_PER_PAGE ) );
	}

	/**
	 * Column defaults for a work, so a partial payload is a valid row.
	 *
	 * @param array<string, mixed> $data Provided values.
	 * @return array<string, mixed>
	 */
	private function work_defaults( array $data ): array {
		$allowed = array(
			'kind'             => CatalogSchema::KIND_ANIME,
			'tenrai_id'        => 0,
			'mal_id'           => 0,
			'slug'             => '',
			'title'            => '',
			'title_english'    => '',
			'title_japanese'   => '',
			'synonyms'         => '[]',
			'synopsis'         => '',
			'poster_url'       => '',
			'banner_url'       => '',
			'trailer_url'      => '',
			'score'            => 0,
			'popularity'       => 0,
			'year'             => 0,
			'season'           => '',
			'status'           => '',
			'format'           => '',
			'rating'           => '',
			'studio'           => '',
			'genres'           => '[]',
			'total_episodes'   => 0,
			'duration_seconds' => 0,
			'published'        => 0,
			'created_by'       => 0,
		);

		$row = array();
		foreach ( $allowed as $column => $default ) {
			$row[ $column ] = $data[ $column ] ?? $default;
		}

		return $row;
	}

	/**
	 * Column defaults for an episode.
	 *
	 * @param array<string, mixed> $data Provided values.
	 * @return array<string, mixed>
	 */
	private function episode_defaults( array $data ): array {
		$allowed = array(
			'season_number'    => 1,
			'number'           => 1,
			'title'            => '',
			'synopsis'         => '',
			'thumbnail_url'    => '',
			'duration_seconds' => 0,
			'intro_start'      => -1,
			'intro_end'        => -1,
			'outro_start'      => -1,
			'filler'           => 0,
			'published'        => 0,
			'published_at'     => '0000-00-00 00:00:00',
		);

		$row = array();
		foreach ( $allowed as $column => $default ) {
			$row[ $column ] = $data[ $column ] ?? $default;
		}

		$row['season_number'] = max( 1, (int) $row['season_number'] );
		$row['number']        = max( 0, (int) $row['number'] );

		return $row;
	}

	/**
	 * Wrap the driver's message, which is the only clue when a write fails.
	 *
	 * @param string $code Error code.
	 */
	private function db_error( string $code ): WP_Error {
		global $wpdb;

		return new WP_Error(
			$code,
			'' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'Veritabanı yazma hatası.', 'animeh' ),
			array( 'status' => 500 )
		);
	}
}
