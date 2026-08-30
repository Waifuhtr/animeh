<?php
/**
 * Registration, sign-in, refresh, sign-out and password changes.
 *
 * Passwords stay in WordPress: §9 is explicit that they must not be duplicated
 * into another store, and `wp_check_password` already handles the hash upgrade
 * path across WordPress versions. What this adds is the token layer a native
 * app needs, and the rate limiting that an endpoint accepting passwords needs.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\LogRepository;
use Animeh\Storage\TokenRepository;
use Animeh\Support\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * The auth endpoints.
 */
final class AuthController {

	/**
	 * Failed sign-ins allowed per window, per address.
	 */
	private const LOGIN_LIMIT = 10;

	/**
	 * Registrations allowed per window, per address.
	 */
	private const REGISTER_LIMIT = 5;

	/**
	 * Window length for both.
	 */
	private const WINDOW = 900;

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;

		register_rest_route(
			$namespace,
			'/auth/register',
			array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'register_user' ),
				// Open by necessity — someone without an account is the point —
				// but gated on the site allowing registration and rate limited
				// inside the handler.
				'permission_callback' => array( $this, 'registration_open' ),
				'args'                => array(
					'username' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_user' ),
					'email'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
					'password' => array( 'required' => true, 'type' => 'string' ),
					'device'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/auth/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'login'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'password' => array( 'required' => true, 'type' => 'string' ),
					'device'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/auth/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				// The refresh token *is* the credential; there is nothing else
				// to check, and checking it here would mean checking it twice.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'refresh' ),
				'args'                => array(
					'refresh_token' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'device'        => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => array( self::class, 'require_login' ),
			)
		);

