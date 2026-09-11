<?php
/**
 * AnimehTok's tables.
 *
 * Its own schema rather than more columns on the catalog, because a short is
 * not a work and must never be counted as one. A chapter could be an episode
 * row and that bought the manga reader the library, history and progress for
 * free — but it also quietly inflated every "watched" number until each query
 * learned to say `kind`. Shorts are the same trade offered again, and the
 * answer this time is no: nothing here joins `animeh_history`, nothing here
 * awards a point, and nothing here appears in a leaderboard.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Creates and upgrades the AnimehTok tables.
 */
final class ShortsSchema {

	/**
	 * Bumped whenever a table definition changes.
	 */
	public const VERSION = '1';

	/**
	 * Option holding the installed version.
	 */
	private const VERSION_OPTION = 'animeh_shorts_version';

	/**
	 * One row per uploaded video.
	 */
	public static function shorts(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_shorts';
	}

	/**
	 * Hashtags, one row per tag per video.
	 */
	public static function tags(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_tags';
	}

	/**
	 * Likes on a video.
	 */
	public static function likes(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_likes';
	}

	/**
	 * Saves — TikTok's "favorites", a private shelf rather than a public like.
	 */
	public static function saves(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_saves';
	}

	/**
	 * Comments, and replies to them.
	 */
	public static function comments(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_comments';
	}

	/**
	 * Likes on a comment.
	 */
	public static function comment_likes(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_comment_likes';
	}

	/**
	 * Sounds: a video's audio, reusable by other videos.
	 */
	public static function sounds(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_sounds';
	}

	/**
	 * Follows — one-directional, unlike the mutual friendship the rest of the
	 * app uses. Following somebody here is not asking to be their friend.
	 */
	public static function follows(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_follows';
	}

	/**
	 * What a viewer has already been shown, so the feed does not repeat.
	 */
	public static function views(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_short_views';
	}

	/**
	 * Create or upgrade the tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// `slug` is what the storage key is built from and what a deep link
		// carries. Counters live on the row rather than being counted on every
		// read: a feed request touches twenty videos and a COUNT per video per
		// request is the query that kills the page.
		$shorts = 'CREATE TABLE ' . self::shorts() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			slug varchar(191) NOT NULL DEFAULT '',
			description text NOT NULL,
			storage_key varchar(512) NOT NULL DEFAULT '',
			thumb_key varchar(512) NOT NULL DEFAULT '',
			sound_id bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			width smallint(5) unsigned NOT NULL DEFAULT 0,
			height smallint(5) unsigned NOT NULL DEFAULT 0,
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			mime varchar(64) NOT NULL DEFAULT 'video/mp4',
			published tinyint(1) NOT NULL DEFAULT 1,
			adult tinyint(1) NOT NULL DEFAULT 0,
			view_count bigint(20) unsigned NOT NULL DEFAULT 0,
			like_count bigint(20) unsigned NOT NULL DEFAULT 0,
			comment_count bigint(20) unsigned NOT NULL DEFAULT 0,
			save_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY user_published (user_id,published),
			KEY sound_id (sound_id),
			KEY feed (published,created_at),
			KEY popularity (published,like_count)
		) {$charset};";

		// The tag as typed and the tag folded for lookup. Turkish makes the
		// difference matter: "İZLE" lowercases to "i̇zle" under the default
		// rules and to "izle" under Turkish ones, and a tag page that depends
		// on which is a tag page that sometimes finds nothing.
		$tags = 'CREATE TABLE ' . self::tags() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			short_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tag varchar(100) NOT NULL DEFAULT '',
			tag_key varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY short_tag (short_id,tag_key),
			KEY tag_key (tag_key)
		) {$charset};";

		$likes = 'CREATE TABLE ' . self::likes() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			short_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY short_user (short_id,user_id),
			KEY user_id (user_id)
		) {$charset};";

		$saves = 'CREATE TABLE ' . self::saves() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			short_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY short_user (short_id,user_id),
			KEY user_created (user_id,created_at)
		) {$charset};";

		// `parent_id` is a reply's parent comment. One level only: a thread
		// that nests without limit is unreadable on a phone, and every app
		// this imitates flattens replies to one level for the same reason.
		$comments = 'CREATE TABLE ' . self::comments() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			short_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			body text NOT NULL,
			like_count bigint(20) unsigned NOT NULL DEFAULT 0,
			reply_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY short_parent (short_id,parent_id,created_at),
			KEY user_id (user_id)
		) {$charset};";

		$comment_likes = 'CREATE TABLE ' . self::comment_likes() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY comment_user (comment_id,user_id)
		) {$charset};";

		// A sound has no file of its own: it is the audio of the video it came
		// from, and `origin_short_id` is where it is played from. Storing a
		// second copy of the same bytes would double the bill for nothing.
		$sounds = 'CREATE TABLE ' . self::sounds() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(191) NOT NULL DEFAULT '',
			author varchar(191) NOT NULL DEFAULT '',
			origin_short_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			use_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY origin_short_id (origin_short_id),
			KEY use_count (use_count)
		) {$charset};";

		$follows = 'CREATE TABLE ' . self::follows() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			follower_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY follower_target (follower_id,target_id),
			KEY target_id (target_id)
		) {$charset};";

		// Not a statistic anybody is shown per row: this is what keeps the
		// feed from handing back the same video tomorrow. Old rows are pruned
		// rather than kept forever.
		$views = 'CREATE TABLE ' . self::views() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			short_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY short_user (short_id,user_id),
			KEY user_created (user_id,created_at)
		) {$charset};";

		$tables = array(
			$shorts,
			$tags,
			$likes,
			$saves,
			$comments,
			$comment_likes,
			$sounds,
			$follows,
			$views,
		);

		foreach ( $tables as $sql ) {
			// The same two steps the catalog goes through, for the same
			// reason: dbDelta splits on `;`, reads `--` as a column name, and
			// reports nothing when it skips one.
			$statement = CatalogSchema::for_delta( $sql );

			dbDelta( $statement );
			CatalogSchema::reconcile( $statement );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Install when the stored version is behind the code.
	 *
	 * Run on every load, not only on activation: a plugin updated by copying
	 * files over the old ones never fires its activation hook, and a site that
	 * upgrades that way would otherwise be running new code against tables
	 * that were never created.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Drop every AnimehTok table. Called only from uninstall.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::all() as $table ) {
			// Table names come from $wpdb->prefix, never from input.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Every table, for uninstall.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::views(),
			self::follows(),
			self::comment_likes(),
			self::comments(),
			self::saves(),
			self::likes(),
			self::tags(),
			self::sounds(),
			self::shorts(),
		);
	}

	/**
	 * The option to delete on uninstall.
	 */
	public static function version_option(): string {
		return self::VERSION_OPTION;
	}
}
