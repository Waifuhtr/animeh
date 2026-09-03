<?php
/**
 * Firebase configuration, and sending a notification through FCM.
 *
 * Two halves with very different sensitivity, kept in one place because they
 * come from one console:
 *
 * 1. The **client config** — api key, app id, project id, database URL, sender
 *    id. These are not secrets. They are compiled into every Android app that
 *    ships a google-services.json, and Firebase's own documentation says so:
 *    what protects the data is the security rules, not the config. Serving
 *    them from `/config` is what lets this app be pointed at a Firebase
 *    project without a rebuild, which is the same reason the server address
 *    is configurable.
 *
 * 2. The **service account** — a private key that can send a notification to
 *    anyone. Encrypted at rest with the rest of this plugin's credentials, and
 *    it never leaves the server.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\SecretBox;
use Animeh\Support\ServiceAccountJwt;
use WP_Error;

/**
 * Firebase settings and the FCM sender.
 */
final class FirebaseClient {

	/**
	 * Option holding the configuration.
	 */
	private const OPTION = 'animeh_firebase';

	/**
	 * Where the cached access token lives. Google issues them for an hour;
	 * fifty minutes leaves room for a slow request at the end of the window.
	 */
	private const TOKEN_TRANSIENT = 'animeh_fcm_token';
	private const TOKEN_TTL       = 3000;

	/**
	 * Current configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$account = (string) ( $stored['service_account'] ?? '' );
		if ( '' !== $account ) {
			$account = self::box()->open( $account );
		}

		return array(
			'api_key'         => (string) ( $stored['api_key'] ?? '' ),
			'app_id'          => (string) ( $stored['app_id'] ?? '' ),
			'project_id'      => (string) ( $stored['project_id'] ?? '' ),
			'database_url'    => rtrim( (string) ( $stored['database_url'] ?? '' ), '/' ),
			'sender_id'       => (string) ( $stored['sender_id'] ?? '' ),
			'service_account' => $account,
			'enabled'         => (bool) ( $stored['enabled'] ?? true ),
		);
	}

	/**
	 * Save configuration. An empty service account keeps the stored one.
	 *
	 * @param array<string, mixed> $data New values.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_settings( array $data ) {
		$current = self::settings();

		$account = trim( (string) ( $data['service_account'] ?? '' ) );
		if ( '' === $account ) {
			$account = $current['service_account'];
		} elseif ( null === ServiceAccountJwt::parse( $account ) ) {
			return new WP_Error(
				'VALIDATION_ERROR',
				__( 'Servis hesabı JSON\'u okunamadı. Firebase konsolundan indirdiğin dosyanın tamamını yapıştır.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		update_option(
			self::OPTION,
			array(
				'api_key'         => sanitize_text_field( (string) ( $data['api_key'] ?? $current['api_key'] ) ),
				'app_id'          => sanitize_text_field( (string) ( $data['app_id'] ?? $current['app_id'] ) ),
				'project_id'      => sanitize_text_field( (string) ( $data['project_id'] ?? $current['project_id'] ) ),
				'database_url'    => esc_url_raw( (string) ( $data['database_url'] ?? $current['database_url'] ) ),
				'sender_id'       => sanitize_text_field( (string) ( $data['sender_id'] ?? $current['sender_id'] ) ),
				'service_account' => '' === $account ? '' : self::box()->seal( $account ),
				'enabled'         => ! empty( $data['enabled'] ),
			),
			false
		);

		delete_transient( self::TOKEN_TRANSIENT );

		return self::settings();
	}

	/**
	 * What the app is told, which is everything except the private key.
	 *
	 * @return array<string, mixed>
	 */
	public static function client_config(): array {
		$settings = self::settings();

		if ( ! $settings['enabled'] || '' === $settings['database_url'] ) {
			// An app told nothing simply does not offer watch parties, which
			// is the right behaviour on an install that has not set Firebase
			// up rather than a screen full of connection errors.
			return array();
		}

		return array(
			'api_key'      => $settings['api_key'],
			'app_id'       => $settings['app_id'],
			'project_id'   => $settings['project_id'],
			'database_url' => $settings['database_url'],
			'sender_id'    => $settings['sender_id'],
		);
	}

