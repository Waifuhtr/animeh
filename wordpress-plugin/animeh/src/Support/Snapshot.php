<?php
/**
 * The snapshot envelope: what a backup contains and what makes one trustworthy.
 *
 * A snapshot is the plugin's own tables and options, serialised into one JSON
 * document that a fresh installation can read without knowing anything about
 * the site it came from. That last part is the whole design constraint: the
 * recovery case is a host that no longer answers, so nothing here may depend on
 * the origin site being reachable, on its table prefix, or on its uploads
 * directory.
 *
 * Two rules follow from that and are enforced below rather than by convention:
 *
 * - Table names are stored *unprefixed*. A snapshot from `wp_` restores onto
 *   `wp_abc123_` because the reader re-applies the local prefix.
 * - Storage credentials are never included. A snapshot lives in the bucket, so
 *   putting the bucket's keys inside it would mean one leaked object hands over
 *   everything; and a site restoring from a bucket necessarily already has the
 *   credentials to read it.
 *
 * Free of any WordPress dependency, so the envelope rules are tested directly.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Builds, checks and describes snapshot envelopes.
 */
final class Snapshot {

	/**
	 * Envelope format.
	 *
	 * Read by a plugin that may be older than the snapshot it is handed, which
	 * is the case this number exists for: an older reader must refuse a newer
	 * envelope loudly instead of restoring part of it.
	 */
	public const FORMAT = 1;

	/**
	 * Tables carried in a snapshot, without the site's prefix.
	 *
	 * @var string[]
	 */
	public const TABLES = array( 'animeh_fonts', 'animeh_test_sessions' );

	/**
	 * Options carried in a snapshot.
	 *
	 * Deliberately enumerated rather than matched by prefix. A wildcard over
	 * `animeh_%` would eventually sweep up the storage credentials, which must
	 * never travel inside an object stored in the bucket those credentials
	 * open. It would also carry `animeh_schema_version`, and importing that
	 * from another site is how a restore ends up suppressing the table upgrade
	 * it needs.
	 *
	 * @var string[]
	 */
	public const OPTIONS = array( 'animeh_test_presets', 'animeh_settings' );

	/**
	 * Options that must never appear in a snapshot, whatever the caller passes.
	 *
	 * `animeh_storage` holds the bucket credentials. `animeh_migration_handoff`
	 * holds a live pairing-code hash, which belongs to one site and one move.
	 *
	 * @var string[]
	 */
	public const EXCLUDED_OPTIONS = array( 'animeh_storage', 'animeh_migration_handoff' );

	/**
	 * Assemble an envelope around already-read table rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $tables  Rows keyed by unprefixed table name.
	 * @param array<string, mixed>                            $options Option values keyed by name.
	 * @param array<string, mixed>                            $meta    Origin details: site_url, plugin_version, generated_by.
	 * @return array<string, mixed>
	 */
	public static function build( array $tables, array $options, array $meta = array() ): array {
		$clean_tables = array();
		foreach ( self::TABLES as $table ) {
			$rows                   = $tables[ $table ] ?? array();
			$clean_tables[ $table ] = array_values( $rows );
		}

		$clean_options = array();
		foreach ( $options as $name => $value ) {
			if ( in_array( $name, self::EXCLUDED_OPTIONS, true ) ) {
				continue;
			}
			if ( ! in_array( $name, self::OPTIONS, true ) ) {
				continue;
			}
			$clean_options[ $name ] = $value;
		}
		ksort( $clean_options );

		$envelope = array(
			'format'     => self::FORMAT,
			'created_at' => gmdate( 'c', isset( $meta['created_at'] ) ? (int) $meta['created_at'] : time() ),
			'origin'     => array(
				'site_url'       => (string) ( $meta['site_url'] ?? '' ),
				'plugin_version' => (string) ( $meta['plugin_version'] ?? '' ),
				'generated_by'   => (string) ( $meta['generated_by'] ?? '' ),
			),
			'tables'     => $clean_tables,
			'options'    => $clean_options,
		);

		$envelope['checksum'] = self::checksum( $envelope );

		return $envelope;
	}

	/**
	 * Fingerprint of an envelope's contents.
	 *
	 * Computed over a canonical serialisation — keys sorted at every depth — so
	 * the same data hashes the same regardless of the order PHP happened to
	 * build the arrays in. Without that, a checksum would fail on a snapshot
	 * that is byte-for-byte equivalent.
	 *
	 * @param array<string, mixed> $envelope Envelope, with or without a checksum.
	 */
	public static function checksum( array $envelope ): string {
		unset( $envelope['checksum'] );
		return hash( 'sha256', self::canonical( $envelope ) );
	}

