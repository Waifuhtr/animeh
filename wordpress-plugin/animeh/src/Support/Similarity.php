<?php
/**
 * How close two titles are, by the genres they share.
 *
 * Kept apart from the query that fetches the candidates, and not only for
 * testing. The two answer different questions: SQL is asked *which rows could
 * possibly be relevant*, which it can do cheaply with an index and a LIKE;
 * this is asked *which of them is the better suggestion*, which needs the
 * genre lists side by side and is arithmetic rather than storage.
 *
 * Genres live as a JSON array inside a column rather than in a join table, so
 * there is no `COUNT(*) ... GROUP BY` to lean on here. That is what makes the
 * ranking a piece of logic worth naming — and worth a test, because a
 * suggestion row that is subtly mis-ordered looks exactly like one that is
 * working.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Ranks candidate works against the one being looked at.
 */
final class Similarity {

	/**
	 * The candidates, best first.
	 *
	 * Ordering is by shared genre count, then by the order they arrived in.
	 * The second half matters as much as the first: the caller hands these
	 * over already sorted by score, so preserving that inside each group of
	 * equally-similar titles is what makes the row read as "good things like
	 * this one" rather than "things like this one, shuffled".
	 *
	 * A candidate sharing nothing is dropped rather than ranked last. It can
	 * only be in the pool because the query matched a genre this decoder
	 * could not read, and a suggestion with no reason behind it is worse than
	 * a shorter row.
	 *
	 * @param string[]                         $genres     The subject's genres.
	 * @param array<int, array<string, mixed>> $candidates Rows, already in score order.
	 * @param int                              $limit      How many to keep.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rank( array $genres, array $candidates, int $limit ): array {
		$wanted = array();

		foreach ( $genres as $genre ) {
			$name = trim( (string) $genre );

			if ( '' !== $name ) {
				$wanted[ $name ] = true;
			}
		}

		if ( array() === $wanted || $limit < 1 ) {
			return array();
		}

		$scored = array();

		foreach ( array_values( $candidates ) as $index => $row ) {
			$shared = self::shared( $wanted, $row['genres'] ?? '[]' );

			if ( 0 === $shared ) {
				continue;
			}

			$scored[] = array( $shared, $index, $row );
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				// More shared genres first; ties keep the order they came in.
				return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
			}
		);

		return array_map(
			static fn( array $entry ): array => $entry[2],
			array_slice( $scored, 0, $limit )
		);
	}

	/**
	 * How many of [$wanted] appear in a row's genre list.
	 *
	 * The row's genres arrive as the JSON the column holds. Anything that is
	 * not a readable list counts as zero rather than throwing: a single badly
	 * imported title should cost itself a place in one row, not break the
	 * page it appears on.
	 *
	 * Counted over unique names, so a list that repeats a genre cannot rank
	 * above one that does not.
	 *
	 * @param array<string, bool> $wanted Subject genres, as a lookup.
	 * @param mixed               $raw    The candidate's `genres` column.
	 */
	private static function shared( array $wanted, $raw ): int {
		$theirs = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );

		if ( ! is_array( $theirs ) ) {
			return 0;
		}

		$seen = array();

		foreach ( $theirs as $genre ) {
			$name = trim( (string) $genre );

			if ( '' !== $name && isset( $wanted[ $name ] ) ) {
				$seen[ $name ] = true;
			}
		}

		return count( $seen );
	}
}
