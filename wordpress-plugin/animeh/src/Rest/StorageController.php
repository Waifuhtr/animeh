<?php
/**
 * Storage endpoints: configuration, connection test, uploads and playback URLs.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\B2Client;
use Animeh\Storage\StorageSettings;
use Animeh\Support\StorageKey;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the media bucket.
 */
final class StorageController {

	/**
	 * Largest file accepted through the server-side path.
	 *
	 * Anything bigger goes through presigned multipart instead: PHP would have
	 * to hold or stream it, and upload limits on a shared host make that
	 * unreliable at episode sizes.
	 */
	private const MAX_DIRECT_BYTES = 8 * 1024 * 1024;

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$guard     = array( Permissions::class, 'require_manage' );

		register_rest_route(
			$namespace,
			'/storage/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $guard,
					'args'                => $this->settings_args(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/storage/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			$namespace,
			'/storage/uploads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'begin_upload' ),
				'permission_callback' => $guard,
				'args'                => array(
					'anime_title'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'anime_id'     => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'season'       => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'episode'      => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'filename'     => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'size'         => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'content_type' => array( 'type' => 'string', 'default' => 'video/mp4', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/storage/uploads/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_upload' ),
				'permission_callback' => $guard,
				'args'                => array(
					'key'       => array( 'required' => true, 'type' => 'string' ),
					'upload_id' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/storage/uploads/abort',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'abort_upload' ),
				'permission_callback' => $guard,
				'args'                => array(
					'key'       => array( 'required' => true, 'type' => 'string' ),
					'upload_id' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/storage/objects',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'put_small_object' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			$namespace,
			'/storage/playback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'playback' ),
				'permission_callback' => $guard,
				'args'                => array(
					'key' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Current configuration, without the secret.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( array( 'storage' => StorageSettings::load()->to_public_array() ) );
	}

	/**
	 * Save configuration.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$region   = (string) $request->get_param( 'region' );
		$endpoint = (string) $request->get_param( 'endpoint' );

		$settings = StorageSettings::save(
			array(
				'region'        => $region,
				'bucket'        => (string) $request->get_param( 'bucket' ),
				// An operator who filled in the region should not also have to
				// know the endpoint; Backblaze derives one from the other.
				'endpoint'      => '' !== $endpoint ? $endpoint : StorageSettings::default_endpoint( $region ),
				'key_id'        => (string) $request->get_param( 'key_id' ),
				'secret'        => (string) $request->get_param( 'secret' ),
				'friendly_base' => (string) $request->get_param( 'friendly_base' ),
				'public_bucket' => (bool) $request->get_param( 'public_bucket' ),
				'link_ttl'      => (int) $request->get_param( 'link_ttl' ),
			)
		);

		return new WP_REST_Response( array( 'storage' => $settings->to_public_array() ) );
	}

	/**
	 * Check the bucket is reachable with the stored credentials.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test() {
		$settings = StorageSettings::load();
		$result   = ( new B2Client( $settings ) )->test_connection();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'result' => $result ) );
	}

	/**
	 * Start a direct-to-storage upload for a video.
	 *
	 * The response carries a signed URL per part. The app uploads to storage
	 * itself and reports the ETags back, so an episode never passes through
	 * WordPress and the credentials never leave it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function begin_upload( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return self::unconfigured();
		}

		$size = (int) $request->get_param( 'size' );
		if ( $size <= 0 ) {
			return new WP_Error( 'animeh_bad_size', __( 'Dosya boyutu geçersiz.', 'animeh' ), array( 'status' => 400 ) );
		}

		$slug = StorageKey::slug(
			(string) $request->get_param( 'anime_title' ),
			(int) $request->get_param( 'anime_id' )
		);
		$key = StorageKey::episode_file(
			$slug,
			(int) $request->get_param( 'season' ),
			(int) $request->get_param( 'episode' ),
			(string) $request->get_param( 'filename' )
		);

		$result = ( new B2Client( $settings ) )->create_multipart_upload(
			$key,
			$size,
			(string) $request->get_param( 'content_type' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['slug'] = $slug;
		return new WP_REST_Response( array( 'upload' => $result ), 201 );
	}

	/**
	 * Finish a direct upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_upload( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return self::unconfigured();
		}

		$body  = $request->get_json_params();
		$parts = is_array( $body ) && isset( $body['parts'] ) && is_array( $body['parts'] ) ? $body['parts'] : array();

		$clean = array();
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			$number = (int) ( $part['part_number'] ?? 0 );
			$etag   = (string) ( $part['etag'] ?? '' );
			if ( $number > 0 && '' !== $etag ) {
				$clean[] = array( 'part_number' => $number, 'etag' => $etag );
			}
		}

		if ( array() === $clean ) {
			return new WP_Error( 'animeh_no_parts', __( 'Tamamlanacak parça yok.', 'animeh' ), array( 'status' => 400 ) );
		}

		$result = ( new B2Client( $settings ) )->complete_multipart_upload(
			(string) $request->get_param( 'key' ),
			(string) $request->get_param( 'upload_id' ),
			$clean
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'upload' => $result ) );
	}

	/**
	 * Abandon a direct upload.
	 *
	 * Worth calling: abandoned parts sit in the bucket and are billed until
	 * something removes them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function abort_upload( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return self::unconfigured();
		}

		$result = ( new B2Client( $settings ) )->abort_multipart_upload(
			(string) $request->get_param( 'key' ),
			(string) $request->get_param( 'upload_id' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'aborted' => true ) );
	}

	/**
	 * Upload a small file through the server.
	 *
	 * For subtitles, fonts and images, where the round trip is cheap and a
	 * presigned handshake would be more moving parts than the file is worth.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function put_small_object( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return self::unconfigured();
		}

		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) {
			return new WP_Error( 'animeh_no_file', __( 'Dosya bulunamadı.', 'animeh' ), array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) && ! defined( 'ANIMEH_TESTING' ) ) {
			return new WP_Error( 'animeh_not_uploaded', __( 'Geçersiz yükleme.', 'animeh' ), array( 'status' => 400 ) );
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size > self::MAX_DIRECT_BYTES ) {
			return new WP_Error(
				'animeh_too_large',
				__( 'Bu yol yalnızca küçük dosyalar içindir; video için çok parçalı yüklemeyi kullan.', 'animeh' ),
				array( 'status' => 413 )
			);
		}

		$body = file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $body ) {
			return new WP_Error( 'animeh_unreadable', __( 'Dosya okunamadı.', 'animeh' ), array( 'status' => 400 ) );
		}

		$key = (string) $request->get_param( 'key' );
		if ( '' === $key ) {
			return new WP_Error( 'animeh_no_key', __( 'Hedef anahtar eksik.', 'animeh' ), array( 'status' => 400 ) );
		}

		$result = ( new B2Client( $settings ) )->put_object(
			$key,
			$body,
			(string) ( $file['type'] ?? 'application/octet-stream' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'object' => $result ), 201 );
	}

	/**
	 * Addresses the player should use for an object.
	 *
	 * Two are returned wherever possible. Backblaze's friendly hostname and its
	 * S3 endpoint front the same bytes but do not fail together, and the player
	 * moves to the second when the first refuses — which is the whole reason
	 * `MediaSourceDescriptor.fallbackUrls` exists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function playback( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return self::unconfigured();
		}

		$key    = ltrim( (string) $request->get_param( 'key' ), '/' );
		$client = new B2Client( $settings );

		if ( $settings->public_bucket ) {
			// Both hostnames serve the object without a signature, so the
			// friendly one leads — it is the cacheable, CDN-frontable address —
			// and the S3 endpoint stands behind it.
			$friendly = $settings->friendly_url( $key );
			$s3       = $settings->s3_url( $key );

			$urls = array_values( array_filter( array( $friendly, $s3 ) ) );
		} else {
			// A private bucket's friendly hostname needs Backblaze's own
			// download authorisation, which is a different API from the one
			// signing these requests. Only the presigned S3 URL is offered,
			// rather than handing out an address that will answer 401.
			$urls = array( $client->presign_get( $key ) );
		}

		if ( array() === $urls ) {
			return new WP_Error(
				'animeh_no_url',
				__( 'Bu nesne için adres üretilemedi.', 'animeh' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'key'          => $key,
				'url'          => $urls[0],
				'fallbackUrls' => array_slice( $urls, 1 ),
				'expiresIn'    => $settings->public_bucket ? null : $settings->link_ttl,
			)
		);
	}

	/**
	 * The shared "storage is not set up yet" response.
	 */
	private static function unconfigured(): WP_Error {
		return new WP_Error(
			'animeh_storage_unconfigured',
			__( 'Önce depolama ayarlarını gir.', 'animeh' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Argument schema for saving settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_args(): array {
		return array(
			'region'        => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'bucket'        => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'endpoint'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'key_id'        => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			// Blank means "keep what is stored", so the form never has to
			// receive the secret in order to save anything else.
			'secret'        => array( 'type' => 'string', 'default' => '' ),
			'friendly_base' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ),
			'public_bucket' => array( 'type' => 'boolean', 'default' => false ),
			'link_ttl'      => array( 'type' => 'integer', 'default' => 3600, 'sanitize_callback' => 'absint' ),
		);
	}
}
