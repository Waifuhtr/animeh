<?php
/**
 * Admin asset loading.
 *
 * The player ships as an ES module, so it is printed with
 * `wp_print_script_tag()` rather than enqueued. `wp_enqueue_script()` has no
 * way to mark a script as a module without filtering the tag, and the Script
 * Modules API only exists on WordPress 6.5 and later — printing the tag works
 * everywhere the plugin claims to support.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Admin;

use Animeh\Media\ProxyHandler;
use Animeh\Rest\FontsController;
use Animeh\Rest\Permissions;
use Animeh\Rest\TestController;

/**
 * Loads the panel's scripts and styles.
 */
final class Assets {

	/**
	 * Enqueue on this plugin's screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! MenuPage::owns_screen( $hook_suffix ) ) {
			return;
		}
		if ( ! Permissions::current_user_can_manage() ) {
			return;
		}

		$version = \Animeh\VERSION;

		wp_enqueue_style(
			'animeh-player',
			ANIMEH_PLUGIN_URL . 'assets/player/animeh-player.css',
			array(),
			$version
		);

		wp_enqueue_style(
			'animeh-admin',
			ANIMEH_PLUGIN_URL . 'assets/admin/admin.css',
			array( 'animeh-player' ),
			$version
		);

		// The module reads its configuration off a global rather than an import,
		// so nothing has to be bundled per site.
		wp_register_script( 'animeh-admin-config', '', array(), $version, true );
		wp_enqueue_script( 'animeh-admin-config' );
		wp_add_inline_script(
			'animeh-admin-config',
			'window.ANIMEH_ADMIN = ' . wp_json_encode( self::config() ) . ';',
			'before'
		);

		add_action( 'admin_footer', array( self::class, 'print_module' ) );
	}

	/**
	 * Print the module tag.
	 *
	 * In the footer so the configuration global and the mount points both
	 * exist by the time it runs.
	 */
	public static function print_module(): void {
		wp_print_script_tag(
			array(
				'type' => 'module',
				'src'  => add_query_arg( 'ver', \Animeh\VERSION, ANIMEH_PLUGIN_URL . 'assets/admin/admin.js' ),
				'id'   => 'animeh-admin-js',
			)
		);
	}

	/**
	 * Configuration handed to the panel.
	 *
	 * @return array<string, mixed>
	 */
	private static function config(): array {
		$settings = TestController::settings();

		return array(
			'version'    => \Animeh\VERSION,
			'restUrl'    => esc_url_raw( rest_url( FontsController::NAMESPACE ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'proxy'      => array(
				'url'   => ProxyHandler::endpoint(),
				'nonce' => wp_create_nonce( ProxyHandler::NONCE_ACTION ),
			),
			'assets'     => array(
				'worker'     => ANIMEH_PLUGIN_URL . 'assets/jassub/jassub-worker.js',
				'wasm'       => ANIMEH_PLUGIN_URL . 'assets/jassub/jassub-worker.wasm',
				'modernWasm' => ANIMEH_PLUGIN_URL . 'assets/jassub/jassub-worker-modern.wasm',
				'player'     => ANIMEH_PLUGIN_URL . 'assets/player/animeh-player.js',
			),
			'settings'   => $settings,
			'presets'    => TestController::presets(),
			'screen'     => self::current_screen(),
			'adminUrl'   => admin_url( 'admin.php' ),
			'fontsPage'  => MenuPage::FONTS_SLUG,
			'testPage'   => MenuPage::TEST_SLUG,
		);
	}

	/**
	 * Which of the plugin's screens is being rendered.
	 */
	private static function current_screen(): string {
		// Read-only page identification for choosing which panel to mount; it
		// grants nothing on its own, so a nonce would add no protection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return MenuPage::FONTS_SLUG === $page ? 'fonts' : 'test';
	}
}
