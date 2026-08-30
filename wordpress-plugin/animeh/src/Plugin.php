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
use Animeh\Rest\MigrationController;
use Animeh\Rest\Permissions;
use Animeh\Rest\StorageController;
use Animeh\Rest\TestController;
use Animeh\Storage\FontRepository;
use Animeh\Storage\Schema;
use Animeh\Storage\SnapshotStore;

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
				( new StorageController() )->register_routes();
				( new MigrationController() )->register_routes();
			}
		);

		if ( is_admin() ) {
			add_action( 'admin_menu', array( MenuPage::class, 'register' ) );
			add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue' ) );
		}

		// The media proxy streams and exits, so it hangs off admin-post rather
		// than the REST API, whose response handling would buffer it.
		add_action( 'admin_post_animeh_media_proxy', array( ProxyHandler::class, 'handle' ) );

		// The scheduled snapshot. Registered unconditionally: the event only
		// exists once an operator has turned it on, but the handler has to be
		// attached on every load for cron to find it.
		add_action( SnapshotStore::CRON_HOOK, array( SnapshotStore::class, 'run' ) );
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

		// The scheduled snapshot would otherwise keep firing against a plugin
		// that is no longer loaded, filling the cron log with missing-hook
		// warnings.
		SnapshotStore::set_schedule( false );
	}
}
