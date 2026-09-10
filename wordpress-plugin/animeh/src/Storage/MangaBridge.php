<?php
/**
 * The manga site, read from here.
 *
 * The library already exists: hundreds of chapters, tens of thousands of
 * images, all of it sitting in a WordPress install with its own post types and
 * its own storage settings. Nobody is re-entering that by hand.
 *
 * So a small companion plugin — `animeh-manga-bridge`, installed over there —
 * exposes it as JSON, and this reads it. Two decisions worth stating:
 *
 * **The other end resolves the image addresses, not this one.** Where a
 * chapter's images live is worked out over there from three settings and two
 * compatibility flags. Copying that logic here would mean keeping the copy
 * correct forever against a site that changes without telling us.
 *
 * **A key, not a login.** The bridge reads and never writes, so the worst a
 * leaked key can do is let somebody list manga that are already public on the
 * site itself. That is a proportionate amount of security for what it guards,
 * and it means no password of hers is stored here.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use WP_Error;

/**
 * Talks to the bridge plugin on the manga site.
 */
final class MangaBridge {

	/**
	 * Option holding the address and the key.
	 */
	private const OPTION = 'animeh_manga_bridge';

	/**
	 * Header the bridge expects.
	 */
	private const HEADER = 'X-Animeh-Bridge-Key';

	/**
	 * Longest a single bridge request may take.
	 *
	 * Her host is shared, and its reverse proxy gives up somewhere around
	 * thirty seconds. Twenty leaves room for this end to write a useful error
	 * rather than being cut off mid-sentence.
	 */
	private const TIMEOUT = 20;

	/**
	 * Stored settings.
	 *
	 * @return array{url: string, key: string, connected_at: string, site: string}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'url'          => rtrim( (string) ( $stored['url'] ?? '' ), '/' ),
			'key'          => (string) ( $stored['key'] ?? '' ),
			'connected_at' => (string) ( $stored['connected_at'] ?? '' ),
			'site'         => (string) ( $stored['site'] ?? '' ),
		);
	}

	/**
	 * Whether there is something to talk to.
	 */
	public static function configured(): bool {
		$settings = self::settings();

		return '' !== $settings['url'] && '' !== $settings['key'];
	}

	/**
	 * Store the address and key.
	 *
	 * An empty key leaves the stored one alone, so the panel can submit the
	 * form without ever having been sent the secret.
	 *
	 * @param string $url Bridge base URL.
	 * @param string $key Bridge key, or empty to keep the current one.
	 * @return array{url: string, key: string, connected_at: string, site: string}
	 */
	public static function save( string $url, string $key ): array {
		$current = self::settings();

		$settings = array(
			'url'          => rtrim( trim( $url ), '/' ),
			'key'          => '' !== trim( $key ) ? trim( $key ) : $current['key'],
			'connected_at' => $current['connected_at'],
			'site'         => $current['site'],
		);

		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	/**
	 * Say hello, and remember that it worked.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function ping() {
		$result = self::get( '/ping' );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$settings                 = self::settings();
		$settings['connected_at'] = current_time( 'mysql', true );
		$settings['site']         = (string) ( $result['site'] ?? '' );
		update_option( self::OPTION, $settings, false );

		return $result;
	}

	/**
	 * A page of manga.
	 *
	 * @param int    $page     One-based.
	 * @param int    $per_page How many.
	 * @param string $after    Only rows modified after this GMT datetime.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function manga( int $page, int $per_page = 10, string $after = '' ) {
		return self::get(
			'/manga',
			array(
				'page'           => max( 1, $page ),
				'per_page'       => max( 1, min( 50, $per_page ) ),
				'modified_after' => $after,
			)
		);
	}

	/**
	 * A page of one manga's chapters.
	 *
	 * @param int $manga_id Manga, as the other site numbers them.
	 * @param int $page     One-based.
	 * @param int $per_page How many.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function chapters( int $manga_id, int $page, int $per_page = 20 ) {
		return self::get(
			'/manga/' . $manga_id . '/chapters',
			array(
				'page'     => max( 1, $page ),
				'per_page' => max( 1, min( 50, $per_page ) ),
			)
		);
	}

	/**
	 * One GET against the bridge.
	 *
	 * @param string               $path  Path under the namespace.
	 * @param array<string, mixed> $query Query parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get( string $path, array $query = array() ) {
		$settings = self::settings();

		if ( ! self::configured() ) {
			return new WP_Error(
				'animeh_bridge_unset',
				__( 'Manga köprüsü henüz ayarlanmadı.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$url = $settings['url'] . $path;
		if ( array() !== $query ) {
			$url = add_query_arg( array_filter( $query, static fn( $value ): bool => '' !== $value && null !== $value ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Accept'     => 'application/json',
					self::HEADER => $settings['key'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'animeh_bridge_unreachable',
				sprintf(
					/* translators: %s: the transport's own message */
					__( 'Manga sitesine ulaşılamadı: %s', 'animeh' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'animeh_bridge_key',
				__( 'Köprü anahtarı kabul edilmedi. Manga sitesindeki Ayarlar → Animeh Köprüsü ekranından kopyala.', 'animeh' ),
				array( 'status' => 401 )
			);
		}

		if ( 404 === $code ) {
			return new WP_Error(
				'animeh_bridge_missing',
				__( 'Köprü adresi yanıt vermiyor. Manga sitesinde eklenti etkin mi?', 'animeh' ),
				array( 'status' => 404 )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'animeh_bridge_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'Manga sitesi %d döndürdü.', 'animeh' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'animeh_bridge_format',
				__( 'Manga sitesinden gelen yanıt okunamadı.', 'animeh' ),
				array( 'status' => 502 )
			);
		}

		return $body;
	}
}
