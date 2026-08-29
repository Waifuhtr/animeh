<?php
/**
 * Uninstall cleanup.
 *
 * Runs only when a user deletes the plugin, which is a deliberate act —
 * deactivating leaves the font library and the run history intact.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Storage/Schema.php';
require_once __DIR__ . '/src/Storage/FontRepository.php';
require_once __DIR__ . '/src/Support/FontFile.php';

// Remove the uploaded fonts, then the directory itself.
$animeh_font_dir = \Animeh\Storage\FontRepository::directory();
if ( is_dir( $animeh_font_dir ) ) {
	$animeh_entries = glob( trailingslashit( $animeh_font_dir ) . '*' );
	foreach ( is_array( $animeh_entries ) ? $animeh_entries : array() as $animeh_entry ) {
		if ( is_file( $animeh_entry ) ) {
			wp_delete_file( $animeh_entry );
		}
	}
	$animeh_htaccess = trailingslashit( $animeh_font_dir ) . '.htaccess';
	if ( is_file( $animeh_htaccess ) ) {
		wp_delete_file( $animeh_htaccess );
	}
	@rmdir( $animeh_font_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}

\Animeh\Storage\Schema::drop();

delete_option( 'animeh_test_presets' );
delete_option( 'animeh_settings' );

// The capability was added to a role, so it has to be taken off it too.
$animeh_role = get_role( 'administrator' );
if ( null !== $animeh_role ) {
	$animeh_role->remove_cap( 'animeh_manage_player_tests' );
}
