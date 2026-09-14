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

	/**
	 * How many times one request is sent when it never left the building.
	 *
	 * Three. A resolver having a bad moment usually answers on the second ask;
	 * past the third, something is wrong that waiting will not fix.
	 */
	private const ATTEMPTS = 3;

	/** Pause before the next attempt, in microseconds, multiplied by the try. */
	private const RETRY_PAUSE_US = 250000;

	/**
	 * Seconds a retry may spend resolving and connecting.
	 *
	 * Short, and deliberately shorter than the attempt that just failed. The
	 * whole budget has to stay under the host's `max_execution_time`, which on
	 * shared hosting is thirty seconds: the first attempt already spent ten,
	 * and two more twenty-second waits would turn a clean "storage
	 * unreachable" into a blank page with nothing in it to read.
	 *
	 * Six is generous for the case this exists for. A resolve and a connect
	 * that are going to work take well under a second; one that needs longer
	 * than six is not having a bad moment, it is broken, and waiting is not
	 * the answer.
	 */
	private const CONNECT_SECONDS = 6;

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
	public function create_multipart_upload( string $key, int $size, string $content_type, int $wanted_part_size = 0 ) {
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

		$part_size = self::part_size( $size, $wanted_part_size );
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
	 * How big one part should be.
	 *
	 * The default is sized for a two-gigabyte episode: large parts keep a long
	 * upload under the ten-thousand-part ceiling. It is the wrong number for a
	 * short video, where it made the whole file a single part — one PUT, on one
	 * connection, with a progress bar that sat at zero and then jumped to done.
	 * A phone's uplink is not one connection's worth of bandwidth, and nothing
	 * about a single part can be sent in parallel.
	 *
	 * So the caller may ask for something smaller. Clamped at both ends: S3
	 * refuses a part under five megabytes except for the last one, and the part
	 * count is held under the protocol's ceiling whatever was asked for.
	 *
	 * @param int $size      Whole file, in bytes.
	 * @param int $wanted    What the caller asked for, or 0 for the default.
	 */
	public static function part_size( int $size, int $wanted = 0 ): int {
		if ( $wanted <= 0 ) {
			return self::PART_BYTES;
		}

		$part_size = max( self::MIN_PART_BYTES, $wanted );

		// Ten thousand parts is the protocol's limit; leave room rather than
		// land on it, since the last part is whatever is left over.
		$smallest = (int) ceil( $size / 9000 );

		return (int) max( $part_size, $smallest );
	}

	/**
	 * One request to storage, tried more than once when it never left.
	 *
	 * Written after a live failure: `cURL error 28: Resolving timed out after
	 * 10002 milliseconds`. The name of the bucket's endpoint could not be
	 * resolved, so the request never reached anybody — and an upload that is
	 * four taps in died on a resolver having a bad ten seconds.
	 *
	 * Only failures that provably happened *before* anything was sent are
	 * retried. That distinction is the whole of the safety: a request that
	 * never left cannot have created a multipart upload or stored an object,
	 * so sending it again cannot do anything twice. A timeout after the bytes
	 * went out is a different animal and is left alone.
	 *
	 * The second attempt also asks cURL for IPv4. A host whose IPv6 is
	 * advertised but dead is the ordinary cause of a resolve that hangs rather
	 * than fails, and this is the ordinary fix — held back to the retry so a
	 * host that is genuinely IPv6-only is not broken by it.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string, mixed> $args wp_remote_request arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function send( string $url, array $args ) {
		$last = null;

		for ( $attempt = 1; $attempt <= self::ATTEMPTS; $attempt++ ) {
			if ( $attempt > 1 ) {
				add_action( 'http_api_curl', array( self::class, 'steady_connection' ), 99 );
			}

			$response = wp_remote_request( $url, $args );

			if ( $attempt > 1 ) {
				remove_action( 'http_api_curl', array( self::class, 'steady_connection' ), 99 );
			}

			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			$last = $response;

			if ( ! self::never_left( $response ) ) {
				break;
			}

			// A resolver that just timed out does not answer faster for being
			// asked again immediately.
			if ( $attempt < self::ATTEMPTS ) {
				usleep( self::RETRY_PAUSE_US * $attempt );
			}
		}

		return $last;
	}

	/**
	 * Whether a transport failure happened before anything was sent.
	 *
	 * cURL 6 is a name that would not resolve, 7 a host that would not accept
	 * a connection, and 28 a timeout — which is only safe to retry when the
	 * message says it timed out resolving or connecting rather than waiting
	 * for a reply.
	 *
	 * @param WP_Error $error What the transport said.
	 */
	private static function never_left( WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );

		foreach ( array( 'cURL error 6:', 'cURL error 7:', 'resolving timed out', 'connection timed out', 'could not resolve' ) as $mark ) {
			if ( str_contains( $message, strtolower( $mark ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Give the retry a fair chance: IPv4, and time to finish resolving.
	 *
	 * Hooked at a late priority so it is the last word on these two options —
	 * a host that caps the connect timeout in its own hook has already run.
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 */
	public static function steady_connection( $handle ): void {
		if ( ! function_exists( 'curl_setopt' ) ) {
			return;
		}

		if ( defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, self::CONNECT_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * The transport's message, with what it means when that is not obvious.
	 *
	 * "Resolving timed out" is the site's own DNS failing, not a bad key and
	 * not a bucket that is down — and without saying so the only thing left to
	 * do about it is guess.
	 *
	 * @param WP_Error $error What the transport said.
	 */
	private static function explain_transport( WP_Error $error ): string {
		$message = $error->get_error_message();

		if ( self::never_left( $error ) ) {
			return $message . ' — ' . __( 'sunucu depolama adresine ulaşamadı (DNS ya da giden bağlantı). Barındırıcının ayarı; anahtarlarla ilgisi yok.', 'animeh' );
		}

		return $message;
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

		$response = self::send(
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
					self::explain_transport( $response )
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
