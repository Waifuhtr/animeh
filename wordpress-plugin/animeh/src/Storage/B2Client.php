<?php
/**
 * Backblaze B2 over its S3-compatible API.
 *
 * The S3 API is used rather than B2's native one: it is what `S3Signer`
 * implements, it supports presigned URLs — which is what lets the app upload a
 * two-gigabyte episode without it passing through WordPress — and it is the
 * same protocol any other object store speaks, so moving providers later is a
 * configuration change rather than a rewrite.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\S3Signer;
use WP_Error;

/**
 * Talks to the media bucket.
 */
final class B2Client {

	/**
	 * Smallest part S3 accepts in a multipart upload, except for the last one.
	 */
	public const MIN_PART_BYTES = 5 * 1024 * 1024;

	/**
	 * Part size handed to the app.
	 *
	 * Large enough that a full episode stays well under the 10,000 part limit,
	 * small enough that a failed part is cheap to retry on a phone.
	 */
	public const PART_BYTES = 32 * 1024 * 1024;

	private StorageSettings $settings;
	private S3Signer $signer;

	/**
	 * @param StorageSettings $settings Bucket configuration.
	 */
	public function __construct( StorageSettings $settings ) {
		$this->settings = $settings;
		$this->signer   = new S3Signer(
			$settings->key_id,
			$settings->secret,
			$settings->region,
			's3'
		);
	}

	/**
	 * Check the credentials and the bucket in one call.
	 *
	 * Lists a single object rather than issuing HEAD on the bucket: a HEAD
	 * answers 200 for a bucket the key cannot actually read, so it would report
	 * success for a credential that fails on the first real request.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Depolama ayarları eksik.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$started  = microtime( true );
		$response = $this->request(
			'GET',
			'/' . $this->settings->bucket,
			array( 'list-type' => '2', 'max-keys' => '1' )
		);
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'ok'           => true,
			'bucket'       => $this->settings->bucket,
			'region'       => $this->settings->region,
			'endpoint'     => $this->settings->endpoint,
			'latency_ms'   => $elapsed,
			'object_count' => substr_count( (string) $response['body'], '<Key>' ),
		);
	}

	/**
	 * Upload a small object directly from the server.
	 *
	 * For subtitles, fonts and images. Video never goes this way: PHP would
	 * have to hold or stream the whole file, and the upload limits and
	 * execution timeouts on a shared host make that unreliable at episode
	 * sizes. {@see self::create_multipart_upload()} is the path for those.
	 *
	 * @param string $key          Object key.
	 * @param string $body         File contents.
	 * @param string $content_type MIME type.
	 * @return array<string, mixed>|WP_Error
	 */
	public function put_object( string $key, string $body, string $content_type = 'application/octet-stream' ) {
		$response = $this->request(
			'PUT',
			'/' . $this->settings->bucket . '/' . ltrim( $key, '/' ),
			array(),
			$body,
			array( 'content-type' => $content_type )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'key'  => $key,
			'size' => strlen( $body ),
			'etag' => trim( (string) ( $response['headers']['etag'] ?? '' ), '"' ),
		);
	}

