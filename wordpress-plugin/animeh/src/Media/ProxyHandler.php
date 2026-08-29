<?php
/**
 * Range-capable media proxy with bandwidth pacing.
 *
 * The player exists to behave well on weak connections, and that cannot be
 * judged on a fast local link. This streams a source through at a chosen rate,
 * and can be told to fail the first few requests so the recovery ladder gets
 * exercised too.
 *
 * It hangs off `admin-post.php` rather than the REST API because REST buffers
 * responses, which is the one thing a streaming endpoint must not do.
 *
 * SECURITY: this fetches a URL supplied by a request, which is a server-side
 * request forgery surface. Three things stand between it and abuse — a
 * capability check, a nonce, and `UrlGuard` refusing anything that resolves
 * into a private range. None of them is optional.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Media;

use Animeh\Rest\Permissions;
use Animeh\Rest\TestController;
use Animeh\Support\PlaylistRewriter;
use Animeh\Support\Throttle;

/**
 * Streams a remote media file to the browser.
 */
final class ProxyHandler {

	/**
	 * Nonce action for proxy requests.
	 */
	public const NONCE_ACTION = 'animeh_media_proxy';

	/**
	 * Transient prefix for the injected-failure counters.
	 */
	private const FAIL_PREFIX = 'animeh_proxy_fail_';

	/**
	 * How long a request may run.
	 *
	 * A full episode at a throttled rate legitimately takes minutes.
	 */
	private const TIME_LIMIT = 1800;

	/**
	 * URL of the proxy endpoint.
	 */
	public static function endpoint(): string {
		return admin_url( 'admin-post.php?action=animeh_media_proxy' );
	}

