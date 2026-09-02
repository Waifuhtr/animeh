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
	 * Capability for the day-to-day moderation work.
	 *
	 * Split from the one above deliberately. A moderator edits the catalog,
	 * deletes a review and suspends someone; they do not get the bucket
	 * credentials, the migration tools or the API keys, and holding this
	 * capability alone must not open those.
	 */
	public const MODERATE = 'animeh_moderate';

	/**
	 * The moderator role's slug.
	 */
	public const MODERATOR_ROLE = 'animeh_moderator';

	/**
	 * Give the capability to administrators on activation.
	 */
	public static function grant_to_administrators(): void {
		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->add_cap( self::CAPABILITY );
			$role->add_cap( self::MODERATE );
		}

		self::ensure_moderator_role();
	}

	/**
	 * Take it back on deactivation.
	 */
	public static function revoke_from_administrators(): void {
		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->remove_cap( self::CAPABILITY );
			$role->remove_cap( self::MODERATE );
		}

		// The role itself stays: removing it would strip the assignment from
		// every moderator, and deactivating a plugin to update it is routine.
	}

	/**
	 * Create the moderator role if it is not there.
	 *
	 * `read` is included because a WordPress user without it cannot see their
	 * own profile screen, which makes the account feel broken even though
	 * moderation happens entirely in the app.
	 */
	public static function ensure_moderator_role(): void {
		if ( null !== get_role( self::MODERATOR_ROLE ) ) {
			return;
		}

		add_role(
			self::MODERATOR_ROLE,
			__( 'Animeh Moderatör', 'animeh' ),
			array(
				'read'        => true,
				self::MODERATE => true,
			)
		);
	}

	/**
	 * Whether the current user may moderate: edit the catalog, remove a
	 * review, suspend someone.
	 *
	 * Every administrator can, by containment — someone who can change the
	 * bucket credentials can obviously delete a comment.
	 */
	public static function current_user_can_moderate(): bool {
		return current_user_can( self::MODERATE ) || self::current_user_can_manage();
	}

	/**
	 * `permission_callback` for the routes a moderator may use.
	 *
	 * @return true|WP_Error
	 */
	public static function require_moderate() {
		if ( self::current_user_can_moderate() ) {
			return true;
		}

		return new WP_Error(
			'animeh_forbidden',
			__( 'Bu işlem için yetkin yok.', 'animeh' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
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