	/**
	 * Delete an object.
	 *
	 * @param string $key Object key.
	 * @return true|WP_Error
	 */
	public function delete_object( string $key ) {
		$response = $this->request( 'DELETE', '/' . $this->settings->bucket . '/' . ltrim( $key, '/' ) );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Fetch a small object.
	 *
	 * @param string $key Object key.
	 * @return string|WP_Error
	 */
	public function get_object( string $key ) {
		$response = $this->request( 'GET', '/' . $this->settings->bucket . '/' . ltrim( $key, '/' ) );
		return is_wp_error( $response ) ? $response : (string) $response['body'];
	}

	/**
	 * List objects under a prefix.
	 *
	 * @param string $prefix Key prefix.
	 * @param int    $limit  Maximum keys.
	 * @return array<int, array{key: string, size: int}>|WP_Error
	 */
	public function list_objects( string $prefix, int $limit = 1000 ) {
		$response = $this->request(
			'GET',
			'/' . $this->settings->bucket,
			array(
				'list-type' => '2',
				'prefix'    => $prefix,
				'max-keys'  => (string) max( 1, min( $limit, 1000 ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::parse_listing( (string) $response['body'] );
	}

	/**
	 * Begin a multipart upload and hand back signed URLs for the parts.
	 *
	 * The app uploads each part straight to storage and reports the ETags back,
	 * so an episode never travels through WordPress and the credentials never
	 * leave the server.
	 *
	 * @param string $key          Object key.
	 * @param int    $size         Total size in bytes.
	 * @param string $content_type MIME type.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_multipart_upload( string $key, int $size, string $content_type ) {
		$response = $this->request(
			'POST',
			'/' . $this->settings->bucket . '/' . ltrim( $key, '/' ),
			array( 'uploads' => '' ),
			'',
			array( 'content-type' => $content_type )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$upload_id = self::extract_tag( (string) $response['body'], 'UploadId' );
		if ( '' === $upload_id ) {
			return new WP_Error(
				'animeh_storage_no_upload_id',
				__( 'Depolama çok parçalı yükleme başlatmadı.', 'animeh' ),
				array( 'status' => 502 )
			);
		}

		$part_size = self::PART_BYTES;
		$parts     = (int) max( 1, (int) ceil( $size / $part_size ) );
		$urls      = array();
		for ( $number = 1; $number <= $parts; $number++ ) {
			$urls[] = array(
				'part_number' => $number,
				'url'         => $this->presign(
					'PUT',
					'/' . $this->settings->bucket . '/' . ltrim( $key, '/' ),
					array( 'partNumber' => (string) $number, 'uploadId' => $upload_id ),
					// Long enough for a slow phone to finish one part.
					6 * HOUR_IN_SECONDS
				),
			);
		}

		return array(
			'key'       => $key,
			'upload_id' => $upload_id,
			'part_size' => $part_size,
			'parts'     => $urls,
		);
	}

	/**
	 * Finish a multipart upload.
	 *
	 * @param string                                     $key       Object key.
	 * @param string                                     $upload_id Upload id.
	 * @param array<int, array{part_number: int, etag: string}> $parts Completed parts.
	 * @return array<string, mixed>|WP_Error
	 */
	public function complete_multipart_upload( string $key, string $upload_id, array $parts ) {
		usort( $parts, static fn( array $a, array $b ): int => $a['part_number'] <=> $b['part_number'] );

		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			$xml .= sprintf(
				'<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>',
				(int) $part['part_number'],
				esc_xml_compat( trim( (string) $part['etag'], '"' ) )
			);
		}
		$xml .= '</CompleteMultipartUpload>';

		$response = $this->request(
			'POST',
			'/' . $this->settings->bucket . '/' . ltrim( $key, '/' ),
			array( 'uploadId' => $upload_id ),
			$xml,
			array( 'content-type' => 'application/xml' )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// S3 can answer 200 and still describe a failure in the body, because
		// the response streams while the parts are assembled.
		if ( str_contains( (string) $response['body'], '<Error>' ) ) {
			return new WP_Error(
				'animeh_storage_complete_failed',
				self::extract_tag( (string) $response['body'], 'Message' ) ?: __( 'Yükleme tamamlanamadı.', 'animeh' ),
				array( 'status' => 502 )
			);
		}

		return array( 'key' => $key, 'location' => self::extract_tag( (string) $response['body'], 'Location' ) );
	}

	/**
	 * Abandon a multipart upload, so the parts do not sit in the bucket
	 * costing money.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload id.
	 * @return true|WP_Error
	 */
	public function abort_multipart_upload( string $key, string $upload_id ) {
		$response = $this->request(
			'DELETE',
			'/' . $this->settings->bucket . '/' . ltrim( $key, '/' ),
			array( 'uploadId' => $upload_id )
		);
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * A temporary URL a client can read an object from.
	 *
	 * @param string $key         Object key.
	 * @param int    $expires_in  Lifetime in seconds.
	 */
	public function presign_get( string $key, int $expires_in = 0 ): string {
		return $this->presign(
			'GET',
			'/' . $this->settings->bucket . '/' . ltrim( $key, '/' ),
			array(),
			$expires_in > 0 ? $expires_in : $this->settings->link_ttl
		);
	}

	/**
	 * Sign a URL for a path under the endpoint.
	 *
	 * @param string                $method     HTTP method.
	 * @param string                $path       Raw path, keys not yet encoded.
	 * @param array<string, string> $query      Query parameters.
	 * @param int                   $expires_in Lifetime in seconds.
	 */
	private function presign( string $method, string $path, array $query, int $expires_in ): string {
		$url = $this->settings->s3_base() . S3Signer::encode_key( $path );
		if ( array() !== $query ) {
			$url .= '?' . self::build_query( $query );
		}
		return $this->signer->presign_url( $method, $url, $expires_in );
	}

	/**
	 * Issue a signed request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $path    Raw path, keys not yet encoded.
	 * @param array<string, string> $query   Query parameters.
	 * @param string                $body    Request body.
	 * @param array<string, string> $headers Extra headers.
	 * @return array{status: int, body: string, headers: array<string, string>}|WP_Error
	 */
	private function request(
		string $method,
		string $path,
		array $query = array(),
		string $body = '',
		array $headers = array()
	) {
		$url = $this->settings->s3_base() . S3Signer::encode_key( $path );
		if ( array() !== $query ) {
			$url .= '?' . self::build_query( $query );
		}

		$signed = $this->signer->sign_request( $method, $url, $headers, hash( 'sha256', $body ) );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $signed,
				'body'    => '' === $body ? null : $body,
				// Generous: a bucket on another continent under load is slow,
				// and a spurious timeout looks identical to bad credentials.
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'animeh_storage_unreachable',
				sprintf(
					/* translators: %s: underlying transport error. */
					__( 'Depolamaya ulaşılamadı: %s', 'animeh' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$text   = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return self::error_from_response( $status, $text );
		}

		$raw_headers = wp_remote_retrieve_headers( $response );
		$normalised  = array();
		foreach ( (array) ( is_object( $raw_headers ) ? $raw_headers->getAll() : $raw_headers ) as $name => $value ) {
			$normalised[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return array(
			'status'  => $status,
			'body'    => $text,
			'headers' => $normalised,
		);
	}

	/**
	 * Turn an S3 error response into something an operator can act on.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	private static function error_from_response( int $status, string $body ): WP_Error {
		$code    = self::extract_tag( $body, 'Code' );
		$message = self::extract_tag( $body, 'Message' );

		// The failures worth naming, because each has a different fix and the
		// raw S3 wording does not suggest one.
		$hints = array(
			'SignatureDoesNotMatch' => __( 'Uygulama anahtarı yanlış görünüyor.', 'animeh' ),
			'InvalidAccessKeyId'    => __( 'Anahtar kimliği bulunamadı.', 'animeh' ),
			'AccessDenied'          => __( 'Bu anahtarın bu bucket üzerinde yetkisi yok.', 'animeh' ),
			'NoSuchBucket'          => __( 'Bucket bulunamadı; adı ve bölgeyi kontrol et.', 'animeh' ),
			'AuthorizationHeaderMalformed' => __( 'Bölge yanlış olabilir.', 'animeh' ),
			'RequestTimeTooSkewed'  => __( 'Sunucu saati depolama ile uyumsuz.', 'animeh' ),
		);

		$hint = $hints[ $code ] ?? '';

		return new WP_Error(
			'animeh_storage_error',
			trim(
				sprintf(
					/* translators: 1: S3 error code, 2: S3 message. */
					__( 'Depolama hatası (%1$s): %2$s', 'animeh' ),
					'' === $code ? (string) $status : $code,
					'' === $message ? __( 'ayrıntı yok', 'animeh' ) : $message
				) . ( '' === $hint ? '' : ' — ' . $hint )
			),
			array(
				'status'    => 502,
				's3_status' => $status,
				's3_code'   => $code,
			)
		);
	}

	/**
	 * First value of an XML tag, without pulling in a parser.
	 *
	 * @param string $xml XML body.
	 * @param string $tag Tag name.
	 */
	private static function extract_tag( string $xml, string $tag ): string {
		if ( 1 === preg_match( '#<' . preg_quote( $tag, '#' ) . '>(.*?)</' . preg_quote( $tag, '#' ) . '>#s', $xml, $matches ) ) {
			return html_entity_decode( trim( $matches[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Object keys and sizes out of a ListObjectsV2 response.
	 *
	 * @param string $xml XML body.
	 * @return array<int, array{key: string, size: int, last_modified: string}>
	 */
	private static function parse_listing( string $xml ): array {
		$out = array();
		if ( preg_match_all( '#<Contents>(.*?)</Contents>#s', $xml, $matches ) ) {
			foreach ( $matches[1] as $chunk ) {
				$key = self::extract_tag( $chunk, 'Key' );
				if ( '' === $key ) {
					continue;
				}
				$out[] = array(
					'key'           => $key,
					'size'          => (int) self::extract_tag( $chunk, 'Size' ),
					'last_modified' => self::extract_tag( $chunk, 'LastModified' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Query string with SigV4's encoding rules.
	 *
	 * `http_build_query` uses form encoding, which differs on spaces and
	 * reserved characters and would not match what was signed.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	private static function build_query( array $query ): string {
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[] = S3Signer::uri_encode( (string) $name ) . '=' . S3Signer::uri_encode( (string) $value );
		}
		return implode( '&', $pairs );
	}
}

/**
 * Escape text for XML, on installations without `esc_xml`.
 *
 * `esc_xml` arrived in WordPress 5.5; the plugin supports 6.0 and up, but the
 * fallback costs nothing and removes a version dependency from a security-
 * relevant path.
 *
 * @param string $value Value to escape.
 */
function esc_xml_compat( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}
