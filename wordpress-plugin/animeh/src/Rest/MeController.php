<?php
/**
 * The signed-in user's own data: profile, library, history, settings.
 *
 * Every route here is scoped to `get_current_user_id()` and never to an id in
 * the request. That is the whole authorisation model for this file, and it is
 * why none of these endpoints can be made to return someone else's history by
 * changing a parameter.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\CatalogRepository;
use Animeh\Storage\UserDataRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Per-user endpoints.
 */
final class MeController {

	/**
	 * User meta key holding the app's preferences blob.
	 */
	private const SETTINGS_META = 'animeh_app_settings';

	/**
	 * Preferences the app may store, with their defaults.
	 *
	 * Enumerated so a client cannot use this as arbitrary per-user storage.
	 *
	 * @var array<string, mixed>
	 */
	private const SETTINGS_SCHEMA = array(
		'theme'              => 'dark',
		'language'           => 'tr',
		'default_quality'    => 'auto',
		'subtitle_language'  => 'tr',
		'subtitles_enabled'  => true,
		'autoplay_next'      => true,
		'skip_intro'         => true,
		'wifi_only_download' => true,
		'notifications'      => true,
		'data_saver'         => false,
	);

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$guard     = array( AuthController::class, 'require_login' );

		register_rest_route(
			$namespace,
			'/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'profile' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => $guard,
					'args'                => array(
						'display_name' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'email'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'settings' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/library',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'library' ),
				'permission_callback' => $guard,
				'args'                => array(
					'list'     => array( 'type' => 'string', 'default' => 'favorite', 'enum' => array( 'favorite', 'watchlist' ) ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/library/(?P<work_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_to_library' ),
					'permission_callback' => $guard,
					'args'                => $this->list_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_from_library' ),
					'permission_callback' => $guard,
					'args'                => $this->list_args(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/history',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'history' ),
					'permission_callback' => $guard,
					'args'                => array(
						'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'record_progress' ),
					'permission_callback' => $guard,
					'args'                => array(
						'episode_id'       => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'position_seconds' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'duration_seconds' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_history' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/history/(?P<episode_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'forget_episode' ),
				'permission_callback' => $guard,
				'args'                => array(
					'episode_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/continue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'continue_watching' ),
				'permission_callback' => $guard,
			)
		);
	}

	/**
	 * Profile plus watch statistics.
	 */
	public function profile(): WP_REST_Response {
		$user_id = get_current_user_id();

		return new WP_REST_Response(
			array(
				'user'     => AuthController::user_payload( $user_id ),
				'stats'    => ( new UserDataRepository() )->stats( $user_id ),
				'settings' => $this->stored_settings( $user_id ),
			)
		);
	}

	/**
	 * Change display name or email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$fields  = array( 'ID' => $user_id );

		$name = $request->get_param( 'display_name' );
		if ( is_string( $name ) && '' !== trim( $name ) ) {
			$fields['display_name'] = trim( $name );
		}

		$email = $request->get_param( 'email' );
		if ( is_string( $email ) && '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'VALIDATION_ERROR', __( 'Geçerli bir e-posta gir.', 'animeh' ), array( 'status' => 400 ) );
			}
			$existing = get_user_by( 'email', $email );
			if ( $existing && (int) $existing->ID !== $user_id ) {
				return new WP_Error( 'VALIDATION_ERROR', __( 'Bu e-posta başka bir hesapta kayıtlı.', 'animeh' ), array( 'status' => 409 ) );
			}
			$fields['user_email'] = $email;
		}

		$updated = wp_update_user( $fields );
		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'WORDPRESS_ERROR', $updated->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'user' => AuthController::user_payload( $user_id ) ) );
	}

	/**
	 * The app's stored preferences.
	 */
	public function settings(): WP_REST_Response {
		return new WP_REST_Response( array( 'settings' => $this->stored_settings( get_current_user_id() ) ) );
	}

	/**
	 * Save preferences, keeping only known keys.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$incoming = $request->get_json_params();
		$incoming = is_array( $incoming ) ? $incoming : array();

		$settings = $this->stored_settings( $user_id );

		foreach ( self::SETTINGS_SCHEMA as $key => $default ) {
			if ( ! array_key_exists( $key, $incoming ) ) {
				continue;
			}

			// Coerced to the default's type rather than trusted: a client
			// sending "true" as a string should not make `is_bool` checks
			// downstream start failing.
			$settings[ $key ] = is_bool( $default )
				? (bool) rest_sanitize_boolean( $incoming[ $key ] )
				: sanitize_text_field( (string) $incoming[ $key ] );
		}

		update_user_meta( $user_id, self::SETTINGS_META, $settings );

		return new WP_REST_Response( array( 'settings' => $settings ) );
	}

	/**
	 * One of the user's lists.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function library( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( (int) $request->get_param( 'per_page' ), CatalogRepository::MAX_PER_PAGE ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$rows = ( new UserDataRepository() )->list_works(
			get_current_user_id(),
			(string) $request->get_param( 'list' ),
			$per_page,
			( $page - 1 ) * $per_page
		);

		return new WP_REST_Response(
			array(
				'items' => array_map( array( CatalogController::class, 'work_payload' ), $rows ),
				'page'  => $page,
			)
		);
	}

	/**
	 * Add a work to a list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_to_library( WP_REST_Request $request ) {
		$work_id = (int) $request->get_param( 'work_id' );

		// Checked before the insert: a favourite pointing at nothing would show
		// up later as a blank card the user cannot remove.
		if ( null === ( new CatalogRepository() )->work( $work_id ) ) {
			return new WP_Error( 'NOT_FOUND', __( 'Anime bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		( new UserDataRepository() )->add_to_list( get_current_user_id(), $work_id, (string) $request->get_param( 'list' ) );

		return new WP_REST_Response( array( 'ok' => true, 'in_list' => true ), 201 );
	}

	/**
	 * Remove a work from a list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remove_from_library( WP_REST_Request $request ): WP_REST_Response {
		( new UserDataRepository() )->remove_from_list(
			get_current_user_id(),
			(int) $request->get_param( 'work_id' ),
			(string) $request->get_param( 'list' )
		);

		return new WP_REST_Response( array( 'ok' => true, 'in_list' => false ) );
	}

	/**
	 * Recent history.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function history( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( (int) $request->get_param( 'per_page' ), CatalogRepository::MAX_PER_PAGE ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$rows = ( new UserDataRepository() )->history( get_current_user_id(), $per_page, ( $page - 1 ) * $per_page );

		return new WP_REST_Response(
			array(
				'items' => array_map( array( CatalogController::class, 'history_payload' ), $rows ),
				'page'  => $page,
			)
		);
	}

	/**
	 * Record a playback position.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_progress( WP_REST_Request $request ) {
		$episode_id = (int) $request->get_param( 'episode_id' );
		$episode    = ( new CatalogRepository() )->episode( $episode_id );

		if ( null === $episode ) {
			return new WP_Error( 'NOT_FOUND', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$duration = (int) $request->get_param( 'duration_seconds' );
		if ( $duration <= 0 ) {
			// The catalog's own figure when the player did not report one, so
			// "completed" can still be worked out.
			$duration = (int) $episode['duration_seconds'];
		}

		$position = (int) $request->get_param( 'position_seconds' );
		if ( $duration > 0 ) {
			// A position past the end means a bad clock or a retry sent after
			// the episode ended; clamping keeps the progress bar sane.
			$position = min( $position, $duration );
		}

		( new UserDataRepository() )->record_progress(
			get_current_user_id(),
			(int) $episode['work_id'],
			$episode_id,
			$position,
			$duration
		);

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Forget one episode.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function forget_episode( WP_REST_Request $request ): WP_REST_Response {
		( new UserDataRepository() )->forget( get_current_user_id(), (int) $request->get_param( 'episode_id' ) );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Forget everything.
	 */
	public function clear_history(): WP_REST_Response {
		( new UserDataRepository() )->clear_history( get_current_user_id() );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * The continue-watching rail on its own, for a pull-to-refresh.
	 */
	public function continue_watching(): WP_REST_Response {
		$rows = ( new UserDataRepository() )->continue_watching( get_current_user_id(), 20 );

		return new WP_REST_Response(
			array( 'items' => array_map( array( CatalogController::class, 'history_payload' ), $rows ) )
		);
	}

	/**
	 * Stored preferences merged over the defaults.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	private function stored_settings( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::SETTINGS_META, true );
		$stored = is_array( $stored ) ? $stored : array();

		// Defaults first, so a preference added in a later release appears for
		// users who saved their settings before it existed.
		return array_merge( self::SETTINGS_SCHEMA, array_intersect_key( $stored, self::SETTINGS_SCHEMA ) );
	}

	/**
	 * Shared arguments for the library routes.
	 *
	 * @return array<string, mixed>
	 */
	private function list_args(): array {
		return array(
			'work_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'list'    => array( 'type' => 'string', 'default' => 'favorite', 'enum' => array( 'favorite', 'watchlist' ) ),
		);
	}
}
