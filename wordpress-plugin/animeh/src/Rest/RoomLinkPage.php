<?php
/**
 * The page a watch-party invite link lands on.
 *
 * A room link is pasted into WhatsApp, Discord or a text message, so it has to
 * be an ordinary https address: most chat apps will not make a custom scheme
 * like `animeh://` tappable, and several strip it entirely. So the link is a
 * real page on this site, and the page's job is to get out of the way — it
 * hands the phone straight to the app.
 *
 * The handover uses an `intent://` URL rather than App Links verification.
 * Verified links are nicer — no chooser — but they need this site's
 * assetlinks.json to carry the release certificate's SHA-256 fingerprint,
 * which is a step an operator has to do by hand after signing. The intent URL
 * works with no setup at all, and `S.browser_fallback_url` sends anyone
 * without the app to the same page with a download link instead of a dead end.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\CatalogRepository;
use Animeh\Storage\SocialRepository;

/**
 * Serves /oda/{code}.
 */
final class RoomLinkPage {

	/**
	 * The query variable the rewrite fills in.
	 */
	private const QUERY_VAR = 'animeh_room';

	/**
	 * The app's package, which the intent URL has to name.
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
	 * Map /oda/{code} onto the query variable.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule(
			'^oda/([A-Za-z0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		// Flushing rewrite rules is expensive, so it happens only when the
		// rule is not already there — which is the first load after the
		// plugin is updated, and never again.
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && ! isset( $rules['^oda/([A-Za-z0-9]+)/?$'] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Let WordPress carry the code through.
	 *
	 * @param string[] $vars Registered query variables.
	 * @return string[]
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Render the handover page, if this request is one.
	 */
	public static function maybe_render(): void {
		$code = get_query_var( self::QUERY_VAR );

		if ( ! is_string( $code ) || '' === $code ) {
			return;
		}

		$room  = ( new SocialRepository() )->room_by_code( $code );
		$title = '';

		if ( null !== $room ) {
			$work = ( new CatalogRepository() )->work( (int) $room['work_id'] );
			if ( null !== $work ) {
				$title = (string) $work['title'];
			}
		}

		$deep_link = 'intent://oda/' . rawurlencode( $code )
			. '#Intent;scheme=animeh;package=' . self::PACKAGE
			. ';S.browser_fallback_url=' . rawurlencode( home_url( '/oda/' . rawurlencode( $code ) . '?app=0' ) )
			. ';end';

		// `?app=0` is what the fallback comes back as: without it the page
		// would bounce anyone who has no app back to itself for ever.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect = ! isset( $_GET['app'] );

		status_header( null === $room ? 404 : 200 );
		nocache_headers();

		self::render( $code, $title, $deep_link, $redirect, null !== $room );
		exit;
	}

	/**
	 * The page itself.
	 *
	 * @param string $code      Room code.
	 * @param string $title     Anime title, when the room is still open.
	 * @param string $deep_link The intent URL.
	 * @param bool   $redirect  Whether to attempt the handover.
	 * @param bool   $open      Whether the room is still open.
	 */
	private static function render( string $code, string $title, string $deep_link, bool $redirect, bool $open ): void {
		?>
<!DOCTYPE html>
<html lang="tr">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( '' === $title ? __( 'Birlikte izleme', 'animeh' ) : $title ); ?></title>
	<style>
		:root { color-scheme: dark; }
		body {
			margin: 0; min-height: 100vh; display: flex; align-items: center;
			justify-content: center; background: #0d0d0f; color: #f4f4f5;
			font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
			padding: 24px; text-align: center;
		}
		.card { max-width: 26rem; }
		h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
		p { color: #a1a1aa; margin: .5rem 0; }
		a.button {
			display: inline-block; margin-top: 1.5rem; padding: .8rem 1.6rem;
			background: #7c5cff; color: #fff; border-radius: 12px;
			text-decoration: none; font-weight: 600;
		}
		code { color: #d4d4d8; }
	</style>
	<?php if ( $redirect && $open ) : ?>
		<meta http-equiv="refresh" content="0;url=<?php echo esc_attr( $deep_link ); ?>">
	<?php endif; ?>
</head>
<body>
	<div class="card">
		<?php if ( $open ) : ?>
			<h1><?php echo esc_html( '' === $title ? __( 'Birlikte izleme odası', 'animeh' ) : $title ); ?></h1>
			<p><?php esc_html_e( 'Uygulama açılıyor…', 'animeh' ); ?></p>
			<p><?php esc_html_e( 'Açılmadıysa aşağıdaki düğmeye dokun.', 'animeh' ); ?></p>
			<p><?php esc_html_e( 'Oda kodu:', 'animeh' ); ?> <code><?php echo esc_html( $code ); ?></code></p>
			<a class="button" href="<?php echo esc_attr( $deep_link ); ?>">
				<?php esc_html_e( 'Uygulamada aç', 'animeh' ); ?>
			</a>
		<?php else : ?>
			<h1><?php esc_html_e( 'Bu oda kapanmış', 'animeh' ); ?></h1>
			<p><?php esc_html_e( 'Odalar içindeki herkes ayrılınca kendini siler. Davet eden kişiden yeni bir bağlantı isteyebilirsin.', 'animeh' ); ?></p>
		<?php endif; ?>
	</div>
</body>
</html>
		<?php
	}
}
