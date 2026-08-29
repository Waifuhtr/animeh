<?php
/**
 * ASS/SSA script reading.
 *
 * The PHP counterpart of the player's `src/subtitles/ass.ts`. Only enough
 * structure is read to know which fonts a script needs — rendering is libass's
 * job, in the browser.
 *
 * The two implementations are held to the same expected output by the test
 * suites on both sides, so a change to one that the other misses shows up
 * immediately.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Reads font requirements out of an ASS script.
 */
final class AssScript {

	/**
	 * Every font family a script can ask for.
	 *
	 * Two places name fonts: the `Fontname` column of each style, and `\fn`
	 * override tags inside dialogue text. Missing either one means a line
	 * renders in the wrong face at playback time, which is exactly the failure
	 * the font report exists to catch.
	 *
	 * @param string $content Script contents.
	 * @return string[] Sorted, de-duplicated family names.
	 */
	public static function font_families( string $content ): array {
		$families = array();

		foreach ( self::styles( $content ) as $style ) {
			if ( '' !== $style['fontname'] ) {
				$families[ FontFile::key( $style['fontname'] ) ] = $style['fontname'];
			}
		}

		// `\fn` runs until the next backslash or the end of the override block.
		if ( preg_match_all( '/\\\\fn([^\\\\}]*)/u', $content, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$name = self::normalise_font_name( $raw );
				if ( '' !== $name ) {
					$families[ FontFile::key( $name ) ] = $name;
				}
			}
		}

		$values = array_values( $families );
		usort( $values, static fn( string $a, string $b ): int => strcasecmp( $a, $b ) );
		return $values;
	}

	/**
	 * Styles declared by the script.
	 *
	 * @param string $content Script contents.
	 * @return array<int, array{name: string, fontname: string, fontsize: float, bold: bool, italic: bool}>
	 */
	public static function styles( string $content ): array {
		$section = self::section( $content, array( 'v4+ styles', 'v4 styles' ) );
		if ( array() === $section ) {
			return array();
		}

		$format = array();
		$styles = array();

		foreach ( $section as $line ) {
			$entry = self::split_entry( $line );
			if ( null === $entry ) {
				continue;
			}
			$key = strtolower( $entry[0] );

			if ( 'format' === $key ) {
				$format = array_map(
					static fn( string $field ): string => strtolower( trim( $field ) ),
					explode( ',', $entry[1] )
				);
				continue;
			}

			if ( 'style' !== $key || array() === $format ) {
				continue;
			}

			// Style values are comma-separated and positional.
			$fields = explode( ',', $entry[1] );
			$get    = static function ( string $name ) use ( $format, $fields ): string {
				$index = array_search( $name, $format, true );
				return false === $index ? '' : trim( $fields[ $index ] ?? '' );
			};

			$name = $get( 'name' );
			if ( '' === $name ) {
				continue;
			}

			$bold   = $get( 'bold' );
			$italic = $get( 'italic' );

			$styles[] = array(
				'name'     => $name,
				'fontname' => self::normalise_font_name( $get( 'fontname' ) ),
				'fontsize' => (float) $get( 'fontsize' ),
				// ASS booleans are -1 for true, 0 for false.
				'bold'     => ( '-1' === $bold || '1' === $bold ),
				'italic'   => ( '-1' === $italic || '1' === $italic ),
			);
		}

		return $styles;
	}

	/**
	 * Script resolution, when declared.
	 *
	 * @param string $content Script contents.
	 * @return array{x: int|null, y: int|null}
	 */
	public static function play_res( string $content ): array {
		$result = array(
			'x' => null,
			'y' => null,
		);
		foreach ( self::section( $content, array( 'script info' ) ) as $line ) {
			if ( str_starts_with( ltrim( $line ), ';' ) ) {
				continue;
			}
			$entry = self::split_entry( $line );
			if ( null === $entry ) {
				continue;
			}
			$key = strtolower( $entry[0] );
			if ( 'playresx' === $key ) {
				$result['x'] = (int) $entry[1];
			} elseif ( 'playresy' === $key ) {
				$result['y'] = (int) $entry[1];
			}
		}
		return $result;
	}

	/**
	 * Number of dialogue events.
	 *
	 * @param string $content Script contents.
	 */
	public static function dialogue_count( string $content ): int {
		$count = 0;
		foreach ( self::section( $content, array( 'events' ) ) as $line ) {
			$entry = self::split_entry( $line );
			if ( null !== $entry && 'dialogue' === strtolower( $entry[0] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Strip the decorations ASS allows around a font name.
	 *
	 * A leading `@` requests the vertical-writing variant of a CJK face; the
	 * family being asked for is the same one either way.
	 *
	 * @param string $name Raw name.
	 */
	public static function normalise_font_name( string $name ): string {
		return trim( ltrim( trim( $name ), '@' ) );
	}

	/**
	 * Lines belonging to the first matching section.
	 *
	 * @param string   $content Script contents.
	 * @param string[] $names   Section names to try, lowercased, in order.
	 * @return string[]
	 */
	private static function section( string $content, array $names ): array {
		// Strip a BOM: libass copes, but our own field parsing would not.
		$content  = preg_replace( '/^\xEF\xBB\xBF/', '', $content ) ?? $content;
		$sections = array();
		$current  = null;

		foreach ( preg_split( '/\r\n|\r|\n/', $content ) ?: array() as $line ) {
			if ( preg_match( '/^\s*\[([^\]]+)\]\s*$/', $line, $matches ) ) {
				$current              = strtolower( trim( $matches[1] ) );
				$sections[ $current ] = array();
				continue;
			}
			if ( null !== $current ) {
				$sections[ $current ][] = $line;
			}
		}

		foreach ( $names as $name ) {
			if ( isset( $sections[ $name ] ) ) {
				return $sections[ $name ];
			}
		}
		return array();
	}

	/**
	 * Split a `Key: value` line.
	 *
	 * @param string $line Line to split.
	 * @return array{0: string, 1: string}|null
	 */
	private static function split_entry( string $line ): ?array {
		$index = strpos( $line, ':' );
		if ( false === $index ) {
			return null;
		}
		return array( trim( substr( $line, 0, $index ) ), trim( substr( $line, $index + 1 ) ) );
	}
}
