<?php
/**
 * AWS Signature Version 4.
 *
 * Backblaze B2 exposes an S3-compatible API, so every request to it — listing a
 * bucket, uploading a part, handing the app a temporary download link — is
 * signed with SigV4. Getting this wrong fails in a way that is hard to read
 * from the outside: the service answers `SignatureDoesNotMatch` and says
 * nothing about which of the dozen canonicalisation rules was broken.
 *
 * Free of any WordPress dependency, so it is verified directly: against the
 * signature published in the AWS documentation, and by cross-checking a spread
 * of generated requests against an independent implementation.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Signs S3-compatible requests.
 */
final class S3Signer {

	private const ALGORITHM = 'AWS4-HMAC-SHA256';
	private const TERMINATOR = 'aws4_request';

	/**
	 * Payload hash used when the body is not signed.
	 *
	 * Required for presigned URLs, where the body is unknown at signing time,
	 * and for streamed uploads we do not want to buffer in memory.
	 */
	public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

	/**
	 * SHA-256 of the empty string, for requests with no body.
	 */
	public const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	private string $access_key;
	private string $secret_key;
	private string $region;
	private string $service;

	/**
	 * @param string $access_key Access key id.
	 * @param string $secret_key Secret access key.
	 * @param string $region     Region, e.g. `us-west-004`.
	 * @param string $service    Service name; `s3` for object storage.
	 */
	public function __construct( string $access_key, string $secret_key, string $region, string $service = 's3' ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->region     = $region;
		$this->service    = $service;
	}

