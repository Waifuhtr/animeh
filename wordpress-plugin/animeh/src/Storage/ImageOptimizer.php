<?php
/**
 * Re-hosting hand-entered artwork at the size it is drawn at.
 *
 * The catalog holds three image addresses — a work's poster and banner, an
 * episode's cover — and all three are typed into a text field. Whatever is
 * behind them is what every phone downloads and decodes, at whatever
 * resolution the person who made it happened to export. An import from TMDB
 * arrives already sized (`w500`, `w1280`, `w300`); everything else does not.
 *
 * This closes that gap: fetch the image once, shrink it to the ceiling for
 * where it is shown, put the result in the bucket beside the anime it belongs
 * to, and point the catalog at that instead.
 *
 * Two things it deliberately will not do.
 *
 * It will not touch an image it already owns, so running it twice is free and
 * an image is never re-compressed on top of itself. And it will not run at all
 * against a private bucket: the only address a private bucket can offer is a
 * presigned one, which expires, and writing an expiring URL into a column read
 * for years is a broken image with a date on it.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\ImageResizer;
use Animeh\Support\StorageKey;

/**
 * Shrinks catalog artwork and re-hosts it in the media bucket.
 */
final class ImageOptimizer {

	/**
	 * Option holding whether saving a work or an episode optimises its images.
	 *
	 * Off by default. The bulk action is always available and is explicit;
	 * this is the one that happens without being asked, so it is opted into.
	 */
	public const OPTION = 'animeh_optimize_images';

	/**
	 * Folder the rewritten copies live in, under the anime's own prefix.
	 */
	private const FOLDER = 'artwork';

	/**
	 * Longest a fetch may take.
	 *
	 * Generous, because the source may be a slow fan site, but bounded: this
	 * runs inside a request somebody is waiting on.
	 */
	private const TIMEOUT = 20;

	/**
	 * Largest source this will download.
	 *
	 * Past this it is not artwork, and pulling it would only be a way to run
	 * the host out of memory.
	 */
	private const MAX_DOWNLOAD = 24 * 1024 * 1024;

	private StorageSettings $settings;

	private ?B2Client $client;

	public function __construct( ?StorageSettings $settings = null ) {
		$this->settings = $settings ?? StorageSettings::load();
		$this->client   = $this->settings->is_configured() ? new B2Client( $this->settings ) : null;
	}

