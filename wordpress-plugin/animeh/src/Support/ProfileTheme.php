<?php
/**
 * The profile colour palette.
 *
 * A closed list, held on the server. The app draws the swatches from its own
 * copy — a palette is a design decision and belongs in the design — but the
 * choice is stored here, and a choice the server does not recognise is not a
 * colour: it is whatever string somebody put in the request body, on its way
 * to being rendered inside every other viewer's profile screen.
 *
 * Slugs, not hex. A hex value in the database is a colour that can never be
 * adjusted afterwards without a migration, and one that lets a caller pick
 * black text on a black card.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Valid profile themes.
 */
final class ProfileTheme {

	/**
	 * What a profile looks like when nobody has chosen: the app's own accent.
	 */
	public const DEFAULT_THEME = 'amethyst';

	/**
	 * Every theme, in the order the palette shows them.
	 *
	 * Free, all of them. The frames are what points are for; a colour is not
	 * something to charge for and then have half the profiles look the same.
	 *
	 * @var string[]
	 */
	public const THEMES = array(
		'amethyst',
		'sakura',
		'ocean',
		'jade',
		'ember',
		'gold',
		'rose',
		'midnight',
		'lagoon',
		'sunset',
		'mint',
		'violet',
	);

	/**
	 * Whether a slug is one of ours.
	 *
	 * @param string $slug Candidate.
	 * @return bool
	 */
	public static function valid( string $slug ): bool {
		return in_array( $slug, self::THEMES, true );
	}

	/**
	 * A stored or submitted value, made safe to hand to a client.
	 *
	 * Anything unrecognised — an old theme that has since been removed, an
	 * empty column on a row written before this existed, a hand-crafted
	 * request — becomes the default rather than an error. This runs on the
	 * read path of every profile; failing there would take down a screen over
	 * a colour.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function normalise( $value ): string {
		if ( ! is_string( $value ) ) {
			return self::DEFAULT_THEME;
		}

		$slug = strtolower( trim( $value ) );

		return self::valid( $slug ) ? $slug : self::DEFAULT_THEME;
	}
}
