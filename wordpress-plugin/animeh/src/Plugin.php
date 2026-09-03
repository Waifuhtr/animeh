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
use Animeh\Rest\AdminController;
use Animeh\Rest\AppLinks;
use Animeh\Rest\Auth;
use Animeh\Rest\AuthController;
use Animeh\Rest\CatalogController;
use Animeh\Rest\CommunityController;
use Animeh\Rest\FontsController;
use Animeh\Rest\MeController;
use Animeh\Rest\MigrationController;
use Animeh\Rest\Permissions;
use Animeh\Rest\RoomLinkPage;
use Animeh\Rest\SocialController;
use Animeh\Rest\StorageController;
use Animeh\Rest\TestController;
use Animeh\Storage\CatalogSchema;
use Animeh\Storage\FontRepository;
use Animeh\Storage\LogRepository;
use Animeh\Storage\Schema;
use Animeh\Storage\SnapshotStore;
use Animeh\Storage\SocialRepository;
use Animeh\Storage\TokenRepository;
use Animeh\Storage\UserDataRepository;

/**
 * Bootstraps the plugin.
 */
final class Plugin {

	/**
	 * Daily housekeeping event.
	 */
	public const CLEANUP_HOOK = 'animeh_cleanup_event';

	/**
	 * Sweeps rooms nobody is in any more.
	 */
	public const ROOM_SWEEP_HOOK = 'animeh_room_sweep_event';

	/**
	 * Register everything.
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'animeh', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

		// A plugin updated by file copy never runs its activation hook, so the
		// schema version is checked on every load instead.
		add_action( 'init', array( Schema::class, 'maybe_upgrade' ) );
		add_action( 'init', array( CatalogSchema::class, 'maybe_upgrade' ) );

		// Registered before `rest_api_init` so the bearer token is resolved by
		// the time any permission callback asks who is calling.
		Auth::register();

		add_action(
			'rest_api_init',
			static function (): void {
				( new FontsController() )->register_routes();
				( new TestController() )->register_routes();
				( new StorageController() )->register_routes();
				( new MigrationController() )->register_routes();
				( new AuthController() )->register_routes();
				( new CatalogController() )->register_routes();
				( new MeController() )->register_routes();
				( new CommunityController() )->register_routes();
				( new SocialController() )->register_routes();
				( new AdminController() )->register_routes();
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

		// Housekeeping: expired tokens and old log rows. Both tables grow with
		// use and neither is worth keeping indefinitely.
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		if ( false === wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}

		// A deleted user leaves history, favourites and live tokens behind
		// otherwise — the tokens being the part that matters.
		add_action( 'deleted_user', array( self::class, 'forget_user' ) );

		// The page an invite link lands on. A room link is shared into a chat
		// app, so it has to be a web address that opens the app rather than a
		// custom scheme that most chat apps refuse to make tappable.
		RoomLinkPage::register();

		// And the file that lets Android skip that page entirely, once an
		// operator has published the app's signing fingerprint.
		AppLinks::register();

		// Rooms are swept far more often than the daily cleanup: the promise
		// is that a room does not outlive the people in it, and a day is not
		// "does not outlive".
		add_action( self::ROOM_SWEEP_HOOK, array( self::class, 'sweep_rooms' ) );
		if ( false === wp_next_scheduled( self::ROOM_SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::ROOM_SWEEP_HOOK );
		}
	}

	/**
	 * Create tables, the font directory and the capability.
	 */
	public static function activate(): void {
		Schema::install();
		CatalogSchema::install();
		FontRepository::ensure_directory();
		Permissions::grant_to_administrators();
	}

	/**
	 * Drop what has aged out. Runs daily.
	 */
	public static function cleanup(): void {
		( new TokenRepository() )->prune();
		( new LogRepository() )->prune();
	}

	/**
	 * Delete rooms nobody has reported themselves in.
	 *
	 * The app closes its own room when the last person leaves. This covers the
	 * app that was killed, lost its connection or ran out of battery and never
	 * got to say so — without it, "the room deletes itself" is only true when
	 * everyone exits politely.
	 */
	public static function sweep_rooms(): void {
		( new SocialRepository() )->sweep_idle_rooms();
	}

	/**
	 * Forget everything belonging to a user WordPress just deleted.
	 *
	 * @param int $user_id The deleted user.
	 */
	public static function forget_user( int $user_id ): void {
		( new UserDataRepository() )->purge_user( $user_id );
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

		$cleanup = wp_next_scheduled( self::CLEANUP_HOOK );
		if ( false !== $cleanup ) {
			wp_unschedule_event( $cleanup, self::CLEANUP_HOOK );
		}
	}
}