	/**
	 * Handle a proxy request.
	 */
	public static function handle(): void {
		if ( ! Permissions::current_user_can_manage() ) {
			status_header( 403 );
			wp_die( esc_html__( 'Bu işlem için yetkin yok.', 'animeh' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Güvenlik doğrulaması başarısız.', 'animeh' ), '', array( 'response' => 403 ) );
		}

		$src = isset( $_GET['src'] ) ? esc_url_raw( wp_unslash( (string) $_GET['src'] ) ) : '';
		if ( '' === $src ) {
			status_header( 400 );
			wp_die( esc_html__( 'Kaynak adresi eksik.', 'animeh' ), '', array( 'response' => 400 ) );
		}

		$allowed = TestController::check_url( $src );
		if ( is_wp_error( $allowed ) ) {
			status_header( 400 );
			wp_die( esc_html( $allowed->get_error_message() ), '', array( 'response' => 400 ) );
		}

		$kbps = isset( $_GET['kbps'] ) ? absint( wp_unslash( (string) $_GET['kbps'] ) ) : 0;
		$fail = isset( $_GET['fail'] ) ? absint( wp_unslash( (string) $_GET['fail'] ) ) : 0;

		// Injected failures: the first N requests for this source are dropped so
		// the player's retry and backoff can be watched doing their job.
		if ( $fail > 0 && self::should_fail( $src, $fail ) ) {
			status_header( 503 );
			header( 'Retry-After: 1' );
			exit;
		}

		self::stream( $src, new Throttle( $kbps ), $kbps, $fail );
	}

	/**
	 * Build the proxy URL for a source.
	 *
	 * Used both by the panel and by the playlist rewriter, so a variant
	 * playlist reached through the proxy keeps the same throttle as the master
	 * that pointed at it.
	 *
	 * @param string $src  Absolute source URL.
	 * @param int    $kbps Rate limit to carry over.
	 * @param int    $fail Injected-failure count to carry over.
	 */
	public static function url_for( string $src, int $kbps = 0, int $fail = 0 ): string {
		$args = array(
			'src'      => rawurlencode( $src ),
			'_wpnonce' => wp_create_nonce( self::NONCE_ACTION ),
		);
		if ( $kbps > 0 ) {
			$args['kbps'] = $kbps;
		}
		if ( $fail > 0 ) {
			$args['fail'] = $fail;
		}
		return add_query_arg( $args, self::endpoint() );
	}

	/**
	 * Whether this request should be failed on purpose.
	 *
	 * @param string $src   Source URL.
	 * @param int    $limit How many requests to fail.
	 */
	private static function should_fail( string $src, int $limit ): bool {
		$key   = self::FAIL_PREFIX . md5( $src );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Fetch and stream a URL, honouring Range and pacing the output.
	 *
	 * @param string   $src      Source URL.
	 * @param Throttle $throttle Pacing.
	 * @param int      $kbps     Rate limit, carried into rewritten playlists.
	 * @param int      $fail     Injected-failure count, carried likewise.
	 */
	private static function stream( string $src, Throttle $throttle, int $kbps = 0, int $fail = 0 ): void {
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_RANGE'] ) ) : '';

		$headers = array(
			// Identity encoding only: a compressed body would break byte ranges,
			// and media is already compressed.
			'Accept-Encoding' => 'identity',
		);
		if ( '' !== $range ) {
			$headers['Range'] = $range;
		}

		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'header'          => self::header_lines( $headers ),
					'timeout'         => 30,
					// Redirects are not followed: a redirect would land on a
					// host `UrlGuard` never checked, which is the classic way
					// past an allowlist.
					'follow_location' => 0,
					'ignore_errors'   => true,
				),
				'ssl'  => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			)
		);

		$handle = @fopen( $src, 'rb', false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		if ( false === $handle ) {
			status_header( 502 );
			wp_die( esc_html__( 'Kaynak alınamadı.', 'animeh' ), '', array( 'response' => 502 ) );
		}

		$upstream = self::parse_response_headers( $http_response_header ?? array() );

		// Long transfers must not be cut short by PHP's own limits.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( false );

		// A playlist has to be rewritten before it is sent: its entries are
		// relative to its own address, and the browser would otherwise resolve
		// them against the proxy's path. Playlists are small, so reading one
		// whole costs nothing.
		$content_type = $upstream['headers']['content-type'] ?? '';
		if ( PlaylistRewriter::looks_like_playlist( $src, $content_type ) ) {
			$body = stream_get_contents( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			$rewritten = PlaylistRewriter::rewrite(
				false === $body ? '' : $body,
				$src,
				static fn( string $url ): string => self::url_for( $url, $kbps, $fail )
			);

			// Length changed, and the rewritten body is generated here.
			unset( $upstream['headers']['content-length'], $upstream['headers']['etag'], $upstream['headers']['last-modified'] );
			$upstream['headers']['content-type'] = '' !== $content_type ? $content_type : 'application/vnd.apple.mpegurl';

			self::send_headers( $upstream );
			header( 'Content-Length: ' . strlen( $rewritten ) );
			echo $rewritten; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		self::send_headers( $upstream );

		// Any buffering upstream of us would defeat the pacing entirely.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, $throttle->chunk_size );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();

			// Stop as soon as the client goes away, rather than paying for the
			// rest of a throttled episode nobody is watching.
			if ( connection_aborted() ) {
				break;
			}

			$delay = $throttle->delay_for( strlen( $chunk ) );
			if ( $delay > 0 ) {
				usleep( $delay );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Build header lines for the stream context.
	 *
	 * @param array<string, string> $headers Header map.
	 * @return string
	 */
	private static function header_lines( array $headers ): string {
		$lines = array();
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		return implode( "\r\n", $lines );
	}

	/**
	 * Pull the status and the headers we forward out of the raw response.
	 *
	 * @param array<int, string> $raw Raw header lines.
	 * @return array{status: int, headers: array<string, string>}
	 */
	private static function parse_response_headers( array $raw ): array {
		$status  = 200;
		$headers = array();

		// Only headers that describe the bytes are forwarded. Anything else —
		// cookies, auth challenges, caching directives from another origin —
		// has no business being replayed to our admin.
		$forward = array( 'content-type', 'content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag' );

		foreach ( $raw as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $matches ) ) {
				$status = (int) $matches[1];
				continue;
			}
			$separator = strpos( $line, ':' );
			if ( false === $separator ) {
				continue;
			}
			$name = strtolower( trim( substr( $line, 0, $separator ) ) );
			if ( in_array( $name, $forward, true ) ) {
				$headers[ $name ] = trim( substr( $line, $separator + 1 ) );
			}
		}

		return array(
			'status'  => $status,
			'headers' => $headers,
		);
	}

	/**
	 * Send our response headers.
	 *
	 * @param array{status: int, headers: array<string, string>} $upstream Parsed upstream response.
	 */
	private static function send_headers( array $upstream ): void {
		status_header( $upstream['status'] );

		foreach ( $upstream['headers'] as $name => $value ) {
			header( ucwords( $name, '-' ) . ': ' . $value );
		}

		if ( ! isset( $upstream['headers']['accept-ranges'] ) ) {
			header( 'Accept-Ranges: bytes' );
		}
		if ( ! isset( $upstream['headers']['content-type'] ) ) {
			header( 'Content-Type: application/octet-stream' );
		}

		// Test output must never be cached: the next run wants the real thing,
		// throttled afresh.
		nocache_headers();
		// The proxy only ever serves media to a page on this site.
		header( 'X-Content-Type-Options: nosniff' );
	}
}
