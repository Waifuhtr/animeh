<?php
/**
 * The TMDB client, and the cache in front of it.
 *
 * Same shape as TenraiClient and for the same reasons: the app never talks to
 * TMDB directly, the key never leaves this machine, and repeated lookups are
 * answered from cache. The two are complementary rather than alternatives —
 * Tenrai knows anime (seasons, fillers, the numbering fans use), TMDB has the
 * artwork and translated synopses, which is what a Turkish catalog is short of.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\TmdbMapper;
use WP_Error;

/**
 * Fetches and caches TMDB metadata.
 */
final class TmdbClient {

	/**
	 * Option holding the TMDB configuration.
	 */
	private const OPTION = 'animeh_tmdb';

	/**
	 * API base. Fixed rather than configurable: unlike Tenrai there is one
	 * host, and a typo here would be a silent outage.
	 */
	public const BASE = 'https://api.themoviedb.org/3';

	/**
	 * Language to ask for, which is the point of the integration.
	 */
	public const DEFAULT_LANGUAGE = 'tr-TR';

	/**
	 * Cache lifetimes per endpoint family, in seconds.
	 *
	 * @var array<string, int>
	 */
	private const TTL = array(
		'configuration' => 604800,
		'search'        => 1800,
		'season'        => 21600,
		'tv'            => 21600,
		'default'       => 3600,
	);

	/**
	 * Longest a stale entry may be served while TMDB is unreachable.
	 */
	private const STALE_GRACE = 604800;

	/**
	 * Requests per minute this plugin will make.
	 *
	 * TMDB's own limit is far higher, but an import loop that fills a hundred
	 * episodes should not be able to spend it all in one burst either.
	 */
	private const RATE_PER_MINUTE = 120;

	/**
	 * Current configuration.
	 *
	 * @return array{key: string, language: string, enabled: bool}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$key = (string) ( $stored['key'] ?? '' );
		if ( '' !== $key ) {
			$key = self::box()->open( $key );
		}

		return array(
			'key'      => $key,
			'language' => (string) ( $stored['language'] ?? self::DEFAULT_LANGUAGE ),
			'enabled'  => (bool) ( $stored['enabled'] ?? true ),
		);
	}

	/**
	 * Save configuration. An empty key keeps the stored one.
	 *
	 * @param array<string, mixed> $data New values.
	 * @return array{key: string, language: string, enabled: bool}
	 */
	public static function save_settings( array $data ): array {
		$current = self::settings();

		$key = trim( (string) ( $data['key'] ?? '' ) );
		if ( '' === $key ) {
			$key = $current['key'];
		}

		$language = trim( (string) ( $data['language'] ?? '' ) );
		if ( '' === $language ) {
			$language = $current['language'];
		}

		update_option(
			self::OPTION,
			array(
				// Encrypted at rest for the same reason as every other
				// credential here: the database is not where one belongs in
				// plain text.
				'key'      => '' === $key ? '' : self::box()->seal( $key ),
				'language' => $language,
				'enabled'  => ! empty( $data['enabled'] ),
			),
			false
		);

		return self::settings();
	}

	/**
	 * The shape safe to send to an admin client: never the key itself.
	 *
	 * @return array<string, mixed>
	 */
	public static function public_settings(): array {
		$settings = self::settings();

		return array(
			'has_key'    => '' !== $settings['key'],
			'key_masked' => \Animeh\Support\SecretBox::mask( $settings['key'] ),
			'language'   => $settings['language'],
			'enabled'    => $settings['enabled'],
			'image_base' => self::image_base(),
		);
	}

	/**
	 * Whether a lookup can be attempted at all.
	 */
	public static function configured(): bool {
		$settings = self::settings();

		return $settings['enabled'] && '' !== $settings['key'];
	}

