<?php
/**
 * The doujinshi gallery source.
 *
 * The second metadata source the manga site already used, ported here so that
 * a work imported from it arrives with the same tags, artist and page count it
 * has over there rather than being retyped.
 *
 * Two things it does that the other sources do not:
 *
 * **It asks where its images are.** The service moves its image hosts around
 * and publishes the current list; a cover URL built against yesterday's host
 * is a broken cover. The answer is cached for half a day, which is both what
 * the service asks for and the difference between one request and one per
 * import.
 *
 * **Everything from it is marked adult.** Not a guess and not a heuristic:
 * that is what the source is. The app then puts its usual question in front of
 * opening one, which is the whole point of the flag.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\GalleryRef;
use WP_Error;

/**
 * Reads gallery metadata.
 */
final class GalleryClient {

	/**
	 * Option holding the switch and the key.
	 */
	private const OPTION = 'animeh_gallery_source';

	/**
	 * Where it answers.
	 */
	private const BASE = 'https://nhentai.net/api/v2';

	/**
	 * Transient holding the current image host.
	 */
	private const CDN_TRANSIENT = 'animeh_gallery_cdn';

	/**
	 * How long that host is trusted for.
	 */
	private const CDN_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Used when the service will not say where its images are.
	 */
	private const CDN_FALLBACK = 'https://cdn.nhentai.net';

	/**
	 * How many calls a minute this end will make.
	 *
	 * The service rate-limits and answers 429 when pushed; being refused for
	 * an hour costs far more than waiting a second here.
	 */
	private const RATE_PER_MINUTE = 20;

	/**
	 * Settings.
	 *
	 * @return array{enabled: bool, key: string}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$key = (string) ( $stored['key'] ?? '' );
		if ( '' !== $key ) {
			$key = self::box()->open( $key );
		}

		return array(
			// Off until somebody turns it on. A source of exclusively adult
			// material is not something to have running because it shipped.
			'enabled' => (bool) ( $stored['enabled'] ?? false ),
			'key'     => $key,
		);
	}

	/**
	 * Save settings. An empty key keeps the stored one.
	 *
	 * @param array<string, mixed> $data New values.
	 * @return array{enabled: bool, key: string}
	 */
	public static function save_settings( array $data ): array {
		$current = self::settings();

		$key = array_key_exists( 'key', $data ) ? trim( (string) $data['key'] ) : '';
		if ( '' === $key ) {
			$key = $current['key'];
		}

		update_option(
			self::OPTION,
			array(
				'enabled' => (bool) ( $data['enabled'] ?? $current['enabled'] ),
				'key'     => '' === $key ? '' : self::box()->seal( $key ),
			),
			false
		);

		return self::settings();
	}

	/**
	 * Whether it is switched on.
	 */
	public static function enabled(): bool {
		return self::settings()['enabled'];
	}

	/**
	 * One gallery.
	 *
	 * @param int $id Gallery id.
	 * @return array<string, mixed>|WP_Error Normalised, with `pages` filled in.
	 */
	public function gallery( int $id ) {
		if ( $id <= 0 ) {
			return new WP_Error( 'animeh_gallery_id', __( 'Galeri numarası gerekli.', 'animeh' ), array( 'status' => 400 ) );
		}

		$body = $this->get( '/galleries/' . $id );

		if ( $body instanceof WP_Error ) {
			return $body;
		}

		if ( ! isset( $body['id'] ) ) {
			return new WP_Error( 'animeh_gallery_format', __( 'Galeri yanıtı okunamadı.', 'animeh' ), array( 'status' => 502 ) );
		}

		return $this->normalise( $body );
	}

	/**
	 * Find a gallery.
	 *
	 * The source publishes no search — `/galleries/{id}` and `/cdn` are the
	 * whole of it, which is why the manga site's own importer only ever asked
	 * for a gallery by number. Sending it a `search` request produced a
	 * failure with nothing useful in it, so this asks the question the source
	 * can answer and says plainly what it needs when the box holds something
	 * else.
	 *
	 * @param string $query A gallery number, or an address containing one.
	 * @param int    $page  Unused; kept so both sources share a signature.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function search( string $query, int $page = 1 ) {
		$id = GalleryRef::id( $query );

		if ( $id <= 0 ) {
			return new WP_Error(
				'animeh_gallery_query',
				__( 'Bu kaynakta arama yok. Galeri numarasını yaz (örnek: 177013) ya da galerinin adresini yapıştır.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$gallery = $this->gallery( $id );

		if ( $gallery instanceof WP_Error ) {
			return $gallery;
		}

		return array( $gallery );
	}

	/**
	 * The gallery, with absolute image addresses.
	 *
	 * @param array<string, mixed> $gallery Raw.
	 * @return array<string, mixed>
	 */
	private function normalise( array $gallery ): array {
		$cdn      = $this->cdn();
		$media_id = (string) ( $gallery['media_id'] ?? '' );

		$gallery['cover_image'] = $this->image_url( $cdn, $media_id, $gallery['images']['cover'] ?? null, 'cover' );

		$pages = array();
		foreach ( (array) ( $gallery['images']['pages'] ?? array() ) as $index => $page ) {
			$url = $this->image_url( $cdn, $media_id, $page, (string) ( $index + 1 ) );
			if ( '' === $url ) {
				continue;
			}

			$pages[] = array(
				'position' => $index + 1,
				'file'     => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
				'url'      => $url,
				'fallback' => '',
			);
		}

		$gallery['pages'] = $pages;

		return $gallery;
	}

