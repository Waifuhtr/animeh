<?php
/**
 * What order the pages of a chapter go in.
 *
 * The answer is the file names, and the file names are numbers: a chapter
 * arrives as `1.jpg … 24.jpg`, or `01.webp`, or `page_1.png`, depending on who
 * packed it. What they have in common is a number, and what they must not be
 * sorted by is the string — `10` sorts before `2` and the reader gets the
 * chapter in the wrong order without anything looking broken.
 *
 * So: compare by the first number in the name, and fall back to comparing the
 * names themselves only when there is no number to go on.
 *
 * Free of any WordPress dependency, so the rule is verified directly.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Orders the page files of one chapter.
 */
final class PageOrder {

	/**
	 * Image extensions a page may have.
	 */
	private const EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp' );

	/**
	 * Whether a name looks like a page rather than a stray file in the zip.
	 *
	 * @param string $name File name, possibly with directories.
	 */
	public static function is_image( string $name ): bool {
		$base = self::basename( $name );

		// Directory entries, macOS resource forks and dotfiles are not pages.
		if ( '' === $base || str_starts_with( $base, '.' ) || str_contains( $name, '__MACOSX' ) ) {
			return false;
		}

		return in_array( strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	/**
	 * The first number in a file name, or null when it has none.
	 *
	 * Read from the base name so a chapter folder called `bolum-12` inside the
	 * zip cannot decide the order of the pages under it.
	 *
	 * @param string $name File name.
	 */
	public static function number( string $name ): ?int {
		$base = (string) pathinfo( self::basename( $name ), PATHINFO_FILENAME );

		if ( 1 !== preg_match( '/\d+/', $base, $digits ) ) {
			return null;
		}

		return (int) $digits[0];
	}

	/**
	 * The names, in reading order.
	 *
	 * Numbered names lead, in numeric order; anything unnumbered follows in
	 * natural order, because a `cover.jpg` beside `1.jpg … 20.jpg` belongs at
	 * one end and putting it in the middle would be worse than either end.
	 *
	 * @param array<int, string> $names File names.
	 * @return array<int, string>
	 */
	public static function sort( array $names ): array {
		$names = array_values( $names );

		usort(
			$names,
			static function ( string $left, string $right ): int {
				$a = self::number( $left );
				$b = self::number( $right );

				if ( null !== $a && null !== $b ) {
					// Same number, different names: `1.jpg` and `1b.jpg` do
					// happen, and the name settles it.
					return $a === $b ? strnatcasecmp( $left, $right ) : $a <=> $b;
				}

				if ( null !== $a ) {
					return -1;
				}
				if ( null !== $b ) {
					return 1;
				}

				return strnatcasecmp( $left, $right );
			}
		);

		return $names;
	}

	/**
	 * Only the pages, in reading order.
	 *
	 * @param array<int, string> $names File names, as they came.
	 * @return array<int, string>
	 */
	public static function pages( array $names ): array {
		return self::sort( array_values( array_filter( $names, array( self::class, 'is_image' ) ) ) );
	}

	/**
	 * The last path segment, whichever slash the archive used.
	 *
	 * @param string $name Possibly a path.
	 */
	private static function basename( string $name ): string {
		$name = str_replace( '\\', '/', trim( $name ) );
		$parts = explode( '/', $name );

		return (string) end( $parts );
	}
}
