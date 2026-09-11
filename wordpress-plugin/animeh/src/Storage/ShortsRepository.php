<?php
/**
 * Reads and writes AnimehTok.
 *
 * Everything that touches `$wpdb` for shorts, their tags, likes, saves,
 * comments, sounds, follows and views lives here.
 *
 * Two rules this file keeps, because they are the whole reason AnimehTok has
 * its own tables rather than riding on the catalog's:
 *
 * 1. Nothing here writes to `animeh_history`, `animeh_points` or anything a
 *    leaderboard reads. Watching shorts earns nothing and counts toward
 *    nothing, so there is no reason to leave one running at a wall.
 * 2. Counters live on the row. A feed page is twenty videos, and a COUNT per
 *    video per request is the query that makes the feed unusable at the exact
 *    moment it starts working.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\Hashtag;
use Animeh\Support\StorageKey;
use WP_Error;

/**
 * AnimehTok persistence.
 */
final class ShortsRepository {

	/**
	 * Largest page any listing returns.
	 */
	public const MAX_PER_PAGE = 30;

	/**
	 * How long a "seen" row keeps a video out of the For You feed.
	 *
	 * Thirty days, then it can come round again. Kept rather than deleted on
	 * sight so the feed does not loop on a small catalogue, and pruned rather
	 * than kept forever so the table does not outgrow the videos it describes.
	 */
	private const SEEN_DAYS = 30;

	/* ── Videos ──────────────────────────────────────────────────────── */

	/**
	 * Insert a video and index its tags.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int|WP_Error The new id.
	 */
	public function create( array $data ) {
		global $wpdb;

		$now         = current_time( 'mysql', true );
		$description = (string) ( $data['description'] ?? '' );

		$row = array(
			'user_id'     => (int) ( $data['user_id'] ?? 0 ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'description' => $description,
			'storage_key' => (string) ( $data['storage_key'] ?? '' ),
			'thumb_key'   => (string) ( $data['thumb_key'] ?? '' ),
			'sound_id'    => (int) ( $data['sound_id'] ?? 0 ),
			'duration_ms' => (int) ( $data['duration_ms'] ?? 0 ),
			'width'       => (int) ( $data['width'] ?? 0 ),
			'height'      => (int) ( $data['height'] ?? 0 ),
			'size_bytes'  => (int) ( $data['size_bytes'] ?? 0 ),
			'mime'        => (string) ( $data['mime'] ?? 'video/mp4' ),
			'published'   => empty( $data['published'] ) ? 0 : 1,
			'adult'       => empty( $data['adult'] ) ? 0 : 1,
			'created_at'  => $now,
		);

		$inserted = $wpdb->insert( ShortsSchema::shorts(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $inserted ) {
			return new WP_Error(
				'animeh_short_insert_failed',
				'' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'Video kaydedilemedi.', 'animeh' ),
				array( 'status' => 500 )
			);
		}

		$id = (int) $wpdb->insert_id;

		$this->sync_tags( $id, $description );

		return $id;
	}

	/**
	 * One video by id.
	 *
	 * @param int $id Short id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . ShortsSchema::shorts() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Set the caption, re-indexing its tags.
	 *
	 * @param int    $id          Short id.
	 * @param string $description New caption.
	 */
	public function set_description( int $id, string $description ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::shorts(),
			array( 'description' => $description ),
			array( 'id' => $id )
		);

		$this->sync_tags( $id, $description );
	}

	/**
	 * Delete a video and everything hanging off it.
	 *
	 * The stored objects are the caller's to remove: this class does not know
	 * about buckets, and a delete that half-succeeds is easier to reason about
	 * when the two halves are in one place up the stack.
	 *
	 * @param int $id Short id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		foreach (
			array(
				ShortsSchema::tags()  => 'short_id',
				ShortsSchema::likes() => 'short_id',
				ShortsSchema::saves() => 'short_id',
				ShortsSchema::views() => 'short_id',
			) as $table => $column
		) {
			$wpdb->delete( $table, array( $column => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Comment likes point at comments, not at the video, so they have to go
		// first and by id.
		$comment_ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM ' . ShortsSchema::comments() . ' WHERE short_id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		foreach ( (array) $comment_ids as $comment_id ) {
			$wpdb->delete( ShortsSchema::comment_likes(), array( 'comment_id' => (int) $comment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$wpdb->delete( ShortsSchema::comments(), array( 'short_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// A sound whose only video is going away goes with it; one that other
		// videos use stays, and simply loses a use.
		$row = $this->find( $id );
		if ( null !== $row && (int) $row['sound_id'] > 0 ) {
			$this->release_sound( (int) $row['sound_id'], $id );
		}

		$wpdb->delete( ShortsSchema::shorts(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether a slug is free.
	 *
	 * @param string $slug Candidate.
	 */
	public function slug_taken( string $slug ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . ShortsSchema::shorts() . ' WHERE slug = %s', $slug ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		return null !== $found;
	}

