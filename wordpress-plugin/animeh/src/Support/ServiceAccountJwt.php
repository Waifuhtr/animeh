<?php
/**
 * The assertion that turns a Google service account into an access token.
 *
 * Free of WordPress so it can be tested here: the signature has to be exactly
 * right or every notification fails with a 401 that says nothing useful about
 * which of the several things went wrong.
 *
 * Google's OAuth2 service-account flow is: build a JWT whose claims say who
 * you are and what you want, sign it with the account's RSA private key, and
 * POST it to the token endpoint in exchange for an access token. FCM's HTTP v1
 * API takes that token as a bearer. The older "server key" this replaced was
 * a single secret with no expiry and was turned off by Google in 2024.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Builds and signs the service-account assertion.
 */
final class ServiceAccountJwt {

	/**
	 * Where the assertion is exchanged for a token.
	 */
	public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * What the token is allowed to do. Only sending messages.
	 */
	public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

	/**
	 * How long the assertion is valid. Google rejects anything over an hour.
	 */
	public const LIFETIME = 3600;

	/**
	 * Base64 as a URL wants it: no padding, and the two awkward characters
	 * swapped. JWT is built out of three of these joined by dots.
	 *
	 * @param string $value Raw bytes.
	 */
	public static function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * The unsigned part of the assertion: header and claims, dot-joined.
	 *
	 * @param string $email Service-account address (`client_email`).
	 * @param int    $now   Unix time to issue at.
	 * @return string
	 */
	public static function payload( string $email, int $now ): string {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$claims = array(
			'iss'   => $email,
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + self::LIFETIME,
		);

		return self::base64url( (string) wp_json_encode_compat( $header ) )
			. '.'
			. self::base64url( (string) wp_json_encode_compat( $claims ) );
	}

	/**
	 * Sign the assertion with the account's private key.
	 *
	 * @param string $payload     Output of [payload].
	 * @param string $private_key PEM from the service-account JSON.
	 * @return string|null The complete assertion, or null when the key is unusable.
	 */
	public static function sign( string $payload, string $private_key ): ?string {
		// The JSON file carries the PEM with literal backslash-n rather than
		// newlines when it has been through a form field, which openssl will
		// not parse. Normalising is cheaper than telling someone to fix it.
		$private_key = str_replace( '\\n', "\n", trim( $private_key ) );

		$key = openssl_pkey_get_private( $private_key );
		if ( false === $key ) {
			return null;
		}

		$signature = '';
		$signed    = openssl_sign( $payload, $signature, $key, OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			return null;
		}

		return $payload . '.' . self::base64url( $signature );
	}

	/**
	 * Pull the three fields that matter out of a service-account JSON file.
	 *
	 * @param string $json Contents of the downloaded key file.
	 * @return array{project_id: string, client_email: string, private_key: string}|null
	 */
	public static function parse( string $json ): ?array {
		$decoded = json_decode( trim( $json ), true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$project = (string) ( $decoded['project_id'] ?? '' );
		$email   = (string) ( $decoded['client_email'] ?? '' );
		$key     = (string) ( $decoded['private_key'] ?? '' );

		if ( '' === $project || '' === $email || '' === $key ) {
			return null;
		}

		return array(
			'project_id'   => $project,
			'client_email' => $email,
			'private_key'  => $key,
		);
	}
}

if ( ! function_exists( 'Animeh\\Support\\wp_json_encode_compat' ) ) {
	/**
	 * JSON encoding that works with or without WordPress loaded.
	 *
	 * The tests require this file directly, and `wp_json_encode` is not there.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function wp_json_encode_compat( $value ): string {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES );

		return false === $encoded ? '{}' : $encoded;
	}
}
