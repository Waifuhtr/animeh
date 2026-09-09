<?php
/**
 * Avatar frames: the files, the catalogue and who owns what.
 *
 * The files live in a plugin-owned directory under uploads, exactly as the
 * fonts do, and for the same two reasons: the media library would mean
 * loosening `upload_mimes` site-wide for everybody, and these files are only
 * ever fetched by the app.
 *
 * They are deliberately **not** in object storage. A frame's URL is written
 * into the catalogue and then into thousands of profile payloads; a presigned
 * URL expires, and a bucket that is private cannot serve one that does not.
 * The uploads directory is public, permanent and needs no configuration at
 * all — and since a phone caches each frame on disk after the first sight of
 * it, the difference in delivery is one request per frame per install.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\FrameFile;
use WP_Error;

/**
 * Reads and writes the frame catalogue.
 */
final class FrameRepository {

	/**
	 * Directory name under the uploads root.
	 */
	private const DIRECTORY = 'animeh-frames';

	/**
	 * User meta holding the frame somebody is wearing.
	 */
	public const EQUIPPED_META = 'animeh_frame';

	/**
	 * Rarities, purely cosmetic: they pick the colour of the card's edge.
	 *
	 * @var string[]
	 */
	public const RARITIES = array( 'common', 'rare', 'epic', 'legendary' );

