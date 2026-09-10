<?php
/**
 * How a gallery is named.
 *
 * The source has no search: her own importer only ever asked it for
 * `/galleries/{id}`, and that is the whole of the surface it publishes. So a
 * work is found there the way people actually refer to one — by its number,
 * or by pasting the address that contains it.
 *
 * This turns any of those into the number, so the panel can accept whatever
 * lands in the box:
 *
 *     177013                       → 177013
 *     #177013                      → 177013
 *     https://…/g/177013/          → 177013
 *     g/177013                     → 177013
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Reads a gallery number out of whatever was typed.
 */
final class GalleryRef {

	/**
	 * The number, or 0 when there isn't one.
	 *
	 * A bare number wins outright. Otherwise the last `/g/<n>` group is taken,
	 * because that is where the address carries it; a number that is only part
	 * of a word ("chapter12") is not a reference and gives 0.
	 *
	 * @param string $text Whatever was typed.
	 */
	public static function id( string $text ): int {
		$text = trim( $text );

		if ( '' === $text ) {
			return 0;
		}

		if ( 1 === preg_match( '/^#?(\d{1,9})$/', $text, $bare ) ) {
			return (int) $bare[1];
		}

		if ( 1 === preg_match( '#(?:^|/)g/(\d{1,9})#i', $text, $path ) ) {
			return (int) $path[1];
		}

		return 0;
	}

	/**
	 * Whether this text names a gallery.
	 *
	 * @param string $text Whatever was typed.
	 */
	public static function looks_like_id( string $text ): bool {
		return self::id( $text ) > 0;
	}
}