	/**
	 * Sign a request, returning the headers to send with it.
	 *
	 * @param string                $method       HTTP method.
	 * @param string                $url          Absolute request URL.
	 * @param array<string, string> $headers      Headers to sign. `host` is derived from the URL when absent.
	 * @param string                $payload_hash SHA-256 of the body, hex, or UNSIGNED_PAYLOAD.
	 * @param int|null              $timestamp    Unix time; defaults to now. Injected by tests.
	 * @return array<string, string> Headers including Authorization.
	 */
	public function sign_request(
		string $method,
		string $url,
		array $headers = array(),
		string $payload_hash = self::EMPTY_PAYLOAD_HASH,
		?int $timestamp = null
	): array {
		$timestamp ??= time();
		$amz_date   = gmdate( 'Ymd\THis\Z', $timestamp );
		$short_date = gmdate( 'Ymd', $timestamp );

		$parts = self::split_url( $url );

		// The host header is part of every signature, so it has to be present
		// and has to match what will actually be sent.
		$headers = self::with_host( $headers, $parts['host'] );
		$headers['x-amz-date'] = $amz_date;
		// Required by S3 on every signed request, but not part of SigV4 itself,
		// so it is not added for other services.
		if ( 's3' === $this->service ) {
			$headers['x-amz-content-sha256'] = $payload_hash;
		}

		$canonical_headers = self::canonical_headers( $headers );
		$signed_headers    = self::signed_headers( $headers );

		$canonical_request = implode(
			"\n",
			array(
				strtoupper( $method ),
				$parts['canonical_path'],
				self::canonical_query( $parts['query'] ),
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			)
		);

		$scope           = $short_date . '/' . $this->region . '/' . $this->service . '/' . self::TERMINATOR;
		$string_to_sign  = implode(
			"\n",
			array(
				self::ALGORITHM,
				$amz_date,
				$scope,
				hash( 'sha256', $canonical_request ),
			)
		);
		$signature = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $short_date ) );

		$headers['Authorization'] = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			self::ALGORITHM,
			$this->access_key,
			$scope,
			$signed_headers,
			$signature
		);

		return $headers;
	}

	/**
	 * Build a URL that carries its own signature.
	 *
	 * This is what lets the app talk to storage directly — uploading a two
	 * gigabyte episode or streaming one back — without the file passing through
	 * WordPress and without the storage credentials ever leaving the server.
	 *
	 * @param string                $method      HTTP method the URL will be used with.
	 * @param string                $url         Absolute request URL.
	 * @param int                   $expires_in  Lifetime in seconds; S3 caps this at seven days.
	 * @param array<string, string> $headers     Extra headers that must be signed, e.g. content-type.
	 * @param int|null              $timestamp   Unix time; defaults to now. Injected by tests.
	 */
	public function presign_url(
		string $method,
		string $url,
		int $expires_in = 3600,
		array $headers = array(),
		?int $timestamp = null
	): string {
		$timestamp ??= time();
		$amz_date   = gmdate( 'Ymd\THis\Z', $timestamp );
		$short_date = gmdate( 'Ymd', $timestamp );

		// Seven days is the protocol maximum; a longer request is silently
		// rejected at use time, which is far harder to debug than here.
		$expires_in = max( 1, min( $expires_in, 604800 ) );

		$parts   = self::split_url( $url );
		$headers = self::with_host( $headers, $parts['host'] );

		$signed_headers = self::signed_headers( $headers );
		$scope          = $short_date . '/' . $this->region . '/' . $this->service . '/' . self::TERMINATOR;

		$query                              = $parts['query'];
		$query['X-Amz-Algorithm']           = self::ALGORITHM;
		$query['X-Amz-Credential']          = $this->access_key . '/' . $scope;
		$query['X-Amz-Date']                = $amz_date;
		$query['X-Amz-Expires']             = (string) $expires_in;
		$query['X-Amz-SignedHeaders']       = $signed_headers;

		$canonical_request = implode(
			"\n",
			array(
				strtoupper( $method ),
				$parts['canonical_path'],
				self::canonical_query( $query ),
				self::canonical_headers( $headers ),
				$signed_headers,
				// A presigned URL cannot know the body, so it is never signed.
				self::UNSIGNED_PAYLOAD,
			)
		);

		$string_to_sign = implode(
			"\n",
			array(
				self::ALGORITHM,
				$amz_date,
				$scope,
				hash( 'sha256', $canonical_request ),
			)
		);
		$query['X-Amz-Signature'] = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $short_date ) );

		return $parts['base'] . $parts['path'] . '?' . self::canonical_query( $query );
	}

	/**
	 * Derive the date/region/service scoped signing key.
	 *
	 * @param string $short_date `Ymd` in UTC.
	 */
	private function signing_key( string $short_date ): string {
		$key = hash_hmac( 'sha256', $short_date, 'AWS4' . $this->secret_key, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', $this->service, $key, true );
		return hash_hmac( 'sha256', self::TERMINATOR, $key, true );
	}

	/**
	 * Split a URL into the pieces signing needs.
	 *
	 * @param string $url Absolute URL.
	 * @return array{base: string, host: string, path: string, canonical_path: string, query: array<string, string>}
	 */
	private static function split_url( string $url ): array {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw new \InvalidArgumentException( 'S3Signer needs an absolute URL' );
		}

		$host = $parts['host'];
		// A non-default port is part of the host header, and therefore signed.
		if ( isset( $parts['port'] ) ) {
			$default = ( 'https' === $parts['scheme'] && 443 === $parts['port'] )
				|| ( 'http' === $parts['scheme'] && 80 === $parts['port'] );
			if ( ! $default ) {
				$host .= ':' . $parts['port'];
			}
		}

		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}

		$query = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $parsed );
			foreach ( $parsed as $key => $value ) {
				$query[ (string) $key ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
			}
		}

		return array(
			'base'           => $parts['scheme'] . '://' . $host,
			'host'           => $host,
			'path'           => $path,
			'canonical_path' => self::canonical_path( $path ),
			'query'          => $query,
		);
	}

	/**
	 * The path exactly as it will appear on the wire.
	 *
	 * Signed verbatim, deliberately. Decoding and re-encoding it here would
	 * make the signature cover a different string from the one actually sent
	 * whenever the two round-trips disagree — `+` and `=` in a key are enough
	 * to cause it — and the service would answer `SignatureDoesNotMatch` with
	 * no hint as to why. S3 also forbids normalising the path, since a key may
	 * legitimately contain an empty segment.
	 *
	 * Callers turn a raw object key into a path with {@see self::encode_key()}
	 * once, when building the URL.
	 *
	 * @param string $path Path from the request URL, already encoded.
	 */
	private static function canonical_path( string $path ): string {
		return '' === $path ? '/' : $path;
	}

	/**
	 * Encode a raw object key into a URL path.
	 *
	 * Every byte outside the unreserved set is percent-encoded, except the
	 * separators between key segments — S3 keys use `/` for display grouping
	 * and it must stay literal.
	 *
	 * @param string $key Raw object key, e.g. `anime/bölüm 1/güneş.mp4`.
	 */
	public static function encode_key( string $key ): string {
		$segments = explode( '/', ltrim( $key, '/' ) );
		return '/' . implode( '/', array_map( array( self::class, 'uri_encode' ), $segments ) );
	}

	/**
	 * Build the canonical query string.
	 *
	 * Sorted by encoded name, every name and value percent-encoded, and a
	 * valueless parameter written with a trailing `=`.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	private static function canonical_query( array $query ): string {
		$encoded = array();
		foreach ( $query as $name => $value ) {
			$encoded[ self::uri_encode( (string) $name ) ] = self::uri_encode( (string) $value );
		}
		ksort( $encoded, SORT_STRING );

		$pairs = array();
		foreach ( $encoded as $name => $value ) {
			$pairs[] = $name . '=' . $value;
		}
		return implode( '&', $pairs );
	}

	/**
	 * Canonical header block: lowercase names, collapsed values, sorted.
	 *
	 * @param array<string, string> $headers Headers.
	 */
	private static function canonical_headers( array $headers ): string {
		$normalised = self::normalise_headers( $headers );
		$lines      = array();
		foreach ( $normalised as $name => $value ) {
			$lines[] = $name . ':' . $value . "\n";
		}
		return implode( '', $lines );
	}

	/**
	 * Semicolon-joined list of signed header names.
	 *
	 * @param array<string, string> $headers Headers.
	 */
	private static function signed_headers( array $headers ): string {
		return implode( ';', array_keys( self::normalise_headers( $headers ) ) );
	}

	/**
	 * Lowercase names, trim values, collapse internal runs of whitespace, sort.
	 *
	 * @param array<string, string> $headers Headers.
	 * @return array<string, string>
	 */
	private static function normalise_headers( array $headers ): array {
		$normalised = array();
		foreach ( $headers as $name => $value ) {
			$key = strtolower( trim( (string) $name ) );
			if ( 'authorization' === $key ) {
				continue;
			}
			$collapsed          = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
			$normalised[ $key ] = null === $collapsed ? trim( (string) $value ) : $collapsed;
		}
		ksort( $normalised, SORT_STRING );
		return $normalised;
	}

	/**
	 * Ensure a host header is present, without overwriting a caller's own.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string                $host    Host from the URL.
	 * @return array<string, string>
	 */
	private static function with_host( array $headers, string $host ): array {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'host' === strtolower( (string) $name ) ) {
				return $headers;
			}
		}
		$headers['host'] = $host;
		return $headers;
	}

	/**
	 * Percent-encoding as SigV4 defines it.
	 *
	 * Every byte is encoded except the unreserved set: letters, digits, `-`,
	 * `.`, `_` and `~`. This is stricter than a URL escape — `+`, `=` and `&`
	 * are all encoded — which is what the canonical query string requires.
	 *
	 * @param string $value Value to encode.
	 */
	public static function uri_encode( string $value ): string {
		// PHP's rawurlencode already implements exactly this set.
		return rawurlencode( $value );
	}
}
