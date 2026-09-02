<?php
/**
 * How a request from the app becomes a logged-in user.
 *
 * WordPress authenticates a browser with a cookie and a nonce. A native app
 * holds neither, so it presents a bearer token, and this class hooks
 * `determine_current_user` to turn that token into a user id before any
 * permission callback runs. From that point on the rest of the plugin — and
 * WordPress itself — sees an ordinary logged-in user, so `current_user_can()`
 * is the authority everywhere and §8's rule holds without a second code path.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\TokenRepository;
use Animeh\Support\ApiToken;

/**
 * Bearer-token authentication for the app's REST calls.
 */
final class Auth {

	/**
	 * The user resolved from the token on this request.
	 *
	 * Cached because `determine_current_user` fires more than once per request
	 * and each miss would otherwise be another query.
	 */
	private static ?int $resolved = null;

	/**
	 * The token presented on this request, for logout.
	 */
	private static string $presented = '';

	/**
	 * Whether the filter has already run, so a null result is not re-derived.
	 */
	private static bool $attempted = false;

	/**
	 * Register the filter.
	 */
	public static function register(): void {
		// Priority 20: after WordPress's own cookie check, so a request that is
		// already authenticated by a browser session is left alone.
		add_filter( 'determine_current_user', array( self::class, 'resolve' ), 20 );

		// Without this, a REST call that authenticated by token still fails
		// WordPress's nonce check with `rest_cookie_invalid_nonce`. The nonce
		// exists to stop a browser being tricked into using its own cookie; a
		// bearer token is not sent automatically by anything, so there is no
		// cross-site request to forge.
		add_filter( 'rest_authentication_errors', array( self::class, 'allow_token_requests' ), 20 );

		// After the two above, so it sees the user they resolved. A sanction
		// that only stopped new logins would leave every already-issued token
		// working, which is the sanction missed.
		add_filter( 'rest_authentication_errors', array( self::class, 'block_banned_users' ), 30 );
	}

	/**
	 * Refuse a banned user's requests to this plugin's endpoints.
	 *
	 * Scoped to `animeh/v1` on purpose. This filter runs for every REST route
	 * on the site, and locking someone out of WordPress core's own API — their
	 * profile, the block editor — is a different and larger decision than
	 * suspending them from the app.
	 *
	 * @param mixed $result Current authentication result.
	 * @return mixed
	 */
	public static function block_banned_users( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! self::is_animeh_route() ) {
			return $result;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $result;
		}

		$ban = ( new \Animeh\Storage\ModerationRepository() )->active_ban( $user_id );
		if ( null === $ban ) {
			return $result;
		}

		return self::ban_error( $ban );
	}

	/**
	 * The refusal a banned user gets, with what the app needs to explain it.
	 *
	 * @param array<string, mixed> $ban Ban row.
	 */
	public static function ban_error( array $ban ): \WP_Error {
		$expires = $ban['expires_at'] ?? null;

		return new \WP_Error(
			'ACCOUNT_BANNED',
			null === $expires
				? __( 'Hesabın kalıcı olarak kapatıldı.', 'animeh' )
				: __( 'Hesabın geçici olarak uzaklaştırıldı.', 'animeh' ),
			array(
				'status'     => 403,
				'reason'     => (string) ( $ban['reason'] ?? '' ),
				'expires_at' => null === $expires ? '' : (string) $expires,
				'permanent'  => null === $expires,
			)
		);
	}

	/**
	 * Whether this request is for one of this plugin's REST routes.
	 *
	 * Both spellings are checked: pretty permalinks put the route in the path,
	 * plain ones put it in `rest_route`.
	 */
	private static function is_animeh_route(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$route = isset( $_GET['rest_route'] )
			? (string) wp_unslash( $_GET['rest_route'] )
			: (string) ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return str_contains( $route, FontsController::NAMESPACE . '/' );
	}

	/**
	 * Resolve a bearer token to a user id.
	 *
	 * @param int|false $user_id What WordPress decided already.
	 * @return int|false
	 */
	public static function resolve( $user_id ) {
		// Someone already authenticated — a cookie session in wp-admin, say.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		if ( self::$attempted ) {
			return self::$resolved > 0 ? self::$resolved : $user_id;
		}

		self::$attempted = true;

		$token = self::presented_token();
		if ( '' === $token ) {
			return $user_id;
		}

		$resolved = ( new TokenRepository() )->user_for( $token, 'access' );
		if ( $resolved <= 0 ) {
			// Deliberately not an error: returning false here would break
			// wp-admin for anyone whose browser sent a stale header. An invalid
			// token means "not logged in", and the endpoint's own permission
			// callback produces the 401.
			return $user_id;
		}

		self::$resolved = $resolved;

		return $resolved;
	}

	/**
	 * Let a token-authenticated REST request through the nonce check.
	 *
	 * @param mixed $result Current authentication result.
	 * @return mixed
	 */
	public static function allow_token_requests( $result ) {
		if ( true === $result || is_wp_error( $result ) && 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
			return $result;
		}

		if ( self::$resolved > 0 && '' !== self::presented_token() ) {
			return true;
		}

		return $result;
	}

	/**
	 * The token on this request, if any.
	 */
	public static function presented_token(): string {
		if ( '' !== self::$presented ) {
			return self::$presented;
		}

		$header = '';

		// Apache with mod_php hides the header unless it is passed through, so
		// the redirect copy is checked too — the usual cause of "works on nginx,
		// 401 on shared hosting".
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
				if ( '' !== $header ) {
					break;
				}
			}
		}

		if ( '' === $header && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
						$header = sanitize_text_field( (string) $value );
						break;
					}
				}
			}
		}

		self::$presented = ApiToken::from_header( $header );

		return self::$presented;
	}

	/**
	 * Whether this request authenticated with a token rather than a cookie.
	 */
	public static function is_token_request(): bool {
		return '' !== self::presented_token();
	}

	/**
	 * Reset the per-request cache. Tests only.
	 */
	public static function reset(): void {
		self::$resolved  = null;
		self::$presented = '';
		self::$attempted = false;
	}
}