	/**
	 * One image address, whichever shape the service described it in.
	 *
	 * Newer responses give a path to join to the host; older ones give a type
	 * letter and expect the caller to build the name. Both still turn up.
	 *
	 * @param string $cdn      Image host.
	 * @param string $media_id Media id, for the older shape.
	 * @param mixed  $image    The image entry.
	 * @param string $name     File stem: `cover` or a page number.
	 * @return string
	 */
	private function image_url( string $cdn, string $media_id, $image, string $name ): string {
		if ( is_string( $image ) && '' !== $image ) {
			return rtrim( $cdn, '/' ) . '/' . ltrim( $image, '/' );
		}

		if ( ! is_array( $image ) || '' === $media_id ) {
			return '';
		}

		$types = array(
			'j' => 'jpg',
			'p' => 'png',
			'g' => 'gif',
			'w' => 'webp',
		);

		$extension = $types[ (string) ( $image['t'] ?? 'j' ) ] ?? 'jpg';

		return rtrim( $cdn, '/' ) . '/galleries/' . $media_id . '/' . $name . '.' . $extension;
	}

	/**
	 * The current image host.
	 *
	 * @return string
	 */
	private function cdn(): string {
		$cached = get_transient( self::CDN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$body = $this->get( '/cdn' );

		if ( ! ( $body instanceof WP_Error ) && isset( $body[0] ) && is_string( $body[0] ) ) {
			$host = rtrim( $body[0], '/' );
			set_transient( self::CDN_TRANSIENT, $host, self::CDN_TTL );

			return $host;
		}

		return self::CDN_FALLBACK;
	}

	/**
	 * One GET.
	 *
	 * @param string               $path  Path under the base.
	 * @param array<string, mixed> $query Query parameters.
	 * @return array<mixed>|WP_Error
	 */
	private function get( string $path, array $query = array() ) {
		$settings = self::settings();

		if ( ! $settings['enabled'] ) {
			return new WP_Error(
				'animeh_gallery_off',
				__( 'Bu kaynak kapalı. Yönetim panelinden açabilirsin.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->take_token() ) {
			return new WP_Error(
				'animeh_gallery_rate',
				__( 'Bu kaynağa çok sık soruldu. Bir dakika bekle.', 'animeh' ),
				array( 'status' => 429 )
			);
		}

		$url = self::BASE . $path;
		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $settings['key'] ) {
			$headers['Authorization'] = 'Bearer ' . $settings['key'];
		}

		$args = array(
			'timeout' => 20,
			'headers' => $headers,
		);

		// A second attempt only when nothing answered: her host's first call
		// out can die resolving the name while the next one is instant. Any
		// real answer, 429 included, is taken at its word.
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'animeh_gallery_unreachable',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			return new WP_Error(
				'animeh_gallery_rate',
				__( 'Kaynak hız sınırına takıldı. Biraz bekleyip tekrar dene.', 'animeh' ),
				array( 'status' => 429 )
			);
		}

		if ( 403 === $code ) {
			return new WP_Error(
				'animeh_gallery_forbidden',
				__( 'Kaynak erişimi reddetti. Bir API anahtarı gerekiyor olabilir.', 'animeh' ),
				array( 'status' => 403 )
			);
		}

		if ( 404 === $code ) {
			return new WP_Error(
				'animeh_gallery_missing',
				__( 'Galeri bulunamadı.', 'animeh' ),
				array( 'status' => 404 )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'animeh_gallery_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'Kaynak %d döndürdü.', 'animeh' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : new WP_Error(
			'animeh_gallery_format',
			__( 'Kaynaktan gelen yanıt okunamadı.', 'animeh' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * The same box the other clients keep their keys in.
	 *
	 * Derived from the site's own salts, so a stolen database row is not a
	 * stolen key unless `wp-config.php` went with it.
	 */
	private static function box(): \Animeh\Support\SecretBox {
		$material = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '' );

		return new \Animeh\Support\SecretBox( '' === $material ? 'animeh-fallback' : $material );
	}

	/**
	 * A token from this minute's budget.
	 *
	 * @return bool
	 */
	private function take_token(): bool {
		$key   = 'animeh_gallery_rate_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $count + 1, 120 );

		return true;
	}
}
