<?php
/**
 * Plugin wiring.
 *
 * The one place hooks are registered, so the surface the plugin presents to
 * WordPress can be read in a single file.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh;

use Animeh\Admin\Assets;
use Animeh\Admin\MenuPage;
use Animeh\Media\ProxyHandler;
use Animeh\Rest\FontsController;
use Animeh\Rest\Permissions;
use Animeh\Rest\TestController;
use Animeh\Storage\FontRepository;
use Animeh\Storage\Schema;

/**
 * Bootstraps the plugin.
 */
final class Plugin {

	/**
	 * Register everything.
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'animeh', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

		// A plugin updated by file copy never runs its activation hook, so the
		// schema version is checked on every load instead.
		add_action( 'init', array( Schema::class, 'maybe_upgrade' ) );

		add_action(
			'rest_api_init',
			static function (): void {
				( new FontsController() )->register_routes();
				( new TestController() )->register_routes();
			}
		);

		if ( is_admin() ) {
			add_action( 'admin_menu', array( MenuPage::class, 'register' ) );
			add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue' ) );
		}

		// The media proxy streams and exits, so it hangs off admin-post rather
		// than the REST API, whose response handling would buffer it.
		add_action( 'admin_post_animeh_media_proxy', array( ProxyHandler::class, 'handle' ) );
	}

	/**
	 * Create tables, the font directory and the capability.
	 */
	public static function activate(): void {
		Schema::install();
		FontRepository::ensure_directory();
		Permissions::grant_to_administrators();
	}

	/**
	 * Nothing is torn down on deactivate.
	 *
	 * Tables and uploaded fonts survive so that deactivating to debug something
	 * does not throw away an operator's font library. `uninstall.php` is where
	 * removal belongs, because that is the action a user takes deliberately.
	 */
	public static function deactivate(): void {
		Permissions::revoke_from_administrators();
	}
}
