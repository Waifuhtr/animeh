<?php
/**
 * HLS playlist rewriting for the media proxy.
 *
 * A playlist's entries are relative to the playlist's own address. Serve a
 * master playlist from `/proxy?src=…` without touching it and the browser
 * resolves `720p/index.m3u8` against the proxy's path, which points nowhere.
 * Every URI inside has to be resolved against the original address and sent
 * back through the proxy, or throttling only ever works for single-file
 * sources.
 *
 * Free of any WordPress dependency so the rules can be unit tested.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Rewrites the URIs inside an HLS playlist.
 */
final class PlaylistRewriter {

	/**
	 * Tags whose `URI="…"` attribute points at another resource.
	 *
	 * Encryption keys, initialisation segments, alternate renditions and
	 * I-frame playlists all address other files and all break the same way.
	 *
	 * @var string[]
	 */
	private const URI_TAGS = array(
		'EXT-X-KEY',
		'EXT-X-SESSION-KEY',
		'EXT-X-MAP',
		'EXT-X-MEDIA',
		'EXT-X-I-FRAME-STREAM-INF',
		'EXT-X-PART',
		'EXT-X-PRELOAD-HINT',
		'EXT-X-RENDITION-REPORT',
	);

	/**
	 * Whether a response looks like an HLS playlist.
	 *
	 * Content type is checked first because it is what the server actually
	 * declares; the extension is a fallback for servers that send
	 * `application/octet-stream` for everything.
	 *
	 * @param string $url          Source URL.
	 * @param string $content_type Content-Type header, if any.
	 */
	public static function looks_like_playlist( string $url, string $content_type ): bool {
		$type = strtolower( $content_type );
		if ( str_contains( $type, 'mpegurl' ) ) {
			return true;
		}
		$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return 'm3u8' === $ext || 'm3u' === $ext;
	}

	/**
	 * Rewrite every URI in a playlist.
	 *
	 * @param string   $playlist Playlist body.
	 * @param string   $base_url Address the playlist was fetched from.
	 * @param callable $wrap     Receives an absolute URL, returns the proxied one.
	 */
	public static function rewrite( string $playlist, string $base_url, callable $wrap ): string {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $playlist ) ?: array() as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				$out[] = $line;
				continue;
			}

			if ( str_starts_with( $trimmed, '#' ) ) {
				$out[] = self::rewrite_tag( $trimmed, $base_url, $wrap );
				continue;
			}

			// Anything else on its own line is a media or playlist URI.
			$absolute = self::resolve_url( $base_url, $trimmed );
			$out[]    = (string) $wrap( $absolute );
		}

		return implode( "\n", $out );
	}

	/**
	 * Rewrite the `URI` attribute of a tag that carries one.
	 *
	 * @param string   $line     Tag line.
	 * @param string   $base_url Playlist address.
	 * @param callable $wrap     URL wrapper.
	 */
	private static function rewrite_tag( string $line, string $base_url, callable $wrap ): string {
		$colon = strpos( $line, ':' );
		if ( false === $colon ) {
			return $line;
		}

		$name = substr( $line, 1, $colon - 1 );
		if ( ! in_array( $name, self::URI_TAGS, true ) ) {
			return $line;
		}

		return (string) preg_replace_callback(
			'/URI="([^"]*)"/',
			static function ( array $matches ) use ( $base_url, $wrap ): string {
				if ( '' === $matches[1] ) {
					return $matches[0];
				}
				$absolute = self::resolve_url( $base_url, $matches[1] );
				return 'URI="' . (string) $wrap( $absolute ) . '"';
			},
			$line
		);
	}

	/**
	 * Resolve a possibly-relative reference against a base URL.
	 *
	 * A trimmed-down RFC 3986 resolver: enough for the forms playlists
	 * actually use — absolute, protocol-relative, root-relative and relative
	 * paths with `.` and `..` segments.
	 *
	 * @param string $base      Absolute base URL.
	 * @param string $reference Reference to resolve.
	 */
	public static function resolve_url( string $base, string $reference ): string {
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return $base;
		}

		// Already absolute.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $reference ) ) {
			return $reference;
		}

		$parts = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return $reference;
		}

		$scheme    = $parts['scheme'];
		$authority = $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$authority .= ':' . $parts['port'];
		}

		// Protocol-relative: keep the base's scheme, take everything else.
		if ( str_starts_with( $reference, '//' ) ) {
			return $scheme . ':' . $reference;
		}

		// A fragment or query alone attaches to the base path.
		if ( str_starts_with( $reference, '#' ) ) {
			return self::strip_fragment( $base ) . $reference;
		}
		if ( str_starts_with( $reference, '?' ) ) {
			$path = $parts['path'] ?? '/';
			return $scheme . '://' . $authority . $path . $reference;
		}

		if ( str_starts_with( $reference, '/' ) ) {
			$path = $reference;
		} else {
			// Relative: resolve against the base's directory.
			$base_path = $parts['path'] ?? '/';
			$directory = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
			if ( '' === $directory ) {
				$directory = '/';
			}
			$path = $directory . $reference;
		}

		return $scheme . '://' . $authority . self::normalise_path( $path );
	}

	/**
	 * Collapse `.` and `..` segments.
	 *
	 * @param string $path Path, possibly with a query string attached.
	 */
	private static function normalise_path( string $path ): string {
		// The query is carried along untouched; only the path is normalised.
		$query    = '';
		$question = strpos( $path, '?' );
		if ( false !== $question ) {
			$query = substr( $path, $question );
			$path  = substr( $path, 0, $question );
		}

		$segments = explode( '/', $path );
		$output   = array();

		foreach ( $segments as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				// Never climb above the root.
				if ( count( $output ) > 1 ) {
					array_pop( $output );
				}
				continue;
			}
			$output[] = $segment;
		}

		$normalised = implode( '/', $output );
		if ( ! str_starts_with( $normalised, '/' ) ) {
			$normalised = '/' . $normalised;
		}

		return $normalised . $query;
	}

	/**
	 * Drop a fragment from a URL.
	 *
	 * @param string $url URL.
	 */
	private static function strip_fragment( string $url ): string {
		$hash = strpos( $url, '#' );
		return false === $hash ? $url : substr( $url, 0, $hash );
	}
}
