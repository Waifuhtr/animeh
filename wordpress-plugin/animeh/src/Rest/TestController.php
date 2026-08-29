<?php
/**
 * Player test run endpoints.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Media\ProxyHandler;
use Animeh\Storage\SessionRepository;
use Animeh\Support\TestVerdict;
use Animeh\Support\UrlGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Starts, records and lists player test runs.
 */
final class TestController {

	/**
	 * Option holding saved test sources.
	 */
	public const PRESETS_OPTION = 'animeh_test_presets';

	/**
	 * Option holding plugin settings.
	 */
	public const SETTINGS_OPTION = 'animeh_settings';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;

		register_rest_route(
			$namespace,
			'/test/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'config' ),
				'permission_callback' => array( Permissions::class, 'require_manage' ),
			)
		);

		register_rest_route(
			$namespace,
			'/test/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_presets' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_preset' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
					'args'                => $this->preset_args(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/test/presets/(?P<id>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_preset' ),
				'permission_callback' => array( Permissions::class, 'require_manage' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/test/sessions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_sessions' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
					'args'                => array(
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100,
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_session' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
					'args'                => array(
						'source_url'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'source_type'   => array(
							'type'              => 'string',
							'default'           => 'auto',
							'enum'              => array( 'auto', 'hls', 'mkv', 'mp4' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'subtitle_url'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'esc_url_raw',
						),
						'throttle_kbps' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/test/sessions/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_session' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_session' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_session' ),
					'permission_callback' => array( Permissions::class, 'require_manage' ),
					'args'                => $this->id_arg(),
				),
			)
		);
	}

	/**
	 * Everything the panel needs on load.
	 */
	public function config(): WP_REST_Response {
		$settings = self::settings();

		return new WP_REST_Response(
			array(
				'version'     => \Animeh\VERSION,
				'assets'      => array(
					'player'      => ANIMEH_PLUGIN_URL . 'assets/player/animeh-player.js',
					'style'       => ANIMEH_PLUGIN_URL . 'assets/player/animeh-player.css',
					'worker'      => ANIMEH_PLUGIN_URL . 'assets/jassub/jassub-worker.js',
					'wasm'        => ANIMEH_PLUGIN_URL . 'assets/jassub/jassub-worker.wasm',
					'modern_wasm' => ANIMEH_PLUGIN_URL . 'assets/jassub/jassub-worker-modern.wasm',
				),
				'proxy_url'   => ProxyHandler::endpoint(),
				'presets'     => self::presets(),
				'settings'    => array(
					'host_allowlist' => $settings['host_allowlist'],
					'allow_any_host' => $settings['allow_any_host'],
				),
				'can_manage'  => Permissions::current_user_can_manage(),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'proxy_nonce' => wp_create_nonce( ProxyHandler::NONCE_ACTION ),
			)
		);
	}

	/**
	 * Saved test sources.
	 */
	public function list_presets(): WP_REST_Response {
		return new WP_REST_Response( array( 'presets' => self::presets() ) );
	}

	/**
	 * Save a test source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_preset( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'source_url' );

		$check = self::check_url( $url );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$presets = self::presets();
		if ( count( $presets ) >= 50 ) {
			return new WP_Error(
				'animeh_preset_limit',
				__( 'Kayıtlı kaynak sınırına ulaşıldı.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$preset = array(
			'id'            => wp_generate_uuid4(),
			'label'         => (string) $request->get_param( 'label' ),
			'source_url'    => $url,
			'source_type'   => (string) $request->get_param( 'source_type' ),
			'subtitle_url'  => (string) $request->get_param( 'subtitle_url' ),
			'throttle_kbps' => (int) $request->get_param( 'throttle_kbps' ),
		);

		$presets[] = $preset;
		update_option( self::PRESETS_OPTION, $presets, false );

		return new WP_REST_Response( array( 'preset' => $preset ), 201 );
	}

	/**
	 * Remove a saved source.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_preset( WP_REST_Request $request ): WP_REST_Response {
		$id      = (string) $request->get_param( 'id' );
		$presets = array_values(
			array_filter(
				self::presets(),
				static fn( array $preset ): bool => ( $preset['id'] ?? '' ) !== $id
			)
		);
		update_option( self::PRESETS_OPTION, $presets, false );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * Start a run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_session( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'source_url' );

		$check = self::check_url( $url );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$subtitle = (string) $request->get_param( 'subtitle_url' );
		if ( '' !== $subtitle ) {
			$subtitle_check = self::check_url( $subtitle );
			if ( is_wp_error( $subtitle_check ) ) {
				return $subtitle_check;
			}
		}

		$id = SessionRepository::create(
			array(
				'source_url'    => $url,
				'source_type'   => (string) $request->get_param( 'source_type' ),
				'subtitle_url'  => $subtitle,
				'throttle_kbps' => (int) $request->get_param( 'throttle_kbps' ),
			),
			get_current_user_id()
		);

		if ( null === $id ) {
			return new WP_Error(
				'animeh_session_failed',
				__( 'Test oturumu oluşturulamadı.', 'animeh' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'session' => SessionRepository::find( $id ) ), 201 );
	}

	/**
	 * Append results to a run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_session( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$updates = array();

		if ( isset( $body['metrics'] ) && is_array( $body['metrics'] ) ) {
			$updates['metrics'] = $body['metrics'];
		}
		if ( isset( $body['font_report'] ) && is_array( $body['font_report'] ) ) {
			$updates['font_report'] = $body['font_report'];
		}
		if ( isset( $body['events'] ) && is_array( $body['events'] ) ) {
			$updates['events'] = self::sanitise_events( $body['events'] );
		}

		// The verdict is decided here rather than taken from the client: it is
		// the record the operator will trust later, and the browser is free to
		// send whatever it likes.
		if ( isset( $body['states'] ) && is_array( $body['states'] ) ) {
			$states             = array_map( 'strval', $body['states'] );
			$updates['verdict'] = TestVerdict::decide( $states, $updates['metrics'] ?? array() );
		}

		if ( array() === $updates ) {
			return new WP_Error(
				'animeh_nothing_to_update',
				__( 'Güncellenecek bir şey yok.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		if ( ! SessionRepository::update( $id, $updates ) ) {
			return new WP_Error(
				'animeh_session_not_found',
				__( 'Test oturumu bulunamadı.', 'animeh' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'session' => SessionRepository::find( $id ) ) );
	}

	/**
	 * One run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_session( WP_REST_Request $request ) {
		$session = SessionRepository::find( (int) $request->get_param( 'id' ) );
		if ( null === $session ) {
			return new WP_Error(
				'animeh_session_not_found',
				__( 'Test oturumu bulunamadı.', 'animeh' ),
				array( 'status' => 404 )
			);
		}
		return new WP_REST_Response( array( 'session' => $session ) );
	}

	/**
	 * Recent runs.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_sessions( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		return new WP_REST_Response(
			array(
				'sessions' => SessionRepository::recent( $per_page, ( $page - 1 ) * $per_page ),
				'total'    => SessionRepository::count(),
			)
		);
	}

	/**
	 * Delete a run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_session( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! SessionRepository::delete( $id ) ) {
			return new WP_Error(
				'animeh_session_not_found',
				__( 'Test oturumu bulunamadı.', 'animeh' ),
				array( 'status' => 404 )
			);
		}
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * Saved test sources.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function presets(): array {
		$presets = get_option( self::PRESETS_OPTION, array() );
		return is_array( $presets ) ? array_values( array_filter( $presets, 'is_array' ) ) : array();
	}

	/**
	 * Plugin settings with defaults applied.
	 *
	 * @return array{host_allowlist: string[], allow_any_host: bool}
	 */
	public static function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$allowlist = $stored['host_allowlist'] ?? array();
		$allowlist = is_array( $allowlist ) ? array_values( array_filter( array_map( 'strval', $allowlist ) ) ) : array();

		return array(
			'host_allowlist' => $allowlist,
			// Off unless deliberately enabled: the proxy fetching arbitrary
			// hosts is the plugin's largest attack surface.
			'allow_any_host' => (bool) ( $stored['allow_any_host'] ?? false ),
		);
	}

	/**
	 * Vet a URL against the guard and the configured allowlist.
	 *
	 * @param string $url URL to check.
	 * @return true|WP_Error
	 */
	public static function check_url( string $url ) {
		$settings  = self::settings();
		$allowlist = $settings['allow_any_host'] ? array() : $settings['host_allowlist'];

		$result = UrlGuard::check( $url, $allowlist );
		if ( $result->allowed() ) {
			return true;
		}

		$messages = array(
			'malformed_url'      => __( 'Geçersiz URL.', 'animeh' ),
			'unsupported_scheme' => __( 'Yalnızca http ve https adresleri kullanılabilir.', 'animeh' ),
			'credentials_in_url' => __( 'URL içinde kullanıcı adı veya şifre olamaz.', 'animeh' ),
			'host_not_allowed'   => __( 'Bu alan adı izin listesinde değil. Ayarlardan ekleyebilirsin.', 'animeh' ),
			'unresolvable_host'  => __( 'Alan adı çözümlenemedi.', 'animeh' ),
			'private_address'    => __( 'Bu adres özel bir ağa işaret ediyor ve kullanılamaz.', 'animeh' ),
		);

		return new WP_Error(
			'animeh_url_rejected',
			$messages[ (string) $result->reason ] ?? __( 'Bu URL kullanılamaz.', 'animeh' ),
			array(
				'status' => 400,
				'reason' => $result->reason,
			)
		);
	}

	/**
	 * Clean log lines coming from the browser.
	 *
	 * @param array<int, mixed> $events Raw events.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitise_events( array $events ): array {
		$clean = array();
		foreach ( array_slice( $events, -200 ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$clean[] = array(
				'at'      => isset( $event['at'] ) ? (int) $event['at'] : 0,
				'tone'    => in_array( $event['tone'] ?? '', array( 'info', 'ok', 'warn', 'error' ), true )
					? (string) $event['tone']
					: 'info',
				// Log lines are rendered into the admin page, so anything that
				// could carry markup is stripped on the way in.
				'message' => sanitize_text_field( (string) ( $event['message'] ?? '' ) ),
			);
		}
		return $clean;
	}

	/**
	 * Shared `id` argument schema.
	 *
	 * @return array<string, mixed>
	 */
	private function id_arg(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Argument schema for saving a preset.
	 *
	 * @return array<string, mixed>
	 */
	private function preset_args(): array {
		return array(
			'label'         => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'source_url'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'source_type'   => array(
				'type'              => 'string',
				'default'           => 'auto',
				'enum'              => array( 'auto', 'hls', 'mkv', 'mp4' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'subtitle_url'  => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			),
			'throttle_kbps' => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
