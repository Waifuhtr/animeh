<?php
/**
 * Snapshots: writing the library's own data into the bucket, and reading it back.
 *
 * The failure this exists for is the one the operator described — hosting or
 * the domain goes away — and the important part of that scenario is that the
 * old site is *gone*. Anything that requires the old installation to answer a
 * request is a plan for a move, not for a loss. So the bucket, which is paid
 * for separately and outlives the host, is where the plugin keeps a copy of
 * everything it knows.
 *
 * A snapshot never contains the storage credentials. That is not an oversight:
 * the file lives inside the bucket, so including its keys would make one leaked
 * object equivalent to handing over the account — and a site restoring from the
 * bucket has already had to be given those keys to read the snapshot at all.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\Snapshot;
use Animeh\Support\StorageKey;
use WP_Error;

/**
 * Captures, uploads, lists and restores snapshots.
 */
final class SnapshotStore {

	/**
	 * Where snapshots live in the bucket.
	 */
	public const PREFIX = StorageKey::SYSTEM_ROOT . '/snapshots';

	/**
	 * The pointer the app reads to find its backend.
	 *
	 * Kept in the bucket rather than on any site, because it must stay readable
	 * when a site does not. An installed app that cannot reach its configured
	 * WordPress checks here and follows whatever address the operator moved to.
	 */
	public const POINTER_KEY = StorageKey::SYSTEM_ROOT . '/backend.json';

	/**
	 * How many snapshots to keep.
	 *
	 * Enough history to step back past a bad import; few enough that they cost
	 * nothing worth thinking about.
	 */
	public const KEEP = 14;

	/**
	 * Option recording the last run, for the admin screen.
	 */
	private const STATUS_OPTION = 'animeh_snapshot_status';

	/**
	 * Cron hook name.
	 */
	public const CRON_HOOK = 'animeh_snapshot_event';

