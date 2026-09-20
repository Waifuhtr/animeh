<?php
/**
 * The public page describing the Android application.
 *
 * An advertising network will not take a publisher's word for what an app is.
 * Before an account is approved somebody on their side opens a page, reads
 * what the property does, downloads the build and looks at it. That page has
 * to exist, be reachable without an account, and say true things.
 *
 * So this is not marketing copy. It is a statement of fact about a piece of
 * software: what it contains, who it is for, where it is hosted, what is
 * collected, and where an ad would appear. Anything that would need a number
 * nobody has measured — installs, active users, impressions — is absent on
 * purpose, because inventing one to fill a page is how a review becomes a
 * complaint later.
 *
 * ## Why this file does not use `__()`
 *
 * Every other string in this plugin goes through the translation functions
 * and the site runs in Turkish. This page is read by a reviewer who does not,
 * and a page that renders in the site's locale would be useless to exactly
 * the one reader it exists for. The English here is therefore literal and
 * deliberately untranslatable.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Support\PublicUrl;

/**
 * Serves /app.
 */
final class AppPage {

	/**
	 * Option holding what an administrator filled in.
	 */
	public const OPTION = 'animeh_app_page';

	/**
	 * The query variable the rewrite fills in.
	 */
	private const QUERY_VAR = 'animeh_app_page';

	/**
	 * The path the page answers on.
	 */
	private const PATH = 'app';

	/**
	 * The repository the build comes from.
	 */
	private const REPO_URL = 'https://github.com/Waifuhtr/animeh';

	/**
	 * The build the download button points at.
	 *
	 * A fixed tag rather than a version: the workflow writes over this asset
	 * on every build, so the address stays valid after the link has been
	 * handed to somebody. See `.github/workflows/android.yml`.
	 */
	private const DOWNLOAD_URL = 'https://github.com/Waifuhtr/animeh/releases/download/latest/animeh.apk';

	/**
	 * The application's package, as declared in the Android build.
	 *
	 * The shipped build carries the `.debug` suffix that `build.gradle.kts`
	 * appends, because a `release` variant needs a signing keystore that is
	 * deliberately not in the repository. Stated as it actually is: a
	 * reviewer comparing this page against the installed package should find
	 * them equal.
	 */
	private const PACKAGE = 'com.animeh.app.debug';

	/**
	 * Oldest Android the build installs on (minSdk 24).
	 */
	private const MIN_ANDROID = '7.0';