	/**
	 * The shape safe to show an administrator.
	 *
	 * @return array<string, mixed>
	 */
	public static function public_settings(): array {
		$settings = self::settings();
		$parsed   = '' === $settings['service_account']
			? null
			: ServiceAccountJwt::parse( $settings['service_account'] );

		return array(
			'api_key'         => $settings['api_key'],
			'app_id'          => $settings['app_id'],
			'project_id'      => $settings['project_id'],
			'database_url'    => $settings['database_url'],
			'sender_id'       => $settings['sender_id'],
			'enabled'         => $settings['enabled'],
			'has_account'     => null !== $parsed,
			'account_email'   => null === $parsed ? '' : $parsed['client_email'],
			'ready'           => self::can_send(),
		);
	}

	/**
	 * Whether a notification could actually be sent right now.
	 */
	public static function can_send(): bool {
		$settings = self::settings();

		return $settings['enabled']
			&& '' !== $settings['service_account']
			&& null !== ServiceAccountJwt::parse( $settings['service_account'] );
	}

	/**
	 * Send one data notification to a set of devices.
	 *
	 * FCM v1 has no multicast: one HTTP request per token. That is fine at the
	 * scale this runs at — a handful of friends being invited — and the loop
	 * stops at a sane ceiling rather than trusting the caller.
	 *
	 * A token FCM rejects as unregistered is deleted here. Dead tokens
	 * otherwise accumulate for the life of the install and every send gets
	 * slower.
	 *
	 * @param string[]              $tokens Registration tokens.
	 * @param string                $title  Notification title.
	 * @param string                $body   Notification body.
	 * @param array<string, string> $data   Extra key/values for the app.
	 * @return int How many were accepted.
	 */
	public function send( array $tokens, string $title, string $body, array $data = array() ): int {
		if ( ! self::can_send() || array() === $tokens ) {
			return 0;
		}

		$token = $this->access_token();
		if ( null === $token ) {
			return 0;
		}

		$settings = self::settings();
		$parsed   = ServiceAccountJwt::parse( $settings['service_account'] );
		$project  = null === $parsed ? $settings['project_id'] : $parsed['project_id'];

		if ( '' === $project ) {
			return 0;
		}

		$url  = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";
		$sent = 0;

		foreach ( array_slice( $tokens, 0, 200 ) as $device ) {
			$message = array(
				'message' => array(
					'token'        => $device,
					'notification' => array(
						'title' => $title,
						'body'  => $body,
					),
					// Data as well as a notification: the notification is what
					// shows in the tray while the app is closed, and the data
					// is what the app reads when it is opened from the tap.
					'data'         => array_map( 'strval', $data ),
					'android'      => array(
						'priority' => 'high',
					),
				),
			);

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $message ),
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $status ) {
				++$sent;
				continue;
			}

			// 404 UNREGISTERED and 400 INVALID_ARGUMENT on the token both mean
			// this device will never receive anything again.
			if ( 404 === $status || 403 === $status ) {
				( new SocialRepository() )->forget_device( $device );
			}

			( new LogRepository() )->error(
				'FCM_ERROR',
				'FCM ' . $status,
				array( 'status' => $status, 'body' => substr( (string) wp_remote_retrieve_body( $response ), 0, 500 ) )
			);
		}

		return $sent;
	}

	/**
	 * A Google access token, cached until it is nearly expired.
	 */
	private function access_token(): ?string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$parsed = ServiceAccountJwt::parse( self::settings()['service_account'] );
		if ( null === $parsed ) {
			return null;
		}

		$assertion = ServiceAccountJwt::sign(
			ServiceAccountJwt::payload( $parsed['client_email'], time() ),
			$parsed['private_key']
		);

		if ( null === $assertion ) {
			( new LogRepository() )->error( 'FCM_ERROR', 'Servis hesabı anahtarı imzalanamadı', array() );

			return null;
		}

		$response = wp_remote_post(
			ServiceAccountJwt::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$token   = is_array( $decoded ) ? (string) ( $decoded['access_token'] ?? '' ) : '';

		if ( '' === $token ) {
			( new LogRepository() )->error(
				'FCM_ERROR',
				'Erişim jetonu alınamadı',
				array( 'status' => (int) wp_remote_retrieve_response_code( $response ) )
			);

			return null;
		}

		set_transient( self::TOKEN_TRANSIENT, $token, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Encryption bound to this installation's salts.
	 */
	private static function box(): SecretBox {
		$material = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '' );

		return new SecretBox( '' === $material ? 'animeh-fallback' : $material );
	}
}