	/**
	 * A slug nothing else is using, derived from a caption.
	 *
	 * @param string $seed    Caption, or anything else to derive from.
	 * @param int    $user_id Account, so two people's "dans" do not collide.
	 */
	public function unique_slug( string $seed, int $user_id ): string {
		$base = StorageKey::slug( '' === trim( $seed ) ? 'video' : $seed );

		if ( '' === $base || 'anime' === $base ) {
			$base = 'video';
		}

		// Trimmed so the suffix below always has room: a slug column is 191
		// characters and a caption is longer than that more often than not.
		$base = substr( $base, 0, 120 );

		$candidate = $base . '-' . $user_id;
		$suffix    = 1;

		while ( $this->slug_taken( $candidate ) ) {
			$candidate = $base . '-' . $user_id . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/* ── Feeds and listings ──────────────────────────────────────────── */

	/**
	 * The For You feed.
	 *
	 * Not a recommendation engine, and not pretending to be one. Newest first,
	 * with anything the viewer has already been shown pushed behind everything
	 * they have not, and likes breaking the tie inside each group. On a
	 * catalogue this size that is a better feed than any score would be, and
	 * it has the property that matters: swiping never hands back the video you
	 * just swiped past.
	 *
	 * @param int $user_id  Viewer, or 0 when signed out.
	 * @param int $limit    How many.
	 * @param int $offset   How many to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_you( int $user_id, int $limit = 10, int $offset = 0 ): array {
		global $wpdb;

		$shorts = ShortsSchema::shorts();
		$views  = ShortsSchema::views();
		$limit  = $this->page_size( $limit );
		$offset = max( 0, $offset );

		if ( $user_id <= 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					"SELECT * FROM {$shorts}
					 WHERE published = 1
					 ORDER BY created_at DESC, id DESC
					 LIMIT %d OFFSET %d",
					$limit,
					$offset
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT s.*, ( v.id IS NOT NULL ) AS seen
				 FROM {$shorts} s
				 LEFT JOIN {$views} v ON v.short_id = s.id AND v.user_id = %d
				 WHERE s.published = 1
				 ORDER BY seen ASC, s.created_at DESC, s.like_count DESC, s.id DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Videos by the people a viewer follows.
	 *
	 * @param int $user_id Viewer.
	 * @param int $limit   How many.
	 * @param int $offset  How many to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function following_feed( int $user_id, int $limit = 10, int $offset = 0 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$shorts  = ShortsSchema::shorts();
		$follows = ShortsSchema::follows();
		$limit   = $this->page_size( $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT s.* FROM {$shorts} s
				 INNER JOIN {$follows} f ON f.target_id = s.user_id AND f.follower_id = %d
				 WHERE s.published = 1
				 ORDER BY s.created_at DESC, s.id DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One creator's videos, newest first.
	 *
	 * @param int  $user_id      Creator.
	 * @param int  $limit        How many.
	 * @param int  $offset       How many to skip.
	 * @param bool $include_hidden Whether unpublished rows are included.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_user( int $user_id, int $limit = 20, int $offset = 0, bool $include_hidden = false ): array {
		global $wpdb;

		$shorts = ShortsSchema::shorts();
		$where  = $include_hidden ? 'user_id = %d' : 'user_id = %d AND published = 1';

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$shorts} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$user_id,
				$this->page_size( $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Videos carrying one tag, most liked first.
	 *
	 * @param string $tag    Tag as written or as folded; both work.
	 * @param int    $limit  How many.
	 * @param int    $offset How many to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_tag( string $tag, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$key = Hashtag::key( $tag );
		if ( '' === $key ) {
			return array();
		}

		$shorts = ShortsSchema::shorts();
		$tags   = ShortsSchema::tags();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT s.* FROM {$shorts} s
				 INNER JOIN {$tags} t ON t.short_id = s.id
				 WHERE t.tag_key = %s AND s.published = 1
				 ORDER BY s.like_count DESC, s.created_at DESC, s.id DESC
				 LIMIT %d OFFSET %d",
				$key,
				$this->page_size( $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many videos carry a tag, and how it is most often written.
	 *
	 * @param string $tag Tag.
	 * @return array{tag: string, key: string, count: int}
	 */
	public function tag_summary( string $tag ): array {
		global $wpdb;

		$key = Hashtag::key( $tag );
		if ( '' === $key ) {
			return array(
				'tag'   => $tag,
				'key'   => '',
				'count' => 0,
			);
		}

		$shorts = ShortsSchema::shorts();
		$tags   = ShortsSchema::tags();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT COUNT(*) FROM {$tags} t
				 INNER JOIN {$shorts} s ON s.id = t.short_id AND s.published = 1
				 WHERE t.tag_key = %s",
				$key
			)
		);

		// Whatever spelling most people used, so the page is titled the way
		// they would write it rather than the way it is indexed.
		$written = (string) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT tag FROM {$tags} WHERE tag_key = %s GROUP BY tag ORDER BY COUNT(*) DESC LIMIT 1",
				$key
			)
		);

		return array(
			'tag'   => '' !== $written ? $written : $tag,
			'key'   => $key,
			'count' => $count,
		);
	}

	/**
	 * The most used tags, for a discover page.
	 *
	 * @param int $limit How many.
	 * @return array<int, array{tag: string, key: string, count: int}>
	 */
	public function trending_tags( int $limit = 20 ): array {
		global $wpdb;

		$shorts = ShortsSchema::shorts();
		$tags   = ShortsSchema::tags();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT t.tag_key, MAX(t.tag) AS tag, COUNT(*) AS uses
				 FROM {$tags} t
				 INNER JOIN {$shorts} s ON s.id = t.short_id AND s.published = 1
				 GROUP BY t.tag_key
				 ORDER BY uses DESC, t.tag_key ASC
				 LIMIT %d",
				$this->page_size( $limit )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'tag'   => (string) $row['tag'],
				'key'   => (string) $row['tag_key'],
				'count' => (int) $row['uses'],
			);
		}

		return $out;
	}

	/**
	 * The tags on one video, as written.
	 *
	 * @param int $short_id Short id.
	 * @return array<int, string>
	 */
	public function tags_of( int $short_id ): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT tag FROM ' . ShortsSchema::tags() . ' WHERE short_id = %d ORDER BY id ASC', $short_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		return array_map( 'strval', (array) $rows );
	}

	/* ── Likes, saves and views ──────────────────────────────────────── */

	/**
	 * Like a video, or do nothing if it is already liked.
	 *
	 * @param int $short_id Short id.
	 * @param int $user_id  Viewer.
	 * @return bool Whether this call was the one that liked it.
	 */
	public function like( int $short_id, int $user_id ): bool {
		return $this->set_flag( ShortsSchema::likes(), 'short_id', $short_id, $user_id, 'like_count', 1 );
	}

	/**
	 * Remove a like.
	 *
	 * @param int $short_id Short id.
	 * @param int $user_id  Viewer.
	 * @return bool Whether a like was actually removed.
	 */
	public function unlike( int $short_id, int $user_id ): bool {
		return $this->clear_flag( ShortsSchema::likes(), 'short_id', $short_id, $user_id, 'like_count', 1 );
	}

	/**
	 * Save a video to the viewer's own shelf.
	 *
	 * @param int $short_id Short id.
	 * @param int $user_id  Viewer.
	 */
	public function save( int $short_id, int $user_id ): bool {
		return $this->set_flag( ShortsSchema::saves(), 'short_id', $short_id, $user_id, 'save_count', 1 );
	}

	/**
	 * Take it off again.
	 *
	 * @param int $short_id Short id.
	 * @param int $user_id  Viewer.
	 */
	public function unsave( int $short_id, int $user_id ): bool {
		return $this->clear_flag( ShortsSchema::saves(), 'short_id', $short_id, $user_id, 'save_count', 1 );
	}

	/**
	 * A viewer's saved videos.
	 *
	 * @param int $user_id Viewer.
	 * @param int $limit   How many.
	 * @param int $offset  How many to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function saved_by( int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$shorts = ShortsSchema::shorts();
		$saves  = ShortsSchema::saves();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT s.* FROM {$shorts} s
				 INNER JOIN {$saves} k ON k.short_id = s.id AND k.user_id = %d
				 WHERE s.published = 1
				 ORDER BY k.created_at DESC, s.id DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$this->page_size( $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count a view, once per person per video.
	 *
	 * Once, because the number under a video is meant to say how many people
	 * watched it and not how many times a thumb happened to rest on it. The
	 * unique key does the work; a repeat insert fails and changes nothing.
	 *
	 * @param int $short_id Short id.
	 * @param int $user_id  Viewer, or 0 when signed out.
	 */
	public function record_view( int $short_id, int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			// Nobody to deduplicate against: count it and move on.
			$this->bump( $short_id, 'view_count', 1 );
			return;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::views(),
			array(
				'short_id'   => $short_id,
				'user_id'    => $user_id,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		if ( false !== $inserted ) {
			$this->bump( $short_id, 'view_count', 1 );
		}
	}

	/**
	 * Drop "seen" rows old enough to be forgotten.
	 *
	 * @return int Rows removed.
	 */
	public function prune_views(): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::SEEN_DAYS * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . ShortsSchema::views() . ' WHERE created_at < %s', $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Which of these videos the viewer has liked and saved.
	 *
	 * One query per relation for the whole page rather than one per video: the
	 * feed asks this about twenty rows at a time.
	 *
	 * @param array<int, int> $short_ids Ids on the page.
	 * @param int             $user_id   Viewer.
	 * @return array{liked: array<int, bool>, saved: array<int, bool>}
	 */
	public function relations( array $short_ids, int $user_id ): array {
		$empty = array(
			'liked' => array(),
			'saved' => array(),
		);

		if ( $user_id <= 0 || array() === $short_ids ) {
			return $empty;
		}

		return array(
			'liked' => $this->flagged( ShortsSchema::likes(), 'short_id', $short_ids, $user_id ),
			'saved' => $this->flagged( ShortsSchema::saves(), 'short_id', $short_ids, $user_id ),
		);
	}

	/* ── Comments ────────────────────────────────────────────────────── */

	/**
	 * Add a comment, or a reply to one.
	 *
	 * @param int    $short_id  Short id.
	 * @param int    $user_id   Author.
	 * @param string $body      Text.
	 * @param int    $parent_id Comment being replied to, or 0.
	 * @return int|WP_Error New comment id.
	 */
	public function add_comment( int $short_id, int $user_id, string $body, int $parent_id = 0 ) {
		global $wpdb;

		// A reply to a reply is filed under the same parent. One level is what
		// the screen can draw and what a reader can follow.
		if ( $parent_id > 0 ) {
			$parent = $this->comment( $parent_id );

			if ( null === $parent || (int) $parent['short_id'] !== $short_id ) {
				return new WP_Error(
					'animeh_comment_parent_missing',
					__( 'Yanıtlanan yorum bulunamadı.', 'animeh' ),
					array( 'status' => 404 )
				);
			}

			if ( (int) $parent['parent_id'] > 0 ) {
				$parent_id = (int) $parent['parent_id'];
			}
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::comments(),
			array(
				'short_id'   => $short_id,
				'user_id'    => $user_id,
				'parent_id'  => $parent_id,
				'body'       => $body,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'animeh_comment_insert_failed',
				__( 'Yorum kaydedilemedi.', 'animeh' ),
				array( 'status' => 500 )
			);
		}

		$this->bump( $short_id, 'comment_count', 1 );

		if ( $parent_id > 0 ) {
			$this->bump_comment( $parent_id, 'reply_count', 1 );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One comment.
	 *
	 * @param int $id Comment id.
	 * @return array<string, mixed>|null
	 */
	public function comment( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . ShortsSchema::comments() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Top-level comments on a video, or the replies under one.
	 *
	 * @param int $short_id  Short id.
	 * @param int $parent_id 0 for the top level, or a comment id for replies.
	 * @param int $limit     How many.
	 * @param int $offset    How many to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function comments( int $short_id, int $parent_id = 0, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$table = ShortsSchema::comments();

		// Top-level newest first, because a video's newest comments are the
		// live conversation; replies oldest first, because a thread read out
		// of order is not a thread.
		$order = $parent_id > 0 ? 'ASC' : 'DESC';

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table}
				 WHERE short_id = %d AND parent_id = %d
				 ORDER BY created_at {$order}, id {$order}
				 LIMIT %d OFFSET %d",
				$short_id,
				$parent_id,
				$this->page_size( $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove a comment and its replies.
	 *
	 * @param int $id Comment id.
	 */
	public function delete_comment( int $id ): void {
		global $wpdb;

		$comment = $this->comment( $id );
		if ( null === $comment ) {
			return;
		}

		$replies = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM ' . ShortsSchema::comments() . ' WHERE parent_id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		$ids = array_map( 'intval', (array) $replies );
		$ids[] = $id;

		foreach ( $ids as $comment_id ) {
			$wpdb->delete( ShortsSchema::comment_likes(), array( 'comment_id' => $comment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( ShortsSchema::comments(), array( 'id' => $comment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// The video's counter loses the comment and every reply under it.
		$this->bump( (int) $comment['short_id'], 'comment_count', -count( $ids ) );

		if ( (int) $comment['parent_id'] > 0 ) {
			$this->bump_comment( (int) $comment['parent_id'], 'reply_count', -1 );
		}
	}

	/**
	 * Like a comment.
	 *
	 * @param int $comment_id Comment id.
	 * @param int $user_id    Viewer.
	 */
	public function like_comment( int $comment_id, int $user_id ): bool {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::comment_likes(),
			array(
				'comment_id' => $comment_id,
				'user_id'    => $user_id,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		if ( false === $inserted ) {
			return false;
		}

		$this->bump_comment( $comment_id, 'like_count', 1 );

		return true;
	}

	/**
	 * Take the like off again.
	 *
	 * @param int $comment_id Comment id.
	 * @param int $user_id    Viewer.
	 */
	public function unlike_comment( int $comment_id, int $user_id ): bool {
		global $wpdb;

		$removed = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::comment_likes(),
			array(
				'comment_id' => $comment_id,
				'user_id'    => $user_id,
			)
		);

		if ( ! $removed ) {
			return false;
		}

		$this->bump_comment( $comment_id, 'like_count', -1 );

		return true;
	}

	/**
	 * Which of these comments the viewer has liked.
	 *
	 * @param array<int, int> $comment_ids Ids on the page.
	 * @param int             $user_id     Viewer.
	 * @return array<int, bool>
	 */
	public function liked_comments( array $comment_ids, int $user_id ): array {
		if ( $user_id <= 0 || array() === $comment_ids ) {
			return array();
		}

		return $this->flagged( ShortsSchema::comment_likes(), 'comment_id', $comment_ids, $user_id );
	}

	/* ── Sounds ──────────────────────────────────────────────────────── */

	/**
	 * Register the sound a new video brings with it.
	 *
	 * Every video has one whether its creator thought about it or not — it is
	 * that video's own audio — so the sound page has something to list from
	 * the first upload rather than only once somebody reuses one.
	 *
	 * @param string $title    What to call it.
	 * @param string $author   Whose it is.
	 * @param int    $user_id  Creator.
	 * @return int New sound id, or 0 on failure.
	 */
	public function create_sound( string $title, string $author, int $user_id ): int {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::sounds(),
			array(
				'title'      => $title,
				'author'     => $author,
				'created_by' => $user_id,
				'use_count'  => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Point a sound at the video it is played from, and count a use.
	 *
	 * @param int $sound_id Sound id.
	 * @param int $short_id Video the audio lives in.
	 */
	public function attach_sound( int $sound_id, int $short_id ): void {
		global $wpdb;

		$sound = $this->sound( $sound_id );
		if ( null === $sound ) {
			return;
		}

		$row = array( 'use_count' => (int) $sound['use_count'] + 1 );

		// The first video using a sound is where it is played from. A later
		// one does not move it, or deleting that video would silence the page.
		if ( (int) $sound['origin_short_id'] <= 0 ) {
			$row['origin_short_id'] = $short_id;
		}

		$wpdb->update( ShortsSchema::sounds(), $row, array( 'id' => $sound_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One sound.
	 *
	 * @param int $id Sound id.
	 * @return array<string, mixed>|null
	 */
	public function sound( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . ShortsSchema::sounds() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Videos using one sound, most liked first.
	 *
	 * @param int $sound_id Sound id.
	 * @param int $limit    How many.
	 * @param int $offset   How many to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sound( int $sound_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$shorts = ShortsSchema::shorts();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$shorts}
				 WHERE sound_id = %d AND published = 1
				 ORDER BY like_count DESC, created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$sound_id,
				$this->page_size( $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Let go of a sound a deleted video was using.
	 *
	 * @param int $sound_id Sound id.
	 * @param int $short_id The video going away.
	 */
	private function release_sound( int $sound_id, int $short_id ): void {
		global $wpdb;

		$sound = $this->sound( $sound_id );
		if ( null === $sound ) {
			return;
		}

		$remaining = (int) $sound['use_count'] - 1;

		if ( $remaining <= 0 ) {
			$wpdb->delete( ShortsSchema::sounds(), array( 'id' => $sound_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$row = array( 'use_count' => $remaining );

		// The page has to be played from something that still exists.
		if ( (int) $sound['origin_short_id'] === $short_id ) {
			$next = (int) $wpdb->get_var(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					'SELECT id FROM ' . ShortsSchema::shorts() . ' WHERE sound_id = %d AND id <> %d ORDER BY id ASC LIMIT 1',
					$sound_id,
					$short_id
				)
			);

			$row['origin_short_id'] = $next;
		}

		$wpdb->update( ShortsSchema::sounds(), $row, array( 'id' => $sound_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ── Follows ─────────────────────────────────────────────────────── */

	/**
	 * Follow somebody.
	 *
	 * One-directional and unconfirmed, unlike the friendship the rest of the
	 * app uses: following is a statement about what you want to see, not a
	 * request for anything from the person followed.
	 *
	 * @param int $follower_id Who is following.
	 * @param int $target_id   Who is followed.
	 */
	public function follow( int $follower_id, int $target_id ): bool {
		global $wpdb;

		if ( $follower_id <= 0 || $target_id <= 0 || $follower_id === $target_id ) {
			return false;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::follows(),
			array(
				'follower_id' => $follower_id,
				'target_id'   => $target_id,
				'created_at'  => current_time( 'mysql', true ),
			)
		);

		return false !== $inserted;
	}

	/**
	 * Stop following.
	 *
	 * @param int $follower_id Who is following.
	 * @param int $target_id   Who is followed.
	 */
	public function unfollow( int $follower_id, int $target_id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ShortsSchema::follows(),
			array(
				'follower_id' => $follower_id,
				'target_id'   => $target_id,
			)
		);
	}

	/**
	 * Whether one account follows another.
	 *
	 * @param int $follower_id Who might be following.
	 * @param int $target_id   Who might be followed.
	 */
	public function follows( int $follower_id, int $target_id ): bool {
		global $wpdb;

		if ( $follower_id <= 0 || $target_id <= 0 ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT id FROM ' . ShortsSchema::follows() . ' WHERE follower_id = %d AND target_id = %d',
				$follower_id,
				$target_id
			)
		);

		return null !== $found;
	}

	/**
	 * Accounts on one side of the follow graph.
	 *
	 * @param int    $user_id   Account.
	 * @param string $direction 'followers' or 'following'.
	 * @param int    $limit     How many.
	 * @param int    $offset    How many to skip.
	 * @return array<int, int> User ids.
	 */
	public function follow_list( int $user_id, string $direction = 'followers', int $limit = 30, int $offset = 0 ): array {
		global $wpdb;

		$table = ShortsSchema::follows();

		if ( 'following' === $direction ) {
			$where  = 'follower_id = %d';
			$select = 'target_id';
		} else {
			$where  = 'target_id = %d';
			$select = 'follower_id';
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT {$select} FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$this->page_size( $limit ),
				max( 0, $offset )
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/* ── Search ──────────────────────────────────────────────────────── */

	/**
	 * Videos whose caption contains a phrase.
	 *
	 * @param string $query  What was typed.
	 * @param int    $limit  How many.
	 * @param int    $offset How many to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $query, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		$shorts = ShortsSchema::shorts();
		$like   = '%' . $wpdb->esc_like( $query ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$shorts}
				 WHERE published = 1 AND description LIKE %s
				 ORDER BY like_count DESC, created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$like,
				$this->page_size( $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sounds whose title or author contains a phrase.
	 *
	 * @param string $query What was typed.
	 * @param int    $limit How many.
	 * @return array<int, array<string, mixed>>
	 */
	public function search_sounds( string $query, int $limit = 20 ): array {
		global $wpdb;

		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		$sounds = ShortsSchema::sounds();
		$like   = '%' . $wpdb->esc_like( $query ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$sounds}
				 WHERE title LIKE %s OR author LIKE %s
				 ORDER BY use_count DESC, id DESC
				 LIMIT %d",
				$like,
				$like,
				$this->page_size( $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Tags matching a phrase.
	 *
	 * @param string $query What was typed.
	 * @param int    $limit How many.
	 * @return array<int, array{tag: string, key: string, count: int}>
	 */
	public function search_tags( string $query, int $limit = 20 ): array {
		global $wpdb;

		$key = Hashtag::key( $query );
		if ( '' === $key ) {
			return array();
		}

		$shorts = ShortsSchema::shorts();
		$tags   = ShortsSchema::tags();
		$like   = '%' . $wpdb->esc_like( $key ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT t.tag_key, MAX(t.tag) AS tag, COUNT(*) AS uses
				 FROM {$tags} t
				 INNER JOIN {$shorts} s ON s.id = t.short_id AND s.published = 1
				 WHERE t.tag_key LIKE %s
				 GROUP BY t.tag_key
				 ORDER BY uses DESC
				 LIMIT %d",
				$like,
				$this->page_size( $limit )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'tag'   => (string) $row['tag'],
				'key'   => (string) $row['tag_key'],
				'count' => (int) $row['uses'],
			);
		}

		return $out;
	}

	/* ── Statistics ──────────────────────────────────────────────────── */

	/**
	 * One account's AnimehTok numbers.
	 *
	 * Its own block, in its own units. None of this is watch time and none of
	 * it is worth a point: a short is not an episode, and counting it as one
	 * is how "izlenen bölüm" stopped meaning anything the last time two kinds
	 * of thing shared a table.
	 *
	 * @param int $user_id Account.
	 * @return array<string, int>
	 */
	public function stats( int $user_id ): array {
		global $wpdb;

		$shorts  = ShortsSchema::shorts();
		$likes   = ShortsSchema::likes();
		$follows = ShortsSchema::follows();

		$totals = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT COUNT(*) AS videos,
				        COALESCE(SUM(like_count), 0) AS likes,
				        COALESCE(SUM(view_count), 0) AS views,
				        COALESCE(SUM(comment_count), 0) AS comments
				 FROM {$shorts} WHERE user_id = %d AND published = 1",
				$user_id
			),
			ARRAY_A
		);

		$given = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $likes . ' WHERE user_id = %d', $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		$followers = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $follows . ' WHERE target_id = %d', $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		$following = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $follows . ' WHERE follower_id = %d', $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		return array(
			'videos'         => (int) ( $totals['videos'] ?? 0 ),
			'likes_received' => (int) ( $totals['likes'] ?? 0 ),
			'likes_given'    => $given,
			'views'          => (int) ( $totals['views'] ?? 0 ),
			'comments'       => (int) ( $totals['comments'] ?? 0 ),
			'followers'      => $followers,
			'following'      => $following,
		);
	}

	/* ── Internals ───────────────────────────────────────────────────── */

	/**
	 * Rewrite a video's tag rows from its caption.
	 *
	 * @param int    $short_id    Short id.
	 * @param string $description Caption.
	 */
	private function sync_tags( int $short_id, string $description ): void {
		global $wpdb;

		$wpdb->delete( ShortsSchema::tags(), array( 'short_id' => $short_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$now = current_time( 'mysql', true );

		foreach ( Hashtag::tags( $description ) as $tag ) {
			$key = Hashtag::key( $tag );
			if ( '' === $key ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				ShortsSchema::tags(),
				array(
					'short_id'   => $short_id,
					'tag'        => $tag,
					'tag_key'    => $key,
					'created_at' => $now,
				)
			);
		}
	}

	/**
	 * Insert a relation row and move the counter it belongs to.
	 *
	 * @param string $table   Relation table.
	 * @param string $column  Column naming the subject.
	 * @param int    $id      Subject id.
	 * @param int    $user_id Viewer.
	 * @param string $counter Column on the short to move.
	 * @param int    $by      How far.
	 */
	private function set_flag( string $table, string $column, int $id, int $user_id, string $counter, int $by ): bool {
		global $wpdb;

		if ( $id <= 0 || $user_id <= 0 ) {
			return false;
		}

		// The unique key is what makes this idempotent: a second like from the
		// same person fails to insert and the counter is left alone, which is
		// the whole reason the counter is safe to keep on the row.
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				$column      => $id,
				'user_id'    => $user_id,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		if ( false === $inserted ) {
			return false;
		}

		$this->bump( $id, $counter, $by );

		return true;
	}

	/**
	 * Remove a relation row and move its counter back.
	 *
	 * @param string $table   Relation table.
	 * @param string $column  Column naming the subject.
	 * @param int    $id      Subject id.
	 * @param int    $user_id Viewer.
	 * @param string $counter Column on the short to move.
	 * @param int    $by      How far.
	 */
	private function clear_flag( string $table, string $column, int $id, int $user_id, string $counter, int $by ): bool {
		global $wpdb;

		$removed = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				$column   => $id,
				'user_id' => $user_id,
			)
		);

		if ( ! $removed ) {
			return false;
		}

		$this->bump( $id, $counter, -$by );

		return true;
	}

	/**
	 * Which of these ids the viewer has a relation row for.
	 *
	 * @param string          $table   Relation table.
	 * @param string          $column  Column naming the subject.
	 * @param array<int, int> $ids     Subject ids.
	 * @param int             $user_id Viewer.
	 * @return array<int, bool>
	 */
	private function flagged( string $table, string $column, array $ids, int $user_id ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		// Every value is an int by the line above, so the list is safe to
		// interpolate; %d placeholders for a variable-length list cannot be
		// built with prepare() alone.
		$list = implode( ',', $ids );

		$rows = $wpdb->get_col(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT {$column} FROM {$table} WHERE user_id = %d AND {$column} IN ({$list})",
				$user_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $value ) {
			$out[ (int) $value ] = true;
		}

		return $out;
	}

	/**
	 * Move a counter on a short, never below zero.
	 *
	 * @param int    $id     Short id.
	 * @param string $column Counter.
	 * @param int    $by     How far.
	 */
	private function bump( int $id, string $column, int $by ): void {
		global $wpdb;

		// The column is chosen by this class, never by a caller, and the
		// GREATEST keeps an unsigned column from wrapping to eighteen
		// quintillion when a delete races a decrement.
		$table = ShortsSchema::shorts();

		$wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"UPDATE {$table} SET {$column} = GREATEST(CAST({$column} AS SIGNED) + %d, 0) WHERE id = %d",
				$by,
				$id
			)
		);
	}

	/**
	 * The same, for a comment.
	 *
	 * @param int    $id     Comment id.
	 * @param string $column Counter.
	 * @param int    $by     How far.
	 */
	private function bump_comment( int $id, string $column, int $by ): void {
		global $wpdb;

		$table = ShortsSchema::comments();

		$wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"UPDATE {$table} SET {$column} = GREATEST(CAST({$column} AS SIGNED) + %d, 0) WHERE id = %d",
				$by,
				$id
			)
		);
	}

	/**
	 * A page size inside the cap.
	 *
	 * @param int $limit Requested.
	 */
	private function page_size( int $limit ): int {
		return max( 1, min( $limit, self::MAX_PER_PAGE ) );
	}
}
