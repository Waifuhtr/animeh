<?php
/**
 * Encryption for credentials held in the database.
 *
 * Storage keys are the one secret this plugin holds that grants write access to
 * somewhere else. Keeping them as plaintext in `wp_options` means any read of
 * the database — a leaked backup, a SQL injection in an unrelated plugin, a
 * shared-host mishap — hands over the bucket.
 *
 * Encrypting with a key derived from WordPress's own salts moves the secret out
 * of the database and into `wp-config.php`. That is not perfect: an attacker
 * with the filesystem has both. It does defeat the far more common case where
 * only the database leaks.
 *
 * Free of any WordPress dependency, so the round trip can be tested directly;
 * the caller supplies the key material.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Authenticated encryption for short secrets.
 */
final class SecretBox {

	private const CIPHER  = 'aes-256-gcm';
	private const VERSION = 'v1';
	private const TAG_BYTES = 16;

	/**
	 * Key material; hashed to the cipher's key length before use.
	 */
	private string $key;

	/**
	 * @param string $key_material Anything with enough entropy, e.g. WordPress salts.
	 */
	public function __construct( string $key_material ) {
		// A raw salt is the wrong length and the wrong shape for a cipher key;
		// hashing gives exactly 32 bytes regardless of what came in.
		$this->key = hash( 'sha256', 'animeh-secret-box|' . $key_material, true );
	}

	/**
	 * Whether encryption is available on this host.
	 *
	 * Without OpenSSL the plugin still works; it just cannot protect a secret
	 * at rest, and says so rather than pretending.
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string Self-describing token, or the marked plaintext when
	 *                encryption is unavailable.
	 */
	public function seal( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! self::is_available() ) {
			// Marked so `open` knows not to try decrypting, and so an operator
			// reading the database can tell this value is not protected.
			return 'plain:' . base64_encode( $plaintext );
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = random_bytes( false === $iv_length ? 12 : $iv_length );
		$tag       = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES );
		if ( false === $ciphertext ) {
			return 'plain:' . base64_encode( $plaintext );
		}

		return self::VERSION . ':' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value produced by {@see self::seal()}.
	 *
	 * @param string $token Stored token.
	 * @return string Empty string when the token is absent, malformed, or was
	 *                sealed under a different key.
	 */
	public function open( string $token ): string {
		if ( '' === $token ) {
			return '';
		}

		if ( str_starts_with( $token, 'plain:' ) ) {
			$decoded = base64_decode( substr( $token, 6 ), true );
			return false === $decoded ? '' : $decoded;
		}

		if ( ! str_starts_with( $token, self::VERSION . ':' ) ) {
			// An unrecognised prefix means a value written by a version that
			// did not encrypt at all; treat it as plaintext so an upgrade does
			// not silently lose the credentials.
			return $token;
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$raw = base64_decode( substr( $token, strlen( self::VERSION ) + 1 ), true );
		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv_length = false === $iv_length ? 12 : $iv_length;
		if ( strlen( $raw ) <= $iv_length + self::TAG_BYTES ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_length );
		$tag        = substr( $raw, $iv_length, self::TAG_BYTES );
		$ciphertext = substr( $raw, $iv_length + self::TAG_BYTES );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag );
		// GCM authenticates: a wrong key or a tampered token fails here rather
		// than returning plausible rubbish.
		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Show enough of a secret to recognise it, and no more.
	 *
	 * @param string $secret Secret to mask.
	 */
	public static function mask( string $secret ): string {
		$length = strlen( $secret );
		if ( 0 === $length ) {
			return '';
		}
		if ( $length <= 8 ) {
			return str_repeat( '•', $length );
		}
		return substr( $secret, 0, 4 ) . str_repeat( '•', min( 16, $length - 8 ) ) . substr( $secret, -4 );
	}
}