	/**
	 * Reasons an envelope cannot be restored.
	 *
	 * Machine-readable rather than prose: the REST layer turns these into
	 * translated sentences, and the tests assert on them.
	 *
	 * @param mixed $envelope Decoded snapshot, of any shape.
	 * @return string[]
	 */
	public static function problems( $envelope ): array {
		$problems = array();

		if ( ! is_array( $envelope ) ) {
			return array( 'not_an_object' );
		}

		if ( ! isset( $envelope['format'] ) || ! is_int( $envelope['format'] ) ) {
			$problems[] = 'missing_format';
		} elseif ( $envelope['format'] > self::FORMAT ) {
			// Written by a newer plugin. Restoring what we recognise and
			// dropping the rest would silently lose data.
			$problems[] = 'format_too_new';
		}

		if ( ! isset( $envelope['tables'] ) || ! is_array( $envelope['tables'] ) ) {
			$problems[] = 'missing_tables';
		} else {
			foreach ( self::TABLES as $table ) {
				if ( ! isset( $envelope['tables'][ $table ] ) || ! is_array( $envelope['tables'][ $table ] ) ) {
					$problems[] = 'missing_table:' . $table;
				}
			}
		}

		if ( isset( $envelope['options'] ) && ! is_array( $envelope['options'] ) ) {
			$problems[] = 'malformed_options';
		}

		foreach ( self::EXCLUDED_OPTIONS as $name ) {
			if ( isset( $envelope['options'][ $name ] ) ) {
				// Either a hand-edited file or a snapshot from something that
				// is not this plugin. Refuse rather than import credentials of
				// unknown origin.
				$problems[] = 'forbidden_option:' . $name;
			}
		}

		if ( ! isset( $envelope['checksum'] ) || ! is_string( $envelope['checksum'] ) ) {
			$problems[] = 'missing_checksum';
		} elseif ( ! hash_equals( self::checksum( $envelope ), $envelope['checksum'] ) ) {
			$problems[] = 'checksum_mismatch';
		}

		return $problems;
	}

	/**
	 * Whether an envelope is safe to restore.
	 *
	 * @param mixed $envelope Decoded snapshot.
	 */
	public static function is_valid( $envelope ): bool {
		return array() === self::problems( $envelope );
	}

	/**
	 * What an operator needs to see before agreeing to overwrite a site.
	 *
	 * @param mixed $envelope Decoded snapshot.
	 * @return array<string, mixed>
	 */
	public static function summarise( $envelope ): array {
		$problems = self::problems( $envelope );
		$summary  = array(
			'valid'      => array() === $problems,
			'problems'   => $problems,
			'format'     => is_array( $envelope ) && isset( $envelope['format'] ) ? $envelope['format'] : null,
			'created_at' => is_array( $envelope ) && isset( $envelope['created_at'] ) ? (string) $envelope['created_at'] : '',
			'origin'     => is_array( $envelope ) && isset( $envelope['origin'] ) && is_array( $envelope['origin'] ) ? $envelope['origin'] : array(),
			'counts'     => array(),
		);

		foreach ( self::TABLES as $table ) {
			$rows                       = is_array( $envelope ) && isset( $envelope['tables'][ $table ] ) && is_array( $envelope['tables'][ $table ] ) ? $envelope['tables'][ $table ] : array();
			$summary['counts'][ $table ] = count( $rows );
		}

		return $summary;
	}

	/**
	 * Serialise an envelope for storage.
	 *
	 * Gzipped when the extension is there, because the bulk of a snapshot is
	 * repetitive JSON — event logs and font reports — and compresses by an
	 * order of magnitude. The result is self-describing: `decode()` looks at
	 * the gzip magic number rather than trusting a file extension, so a
	 * snapshot written by a host with zlib restores on one without.
	 *
	 * @param array<string, mixed> $envelope Envelope to serialise.
	 */
	public static function encode( array $envelope ): string {
		$json = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( false === $json ) {
			return '';
		}

		if ( function_exists( 'gzencode' ) ) {
			$packed = gzencode( $json, 6 );
			if ( false !== $packed ) {
				return $packed;
			}
		}

		return $json;
	}

	/**
	 * Read back whatever `encode()` produced.
	 *
	 * @param string $bytes Stored snapshot bytes.
	 * @return array<string, mixed>|null Null when the bytes are not a snapshot at all.
	 */
	public static function decode( string $bytes ): ?array {
		if ( '' === $bytes ) {
			return null;
		}

		// Gzip's two magic bytes. Checked rather than assumed, so a snapshot
		// downloaded and unpacked by hand still restores.
		if ( "\x1f\x8b" === substr( $bytes, 0, 2 ) && function_exists( 'gzdecode' ) ) {
			// Silenced deliberately: a truncated or corrupt snapshot is an
			// expected input here — it is what the checksum and the null return
			// exist to catch — and it should not fill a site's error log on the
			// way to being reported properly.
			$plain = @gzdecode( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $plain ) {
				return null;
			}
			$bytes = $plain;
		}

		$decoded = json_decode( $bytes, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Serialise deterministically: sorted keys, no incidental ordering.
	 *
	 * Plain `json_encode`, not `wp_json_encode`, because this class runs in the
	 * test harness with no WordPress loaded. The flags matter: escaping differs
	 * between PHP builds otherwise, and a checksum that depends on the build is
	 * not a checksum.
	 *
	 * @param mixed $value Any JSON-encodable value.
	 */
	private static function canonical( $value ): string {
		$json = json_encode( self::sort_keys( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return false === $json ? '' : $json;
	}

	/**
	 * Recursively sort associative array keys, leaving lists in order.
	 *
	 * @param mixed $value Any value.
	 * @return mixed
	 */
	private static function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$sorted = array();
		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = self::sort_keys( $item );
		}

		// Row lists keep their order — it is data. Only object keys, whose
		// order is meaningless, are normalised.
		if ( ! self::is_list( $sorted ) ) {
			ksort( $sorted );
		}

		return $sorted;
	}

	/**
	 * Whether an array is a plain 0..n list.
	 *
	 * Hand-rolled because the plugin supports PHP 8.0, where `array_is_list()`
	 * does not exist yet.
	 *
	 * @param array<mixed> $value Array to inspect.
	 */
	private static function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}
}