	/**
	 * Hook the rewrite and the renderer.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_var' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ) );
	}

	/**
	 * Map /app onto the query variable.
	 */
	public static function add_rewrite(): void {
		$rules   = get_option( 'rewrite_rules' );
		$rules   = is_array( $rules ) ? $rules : array();
		$present = isset( $rules[ self::pattern() ] );
		$wanted  = self::settings()['enabled'];

		// The rule exists only while the page is published, and that is not
		// tidiness. A rule that matches and then declines to draw anything
		// leaves WordPress holding a query that names no post — which is the
		// front page. So an unpublished page answered 200 with the site's
		// home page on it, which is what it did.
		if ( $wanted ) {
			self::add_rule();
		}

		// Flushing rebuilds every rule on the site, so it happens only when
		// what is registered and what should be registered disagree: the load
		// after the plugin is updated, and the load after the setting is
		// changed by something that did not flush for itself.
		if ( $wanted !== $present ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * The rewrite pattern, in one place so the rule and the check agree.
	 */
	private static function pattern(): string {
		return '^' . self::PATH . '/?$';
	}

	/**
	 * Register the rule itself.
	 */
	private static function add_rule(): void {
		add_rewrite_rule(
			self::pattern(),
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
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
	 * What an administrator has saved.
	 *
	 * @return array{enabled: bool, publisher: string, contact: string, download: string, repo: string}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			// Off until somebody turns it on. A page about an application is
			// not something every site running this plugin wants published.
			'enabled'   => ! empty( $stored['enabled'] ),
			'publisher' => isset( $stored['publisher'] ) ? (string) $stored['publisher'] : '',
			'contact'   => isset( $stored['contact'] ) ? (string) $stored['contact'] : '',
			'download'  => PublicUrl::http( $stored['download'] ?? '', self::DOWNLOAD_URL ),
			'repo'      => PublicUrl::http( $stored['repo'] ?? '', self::REPO_URL ),
		);
	}

	/**
	 * Save what an administrator submitted.
	 *
	 * @param array<string, mixed> $input Raw form values.
	 * @return array{enabled: bool, publisher: string, contact: string, download: string, repo: string} What was stored.
	 */
	public static function save( array $input ): array {
		$clean = array(
			'enabled'   => ! empty( $input['enabled'] ),
			'publisher' => isset( $input['publisher'] ) ? sanitize_text_field( (string) $input['publisher'] ) : '',
			'contact'   => self::email_or_nothing( $input['contact'] ?? '' ),
			'download'  => PublicUrl::http( $input['download'] ?? '', self::DOWNLOAD_URL ),
			'repo'      => PublicUrl::http( $input['repo'] ?? '', self::REPO_URL ),
		);

		update_option( self::OPTION, $clean );

		// The rule set in memory was built during `init`, from the setting as
		// it was before this save. Regenerating it now would write out
		// whatever `init` registered — so a page just turned on would be
		// flushed *without* its rule and keep 404ing until the next load.
		// Adding it first is what makes the change take on this request.
		if ( $clean['enabled'] ) {
			self::add_rule();
		}

		flush_rewrite_rules( false );

		return $clean;
	}

	/**
	 * A submitted email address, or nothing at all.
	 *
	 * Rejected on two counts, and the second is the interesting one.
	 *
	 * The first is the obvious check: a thing that is not an address does not
	 * become one. A broken `mailto` on a page whose whole job is to be
	 * verified by a stranger is worse than no contact line.
	 *
	 * The second is that `sanitize_email()` does not reject — it *edits*. It
	 * deletes every character an address cannot contain and hands back what is
	 * left, so `javascript:a@b.com` comes back as `javascripta@b.com`, which
	 * is a perfectly well-formed address at a domain nobody reads. That is not
	 * a security hole — the result is a harmless `mailto:` either way — but it
	 * is a worse failure than a rejection: the operator sees a saved field, a
	 * reviewer sees a contact line, and the mail goes nowhere. So anything the
	 * sanitiser had to change is treated as not having been an address.
	 *
	 * @param mixed $raw What was submitted.
	 * @return string The address, or an empty string.
	 */
	private static function email_or_nothing( $raw ): string {
		$typed = trim( (string) $raw );
		$clean = sanitize_email( $typed );

		if ( $clean !== $typed || ! is_email( $clean ) ) {
			return '';
		}

		return $clean;
	}

	/**
	 * The page's own address, for the admin screen to print.
	 */
	public static function page_url(): string {
		return home_url( '/' . self::PATH );
	}

	/**
	 * Whether WordPress is actually routing the address right now.
	 *
	 * Distinct from the setting. A site on plain permalinks has no rewrite
	 * rules at all, and a rule can be missing after a restore or a caching
	 * plugin's own idea of what to keep. When these two disagree the address
	 * does not work, and the admin screen is the only place that can say so
	 * before somebody hands the link to a stranger.
	 */
	public static function rule_live(): bool {
		$rules = get_option( 'rewrite_rules' );

		return is_array( $rules ) && isset( $rules[ self::pattern() ] );
	}

	/**
	 * The address that works with no rewrite rules at all.
	 *
	 * The query variable is registered either way, so this is what to try
	 * when the pretty address does not answer: it separates "the page is off"
	 * from "the routing is not there".
	 */
	public static function fallback_url(): string {
		return home_url( '/?' . self::QUERY_VAR . '=1' );
	}

	/**
	 * Render the page, if this request is one.
	 */
	public static function maybe_render(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$settings = self::settings();

		// Not published. Reached only when a rule outlived the setting — a
		// cache, or a flush that did not happen — because `add_rewrite` stops
		// registering the rule as soon as the page is turned off.
		//
		// Said as a 404 rather than returning. Returning hands the request
		// back to WordPress with a query that names no post, and WordPress
		// draws the front page for that: the symptom is the home page at this
		// address, which reads as the page being broken rather than absent.
		if ( ! $settings['enabled'] ) {
			global $wp_query;

			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}

			status_header( 404 );
			nocache_headers();

			return;
		}

		status_header( 200 );
		nocache_headers();

		self::render( $settings );
		exit;
	}

	/**
	 * The page itself.
	 *
	 * @param array{enabled: bool, publisher: string, contact: string, download: string, repo: string} $settings Saved values.
	 */
	private static function render( array $settings ): void {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$site = is_string( $site ) ? $site : '';
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Animeh — Android application</title>
	<meta name="description" content="Animeh is an Android application for streaming anime and reading manga. This page describes the application and links to the current build.">
	<style>
		:root { color-scheme: dark; }
		* { box-sizing: border-box; }
		body {
			margin: 0; background: #0d0d0f; color: #e4e4e7;
			font: 16px/1.7 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
			padding-block: 48px; padding-left: 20px; padding-right: 20px;
		}
		main { max-width: 46rem; margin: 0 auto; }
		header { border-bottom: 1px solid #27272a; padding-bottom: 28px; margin-bottom: 8px; }
		h1 { font-size: 1.9rem; margin: 0 0 .35rem; letter-spacing: -.02em; }
		h2 {
			font-size: 1.05rem; margin: 2.5rem 0 .75rem; color: #fafafa;
			text-transform: uppercase; letter-spacing: .08em;
		}
		.lede { color: #a1a1aa; margin: 0; font-size: 1.05rem; }
		p { margin: .75rem 0; }
		ul { margin: .75rem 0; padding-left: 1.2rem; }
		li { margin: .35rem 0; }
		a { color: #a99bff; }
		.download {
			display: inline-block; margin: 1.25rem 0 .5rem; padding: .85rem 1.75rem;
			background: #7c5cff; color: #fff; border-radius: 12px;
			text-decoration: none; font-weight: 600;
		}
		table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
		th, td {
			text-align: left; padding: .6rem .75rem; border-bottom: 1px solid #27272a;
			vertical-align: top;
		}
		th { color: #a1a1aa; font-weight: 500; width: 40%; }
		code {
			background: #18181b; padding: .15rem .4rem; border-radius: 6px;
			font-size: .9em; word-break: break-all;
		}
		.note {
			border-left: 3px solid #f0b429; padding: .1rem 0 .1rem 1rem;
			margin: 1.25rem 0; color: #d4d4d8;
		}
		footer {
			margin-top: 3.5rem; padding-top: 1.5rem; border-top: 1px solid #27272a;
			color: #71717a; font-size: .9rem;
		}
		@media (max-width: 34rem) {
			th, td { display: block; width: auto; }
			th { border-bottom: 0; padding-bottom: 0; }
		}
	</style>
</head>
<body>
<main>
	<header>
		<h1>Animeh</h1>
		<p class="lede">An Android application for streaming anime and reading manga.</p>
	</header>

	<h2>What the application is</h2>
	<p>
		Animeh is a native Android application. It streams anime episodes with
		subtitles, provides a manga reader, and includes a short-video feed of
		clips uploaded by its own users. It is distributed as an APK from the
		project's public repository; it is not published on Google Play.
	</p>
	<p>
		The application talks to one backend, which is the WordPress site you
		are reading this page on<?php echo '' === $site ? '' : ' (<code>' . esc_html( $site ) . '</code>)'; ?>.
		Accounts, watch history, catalogue data and moderation all live there.
		Video and image files are served from a Backblaze B2 bucket through
		time-limited signed URLs.
	</p>

	<h2>Download and verify</h2>
	<p>
		The build below is produced by the repository's own GitHub Actions
		workflow from the source in that repository, so what you install can be
		traced to the commit it was built from. No account is needed to
		download it.
	</p>
	<p>
		<a class="download" href="<?php echo esc_url( $settings['download'] ); ?>" rel="noopener">Download the APK</a>
	</p>
	<table>
		<tr>
			<th>Package name</th>
			<td><code><?php echo esc_html( self::PACKAGE ); ?></code></td>
		</tr>
		<tr>
			<th>Minimum Android version</th>
			<td>Android <?php echo esc_html( self::MIN_ANDROID ); ?> and above</td>
		</tr>
		<tr>
			<th>Source code</th>
			<td><a href="<?php echo esc_url( $settings['repo'] ); ?>" rel="noopener"><?php echo esc_html( $settings['repo'] ); ?></a></td>
		</tr>
		<tr>
			<th>Build and checksum</th>
			<td>
				The release notes on the download page state the commit, the
				branch and the SHA-256 of the file, so the download can be
				checked against them.
			</td>
		</tr>
	</table>
	<p class="note">
		This is a sideloaded APK, so Android asks for permission to install
		from an unknown source the first time. The build is signed with a debug
		certificate, which is why the package name carries a
		<code>.debug</code> suffix. It is the same build the project's own
		users install.
	</p>

	<h2>Content and audience</h2>
	<p>
		The catalogue is anime and manga. Most of it is general audience
		material, and the application is intended for adult users.
	</p>
	<ul>
		<li>
			Individual titles can be marked as containing adult content. A
			title marked that way shows a confirmation dialog before it plays
			or opens, both on the title's page and in the player, and the
			viewer has to accept it once per title.
		</li>
		<li>
			The short-video feed accepts uploads from signed-in users. Uploads
			can be reported by other users and removed by a moderator, and the
			operator can suspend or ban an account.
		</li>
		<li>
			The primary audience is Turkish-speaking; the application's
			interface is in Turkish.
		</li>
	</ul>

	<h2>Planned advertising placement</h2>
	<p class="note">
		Stated as a plan, not as something already running: no advertising code
		is present in the build linked above at the time of writing.
	</p>
	<p>
		The intended placement is an interstitial inside the video player,
		shown at a fixed interval during playback and repeating for the
		duration of an episode. The placement is controlled from the
		application's own administration panel and is off until the operator
		enables it. No advertising is planned in the manga reader or in the
		short-video feed.
	</p>

	<h2>Data the application handles</h2>
	<ul>
		<li>
			<strong>Account:</strong> an email address and a password, used to
			sign in. Registration can be closed by the operator.
		</li>
		<li>
			<strong>Usage:</strong> watch progress per episode, favourites and
			reading position, so playback and reading can be resumed.
		</li>
		<li>
			<strong>Uploads:</strong> video files and profile images uploaded by
			the user, stored in the operator's own Backblaze B2 bucket.
		</li>
		<li>
			<strong>Notifications:</strong> a Firebase device token, when the
			user allows notifications, so watch-party invitations arrive.
		</li>
	</ul>
	<p>
		All of it is stored on the operator's own infrastructure — the
		WordPress site and the B2 bucket — and is not sold or shared with third
		parties.
	</p>

	<?php if ( '' !== $settings['contact'] ) : ?>
	<h2>Contact</h2>
	<p>
		<?php if ( '' !== $settings['publisher'] ) : ?>
			<?php echo esc_html( $settings['publisher'] ); ?><br>
		<?php endif; ?>
		<a href="mailto:<?php echo esc_attr( $settings['contact'] ); ?>"><?php echo esc_html( $settings['contact'] ); ?></a>
	</p>
	<?php endif; ?>

	<footer>
		<?php if ( '' !== $settings['publisher'] ) : ?>
			Published by <?php echo esc_html( $settings['publisher'] ); ?>.
		<?php endif; ?>
		This page is generated by the site's own plugin and describes the
		application linked above.
	</footer>
</main>
</body>
</html>
		<?php
	}
}