		register_rest_route(
			$namespace,
			'/auth/password/forgot',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'forgot_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'login' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/auth/password/change',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'change_password' ),
				'permission_callback' => array( self::class, 'require_login' ),
				'args'                => array(
					'current_password' => array( 'required' => true, 'type' => 'string' ),
					'new_password'     => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/auth/sessions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sessions' ),
				'permission_callback' => array( self::class, 'require_login' ),
			)
		);
	}

	/**
	 * `permission_callback` for anything a signed-in user may do.
	 *
	 * @return true|WP_Error
	 */
	public static function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'AUTH_ERROR',
			__( 'Bu işlem için giriş yapman gerekiyor.', 'animeh' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Whether the site accepts new accounts.
	 *
	 * @return true|WP_Error
	 */
	public function registration_open() {
		if ( (bool) get_option( 'users_can_register' ) ) {
			return true;
		}

		return new WP_Error(
			'AUTH_ERROR',
			__( 'Bu sitede kayıt kapalı.', 'animeh' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Create an account and sign it in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_user( WP_REST_Request $request ) {
		$limited = $this->check_limit( 'register', self::REGISTER_LIMIT );
		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$username = (string) $request->get_param( 'username' );
		$email    = (string) $request->get_param( 'email' );
		$password = (string) $request->get_param( 'password' );

		if ( strlen( $password ) < 8 ) {
			return new WP_Error(
				'AUTH_ERROR',
				__( 'Şifre en az 8 karakter olmalı.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'AUTH_ERROR', __( 'Geçerli bir e-posta gir.', 'animeh' ), array( 'status' => 400 ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => get_option( 'default_role', 'subscriber' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			// wp_insert_user's codes are specific enough to act on (taken
			// username vs. taken email), so they are passed through rather than
			// flattened into one message.
			return new WP_Error(
				'AUTH_ERROR',
				$user_id->get_error_message(),
				array(
					'status' => 400,
					'reason' => $user_id->get_error_code(),
				)
			);
		}

		$this->count_attempt( 'register' );

		$tokens = ( new TokenRepository() )->issue_pair( (int) $user_id, (string) $request->get_param( 'device' ) );

		( new LogRepository() )->record( 'info', 'AUTH_REGISTER', 'Yeni kayıt', array( 'user_id' => $user_id ), (int) $user_id );

		return new WP_REST_Response( $this->session_payload( (int) $user_id, $tokens ), 201 );
	}

	/**
	 * Sign in with a username or email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( WP_REST_Request $request ) {
		$limited = $this->check_limit( 'login', self::LOGIN_LIMIT );
		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$login    = (string) $request->get_param( 'login' );
		$password = (string) $request->get_param( 'password' );

		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );

		// One message and one code for "no such user" and "wrong password":
		// telling them apart is a way to enumerate who has an account here.
		if ( ! $user instanceof WP_User || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			$this->count_attempt( 'login' );
			( new LogRepository() )->record( 'warning', 'AUTH_ERROR', 'Başarısız giriş denemesi', array( 'login' => $login ) );

			return new WP_Error(
				'AUTH_ERROR',
				__( 'Kullanıcı adı veya şifre hatalı.', 'animeh' ),
				array( 'status' => 401 )
			);
		}

		$tokens = ( new TokenRepository() )->issue_pair( $user->ID, (string) $request->get_param( 'device' ) );

		return new WP_REST_Response( $this->session_payload( $user->ID, $tokens ) );
	}

	/**
	 * Exchange a refresh token for a new pair.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh( WP_REST_Request $request ) {
		$tokens = ( new TokenRepository() )->rotate(
			(string) $request->get_param( 'refresh_token' ),
			(string) $request->get_param( 'device' )
		);

		if ( null === $tokens ) {
			return new WP_Error(
				'AUTH_ERROR',
				__( 'Oturum süresi doldu, tekrar giriş yap.', 'animeh' ),
				array( 'status' => 401 )
			);
		}

		$user_id = ( new TokenRepository() )->user_for( $tokens['access'], 'access' );

		return new WP_REST_Response( $this->session_payload( $user_id, $tokens ) );
	}

	/**
	 * Sign out this device.
	 */
	public function logout(): WP_REST_Response {
		$token = Auth::presented_token();
		if ( '' !== $token ) {
			( new TokenRepository() )->revoke_session( $token );
		}

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Start a password reset.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function forgot_password( WP_REST_Request $request ): WP_REST_Response {
		$login = (string) $request->get_param( 'login' );
		$user  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );

		if ( $user instanceof WP_User ) {
			// WordPress's own flow: it mails a keyed link that expires, and
			// re-implementing that is how reset tokens get got wrong.
			retrieve_password( $user->user_login );
		}

		// The same answer either way. A different response for a known address
		// turns this endpoint into a way to check who has an account.
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Bu hesap varsa şifre sıfırlama bağlantısı gönderildi.', 'animeh' ),
			)
		);
	}

	/**
	 * Change the password of the signed-in user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_password( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		$new  = (string) $request->get_param( 'new_password' );

		if ( ! wp_check_password( (string) $request->get_param( 'current_password' ), $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'AUTH_ERROR', __( 'Mevcut şifre hatalı.', 'animeh' ), array( 'status' => 403 ) );
		}

		if ( strlen( $new ) < 8 ) {
			return new WP_Error( 'AUTH_ERROR', __( 'Yeni şifre en az 8 karakter olmalı.', 'animeh' ), array( 'status' => 400 ) );
		}

		wp_set_password( $new, $user->ID );

		$tokens = new TokenRepository();
		$tokens->revoke_all( $user->ID );

		// Signed out everywhere, then straight back in on this device: a
		// password change should end other sessions without making the person
		// who just changed it log in again.
		$fresh = $tokens->issue_pair( $user->ID, 'password-change' );

		( new LogRepository() )->record( 'info', 'AUTH_PASSWORD_CHANGED', 'Şifre değişti', array(), $user->ID );

		return new WP_REST_Response( $this->session_payload( $user->ID, $fresh ) );
	}

	/**
	 * Devices this user is signed in on.
	 */
	public function sessions(): WP_REST_Response {
		$rows = ( new TokenRepository() )->sessions( get_current_user_id() );

		return new WP_REST_Response( array( 'sessions' => $rows ) );
	}

	/**
	 * The body every auth endpoint returns.
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $tokens  Issued pair.
	 * @return array<string, mixed>
	 */
	private function session_payload( int $user_id, array $tokens ): array {
		return array(
			'access_token'       => $tokens['access'],
			'refresh_token'      => $tokens['refresh'],
			'token_type'         => 'Bearer',
			'expires_in'         => $tokens['expires_in'],
			'refresh_expires_in' => $tokens['refresh_expires_in'],
			'user'               => self::user_payload( $user_id ),
		);
	}

	/**
	 * The public shape of a user.
	 *
	 * `is_admin` is here because the app needs to know whether to draw the
	 * admin tab — and for nothing else. Every admin endpoint re-checks the
	 * capability server-side, so a client that lies about this gains nothing.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	public static function user_payload( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return array();
		}

		return array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'avatar'       => get_avatar_url( $user->ID, array( 'size' => 256 ) ),
			'roles'        => array_values( $user->roles ),
			'is_admin'     => user_can( $user, Permissions::CAPABILITY ) || user_can( $user, 'manage_options' ),
			'registered'   => $user->user_registered,
		);
	}

	/**
	 * Refuse when this address has spent its attempts.
	 *
	 * @param string $bucket What is being limited.
	 * @param int    $limit  Attempts per window.
	 * @return true|WP_Error
	 */
	private function check_limit( string $bucket, int $limit ) {
		$now  = time();
		$key  = RateLimit::key( $bucket, $this->actor(), self::WINDOW, $now );
		$used = (int) get_transient( $key );

		if ( RateLimit::allows( $used, $limit ) ) {
			return true;
		}

		$retry = RateLimit::retry_after( self::WINDOW, $now );

		return new WP_Error(
			'RATE_LIMITED',
			sprintf(
				/* translators: %d: seconds to wait. */
				__( 'Çok fazla deneme. %d saniye sonra tekrar dene.', 'animeh' ),
				$retry
			),
			array(
				'status'      => 429,
				'retry_after' => $retry,
			)
		);
	}

	/**
	 * Count one attempt against the current window.
	 *
	 * @param string $bucket What is being limited.
	 */
	private function count_attempt( string $bucket ): void {
		$now = time();
		$key = RateLimit::key( $bucket, $this->actor(), self::WINDOW, $now );

		// Expiry is the remaining window, so the counter disappears with it
		// rather than needing a sweep.
		set_transient( $key, (int) get_transient( $key ) + 1, RateLimit::retry_after( self::WINDOW, $now ) );
	}

	/**
	 * Who is making this request, for limiting purposes.
	 */
	private function actor(): string {
		// REMOTE_ADDR only. A forwarded-for header is set by the client unless
		// a proxy overwrites it, so trusting one here would let an attacker
		// pick a fresh identity per request and defeat the limit entirely.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return $ip;
	}
}
