<?php
/**
 * Authorisation, in one place.
 *
 * Every route's `permission_callback` comes through here. The plugin never uses
 * `__return_true`: an endpoint without a real check is an endpoint open to the
 * internet, and these ones fetch URLs, write files and read the run history.
 *
 * The client's own claim about being an administrator decides nothing. It is
 * used only to pick which controls to draw; the server re-checks every call.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use WP_Error;

/**
 * Capability checks for the REST API and the media proxy.
 */
final class Permissions {

	/**
	 * Capability required to use the player test tools.
	 *
	 * A dedicated capability rather than `manage_options` so a site can hand
	 * subtitle work to an editor without also handing over the whole site.
	 */
	public const CAPABILITY = 'animeh_manage_player_tests';

	/**
	 * Give the capability to administrators on activation.
	 */
	public static function grant_to_administrators(): void {
		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Take it back on deactivation.
	 */
	public static function revoke_from_administrators(): void {
		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->remove_cap( self::CAPABILITY );
		}
	}

	/**
	 * Whether the current user may use the test tools.
	 *
	 * Falls back to `manage_options` so a site whose roles were rebuilt — or
	 * which never ran activation, as happens when a plugin is deployed by
	 * copying files — is not locked out of its own admin screen.
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::CAPABILITY ) || current_user_can( 'manage_options' );
	}

	/**
	 * `permission_callback` for every route in the plugin.
	 *
	 * @return true|WP_Error
	 */
	public static function require_manage() {
		if ( self::current_user_can_manage() ) {
			return true;
		}

		// 401 when nobody is logged in, 403 when someone is but lacks the
		// capability: the distinction tells a client whether logging in helps.
		return new WP_Error(
			'animeh_forbidden',
			__( 'Bu işlem için yetkin yok.', 'animeh' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}
}
