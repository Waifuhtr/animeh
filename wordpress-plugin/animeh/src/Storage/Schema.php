<?php
/**
 * Database schema.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Creates and upgrades the plugin's tables.
 */
final class Schema {

	/**
	 * Bumped whenever a table definition changes.
	 */
	public const VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	private const VERSION_OPTION = 'animeh_schema_version';

	/**
	 * Table holding registered fonts.
	 */
	public static function fonts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_fonts';
	}

	/**
	 * Table holding player test runs.
	 */
	public static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'animeh_test_sessions';
	}

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$fonts   = self::fonts_table();
		$sessions = self::sessions_table();

		// `family_key` is the normalised form a subtitle asks for, and is what
		// lookups hit; `family` keeps the spelling the font itself declares so
		// the admin sees a real name.
		$fonts_sql = "CREATE TABLE {$fonts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			family varchar(191) NOT NULL DEFAULT '',
			family_key varchar(191) NOT NULL DEFAULT '',
			postscript_name varchar(191) NOT NULL DEFAULT '',
			filename varchar(255) NOT NULL DEFAULT '',
			relative_path varchar(255) NOT NULL DEFAULT '',
			format varchar(16) NOT NULL DEFAULT '',
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			sha256 char(64) NOT NULL DEFAULT '',
			uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY family_key (family_key),
			UNIQUE KEY sha256 (sha256)
		) {$charset};";

		$sessions_sql = "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			source_url text NOT NULL,
			source_type varchar(16) NOT NULL DEFAULT '',
			subtitle_url text NOT NULL,
			throttle_kbps int(10) unsigned NOT NULL DEFAULT 0,
			verdict varchar(16) NOT NULL DEFAULT 'pending',
			metrics longtext NOT NULL,
			font_report longtext NOT NULL,
			events longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY created_by (created_by)
		) {$charset};";

		dbDelta( $fonts_sql );
		dbDelta( $sessions_sql );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Run the installer when the stored version is behind.
	 *
	 * Called on every load because a plugin updated by copying files never
	 * fires its activation hook.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Drop the tables. Called only from uninstall.
	 */
	public static function drop(): void {
		global $wpdb;
		// Table names come from $wpdb->prefix, never from input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::fonts_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::sessions_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		delete_option( self::VERSION_OPTION );
	}
}
