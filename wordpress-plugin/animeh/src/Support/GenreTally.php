<?php
/**
 * Which genres someone actually watches.
 *
 * Counted in PHP rather than SQL because genres are a JSON array on the work
 * row, and asking MySQL to unnest that means either JSON_TABLE (MySQL 8 only,
 * and plenty of this plugin's hosts are on MariaDB) or a LIKE per genre. The
 * input is one row per work the person has watched, which is tens of rows for
 * a heavy viewer, so counting them here costs nothing.
 *
 * Free of WordPress so the ordering rules can be tested: this is what draws
 * the wheel on a profile, and a wheel whose slices are in a different order
 * every time it loads looks broken.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Tallies genres across the works someone has watched.
 */
final class GenreTally {

	/**
	 * Count genres and return the strongest, biggest first.
	 *
	 * Ties break alphabetically rather than by whichever row the database
	 * happened to return first — the wheel has to look the same on every load,
	 * and "Aksiyon and Fantezi are both 4" is a real and common tie.
	 *
	 * @param array<int, array<int, string>> $lists One genre list per work watched.
	 * @param int                            $limit How many slices the wheel has.
	 * @return array<int, array{name: string, count: int}>
	 */
	public static function top( array $lists, int $limit = 5 ): array {
		$counts = array();

		foreach ( $lists as $list ) {
			if ( ! is_array( $list ) ) {
				continue;
			}

			// A work counts once per genre however many of its episodes were
			// watched: the wheel is about taste, not about episode counts.
			foreach ( array_unique( array_filter( array_map( 'strval', $list ) ) ) as $genre ) {
				$genre = trim( $genre );
				if ( '' === $genre ) {
					continue;
				}

				$counts[ $genre ] = ( $counts[ $genre ] ?? 0 ) + 1;
			}
		}

		if ( array() === $counts ) {
			return array();
		}

		$names = array_keys( $counts );

		usort(
			$names,
			static function ( string $a, string $b ) use ( $counts ): int {
				if ( $counts[ $a ] !== $counts[ $b ] ) {
					return $counts[ $b ] <=> $counts[ $a ];
				}

				return strcmp( $a, $b );
			}
		);

		$top = array();

		foreach ( array_slice( $names, 0, max( 1, $limit ) ) as $name ) {
			$top[] = array(
				'name'  => $name,
				'count' => $counts[ $name ],
			);
		}

		return $top;
	}
}
