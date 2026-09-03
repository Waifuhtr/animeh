<?php
/**
 * The families subtitles have asked for and not found.
 *
 * §15's flow needs a list: the player parses a script, discovers it needs a
 * font nobody has uploaded, and somebody has to be told *which* font. Without
 * this the operator's only clue is that the subtitles look wrong, and the name
 * of the family lives inside a script file they would have to open by hand.
 *
 * An option rather than a table. This is a short list of strings that changes
 * as episodes are watched and is thrown away as fonts get uploaded; it is
 * worth no schema, no migration and no index. The cap is what keeps it an
 * option: a bounded number of entries, oldest evicted, so a site with a
 * thousand mismatched scripts cannot grow a row in `wp_options` without end.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\FontMatch;

/**
 * A small register of missing font families.
 */
final class WantedFonts {

	/**
	 * Option holding the register.
	 */
	private const OPTION = 'animeh_wanted_fonts';

	/**
	 * Most families kept. Beyond this the least recently asked-for goes.
	 */
	private const LIMIT = 100;

	/**
	 * Record that a script asked for these families and did not get them.
	 *
	 * @param string[] $families Family names, as the script wrote them.
	 * @return int How many were new.
	 */
	public static function report( array $families ): int {
		$stored = self::raw();
		$now    = current_time( 'mysql', true );
		$added  = 0;

		foreach ( $families as $raw ) {
			$family = trim( (string) $raw );

			if ( '' === $family || mb_strlen( $family ) > 120 ) {
				continue;
			}

			$key = FontMatch::compact( $family );

			if ( '' === $key ) {
				continue;
			}

			if ( isset( $stored[ $key ] ) ) {
				$stored[ $key ]['count']     = (int) $stored[ $key ]['count'] + 1;
				$stored[ $key ]['last_seen'] = $now;
				continue;
			}

			$stored[ $key ] = array(
				'family'    => $family,
				'count'     => 1,
				'first_seen' => $now,
				'last_seen' => $now,
			);
			++$added;
		}

		// Newest last, then trimmed from the front: a family nobody has asked
		// for in a long time is the one worth forgetting.
		uasort(
			$stored,
			static fn( array $a, array $b ): int => strcmp( (string) $a['last_seen'], (string) $b['last_seen'] )
		);

		if ( count( $stored ) > self::LIMIT ) {
			$stored = array_slice( $stored, -self::LIMIT, null, true );
		}

		update_option( self::OPTION, $stored, false );

		return $added;
	}

	/**
	 * The register, most recently asked-for first, with what is already on the
	 * server filtered out.
	 *
	 * Filtered on read rather than cleared on upload: a font uploaded under a
	 * different name still answers the request thanks to {@see FontMatch}, and
	 * a list that keeps showing a family somebody has already dealt with is a
	 * list people stop reading.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$fonts = FontRepository::all();
		$rows  = array();

		foreach ( array_reverse( self::raw(), true ) as $entry ) {
			$family = (string) $entry['family'];

			if ( null !== FontMatch::best( $family, $fonts ) ) {
				continue;
			}

			$rows[] = array(
				'family'     => $family,
				'count'      => (int) $entry['count'],
				'first_seen' => (string) ( $entry['first_seen'] ?? '' ),
				'last_seen'  => (string) ( $entry['last_seen'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Forget one family, or everything.
	 *
	 * @param string $family Family to drop, or '' for all of them.
	 */
	public static function forget( string $family = '' ): void {
		if ( '' === $family ) {
			delete_option( self::OPTION );

			return;
		}

		$stored = self::raw();
		unset( $stored[ FontMatch::compact( $family ) ] );

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * The stored register, keyed by compacted family name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function raw(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();

		foreach ( $stored as $key => $entry ) {
			if ( is_array( $entry ) && isset( $entry['family'] ) ) {
				$clean[ (string) $key ] = $entry;
			}
		}

		return $clean;
	}
}
