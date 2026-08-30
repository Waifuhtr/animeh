<?php
/**
 * The Tenrai metadata client, and the cache in front of it.
 *
 * §5 and §24 between them set the shape: the app never talks to Tenrai
 * directly, the server key never leaves this machine, and repeated lookups are
 * answered from cache rather than by hammering someone else's API.
 *
 * The base URL is configurable rather than hard-coded. Tenrai's v1 endpoints
 * follow the Jikan v4 compatibility schema, so a deployment that needs to point
 * at a different compatible host — or at v2 when it lands — is a settings
 * change and not a code change.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use WP_Error;

/**
 * Fetches and caches Tenrai metadata.
 */
final class TenraiClient {

	/**
	 * Option holding the Tenrai configuration.
	 */
	private const OPTION = 'animeh_tenrai';

	/**
	 * Default API base.
	 */
	public const DEFAULT_BASE = 'https://api.tenrai.org/v1';

	/**
	 * How long a cached response stays fresh, per endpoint family.
	 *
	 * A finished series' details do not change; a seasonal listing does. The
	 * numbers reflect that rather than being one global figure.
	 *
	 * @var array<string, int>
	 */
	private const TTL = array(
		'anime'    => 21600,
		'episodes' => 10800,
		'search'   => 1800,
		'seasons'  => 10800,
		'top'      => 10800,
		'genres'   => 604800,
		'default'  => 3600,
	);

	/**
	 * Longest a stale entry may be served while the upstream is unreachable.
	 *
	 * Answering with day-old metadata beats an empty screen, and an outage
	 * upstream should not empty this catalog.
	 */
	private const STALE_GRACE = 604800;

	/**
	 * Requests per minute this plugin will make.
	 *
	 * §24 asks explicitly not to design something that abuses the upstream's
	 * limits. This is the ceiling regardless of how many admins are importing.
	 */
	private const RATE_PER_MINUTE = 30;

