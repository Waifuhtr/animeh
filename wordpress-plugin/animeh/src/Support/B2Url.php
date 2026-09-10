<?php
/**
 * The two addresses Backblaze gives the same object.
 *
 * B2 serves every file at a "friendly" URL —
 * `https://f005.backblazeb2.com/file/{bucket}/{key}` — and at an S3-compatible
 * one — `https://{bucket}.s3.{region}.backblazeb2.com/{key}`. They are the
 * same bytes behind two front doors, and the friendly one has a habit of
 * failing on its own for minutes at a time while the S3 one keeps answering.
 *
 * So every page this app sends carries both, and the reader moves to the
 * second the moment the first refuses. That is not a workaround for something
 * we did wrong; it is what B2 offering two doors is for.
 *
 * Pure string work, deliberately: this is the piece most worth being sure
 * about, and it is the piece a test can reach.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Converts between B2's two URL forms.
 */
final class B2Url {

	/**
	 * The friendly form.
	 *
	 * @param string $download_url Download host, e.g. `https://f005.backblazeb2.com`.
	 * @param string $bucket       Bucket name.
	 * @param string $key          Object key, unencoded.
	 * @return string Empty when there is not enough to build one.
	 */
	public static function friendly( string $download_url, string $bucket, string $key ): string {
		$host   = rtrim( trim( $download_url ), '/' );
		$bucket = trim( $bucket, '/ ' );
		$key    = ltrim( trim( $key ), '/' );

		if ( '' === $host || '' === $bucket || '' === $key ) {
			return '';
		}

		return $host . '/file/' . rawurlencode( $bucket ) . '/' . self::encode_key( $key );
	}

	/**
	 * The S3-compatible form.
	 *
	 * @param string $endpoint S3 host, e.g. `s3.us-west-004.backblazeb2.com`.
	 *                         A scheme is tolerated and stripped.
	 * @param string $bucket   Bucket name.
	 * @param string $key      Object key, unencoded.
	 * @return string Empty when there is not enough to build one.
	 */
	public static function s3( string $endpoint, string $bucket, string $key ): string {
		$host   = trim( $endpoint );
		$host   = (string) preg_replace( '#^https?://#i', '', $host );
		$host   = trim( $host, '/ ' );
		$bucket = trim( $bucket, '/ ' );
		$key    = ltrim( trim( $key ), '/' );

		if ( '' === $host || '' === $bucket || '' === $key ) {
			return '';
		}

		return 'https://' . $bucket . '.' . $host . '/' . self::encode_key( $key );
	}

	/**
	 * The bucket and key inside a friendly URL.
	 *
	 * Used on the way in: pages imported from the manga site arrive as
	 * whatever URL that site was serving, and turning one back into its parts
	 * is what lets the same page be offered in the other form.
	 *
	 * @param string $url A friendly URL.
	 * @return array{bucket: string, key: string}|null Null when it is not one.
	 */
	public static function parse_friendly( string $url ): ?array {
		$marker = '/file/';
		$at     = strpos( $url, $marker );
		if ( false === $at ) {
			return null;
		}

		$rest = substr( $url, $at + strlen( $marker ) );
		$slash = strpos( $rest, '/' );
		if ( false === $slash || 0 === $slash ) {
			return null;
		}

		$bucket = rawurldecode( substr( $rest, 0, $slash ) );
		$key    = substr( $rest, $slash + 1 );

		// The query string is not part of the key. A friendly URL with an
		// auth token on it would otherwise produce a key nothing matches.
		$question = strpos( $key, '?' );
		if ( false !== $question ) {
			$key = substr( $key, 0, $question );
		}

		if ( '' === $bucket || '' === $key ) {
			return null;
		}

		return array(
			'bucket' => $bucket,
			'key'    => self::decode_key( $key ),
		);
	}

	/**
	 * The other address for a URL, when one can be worked out.
	 *
	 * @param string $url      A friendly or S3 URL.
	 * @param string $endpoint S3 endpoint host, for the friendly → S3 direction.
	 * @return string Empty when there is no second address to offer.
	 */
	public static function alternate( string $url, string $endpoint ): string {
		$parts = self::parse_friendly( $url );
		if ( null !== $parts ) {
			return self::s3( $endpoint, $parts['bucket'], $parts['key'] );
		}

		// Already S3-shaped: the friendly host is not derivable from it —
		// the download host is per-account and the URL does not carry it — so
		// there is honestly nothing to offer, and saying so beats guessing.
		return '';
	}

	/**
	 * Percent-encode a key without destroying its slashes.
	 *
	 * `rawurlencode` on the whole key would turn every separator into `%2F`
	 * and address a file whose name contains slashes, which is a different
	 * object and usually no object at all.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public static function encode_key( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}

	/**
	 * The inverse, segment by segment.
	 *
	 * @param string $key Encoded key.
	 * @return string
	 */
	public static function decode_key( string $key ): string {
		return implode( '/', array_map( 'rawurldecode', explode( '/', $key ) ) );
	}
}