	/**
	 * Whether saving something should optimise its images.
	 */
	public static function is_automatic(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Turn the automatic behaviour on or off.
	 *
	 * @param bool $on Whether to optimise on save.
	 */
	public static function set_automatic( bool $on ): void {
		update_option( self::OPTION, $on, false );
	}

	/**
	 * Why this cannot run, or an empty string when it can.
	 *
	 * Returned as a sentence rather than a boolean because every one of these
	 * is something an operator has to go and change, and "it did nothing" is
	 * the least useful thing a maintenance action can say.
	 */
	public function blocker(): string {
		if ( ! ImageResizer::available() ) {
			return __( 'Bu sunucudaki PHP\'de GD eklentisi yok, görseller küçültülemiyor. Hosting panelinden GD\'yi açman gerekiyor.', 'animeh' );
		}
		if ( null === $this->client ) {
			return __( 'Depolama ayarları eksik. Küçültülen görsellerin konacağı bir bucket yok.', 'animeh' );
		}
		if ( ! $this->settings->public_bucket ) {
			return __( 'Bucket herkese açık değil. Özel bir bucket yalnızca süreli adres verebilir, kapak adresi ise kalıcı olmak zorunda — Depolama ayarlarından "herkese açık" seçeneğini açman gerekiyor.', 'animeh' );
		}

		return '';
	}

	/**
	 * Whether one address is already a copy this made.
	 *
	 * @param string $url Stored image address.
	 */
	public function is_ours( string $url ): bool {
		if ( '' === $url ) {
			return true;
		}

		// The folder name is the marker rather than the host: a site that has
		// moved its CDN in front of the same bucket still owns these objects.
		return str_contains( $url, '/' . self::FOLDER . '/' );
	}

	/**
	 * Fetch, shrink and re-host one image.
	 *
	 * @param string $url  Current address.
	 * @param string $slug Anime slug, deciding where the copy is filed.
	 * @param string $role One of the roles {@see ImageResizer::ROLES} names.
	 * @return string|null The new address, or null when nothing changed.
	 */
	public function rewrite( string $url, string $slug, string $role ): ?string {
		$url = trim( $url );

		if ( '' === $url || $this->is_ours( $url ) || '' !== $this->blocker() ) {
			return null;
		}
		if ( ! str_starts_with( $url, 'http://' ) && ! str_starts_with( $url, 'https://' ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'Animeh/1.0 (+image-optimiser)',
				// A redirect is normal for artwork hosts; a chain of them is
				// somebody being clever, and two is enough for anyone.
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$bytes = (string) wp_remote_retrieve_body( $response );

		if ( '' === $bytes || strlen( $bytes ) > self::MAX_DOWNLOAD ) {
			return null;
		}

		$smaller = ImageResizer::shrink( $bytes, $role );

		// Null means the image was already the right size, which is a result
		// and not a failure: there is nothing to gain by re-hosting it.
		if ( null === $smaller ) {
			return null;
		}

		// Named after what it is and what it was. The hash makes the key
		// stable for a given source — so the same poster pasted onto two
		// works costs one object — and makes a replaced poster a new object
		// rather than one hiding behind a cached copy of the old one.
		$key = sprintf(
			'%s/%s/%s-%s.jpg',
			StorageKey::anime_prefix( $slug ),
			self::FOLDER,
			preg_replace( '/[^a-z]/', '', $role ) ?: 'image',
			substr( sha1( $url ), 0, 12 )
		);

		$put = $this->client?->put_object( $key, $smaller, 'image/jpeg' );

		if ( null === $put || is_wp_error( $put ) ) {
			return null;
		}

		$friendly = $this->settings->friendly_url( $key );

		return '' !== $friendly ? $friendly : $this->settings->s3_url( $key );
	}

	/**
	 * Optimise a work's poster and banner.
	 *
	 * @param array<string, mixed> $work Work row.
	 * @return array<string, string> Columns that changed, ready for a save.
	 */
	public function work_changes( array $work ): array {
		$slug    = (string) ( $work['slug'] ?? '' );
		$slug    = '' !== $slug ? $slug : StorageKey::slug( (string) ( $work['title'] ?? '' ), (int) ( $work['id'] ?? 0 ) );
		$changes = array();

		foreach ( array( 'poster_url' => 'poster', 'banner_url' => 'banner' ) as $column => $role ) {
			$rewritten = $this->rewrite( (string) ( $work[ $column ] ?? '' ), $slug, $role );

			if ( null !== $rewritten ) {
				$changes[ $column ] = $rewritten;
			}
		}

		return $changes;
	}

	/**
	 * Optimise an episode's cover.
	 *
	 * @param array<string, mixed> $episode Episode row.
	 * @param string               $slug    Slug of the work it belongs to.
	 * @return array<string, string> Columns that changed.
	 */
	public function episode_changes( array $episode, string $slug ): array {
		$rewritten = $this->rewrite( (string) ( $episode['thumbnail_url'] ?? '' ), $slug, 'still' );

		return null === $rewritten ? array() : array( 'thumbnail_url' => $rewritten );
	}

	/**
	 * Walk the catalog, a slice at a time.
	 *
	 * A slice rather than the lot, because every image is a download, a
	 * resize and an upload, and a site with a hundred series would spend
	 * minutes inside one request and be killed halfway by the host. The
	 * caller repeats with the cursor it gets back until `done` is true, which
	 * also gives the panel something honest to draw a progress bar from.
	 *
	 * @param int $cursor Work id to resume after; 0 to start.
	 * @param int $limit  How many works to process this call.
	 * @return array{done: bool, cursor: int, works: int, images: int, remaining: int}
	 */
	public function optimise_batch( int $cursor = 0, int $limit = 3 ): array {
		global $wpdb;

		$catalog = new CatalogRepository();
		$table   = CatalogSchema::works();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				array( max( 0, $cursor ), max( 1, $limit ) )
			),
			ARRAY_A
		);

		$rows      = is_array( $rows ) ? $rows : array();
		$images    = 0;
		$last      = $cursor;

		foreach ( $rows as $work ) {
			$id   = (int) $work['id'];
			$last = $id;
			$slug = (string) $work['slug'];

			$changes = $this->work_changes( $work );
			if ( array() !== $changes ) {
				$images += count( $changes );
				$catalog->save_work( $changes, $id );
			}

			foreach ( $catalog->episodes( $id, 0, true ) as $episode ) {
				$episode_changes = $this->episode_changes( $episode, $slug );

				if ( array() !== $episode_changes ) {
					++$images;
					$catalog->save_episode( $id, $episode_changes, (int) $episode['id'] );
				}
			}
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id > %d", $last ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		return array(
			'done'      => count( $rows ) < max( 1, $limit ) || 0 === $remaining,
			'cursor'    => $last,
			'works'     => count( $rows ),
			'images'    => $images,
			'remaining' => $remaining,
		);
	}
}