	/**
	 * Current configuration.
	 *
	 * @return array{base: string, key: string, enabled: bool}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$key = (string) ( $stored['key'] ?? '' );
		if ( '' !== $key ) {
			$key = self::box()->open( $key );
		}

		return array(
			'base'    => rtrim( (string) ( $stored['base'] ?? self::DEFAULT_BASE ), '/' ),
			'key'     => $key,
			'enabled' => (bool) ( $stored['enabled'] ?? true ),
		);
	}

	/**
	 * Save configuration. An empty key keeps the stored one.
	 *
	 * @param array<string, mixed> $data New values.
	 * @return array{base: string, key: string, enabled: bool}
	 */
	public static function save_settings( array $data ): array {
		$current = self::settings();

		$key = (string) ( $data['key'] ?? '' );
		if ( '' === $key ) {
			$key = $current['key'];
		}

		update_option(
			self::OPTION,
			array(
				'base'    => rtrim( (string) ( $data['base'] ?? $current['base'] ), '/' ),
				// Encrypted at rest for the same reason as the storage key:
				// the database is not where a credential belongs in plain text.
				'key'     => '' === $key ? '' : self::box()->seal( $key ),
				'enabled' => ! empty( $data['enabled'] ),
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
			'base'       => $settings['base'],
			'has_key'    => '' !== $settings['key'],
			'key_masked' => \Animeh\Support\SecretBox::mask( $settings['key'] ),
			'enabled'    => $settings['enabled'],
		);
	}

	/**
	 * GET one Tenrai path, through the cache.
	 *
	 * @param string               $path  Path under the base, e.g. "anime/1".
	 * @param array<string, mixed> $query Query parameters.
	 * @param bool                 $fresh Skip the cache.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( string $path, array $query = array(), bool $fresh = false ) {
		$settings = self::settings();

		if ( ! $settings['enabled'] ) {
			return new WP_Error(
				'TENRAI_ERROR',
				__( 'Tenrai entegrasyonu kapalı.', 'animeh' ),
				array( 'status' => 503 )
			);
		}

		$path       = ltrim( $path, '/' );
		$cache_key  = $this->cache_key( $path, $query );
		$cached     = get_transient( $cache_key );

		if ( ! $fresh && is_array( $cached ) && isset( $cached['payload'] ) ) {
			return $cached['payload'];
		}

		if ( ! $this->take_rate_token() ) {
			// Rather than queue or fail, serve what is on hand: an import that
			// is running fast is not a reason to make browsing fail.
			$stale = $this->stale( $cache_key );
			if ( null !== $stale ) {
				return $stale;
			}

			return new WP_Error(
				'TENRAI_ERROR',
				__( 'Tenrai istek sınırına ulaşıldı, biraz sonra tekrar dene.', 'animeh' ),
				array( 'status' => 429 )
			);
		}

		$url = $settings['base'] . '/' . $path;
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query );
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $settings['key'] ) {
			// Server-side only. §5 is explicit that this must never reach the
			// APK, which is why the app calls this plugin rather than Tenrai.
			$headers['X-API-Key'] = $settings['key'];
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

			( new LogRepository() )->error( 'TENRAI_ERROR', $response->get_error_message(), array( 'path' => $path ) );

			return new WP_Error(
				'TENRAI_ERROR',
				__( 'Tenrai sunucusuna ulaşılamadı.', 'animeh' ),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$stale = $this->stale( $cache_key );
			if ( null !== $stale && $status >= 500 ) {
				return $stale;
			}

			( new LogRepository() )->error(
				'TENRAI_ERROR',
				'Tenrai ' . $status,
				array( 'path' => $path, 'status' => $status )
			);

			return new WP_Error(
				'TENRAI_ERROR',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Tenrai %d yanıtı döndü.', 'animeh' ),
					$status
				),
				array( 'status' => 404 === $status ? 404 : 502 )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'TENRAI_ERROR', __( 'Tenrai yanıtı okunamadı.', 'animeh' ), array( 'status' => 502 ) );
		}

		$this->store( $cache_key, $decoded, $this->ttl_for( $path ) );

		return $decoded;
	}

	/**
	 * Search anime by title.
	 *
	 * @param string $query Search text.
	 * @param int    $page  Page number.
	 * @param int    $limit Results per page.
	 * @return array<string, mixed>|WP_Error
	 */
	public function search_anime( string $query, int $page = 1, int $limit = 20 ) {
		return $this->get(
			'anime',
			array(
				'q'     => $query,
				'page'  => max( 1, $page ),
				'limit' => max( 1, min( $limit, 25 ) ),
				'sfw'   => 'true',
			)
		);
	}

	/**
	 * Full details for one anime.
	 *
	 * @param int $id Tenrai/MAL id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function anime( int $id ) {
		return $this->get( 'anime/' . $id . '/full' );
	}

	/**
	 * Every episode of one anime, following the pagination.
	 *
	 * @param int $id       Tenrai/MAL id.
	 * @param int $max_pages Safety stop.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function episodes( int $id, int $max_pages = 10 ) {
		$all  = array();
		$page = 1;

		while ( $page <= $max_pages ) {
			$response = $this->get( 'anime/' . $id . '/episodes', array( 'page' => $page ) );
			if ( is_wp_error( $response ) ) {
				// Pages already collected are still useful; only a failure on
				// the first page is a failure of the whole call.
				return 1 === $page ? $response : $all;
			}

			$data = $response['data'] ?? array();
			if ( ! is_array( $data ) || array() === $data ) {
				break;
			}

			foreach ( $data as $entry ) {
				if ( is_array( $entry ) ) {
					$all[] = $entry;
				}
			}

			if ( empty( $response['pagination']['has_next_page'] ) ) {
				break;
			}

			++$page;
		}

		return $all;
	}

	/**
	 * Wipe the cached responses.
	 */
	public static function flush_cache(): int {
		global $wpdb;

		// Transients live in the options table unless an object cache is in
		// play; both paths are covered by deleting the rows and then letting
		// the cache group be invalidated by the deletes.
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_animeh_tenrai_%' OR option_name LIKE '_transient_timeout_animeh_tenrai_%'"
		);

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
		ksort( $query );

		// Hashed and truncated: an option_name is limited to 191 characters and
		// a long query string would otherwise silently collide.
		return 'animeh_tenrai_' . substr( hash( 'sha256', $path . '|' . wp_json_encode( $query ) ), 0, 40 );
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
			'payload'  => $payload,
			'fetched'  => time(),
		);

		set_transient( $key, $entry, $ttl );

		// The stale copy outlives the fresh one; it is what an upstream outage
		// is answered from.
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
		$key  = 'animeh_tenrai_rate_' . (int) floor( time() / 60 );
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
