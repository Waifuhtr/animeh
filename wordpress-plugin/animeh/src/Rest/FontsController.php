<?php
/**
 * Font registry endpoints.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\FontRepository;
use Animeh\Support\FontFile;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves and accepts subtitle fonts.
 */
final class FontsController {

	/**
	 * REST namespace shared by the plugin.
	 */
	public const NAMESPACE = 'animeh/v1';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/fonts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fonts/resolve',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resolve' ),
				'permission_callback' => array( Permissions::class, 'require_manage' ),
				'args'                => array(
					'family' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fonts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( Permissions::class, 'require_manage' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * List registered fonts.
	 */
	public function index(): WP_REST_Response {
		return new WP_REST_Response( array( 'fonts' => FontRepository::all() ) );
	}

	/**
	 * Accept a font upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['font'] ?? null;

		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) {
			return new WP_Error(
				'animeh_font_missing',
				__( 'Yüklenecek dosya bulunamadı.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error(
				'animeh_font_upload_error',
				__( 'Dosya yüklenirken bir sorun oluştu.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		// The file must have arrived through a real upload, not been pointed at
		// an arbitrary path on the server.
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) && ! defined( 'ANIMEH_TESTING' ) ) {
			return new WP_Error(
				'animeh_font_not_uploaded',
				__( 'Geçersiz yükleme.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$stored = FontRepository::store(
			(string) $file['tmp_name'],
			(string) ( $file['name'] ?? 'font' ),
			get_current_user_id()
		);

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return new WP_REST_Response( array( 'font' => $stored ), 201 );
	}

	/**
	 * Look up one family.
	 *
	 * This is what the player calls when a subtitle names a font it does not
	 * already hold. A miss is a 404 rather than an empty 200, so the player can
	 * treat it as "not available here" without inspecting the body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve( WP_REST_Request $request ) {
		$family = (string) $request->get_param( 'family' );
		$font   = FontRepository::resolve( $family );

		if ( null === $font ) {
			return new WP_Error(
				'animeh_font_not_found',
				__( 'Bu font kayıtlı değil.', 'animeh' ),
				array(
					'status' => 404,
					'family' => $family,
					'key'    => FontFile::key( $family ),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'family' => $font['family'],
				'url'    => $font['url'],
				'format' => $font['format'],
			)
		);
	}

	/**
	 * Remove a font.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! FontRepository::delete( $id ) ) {
			return new WP_Error(
				'animeh_font_not_found',
				__( 'Font bulunamadı.', 'animeh' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ) );
	}
}