	/**
	 * GET one TMDB path, through the cache.
	 *
	 * @param string               $path  Path under the base, e.g. "tv/1399".
	 * @param array<string, mixed> $query Query parameters.
	 * @param bool                 $fresh Skip the cache.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( string $path, array $query = array(), bool $fresh = false ) {
		$settings = self::settings();

		if ( ! $settings['enabled'] ) {
			return new WP_Error( 'TMDB_ERROR', __( 'TMDB entegrasyonu kapalı.', 'animeh' ), array( 'status' => 503 ) );
		}

		if ( '' === $settings['key'] ) {
			return new WP_Error( 'TMDB_ERROR', __( 'TMDB API anahtarı girilmemiş.', 'animeh' ), array( 'status' => 400 ) );
		}

		$path = ltrim( $path, '/' );

		// Language is part of the request and so part of the cache key: the
		// same show in two languages is two different answers.
		if ( ! isset( $query['language'] ) ) {
			$query['language'] = $settings['language'];
		}

		$cache_key = $this->cache_key( $path, $query );
		$cached    = get_transient( $cache_key );

		if ( ! $fresh && is_array( $cached ) && isset( $cached['payload'] ) ) {
			return $cached['payload'];
		}

		if ( ! $this->take_rate_token() ) {
			$stale = $this->stale( $cache_key );
			if ( null !== $stale ) {
				return $stale;
			}

			return new WP_Error(
				'TMDB_ERROR',
				__( 'TMDB istek sınırına ulaşıldı, biraz sonra tekrar dene.', 'animeh' ),
				array( 'status' => 429 )
			);
		}

		$headers = array( 'Accept' => 'application/json' );

		// TMDB takes either a v3 key in the query string or a v4 read token in
		// the Authorization header. Which one was pasted is decided by its
		// shape rather than by asking, because the settings screen asking
		// "which kind of key is this?" would be a question about TMDB's
		// history, not about this site.
		if ( self::is_bearer_token( $settings['key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $settings['key'];
		} else {
			$query['api_key'] = $settings['key'];
		}

		$url = self::BASE . '/' . $path;
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 2,
				'headers'     => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$stale = $this->stale( $cache_key );
			if ( null !== $stale ) {
				return $stale;
			}

			( new LogRepository() )->error( 'TMDB_ERROR', $response->get_error_message(), array( 'path' => $path ) );

			return new WP_Error( 'TMDB_ERROR', __( 'TMDB sunucusuna ulaşılamadı.', 'animeh' ), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$stale = $this->stale( $cache_key );
			if ( null !== $stale && $status >= 500 ) {
				return $stale;
			}

			( new LogRepository() )->error(
				'TMDB_ERROR',
				'TMDB ' . $status,
				array( 'path' => $path, 'status' => $status )
			);

			if ( 401 === $status ) {
				return new WP_Error(
					'TMDB_ERROR',
					__( 'TMDB anahtarı kabul edilmedi. Ayarlardan kontrol et.', 'animeh' ),
					array( 'status' => 400 )
				);
			}

			return new WP_Error(
				'TMDB_ERROR',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'TMDB %d yanıtı döndü.', 'animeh' ),
					$status
				),
				array( 'status' => 404 === $status ? 404 : 502 )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'TMDB_ERROR', __( 'TMDB yanıtı okunamadı.', 'animeh' ), array( 'status' => 502 ) );
		}

		$this->store( $cache_key, $decoded, $this->ttl_for( $path ) );

		return $decoded;
	}

	/**
	 * Search TV shows by title.
	 *
	 * @param string $query Search text.
	 * @param int    $year  First-air year, or zero.
	 * @param int    $page  Page number.
	 * @return array<string, mixed>|WP_Error
	 */
	public function search_tv( string $query, int $year = 0, int $page = 1 ) {
		$params = array(
			'query'         => $query,
			'page'          => max( 1, $page ),
			'include_adult' => 'false',
		);

		if ( $year > 0 ) {
			$params['first_air_date_year'] = $year;
		}

		return $this->get( 'search/tv', $params );
	}

	/**
	 * Full details for one show.
	 *
	 * @param int $id TMDB TV id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function tv( int $id ) {
		return $this->get( 'tv/' . $id );
	}

	/**
	 * One season, with its episode list.
	 *
	 * @param int $id     TMDB TV id.
	 * @param int $season Season number.
	 * @return array<string, mixed>|WP_Error
	 */
	public function season( int $id, int $season ) {
		return $this->get( 'tv/' . $id . '/season/' . $season );
	}

