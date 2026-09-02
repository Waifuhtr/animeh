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
require_once __DIR__ . '/src/Storage/CatalogSchema.php';
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
\Animeh\Storage\CatalogSchema::drop();

delete_option( 'animeh_test_presets' );
delete_option( 'animeh_settings' );
delete_option( 'animeh_storage' );
delete_option( 'animeh_snapshot_status' );
delete_option( 'animeh_migration_handoff' );
delete_option( 'animeh_registration_open' );
delete_option( 'animeh_tenrai' );
delete_option( 'animeh_catalog_version' );

// Snapshots already written to the bucket are left alone: they are the copy
// that outlives this site, and deleting a plugin here is not a decision to
// throw away the library's backups.
foreach ( array( 'animeh_snapshot_event', 'animeh_cleanup_event' ) as $animeh_event ) {
	$animeh_next = wp_next_scheduled( $animeh_event );
	if ( false !== $animeh_next ) {
		wp_unschedule_event( $animeh_next, $animeh_event );
	}
}

// The capability was added to a role, so it has to be taken off it too.
$animeh_role = get_role( 'administrator' );
if ( null !== $animeh_role ) {
	$animeh_role->remove_cap( 'animeh_manage_player_tests' );
}
