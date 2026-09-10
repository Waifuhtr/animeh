<?php
/**
 * Chapter numbers, which are not whole numbers.
 *
 * Manga numbering has 10.5 in it — an extra, an omake, a chapter that was
 * split — and 10.5 is a different chapter from 10, not a rounding of it. The
 * column is `decimal(8,2)` for that reason, and this class is the only place
 * that decides how one is read from text and written back out.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Parsing and formatting for chapter numbers.
 */
final class ChapterNumber {

	/**
	 * A number from whatever the other site called the chapter.
	 *
	 * Accepts a bare number, a decimal written either way round — "10,5" is
	 * how half the world writes it — and a title with the number inside it,
	 * because a chapter's post title is often the only place the number lives.
	 *
	 * @param mixed $value Raw.
	 * @return float Zero when nothing numeric is in there.
	 */
	public static function parse( $value ): float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return round( max( 0.0, (float) $value ), 2 );
		}

		if ( ! is_string( $value ) ) {
			return 0.0;
		}

		$text = str_replace( ',', '.', trim( $value ) );

		// The first run of digits with an optional fraction. "Bölüm 10.5" and
		// "Chapter 10.5 - Son" both give 10.5; "Vol. 2 Ch. 10" gives 2, which
		// is why the caller passes a number field when it has one.
		if ( 1 !== preg_match( '/-?\d+(?:\.\d+)?/', $text, $match ) ) {
			return 0.0;
		}

		return round( max( 0.0, (float) $match[0] ), 2 );
	}

	/**
	 * The number as a person writes it: no trailing zeros, no trailing point.
	 *
	 * 10.00 is "10", 10.50 is "10.5", 10.25 is "10.25".
	 *
	 * @param mixed $value Number.
	 * @return string
	 */
	public static function label( $value ): string {
		$number = self::parse( $value );

		$text = number_format( $number, 2, '.', '' );
		$text = rtrim( $text, '0' );
		$text = rtrim( $text, '.' );

		return '' === $text ? '0' : $text;
	}

	/**
	 * The whole part, for callers that can only carry an integer.
	 *
	 * The app's episode payload has sent an integer since the first version
	 * and other screens parse it as one; the exact number travels beside it as
	 * [self::label].
	 *
	 * @param mixed $value Number.
	 * @return int
	 */
	public static function whole( $value ): int {
		return (int) floor( self::parse( $value ) );
	}

	/**
	 * Order two chapter numbers.
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 * @return int
	 */
	public static function compare( $a, $b ): int {
		return self::parse( $a ) <=> self::parse( $b );
	}
}
