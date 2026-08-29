<?php
/**
 * Plugin Name:       Animeh
 * Plugin URI:        https://github.com/Waifuhtr/animeh
 * Description:       Animeh player test panel: run the custom HLS/MKV player against real sources, check ASS subtitle rendering, and manage the fonts subtitles need.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Animeh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       animeh
 * Domain Path:       /languages
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh;

// Loaded directly rather than through WordPress: nothing here should run.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

define( 'ANIMEH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANIMEH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal autoloader for the plugin's own classes.
 *
 * Composer cannot be assumed on a WordPress host, and the class list is small
 * and flat, so a prefix-mapped loader is enough.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = ANIMEH_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Guard against hosts running a PHP older than the plugin needs.
 *
 * WordPress honours `Requires PHP` on install, but a site can be downgraded
 * afterwards, and a fatal error on every admin page is a bad way to find out.
 */
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: current PHP version. */
						__( 'Animeh eklentisi PHP 8.0 veya üstünü gerektirir. Bu sunucuda PHP %s çalışıyor.', 'animeh' ),
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