	/**
	 * Read the plugin's tables and options into an envelope.
	 *
	 * @return array<string, mixed>
	 */
	public static function capture(): array {
		global $wpdb;

		$tables = array();
		foreach ( Snapshot::TABLES as $table ) {
			$name = $wpdb->prefix . $table;
			// Table names are built from $wpdb->prefix and a fixed list; no
			// part of them comes from a request, so there is nothing to bind.
			$rows            = $wpdb->get_results( 'SELECT * FROM ' . $name, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$tables[ $table ] = is_array( $rows ) ? $rows : array();
		}

		$options = array();
		foreach ( Snapshot::OPTIONS as $option ) {
			$value = get_option( $option, null );
			if ( null !== $value ) {
				$options[ $option ] = $value;
			}
		}

		return Snapshot::build(
			$tables,
			$options,
			array(
				'site_url'       => home_url(),
				'plugin_version' => defined( 'Animeh\\VERSION' ) ? constant( 'Animeh\\VERSION' ) : '',
				'generated_by'   => (string) get_current_user_id(),
			)
		);
	}

	/**
	 * Capture and upload, then prune old snapshots.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run() {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Yedek almadan önce bucket bilgilerini gir.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$envelope = self::capture();
		$bytes    = Snapshot::encode( $envelope );
		$key      = self::PREFIX . '/' . gmdate( 'Y-m-d\THis\Z' ) . '.json.gz';

		$client = new B2Client( $settings );
		$result = $client->put_object( $key, $bytes, 'application/gzip' );
		if ( $result instanceof WP_Error ) {
			self::record_status(
				array(
					'ok'      => false,
					'at'      => time(),
					'message' => $result->get_error_message(),
				)
			);
			return $result;
		}

		self::prune( $client );
		self::write_pointer( $settings, $client );

		$status = array(
			'ok'    => true,
			'at'    => time(),
			'key'   => $key,
			'bytes' => strlen( $bytes ),
			'counts' => Snapshot::summarise( $envelope )['counts'],
		);
		self::record_status( $status );

		return $status;
	}

	/**
	 * Snapshots currently in the bucket, newest first.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function listing() {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return array();
		}

		$client  = new B2Client( $settings );
		$objects = $client->list_objects( self::PREFIX . '/', 200 );
		if ( $objects instanceof WP_Error ) {
			return $objects;
		}

		$snapshots = array();
		foreach ( $objects as $object ) {
			$key = (string) ( $object['key'] ?? '' );
			if ( '' === $key || ! str_ends_with( $key, '.gz' ) && ! str_ends_with( $key, '.json' ) ) {
				continue;
			}
			$snapshots[] = array(
				'key'           => $key,
				'size'          => (int) ( $object['size'] ?? 0 ),
				'last_modified' => (string) ( $object['last_modified'] ?? '' ),
			);
		}

		// Keys are timestamps, so a reverse sort by key is chronological and
		// does not depend on the listing order the endpoint happened to use.
		usort(
			$snapshots,
			static fn( array $a, array $b ): int => strcmp( $b['key'], $a['key'] )
		);

		return $snapshots;
	}

	/**
	 * Fetch and decode one snapshot.
	 *
	 * @param string $key Object key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function fetch( string $key ) {
		if ( ! str_starts_with( $key, self::PREFIX . '/' ) ) {
			// Only this prefix, so a crafted key cannot pull an arbitrary
			// bucket object through the restore path.
			return new WP_Error(
				'animeh_snapshot_key',
				__( 'Geçersiz yedek anahtarı.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Bucket bilgileri eksik.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$client = new B2Client( $settings );
		$bytes  = $client->get_object( $key );
		if ( $bytes instanceof WP_Error ) {
			return $bytes;
		}

		$envelope = Snapshot::decode( (string) $bytes );
		if ( null === $envelope ) {
			return new WP_Error(
				'animeh_snapshot_unreadable',
				__( 'Yedek dosyası okunamadı.', 'animeh' ),
				array( 'status' => 422 )
			);
		}

		return $envelope;
	}

	/**
	 * Write the envelope's contents over this site's tables and options.
	 *
	 * Destructive by design: a restore is a replacement, not a merge. Merging
	 * would leave two sites' ids interleaved and every foreign key ambiguous.
	 *
	 * @param array<string, mixed> $envelope Validated envelope.
	 * @return array<string, int>|WP_Error Rows written per table.
	 */
	public static function restore( array $envelope ) {
		global $wpdb;

		$problems = Snapshot::problems( $envelope );
		if ( array() !== $problems ) {
			return new WP_Error(
				'animeh_snapshot_invalid',
				__( 'Yedek doğrulanamadı.', 'animeh' ),
				array(
					'status'   => 422,
					'problems' => $problems,
				)
			);
		}

		// The schema has to exist before rows can go into it; a fresh site has
		// run activation, but a site restored onto a half-installed plugin may
		// not have.
		Schema::install();

		$written = array();

		foreach ( Snapshot::TABLES as $table ) {
			$name = $wpdb->prefix . $table;
			$rows = $envelope['tables'][ $table ];

			// Fixed table names again, and TRUNCATE resets AUTO_INCREMENT so
			// restored ids land exactly where the snapshot says they were.
			$wpdb->query( 'TRUNCATE TABLE ' . $name ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

			// A snapshot from a different plugin version may carry a column
			// this site does not have. Dropping the unknown column loses one
			// field; letting the insert fail would lose the whole row.
			$columns = self::columns( $name );

			$count = 0;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = self::scalars_only( $row, $columns );
				if ( array() === $clean ) {
					continue;
				}
				// $wpdb->insert prepares every value and quotes every column
				// name as an identifier, so nothing from the snapshot reaches
				// the query as SQL.
				$ok = $wpdb->insert( $name, $clean ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( false !== $ok ) {
					++$count;
				}
			}

			$written[ $table ] = $count;
		}

		foreach ( $envelope['options'] as $option => $value ) {
			if ( ! in_array( $option, Snapshot::OPTIONS, true ) ) {
				continue;
			}
			update_option( $option, $value, false );
		}

		return $written;
	}

	/**
	 * Point the app at whichever site is currently serving the library.
	 *
	 * This is what spares the operator from reinstalling the app after a move:
	 * the app keeps its configured address, fails over to this file when that
	 * address stops answering, and picks up the new one.
	 *
	 * @param StorageSettings $settings Bucket configuration.
	 * @param B2Client|null   $client   Reused client, when one is already open.
	 * @return true|WP_Error
	 */
	public static function write_pointer( StorageSettings $settings, ?B2Client $client = null ) {
		$client ??= new B2Client( $settings );

		$pointer = array(
			'format'    => 1,
			'api_base'  => rest_url( 'animeh/v1' ),
			'site_url'  => home_url(),
			'updated_at' => gmdate( 'c' ),
		);

		$json = wp_json_encode( $pointer );

		return $client->put_object( self::POINTER_KEY, false === $json ? '{}' : $json, 'application/json' );
	}

	/**
	 * Read the pointer, so a site can tell whether it is the current backend.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function read_pointer() {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Bucket bilgileri eksik.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$bytes = ( new B2Client( $settings ) )->get_object( self::POINTER_KEY );
		if ( $bytes instanceof WP_Error ) {
			return $bytes;
		}

		$decoded = json_decode( (string) $bytes, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Last run's outcome, for the admin screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$stored = get_option( self::STATUS_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Turn the nightly snapshot on or off.
	 *
	 * @param bool $enabled Whether to schedule it.
	 */
	public static function set_schedule( bool $enabled ): void {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( $enabled ) {
			if ( false === $next ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			}
			return;
		}

		if ( false !== $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Whether the nightly snapshot is scheduled.
	 */
	public static function is_scheduled(): bool {
		return false !== wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Delete everything but the most recent {@see self::KEEP} snapshots.
	 *
	 * @param B2Client $client Open client.
	 */
	private static function prune( B2Client $client ): void {
		$objects = $client->list_objects( self::PREFIX . '/', 500 );
		if ( $objects instanceof WP_Error ) {
			return;
		}

		$keys = array();
		foreach ( $objects as $object ) {
			$key = (string) ( $object['key'] ?? '' );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		rsort( $keys, SORT_STRING );

		foreach ( array_slice( $keys, self::KEEP ) as $key ) {
			$client->delete_object( $key );
		}
	}

	/**
	 * Remember how the last run went.
	 *
	 * @param array<string, mixed> $status Outcome.
	 */
	private static function record_status( array $status ): void {
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * The columns a table actually has.
	 *
	 * @param string $table Prefixed table name.
	 * @return string[]
	 */
	private static function columns( string $table ): array {
		global $wpdb;

		// Fixed table name, built from $wpdb->prefix and a constant list.
		$rows = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Reduce a snapshot row to values this table can actually hold.
	 *
	 * Rows arrive from JSON, where a hand-edited file could carry a nested
	 * object; `$wpdb->insert` would turn that into the string "Array".
	 *
	 * @param array<string, mixed> $row     Row from a snapshot.
	 * @param string[]             $columns Columns the table has; empty means accept any.
	 * @return array<string, scalar|null>
	 */
	private static function scalars_only( array $row, array $columns = array() ): array {
		$clean = array();
		foreach ( $row as $column => $value ) {
			if ( ! is_string( $column ) ) {
				continue;
			}
			if ( array() !== $columns && ! in_array( $column, $columns, true ) ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $column ] = $value;
			}
		}
		return $clean;
	}
}
