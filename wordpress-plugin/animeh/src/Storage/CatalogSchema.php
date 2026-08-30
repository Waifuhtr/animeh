<?php
/**
 * The catalog's tables.
 *
 * Deliberately not WordPress posts. An episode is not a document: it has a
 * numeric ordering, a parent season, several media sources and a per-user
 * playback position, and expressing that as post meta means a self-join for
 * every list query and no way to index the ordering. Custom tables cost an
 * installer and give correct queries.
 *
 * The naming is `work` rather than `anime` where a row could later describe a
 * manga: §20 asks for the manga reader to be addable without a migration, and
 * renaming a column later is the part that hurts.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Creates and upgrades the catalog tables.
 */
final class CatalogSchema {

	/**
	 * Bumped whenever a table definition changes.
	 */
	public const VERSION = '3';

	/**
	 * Option holding the installed catalog version.
	 */
	private const VERSION_OPTION = 'animeh_catalog_version';

	/**
	 * Kinds a work can be. Manga is listed now so the column never has to grow.
	 */
	public const KIND_ANIME = 'anime';

	/**
	 * Manga, reserved for the reader in a later phase.
	 */
	public const KIND_MANGA = 'manga';

	/**
	 * Works: one row per anime (later, per manga).
	 */
	public static function works(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_works';
	}

	/**
	 * Seasons within a work.
	 */
	public static function seasons(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_seasons';
	}

	/**
	 * Episodes (later, chapters).
	 */
	public static function episodes(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_episodes';
	}

	/**
	 * Media attached to an episode: video renditions, subtitles, fonts.
	 */
	public static function sources(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_sources';
	}

	/**
	 * Per-user playback positions.
	 */
	public static function history(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_history';
	}

	/**
	 * Per-user library entries: favourites and the watchlist.
	 */
	public static function library(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_library';
	}

	/**
	 * Issued API tokens, stored as hashes.
	 */
	public static function tokens(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_tokens';
	}

	/**
	 * Announcements pushed to the app.
	 */
	public static function announcements(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_announcements';
	}

	/**
	 * Structured application log.
	 */
	public static function logs(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_logs';
	}

