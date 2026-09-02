<?php
/**
 * Display names for the catalog's vocabulary.
 *
 * An import brings values in the source's own words: `comedy`, `Finished
 * Airing`, `winter`. Those stay exactly as they are — they are the keys every
 * filter, index and stored row matches on — and this table only says what to
 * show a reader instead. Renaming the stored value would have been simpler
 * and would break every existing filter the first time someone edited one.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Reads and writes the display-name overrides.
 */
final class TermRepository {

	/**
	 * The vocabularies that can be relabelled.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array( 'genre', 'status', 'format', 'season' );
	}

	/**
	 * Every override, grouped by kind.
	 *
	 * @return array<string, array<string, string>> kind => (source => display)
	 */
	public function map(): array {
		global $wpdb;

		$table = CatalogSchema::terms();

		$rows = $wpdb->get_results( "SELECT kind, source, display FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$map = array();
		foreach ( (array) $rows as $row ) {
			$kind = (string) $row['kind'];
			$map[ $kind ][ self::key( (string) $row['source'] ) ] = (string) $row['display'];
		}

		return $map;
	}

	/**
	 * Every override as a flat list, for the editor.
	 *
	 * @param string $kind Optional filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $kind = '' ): array {
		global $wpdb;

		$table = CatalogSchema::terms();

		if ( '' !== $kind ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE kind = %s ORDER BY source ASC", $kind ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY kind ASC, source ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Set, or clear, what a value is shown as.
	 *
	 * An empty display removes the override rather than storing a blank label,
	 * so the reader falls back to the imported wording instead of a gap.
	 *
	 * @param string $kind    One of [kinds].
	 * @param string $source  The imported value.
	 * @param string $display What to show, or '' to drop the override.
	 * @return bool Whether the kind was one this accepts.
	 */
	public function set( string $kind, string $source, string $display ): bool {
		global $wpdb;

		if ( ! in_array( $kind, self::kinds(), true ) ) {
			return false;
		}

		$source = trim( $source );
		if ( '' === $source ) {
			return false;
		}

		$table   = CatalogSchema::terms();
		$display = trim( $display );

		if ( '' === $display ) {
			$wpdb->delete( $table, array( 'kind' => $kind, 'source' => $source ), array( '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return true;
		}

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'kind'       => $kind,
				'source'     => $source,
				'display'    => $display,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Every distinct value the catalog actually uses, per kind.
	 *
	 * This is what makes the editor usable: it lists what is really in the
	 * data, including values nobody has relabelled yet, rather than asking an
	 * admin to remember that `tv_special` exists.
	 *
	 * @return array<string, string[]>
	 */
	public function in_use(): array {
		global $wpdb;

		$works = CatalogSchema::works();

		$used = array(
			'status' => array(),
			'format' => array(),
			'season' => array(),
			'genre'  => array(),
		);

		foreach ( array( 'status', 'format', 'season' ) as $column ) {
			$values = $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$works} WHERE {$column} <> '' ORDER BY {$column} ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$used[ $column ] = array_map( 'strval', (array) $values );
		}

		// Genres are stored as a JSON list per work, so they have to be unpacked
		// rather than selected distinctly.
		$genre_rows = $wpdb->get_col( "SELECT genres FROM {$works} WHERE genres <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$genres = array();
		foreach ( (array) $genre_rows as $raw ) {
			$decoded = json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $genre ) {
				$genre = trim( (string) $genre );
				if ( '' !== $genre ) {
					$genres[ self::key( $genre ) ] = $genre;
				}
			}
		}

		sort( $genres );
		$used['genre'] = array_values( $genres );

		return $used;
	}

	/**
	 * Matching is case- and spacing-insensitive: an import that says
	 * "Slice of Life" and one that says "slice of life" are one term.
	 *
	 * @param string $value Raw value.
	 */
	public static function key( string $value ): string {
		return strtolower( trim( $value ) );
	}

	/**
	 * Apply a kind's overrides to one value.
	 *
	 * @param array<string, array<string, string>> $map   From [map].
	 * @param string                               $kind  Vocabulary.
	 * @param string                               $value Stored value.
	 */
	public static function display( array $map, string $kind, string $value ): string {
		return $map[ $kind ][ self::key( $value ) ] ?? $value;
	}
}