	/**
	 * Absolute path of the frame directory.
	 */
	public static function directory(): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIRECTORY;
	}

	/**
	 * Public URL of the frame directory.
	 */
	public static function directory_url(): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . self::DIRECTORY;
	}

	/**
	 * Create the directory and stop it being browsable or executable.
	 */
	public static function ensure_directory(): bool {
		$dir = self::directory();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

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
	 * Store an uploaded frame and add it to the catalogue.
	 *
	 * @param string $tmp_path Temporary path of the upload.
	 * @param string $filename Original name, used for the slug and display.
	 * @param array{name?: string, price?: int, rarity?: string, sort_order?: int, published?: bool} $meta Catalogue fields.
	 * @return array<string, mixed>|WP_Error The stored row, or an error.
	 */
	public static function store( string $tmp_path, string $filename, array $meta = array() ) {
		global $wpdb;

		if ( ! is_readable( $tmp_path ) ) {
			return new WP_Error( 'animeh_frame_unreadable', __( 'Yüklenen dosya okunamadı.', 'animeh' ), array( 'status' => 400 ) );
		}

		$size = filesize( $tmp_path );
		if ( false === $size || $size <= 0 ) {
			return new WP_Error( 'animeh_frame_empty', __( 'Yüklenen dosya boş.', 'animeh' ), array( 'status' => 400 ) );
		}
		if ( $size > FrameFile::MAX_BYTES ) {
			return new WP_Error( 'animeh_frame_too_large', __( 'Çerçeve dosyası çok büyük.', 'animeh' ), array( 'status' => 413 ) );
		}

		$bytes = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			return new WP_Error( 'animeh_frame_unreadable', __( 'Yüklenen dosya okunamadı.', 'animeh' ), array( 'status' => 400 ) );
		}

		$info = FrameFile::inspect( $bytes );
		if ( null === $info ) {
			return new WP_Error(
				'animeh_frame_format',
				__( 'Çerçeve animasyonlu WebP, APNG veya GIF olmalı.', 'animeh' ),
				array( 'status' => 415 )
			);
		}

		$rejection = FrameFile::rejection( $info );
		if ( FrameFile::REJECT_NOT_SQUARE === $rejection ) {
			return new WP_Error(
				'animeh_frame_shape',
				sprintf(
					/* translators: 1: width in pixels, 2: height in pixels */
					__( 'Çerçeve kare olmalı; bu dosya %1$d×%2$d.', 'animeh' ),
					(int) $info['width'],
					(int) $info['height']
				),
				array( 'status' => 422 )
			);
		}
		if ( FrameFile::REJECT_OUT_OF_RANGE === $rejection ) {
			return new WP_Error(
				'animeh_frame_shape',
				sprintf(
					/* translators: 1: side length, 2: minimum, 3: maximum */
					__( 'Çerçeve %2$d ile %3$d piksel arasında olmalı; bu dosya %1$d piksel.', 'animeh' ),
					(int) $info['width'],
					FrameFile::MIN_SIDE,
					FrameFile::MAX_SIDE
				),
				array( 'status' => 422 )
			);
		}

		if ( ! self::ensure_directory() ) {
			return new WP_Error( 'animeh_frame_directory', __( 'Çerçeve klasörü oluşturulamadı.', 'animeh' ), array( 'status' => 500 ) );
		}

		$hash = hash( 'sha256', $bytes );

		// The same file uploaded twice is the same frame; hand back the row
		// that is already there rather than making a second one nobody can
		// tell apart from the first.
		$existing = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::frames() . ' WHERE sha256 = %s',
				$hash
			),
			ARRAY_A
		);
		if ( is_array( $existing ) ) {
			return $existing;
		}

		$display   = trim( (string) ( $meta['name'] ?? '' ) );
		$display   = '' !== $display ? $display : self::name_from_filename( $filename );
		$slug      = self::unique_slug( $display );
		$extension = self::extension_for( (string) $info['format'] );
		$stored    = $slug . '-' . substr( $hash, 0, 8 ) . '.' . $extension;

		$written = file_put_contents( trailingslashit( self::directory() ) . $stored, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new WP_Error( 'animeh_frame_write', __( 'Çerçeve kaydedilemedi.', 'animeh' ), array( 'status' => 500 ) );
		}

		$rarity = (string) ( $meta['rarity'] ?? 'common' );
		if ( ! in_array( $rarity, self::RARITIES, true ) ) {
			$rarity = 'common';
		}

		$row = array(
			'slug'          => $slug,
			'name'          => $display,
			'filename'      => sanitize_file_name( $filename ),
			'relative_path' => $stored,
			'mime'          => (string) $info['mime'],
			'format'        => (string) $info['format'],
			'animated'      => (bool) $info['animated'] ? 1 : 0,
			'width'         => (int) $info['width'],
			'height'        => (int) $info['height'],
			'size_bytes'    => (int) $size,
			'sha256'        => $hash,
			'price'         => max( 0, (int) ( $meta['price'] ?? 0 ) ),
			'rarity'        => $rarity,
			'sort_order'    => (int) ( $meta['sort_order'] ?? 0 ),
			'published'     => isset( $meta['published'] ) && ! $meta['published'] ? 0 : 1,
			'created_at'    => current_time( 'mysql', true ),
		);

		$inserted = $wpdb->insert( CatalogSchema::frames(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $inserted ) {
			@unlink( trailingslashit( self::directory() ) . $stored ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error( 'animeh_frame_insert', __( 'Çerçeve kaydedilemedi.', 'animeh' ), array( 'status' => 500 ) );
		}

		self::forget();

		$row['id'] = (int) $wpdb->insert_id;

		return $row;
	}

	/**
	 * The catalogue, kept for the length of one request.
	 *
	 * A friends list draws twenty people, and every one of them may be
	 * wearing a frame. Looking each one up by id would be twenty queries for
	 * a table with a few dozen rows in it, so the table is read once and the
	 * rest is array access.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $by_id = null;

	/**
	 * One frame by id, from the request's map.
	 *
	 * @param int $id Frame.
	 * @return array<string, mixed>|null
	 */
	public static function cached( int $id ): ?array {
		if ( null === self::$by_id ) {
			self::$by_id = array();
			foreach ( self::all( false ) as $frame ) {
				self::$by_id[ (int) $frame['id'] ] = $frame;
			}
		}

		return self::$by_id[ $id ] ?? null;
	}

	/**
	 * Forget the map, so a write is visible to the rest of the request.
	 */
	public static function forget(): void {
		self::$by_id = null;
	}

	/**
	 * The shape a frame takes in any payload that draws a user.
	 *
	 * `animated` is not decoration: the app uses it to decide whether a frame
	 * needs the decoder that can play it, and a still image loaded through
	 * that decoder costs more than it should on every avatar in a list.
	 *
	 * @param array<string, mixed>|null $frame Row.
	 * @return array<string, mixed>|null
	 */
	public static function payload( ?array $frame ): ?array {
		if ( null === $frame ) {
			return null;
		}

		return array(
			'id'       => (int) $frame['id'],
			'slug'     => (string) $frame['slug'],
			'name'     => (string) $frame['name'],
			'url'      => self::url( $frame ),
			'animated' => (bool) $frame['animated'],
			'rarity'   => (string) $frame['rarity'],
			'price'    => (int) $frame['price'],
		);
	}

	/**
	 * The frame a user is wearing, ready to send.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>|null
	 */
	public static function payload_for_user( int $user_id ): ?array {
		$id = (int) get_user_meta( $user_id, self::EQUIPPED_META, true );
		if ( $id <= 0 ) {
			return null;
		}

		$frame = self::cached( $id );

		// An unpublished frame stays on the profile of whoever already owns
		// it. Taking it off them would be confiscating something they paid
		// for because the shop stopped selling it.
		return self::payload( $frame );
	}

	/**
	 * The whole catalogue.
	 *
	 * @param bool $published_only Hide unpublished rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( bool $published_only = true ): array {
		global $wpdb;

		$where = $published_only ? 'WHERE published = 1' : '';
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . CatalogSchema::frames() . " {$where} ORDER BY sort_order ASC, price ASC, id ASC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One frame.
	 *
	 * @param int $id Frame.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::frames() . ' WHERE id = %d',
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Change a frame's catalogue fields. The file itself is never edited.
	 *
	 * @param int                  $id     Frame.
	 * @param array<string, mixed> $fields Subset of name, price, rarity, sort_order, published.
	 * @return bool
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;

		$allowed = array();

		if ( isset( $fields['name'] ) ) {
			$allowed['name'] = sanitize_text_field( (string) $fields['name'] );
		}
		if ( isset( $fields['price'] ) ) {
			$allowed['price'] = max( 0, (int) $fields['price'] );
		}
		if ( isset( $fields['rarity'] ) && in_array( (string) $fields['rarity'], self::RARITIES, true ) ) {
			$allowed['rarity'] = (string) $fields['rarity'];
		}
		if ( isset( $fields['sort_order'] ) ) {
			$allowed['sort_order'] = (int) $fields['sort_order'];
		}
		if ( isset( $fields['published'] ) ) {
			$allowed['published'] = $fields['published'] ? 1 : 0;
		}

		if ( array() === $allowed ) {
			return false;
		}

		$updated = false !== $wpdb->update( CatalogSchema::frames(), $allowed, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::forget();

		return $updated;
	}

	/**
	 * Remove a frame everywhere.
	 *
	 * Ownership rows go with it, and so does the meta of anyone wearing it:
	 * a profile pointing at a frame that no longer exists draws nothing and
	 * gives its owner no way to find out why.
	 *
	 * Points already spent on it are not refunded automatically — that is a
	 * decision, not a cleanup, and the panel can send them back.
	 *
	 * @param int $id Frame.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$frame = self::find( $id );
		if ( null === $frame ) {
			return false;
		}

		$wpdb->delete( CatalogSchema::user_frames(), array( 'frame_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::frames(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Every user whose equipped frame was this one, in one call.
		delete_metadata( 'user', 0, self::EQUIPPED_META, (string) $id, true );

		self::forget();

		$path = trailingslashit( self::directory() ) . (string) $frame['relative_path'];
		if ( '' !== (string) $frame['relative_path'] && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return true;
	}

	/**
	 * Public URL of a stored frame.
	 *
	 * @param array<string, mixed> $frame Row.
	 * @return string
	 */
	public static function url( array $frame ): string {
		$path = (string) ( $frame['relative_path'] ?? '' );
		if ( '' === $path ) {
			return '';
		}

		return trailingslashit( self::directory_url() ) . $path;
	}

	/**
	 * Frame ids somebody owns.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function owned_ids( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT frame_id FROM ' . CatalogSchema::user_frames() . ' WHERE user_id = %d',
				$user_id
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Whether somebody owns a frame.
	 *
	 * @param int $user_id  User.
	 * @param int $frame_id Frame.
	 * @return bool
	 */
	public static function owns( int $user_id, int $frame_id ): bool {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT COUNT(*) FROM ' . CatalogSchema::user_frames() . ' WHERE user_id = %d AND frame_id = %d',
				$user_id,
				$frame_id
			)
		) > 0;
	}

	/**
	 * Record ownership. Idempotent by unique key.
	 *
	 * @param int $user_id  User.
	 * @param int $frame_id Frame.
	 * @return bool
	 */
	public static function grant( int $user_id, int $frame_id ): bool {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );

		$written = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::user_frames(),
			array(
				'user_id'     => $user_id,
				'frame_id'    => $frame_id,
				'acquired_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);

		$wpdb->suppress_errors( $previous );

		return false !== $written;
	}

	/**
	 * The frame somebody is wearing, or null.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>|null
	 */
	public static function equipped( int $user_id ): ?array {
		$id = (int) get_user_meta( $user_id, self::EQUIPPED_META, true );
		if ( $id <= 0 ) {
			return null;
		}

		return self::find( $id );
	}

	/**
	 * Wear a frame, or none.
	 *
	 * @param int $user_id  User.
	 * @param int $frame_id Frame, or 0 to take it off.
	 * @return bool Whether the change was allowed.
	 */
	public static function equip( int $user_id, int $frame_id ): bool {
		if ( $frame_id <= 0 ) {
			delete_user_meta( $user_id, self::EQUIPPED_META );
			return true;
		}

		if ( ! self::owns( $user_id, $frame_id ) ) {
			return false;
		}

		update_user_meta( $user_id, self::EQUIPPED_META, (string) $frame_id );

		return true;
	}

	/**
	 * Drop a deleted user's frames.
	 *
	 * @param int $user_id User.
	 */
	public static function purge_user( int $user_id ): void {
		global $wpdb;

		$wpdb->delete( CatalogSchema::user_frames(), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_user_meta( $user_id, self::EQUIPPED_META );
	}

	/**
	 * A display name from a filename, when the uploader did not give one.
	 *
	 * @param string $filename Original name.
	 * @return string
	 */
	private static function name_from_filename( string $filename ): string {
		$base = pathinfo( $filename, PATHINFO_FILENAME );
		$base = str_replace( array( '-', '_' ), ' ', (string) $base );
		$base = trim( preg_replace( '/\s+/', ' ', $base ) ?? '' );

		return '' !== $base ? $base : __( 'Çerçeve', 'animeh' );
	}

	/**
	 * A slug nothing else is using.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	private static function unique_slug( string $name ): string {
		global $wpdb;

		$base = sanitize_title( $name );
		$base = '' !== $base ? $base : 'cerceve';
		$slug = $base;
		$n    = 2;

		while ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CatalogSchema::frames() . ' WHERE slug = %s', $slug ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$slug = $base . '-' . $n;
			++$n;
			if ( $n > 200 ) {
				$slug = $base . '-' . wp_generate_password( 6, false, false );
				break;
			}
		}

		return $slug;
	}

	/**
	 * File extension for a detected format.
	 *
	 * @param string $format From [FrameFile::inspect].
	 * @return string
	 */
	private static function extension_for( string $format ): string {
		switch ( $format ) {
			case 'gif':
				return 'gif';
			case 'png':
			case 'apng':
				return 'png';
			default:
				return 'webp';
		}
	}
}