	/**
	 * Every catalog table, unprefixed — the snapshot reads this list.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array(
			'animeh_works',
			'animeh_seasons',
			'animeh_episodes',
			'animeh_sources',
			'animeh_history',
			'animeh_library',
			'animeh_announcements',
		);
	}

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// `slug` is unique so an app can link to /anime/naruto rather than an
		// id, and `tenrai_id` is unique-nullable so importing the same title
		// twice updates rather than duplicates.
		$works = 'CREATE TABLE ' . self::works() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(16) NOT NULL DEFAULT 'anime',
			tenrai_id bigint(20) unsigned NOT NULL DEFAULT 0,
			mal_id bigint(20) unsigned NOT NULL DEFAULT 0,
			slug varchar(191) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			title_english varchar(255) NOT NULL DEFAULT '',
			title_japanese varchar(255) NOT NULL DEFAULT '',
			synonyms longtext NOT NULL,
			synopsis longtext NOT NULL,
			poster_url varchar(512) NOT NULL DEFAULT '',
			banner_url varchar(512) NOT NULL DEFAULT '',
			trailer_url varchar(512) NOT NULL DEFAULT '',
			score decimal(4,2) NOT NULL DEFAULT 0.00,
			popularity int(10) unsigned NOT NULL DEFAULT 0,
			year smallint(5) unsigned NOT NULL DEFAULT 0,
			season varchar(16) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT '',
			format varchar(32) NOT NULL DEFAULT '',
			rating varchar(32) NOT NULL DEFAULT '',
			studio varchar(191) NOT NULL DEFAULT '',
			genres longtext NOT NULL,
			total_episodes smallint(5) unsigned NOT NULL DEFAULT 0,
			duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
			published tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY kind_published (kind,published),
			KEY tenrai_id (tenrai_id),
			KEY score (score),
			KEY updated_at (updated_at),
			KEY year_season (year,season)
		) {$charset};";

		$seasons = 'CREATE TABLE ' . self::seasons() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			work_id bigint(20) unsigned NOT NULL DEFAULT 0,
			number smallint(5) unsigned NOT NULL DEFAULT 1,
			title varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY work_number (work_id,number)
		) {$charset};";

		// intro/outro markers are seconds into the episode; -1 means unset, so
		// that a genuine marker at 0 is distinguishable from "not marked".
		$episodes = 'CREATE TABLE ' . self::episodes() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			work_id bigint(20) unsigned NOT NULL DEFAULT 0,
			season_number smallint(5) unsigned NOT NULL DEFAULT 1,
			number smallint(5) unsigned NOT NULL DEFAULT 1,
			title varchar(255) NOT NULL DEFAULT '',
			synopsis longtext NOT NULL,
			thumbnail_url varchar(512) NOT NULL DEFAULT '',
			duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
			intro_start int(11) NOT NULL DEFAULT -1,
			intro_end int(11) NOT NULL DEFAULT -1,
			outro_start int(11) NOT NULL DEFAULT -1,
			filler tinyint(1) NOT NULL DEFAULT 0,
			published tinyint(1) NOT NULL DEFAULT 0,
			published_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY work_season_number (work_id,season_number,number),
			KEY work_published (work_id,published),
			KEY published_at (published_at)
		) {$charset};";

		// One table for every kind of attached media. A video rendition, a
		// subtitle track and a font differ in which columns matter, not in
		// their relationship to an episode.
		$sources = 'CREATE TABLE ' . self::sources() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			episode_id bigint(20) unsigned NOT NULL DEFAULT 0,
			work_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(16) NOT NULL DEFAULT 'video',
			label varchar(191) NOT NULL DEFAULT '',
			language varchar(16) NOT NULL DEFAULT '',
			storage_key varchar(512) NOT NULL DEFAULT '',
			external_url varchar(512) NOT NULL DEFAULT '',
			mime varchar(64) NOT NULL DEFAULT '',
			height smallint(5) unsigned NOT NULL DEFAULT 0,
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY episode_kind (episode_id,kind),
			KEY work_kind (work_id,kind)
		) {$charset};";

		// One row per user per episode. The unique key is what makes the
		// progress write an upsert rather than a read-then-write race.
		$history = 'CREATE TABLE ' . self::history() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			work_id bigint(20) unsigned NOT NULL DEFAULT 0,
			episode_id bigint(20) unsigned NOT NULL DEFAULT 0,
			position_seconds int(10) unsigned NOT NULL DEFAULT 0,
			duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
			completed tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_episode (user_id,episode_id),
			KEY user_updated (user_id,updated_at),
			KEY user_work (user_id,work_id)
		) {$charset};";

		$library = 'CREATE TABLE ' . self::library() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			work_id bigint(20) unsigned NOT NULL DEFAULT 0,
			list varchar(16) NOT NULL DEFAULT 'favorite',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_work_list (user_id,work_id,list),
			KEY user_list (user_id,list)
		) {$charset};";

		// Only the hash is stored: a database read must not yield a working
		// session. `family` groups an access token with the refresh token that
		// minted it, so revoking one device revokes both.
		$tokens = 'CREATE TABLE ' . self::tokens() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_hash char(64) NOT NULL DEFAULT '',
			kind varchar(16) NOT NULL DEFAULT 'access',
			family char(32) NOT NULL DEFAULT '',
			device varchar(191) NOT NULL DEFAULT '',
			issued_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			revoked_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_kind (user_id,kind),
			KEY family (family),
			KEY expires_at (expires_at)
		) {$charset};";

		$announcements = 'CREATE TABLE ' . self::announcements() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			link varchar(512) NOT NULL DEFAULT '',
			audience varchar(16) NOT NULL DEFAULT 'all',
			published tinyint(1) NOT NULL DEFAULT 1,
			starts_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			ends_at datetime NULL DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY published_starts (published,starts_at)
		) {$charset};";

		$logs = 'CREATE TABLE ' . self::logs() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(16) NOT NULL DEFAULT 'info',
			code varchar(64) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY level_created (level,created_at),
			KEY created_at (created_at)
		) {$charset};";

		foreach ( array( $works, $seasons, $episodes, $sources, $history, $library, $tokens, $announcements, $logs ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Run the installer when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Drop every catalog table. Called only from uninstall.
	 */
	public static function drop(): void {
		global $wpdb;

		// Children first: a foreign key is not declared, but dropping in this
		// order keeps the database consistent if the run is interrupted.
		$tables = array(
			self::logs(),
			self::announcements(),
			self::tokens(),
			self::library(),
			self::history(),
			self::sources(),
			self::episodes(),
			self::seasons(),
			self::works(),
		);

		foreach ( $tables as $table ) {
			// Names come from $wpdb->prefix, never from input.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		delete_option( self::VERSION_OPTION );
	}
}
