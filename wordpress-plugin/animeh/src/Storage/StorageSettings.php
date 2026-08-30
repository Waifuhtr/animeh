<?php
/**
 * Bucket configuration.
 *
 * The secret key is encrypted at rest and never leaves the server: it is not
 * returned by any endpoint, and the admin screen shows only a mask. Everything
 * the app needs to reach storage arrives as a presigned URL instead, so the
 * credentials stay in one place.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\SecretBox;

/**
 * Reads and writes the storage configuration.
 */
final class StorageSettings {

	private const OPTION = 'animeh_storage';

	/**
	 * Backblaze region, e.g. `us-west-004`.
	 */
	public readonly string $region;

	/**
	 * Bucket name.
	 */
	public readonly string $bucket;

	/**
	 * S3-compatible endpoint host, without a scheme.
	 */
	public readonly string $endpoint;

	/**
	 * Application key id.
	 */
	public readonly string $key_id;

	/**
	 * Application key. Never sent to a client.
	 */
	public readonly string $secret;

	/**
	 * Base URL for friendly download links, without a trailing slash.
	 *
	 * A CDN or custom domain in front of the bucket goes here.
	 */
	public readonly string $friendly_base;

	/**
	 * Whether objects are readable without a signature.
	 *
	 * A public bucket can serve plain URLs, which is cheaper and cacheable. A
	 * private one needs every playback URL presigned.
	 */
	public readonly bool $public_bucket;

	/**
	 * Lifetime of a presigned playback URL, in seconds.
	 */
	public readonly int $link_ttl;

	/**
	 * @param array<string, mixed> $data Raw stored values.
	 */
	private function __construct( array $data ) {
		$this->region        = (string) ( $data['region'] ?? '' );
		$this->bucket        = (string) ( $data['bucket'] ?? '' );
		$this->endpoint      = (string) ( $data['endpoint'] ?? '' );
		$this->key_id        = (string) ( $data['key_id'] ?? '' );
		$this->secret        = (string) ( $data['secret'] ?? '' );
		$this->friendly_base = rtrim( (string) ( $data['friendly_base'] ?? '' ), '/' );
		$this->public_bucket = (bool) ( $data['public_bucket'] ?? false );
		$ttl                 = (int) ( $data['link_ttl'] ?? 3600 );
		// Long enough for a full episode, short enough that a leaked link is
		// not a permanent one.
		$this->link_ttl = max( 300, min( $ttl, 604800 ) );
	}

	/**
	 * Load the stored configuration.
	 */
	public static function load(): self {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$box              = self::box();
		$stored['secret'] = $box->open( (string) ( $stored['secret'] ?? '' ) );

		return new self( $stored );
	}

	/**
	 * Save configuration, encrypting the secret.
	 *
	 * An empty secret leaves the stored one untouched, so the admin screen can
	 * submit the form without ever having received it.
	 *
	 * @param array<string, mixed> $input Values from the settings form.
	 */
	public static function save( array $input ): self {
		$current = self::load();

		$secret = trim( (string) ( $input['secret'] ?? '' ) );
		if ( '' === $secret ) {
			$secret = $current->secret;
		}

		$data = array(
			'region'        => sanitize_text_field( (string) ( $input['region'] ?? $current->region ) ),
			'bucket'        => sanitize_text_field( (string) ( $input['bucket'] ?? $current->bucket ) ),
			'endpoint'      => self::normalise_host( (string) ( $input['endpoint'] ?? $current->endpoint ) ),
			'key_id'        => sanitize_text_field( (string) ( $input['key_id'] ?? $current->key_id ) ),
			'secret'        => self::box()->seal( $secret ),
			'friendly_base' => esc_url_raw( (string) ( $input['friendly_base'] ?? $current->friendly_base ) ),
			'public_bucket' => (bool) ( $input['public_bucket'] ?? $current->public_bucket ),
			'link_ttl'      => (int) ( $input['link_ttl'] ?? $current->link_ttl ),
		);

		// Not autoloaded: this is read on a handful of admin and API requests,
		// not on every page of the site.
		update_option( self::OPTION, $data, false );

		return self::load();
	}

	/**
	 * Whether enough is configured to talk to the bucket.
	 */
	public function is_configured(): bool {
		return '' !== $this->region
			&& '' !== $this->bucket
			&& '' !== $this->endpoint
			&& '' !== $this->key_id
			&& '' !== $this->secret;
	}

	/**
	 * Default endpoint host for a region.
	 *
	 * @param string $region Backblaze region.
	 */
	public static function default_endpoint( string $region ): string {
		return '' === $region ? '' : 's3.' . $region . '.backblazeb2.com';
	}

	/**
	 * Base URL of the S3-compatible endpoint.
	 */
	public function s3_base(): string {
		return 'https://' . $this->endpoint;
	}

	/**
	 * The plain S3 URL for an object.
	 *
	 * @param string $key Object key.
	 */
	public function s3_url( string $key ): string {
		return $this->s3_base() . '/' . $this->bucket . '/' . ltrim( $key, '/' );
	}

	/**
	 * The friendly download URL for an object, when one is configured.
	 *
	 * @param string $key Object key.
	 */
	public function friendly_url( string $key ): string {
		if ( '' === $this->friendly_base ) {
			return '';
		}
		return $this->friendly_base . '/' . ltrim( $key, '/' );
	}

	/**
	 * The shape safe to hand a client: everything except the secret.
	 *
	 * @return array<string, mixed>
	 */
	public function to_public_array(): array {
		return array(
			'region'         => $this->region,
			'bucket'         => $this->bucket,
			'endpoint'       => $this->endpoint,
			'key_id'         => $this->key_id,
			// The secret itself is never returned; a mask is enough to confirm
			// which credential is in place.
			'secret_masked'  => SecretBox::mask( $this->secret ),
			'has_secret'     => '' !== $this->secret,
			'friendly_base'  => $this->friendly_base,
			'public_bucket'  => $this->public_bucket,
			'link_ttl'       => $this->link_ttl,
			'configured'     => $this->is_configured(),
			'encryption'     => SecretBox::is_available(),
		);
	}

	/**
	 * Encryption bound to this installation's salts.
	 */
	private static function box(): SecretBox {
		// Salts live in wp-config.php, so the key is not in the database the
		// ciphertext sits in. On an installation that never defined them,
		// AUTH_KEY falls back to a constant and this degrades to obfuscation —
		// which is why `to_public_array` reports whether encryption is real.
		$material = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '' );
		return new SecretBox( '' === $material ? 'animeh-fallback' : $material );
	}

	/**
	 * Reduce whatever was typed into a bare host.
	 *
	 * Operators paste full URLs; the signer needs just the host.
	 *
	 * @param string $value Endpoint as entered.
	 */
	private static function normalise_host( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( str_contains( $value, '://' ) ) {
			$host = parse_url( $value, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$value = is_string( $host ) ? $host : $value;
		}
		return rtrim( strtolower( $value ), '/' );
	}
}
