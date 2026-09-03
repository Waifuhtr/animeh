<?php
/**
 * Publishing the Digital Asset Links file that makes room links open the app.
 *
 * Android stopped offering a chooser for unverified web links in Android 12: a
 * link the system cannot prove belongs to an app goes straight to the browser,
 * silently, with no way for the app to ask. That is exactly the symptom of a
 * site with no `assetlinks.json` — the invite link opens a web page instead of
 * the app, and no amount of work on that page can fully fix it, because by
 * then the browser already has the navigation.
 *
 * Verification needs one thing this plugin cannot know: the SHA-256 fingerprint
 * of the certificate the APK was signed with. Only whoever signed the build
 * has it. So it is a setting, and this class turns it into the file Android
 * fetches. Until it is filled in, nothing is served and the handover page's
 * button remains the way in — which is why that page still exists.
 *
 * The fingerprint is not a secret. It is a hash of a public certificate, and
 * Google's own documentation has you publish it at a well-known URL on purpose.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

/**
 * Serves /.well-known/assetlinks.json.
 */
final class AppLinks {

	/**
	 * Option holding the signing fingerprints.
	 */
	public const OPTION = 'animeh_app_fingerprints';

	/**
	 * The query variable the rewrite fills in.
	 */
	private const QUERY_VAR = 'animeh_assetlinks';

	/**
	 * The package the fingerprints belong to.
	 */
	private const PACKAGE = 'com.animeh.app';

	/**
	 * Hook the rewrite and the renderer.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_var' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ) );
	}

	/**
	 * Map the well-known path onto the query variable.
	 *
	 * A real file on disk still wins — hosts that answer `/.well-known/` for
	 * Let's Encrypt do so before WordPress is reached, and an operator who
	 * would rather drop the file there can.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule(
			'^\.well-known/assetlinks\.json$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);

		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && ! isset( $rules['^\.well-known/assetlinks\.json$'] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Let WordPress carry the flag through.
	 *
	 * @param string[] $vars Registered query variables.
	 * @return string[]
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * The fingerprints an administrator has saved.
	 *
	 * @return string[]
	 */
	public static function fingerprints(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/**
	 * Save fingerprints from whatever an administrator pasted.
	 *
	 * `keytool` and `gradlew signingReport` print them colon-separated in
	 * upper case; `apksigner` prints them without colons in lower case. Both
	 * are accepted and normalised, because being told "invalid fingerprint"
	 * for a difference in punctuation is the kind of thing that costs an hour.
	 *
	 * @param string $raw One fingerprint per line.
	 * @return string[] What was stored.
	 */
	public static function save( string $raw ): array {
		$kept = array();

		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$normalised = self::normalise( (string) $line );

			if ( '' !== $normalised && ! in_array( $normalised, $kept, true ) ) {
				$kept[] = $normalised;
			}
		}

		update_option( self::OPTION, $kept, true );

		return $kept;
	}

	/**
	 * One fingerprint in the colon-separated upper-case form Android wants.
	 *
	 * @param string $value As pasted.
	 * @return string Normalised, or '' when it is not a SHA-256 fingerprint.
	 */
	public static function normalise( string $value ): string {
		$hex = strtoupper( (string) preg_replace( '/[^0-9A-Fa-f]/', '', $value ) );

		// 32 bytes, and only 32: a SHA-1 fingerprint pasted by mistake is a
		// file Android will fetch, parse and reject, which looks like the
		// setting not working at all.
		if ( 64 !== strlen( $hex ) ) {
			return '';
		}

		return implode( ':', str_split( $hex, 2 ) );
	}

	/**
	 * Serve the file, if this request is for it.
	 */
	public static function maybe_render(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$fingerprints = self::fingerprints();

		if ( array() === $fingerprints ) {
			// Nothing saved, so nothing to claim. A 404 is the honest answer:
			// an empty statement list would tell Android the app is *not*
			// associated with this site, which it would then cache.
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array() );
			exit;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );

		echo wp_json_encode( self::statements( $fingerprints ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * The statement list, in the shape Android's verifier expects.
	 *
	 * @param string[] $fingerprints Normalised fingerprints.
	 * @return array<int, array<string, mixed>>
	 */
	public static function statements( array $fingerprints ): array {
		return array(
			array(
				'relation' => array( 'delegate_permission/common.handle_all_urls' ),
				'target'   => array(
					'namespace'                => 'android_app',
					'package_name'             => self::PACKAGE,
					'sha256_cert_fingerprints' => array_values( $fingerprints ),
				),
			),
		);
	}
}
