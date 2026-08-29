<?php
/**
 * Font storage.
 *
 * Fonts live in a plugin-owned directory under uploads rather than the media
 * library. Registering `.ttf`/`.otf` as site-wide uploadable mime types to get
 * them into the library would widen what every author on the site can upload,
 * for no benefit here: these files are only ever served to libass.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\FontFile;
use WP_Error;

/**
 * Reads and writes the font registry.
 */
final class FontRepository {

	/**
	 * Directory name under the uploads root.
	 */
	private const DIRECTORY = 'animeh-fonts';

	/**
	 * Absolute path of the font directory.
	 */
	public static function directory(): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIRECTORY;
	}

	/**
	 * Public URL of the font directory.
	 */
	public static function directory_url(): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . self::DIRECTORY;
	}

	/**
	 * Create the directory and stop it being browsable.
	 */
	public static function ensure_directory(): bool {
		$dir = self::directory();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// An index file blocks directory listing on servers that allow it;
		// the .htaccess denies execution should anything ever be written here
		// that Apache would otherwise be willing to run.
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "Options -Indexes\n"
				. "<FilesMatch \"\\.(php|phtml|php[0-9]|phar)$\">\n"
				. "  Require all denied\n"
				. "</FilesMatch>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return true;
	}

	/**
	 * Store an uploaded font.
	 *
	 * Validation is by content, not by extension or the browser's declared
	 * mime type: both are attacker-supplied.
	 *
	 * @param string $tmp_path Temporary path of the uploaded file.
	 * @param string $filename Original filename, used only for display.
	 * @param int    $user_id  Uploading user.
	 * @return array<string, mixed>|WP_Error The stored row, or an error.
	 */
	public static function store( string $tmp_path, string $filename, int $user_id ) {
		if ( ! is_readable( $tmp_path ) ) {
			return new WP_Error( 'animeh_font_unreadable', __( 'Yüklenen dosya okunamadı.', 'animeh' ), array( 'status' => 400 ) );
		}

		$size = filesize( $tmp_path );
		if ( false === $size || $size <= 0 ) {
			return new WP_Error( 'animeh_font_empty', __( 'Yüklenen dosya boş.', 'animeh' ), array( 'status' => 400 ) );
		}
		if ( $size > FontFile::MAX_BYTES ) {
			return new WP_Error(
				'animeh_font_too_large',
				__( 'Font dosyası çok büyük.', 'animeh' ),
				array( 'status' => 413 )
			);
		}

		$bytes = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			return new WP_Error( 'animeh_font_unreadable', __( 'Yüklenen dosya okunamadı.', 'animeh' ), array( 'status' => 400 ) );
		}

		$font = FontFile::from_string( $bytes );
		if ( null === $font ) {
			return new WP_Error(
				'animeh_font_invalid',
				__( 'Bu dosya geçerli bir font değil.', 'animeh' ),
				array( 'status' => 415 )
			);
		}

		$family = $font->family();
		if ( '' === $family ) {
			// WOFF/WOFF2 carry their names inside compressed tables we do not
			// unpack. Falling back to the filename stem keeps them usable while
			// making clear the name was not read from the file.
			$family = pathinfo( $filename, PATHINFO_FILENAME );
		}
		if ( '' === $family ) {
			return new WP_Error(
				'animeh_font_unnamed',
				__( 'Fontun aile adı okunamadı.', 'animeh' ),
				array( 'status' => 422 )
			);
		}

		$sha256   = hash( 'sha256', $bytes );
		$existing = self::find_by_hash( $sha256 );
		if ( null !== $existing ) {
			return $existing;
		}

		if ( ! self::ensure_directory() ) {
			return new WP_Error(
				'animeh_font_directory',
				__( 'Font klasörü oluşturulamadı.', 'animeh' ),
				array( 'status' => 500 )
			);
		}

		// Stored under the content hash, so the name on disk can never be
		// influenced by what was uploaded.
		$stored_name = $sha256 . '.' . self::extension_for( $font->format );
		$destination = trailingslashit( self::directory() ) . $stored_name;

		if ( false === file_put_contents( $destination, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error(
				'animeh_font_write_failed',
				__( 'Font diske yazılamadı.', 'animeh' ),
				array( 'status' => 500 )
			);
		}
		// Never executable, whatever the server's umask happens to be.
		chmod( $destination, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		global $wpdb;
		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::fonts_table(),
			array(
				'family'          => $family,
				'family_key'      => FontFile::key( $family ),
				'postscript_name' => (string) $font->postscript_name,
				'filename'        => sanitize_file_name( $filename ),
				'relative_path'   => $stored_name,
				'format'          => $font->format,
				'size_bytes'      => $size,
				'sha256'          => $sha256,
				'uploaded_by'     => $user_id,
				'created_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			// Do not leave an orphan on disk if the row could not be written.
			@unlink( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error(
				'animeh_font_insert_failed',
				__( 'Font kaydedilemedi.', 'animeh' ),
				array( 'status' => 500 )
			);
		}

		$row = self::find( (int) $wpdb->insert_id );
		return null === $row
			? new WP_Error( 'animeh_font_missing', __( 'Font kaydedildi ama okunamadı.', 'animeh' ), array( 'status' => 500 ) )
			: $row;
	}

	/**
	 * Every registered font, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT * FROM ' . Schema::fonts_table() . ' ORDER BY family ASC, id DESC',
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( self::class, 'shape' ), $rows ) : array();
	}

	/**
	 * One font by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . Schema::fonts_table() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * One font by content hash.
	 *
	 * @param string $sha256 Content hash.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_hash( string $sha256 ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . Schema::fonts_table() . ' WHERE sha256 = %s', $sha256 ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Resolve a font by the family name a subtitle asked for.
	 *
	 * @param string $family Family name.
	 * @return array<string, mixed>|null
	 */
	public static function resolve( string $family ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::fonts_table() . ' WHERE family_key = %s ORDER BY id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				FontFile::key( $family )
			),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Delete a font and its file.
	 *
	 * @param int $id Row id.
	 */
	public static function delete( int $id ): bool {
		$row = self::find( $id );
		if ( null === $row ) {
			return false;
		}

		global $wpdb;
		$deleted = $wpdb->delete( Schema::fonts_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $deleted ) {
			return false;
		}

		$path = trailingslashit( self::directory() ) . (string) $row['relative_path'];
		// Confirm the resolved path is still inside our directory before
		// unlinking: a stored value should never escape, and checking costs
		// nothing next to the consequence of being wrong.
		$real = realpath( $path );
		$base = realpath( self::directory() );
		if ( false !== $real && false !== $base && str_starts_with( $real, $base ) && is_file( $real ) ) {
			@unlink( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return true;
	}

	/**
	 * Shape a database row for the API.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'family'          => (string) $row['family'],
			'family_key'      => (string) $row['family_key'],
			'postscript_name' => (string) $row['postscript_name'],
			'filename'        => (string) $row['filename'],
			'format'          => (string) $row['format'],
			'size_bytes'      => (int) $row['size_bytes'],
			'sha256'          => (string) $row['sha256'],
			'uploaded_by'     => (int) $row['uploaded_by'],
			'created_at'      => (string) $row['created_at'],
			'url'             => trailingslashit( self::directory_url() ) . (string) $row['relative_path'],
		);
	}

	/**
	 * File extension for a detected format.
	 *
	 * @param string $format Detected format.
	 */
	private static function extension_for( string $format ): string {
		return match ( $format ) {
			'otf'   => 'otf',
			'ttc'   => 'ttc',
			'woff'  => 'woff',
			'woff2' => 'woff2',
			default => 'ttf',
		};
	}
}