	/**
	 * Where TMDB currently serves images from.
	 *
	 * Read from /configuration and cached for a week, as TMDB asks, with the
	 * documented base as the fallback so a failed configuration call degrades
	 * to working images rather than to none.
	 */
	public static function image_base(): string {
		$cached = get_transient( 'animeh_tmdb_image_base' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( ! self::configured() ) {
			return TmdbMapper::IMAGE_BASE;
		}

		$config = ( new self() )->get( 'configuration' );
		if ( $config instanceof WP_Error ) {
			return TmdbMapper::IMAGE_BASE;
		}

		$base = (string) ( $config['images']['secure_base_url'] ?? '' );
		if ( '' === $base ) {
			return TmdbMapper::IMAGE_BASE;
		}

		set_transient( 'animeh_tmdb_image_base', $base, self::TTL['configuration'] );

		return $base;
	}

	/**
	 * Whether a pasted credential is a v4 read token rather than a v3 key.
	 *
	 * v4 tokens are JWTs: three dot-separated base64 segments. v3 keys are 32
	 * hexadecimal characters with no dots at all, so the two cannot be
	 * confused by accident.
	 *
	 * @param string $key The credential.
	 */
	public static function is_bearer_token( string $key ): bool {
		return 2 === substr_count( trim( $key ), '.' ) && str_starts_with( trim( $key ), 'ey' );
	}

	/**
	 * Wipe the cached responses.
	 */
	public static function flush_cache(): int {
		global $wpdb;

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_animeh_tmdb_%' OR option_name LIKE '_transient_timeout_animeh_tmdb_%'"
		);

		delete_transient( 'animeh_tmdb_image_base' );
		wp_cache_flush();

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Cache key for a request.
	 *
	 * @param string               $path  Path.
	 * @param array<string, mixed> $query Query.
	 */
	private function cache_key( string $path, array $query ): string {
		// The key never goes into the cache key: it is a credential, and a
		// hash of it in an option name is a needless copy.
		unset( $query['api_key'] );
		ksort( $query );

		return 'animeh_tmdb_' . substr( hash( 'sha256', $path . '|' . wp_json_encode( $query ) ), 0, 40 );
	}

	/**
	 * How long a response for this path stays fresh.
	 *
	 * @param string $path Request path.
	 */
	private function ttl_for( string $path ): int {
		foreach ( self::TTL as $prefix => $ttl ) {
			if ( 'default' !== $prefix && str_contains( $path, $prefix ) ) {
				return $ttl;
			}
		}

		return self::TTL['default'];
	}

	/**
	 * Write a cache entry, keeping a longer-lived stale copy beside it.
	 *
	 * @param string               $key     Cache key.
	 * @param array<string, mixed> $payload Response.
	 * @param int                  $ttl     Freshness in seconds.
	 */
	private function store( string $key, array $payload, int $ttl ): void {
		$entry = array(
			'payload' => $payload,
			'fetched' => time(),
		);

		set_transient( $key, $entry, $ttl );
		set_transient( $key . '_stale', $entry, self::STALE_GRACE );
	}

	/**
	 * The stale copy, when one is still around.
	 *
	 * @param string $key Cache key.
	 * @return array<string, mixed>|null
	 */
	private function stale( string $key ): ?array {
		$entry = get_transient( $key . '_stale' );

		return is_array( $entry ) && isset( $entry['payload'] ) && is_array( $entry['payload'] )
			? $entry['payload']
			: null;
	}

	/**
	 * Spend one request against this minute's budget.
	 */
	private function take_rate_token(): bool {
		$key  = 'animeh_tmdb_rate_' . (int) floor( time() / 60 );
		$used = (int) get_transient( $key );

		if ( $used >= self::RATE_PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $used + 1, 120 );

		return true;
	}

	/**
	 * Encryption bound to this installation's salts.
	 */
	private static function box(): \Animeh\Support\SecretBox {
		$material = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '' );

		return new \Animeh\Support\SecretBox( '' === $material ? 'animeh-fallback' : $material );
	}
}
