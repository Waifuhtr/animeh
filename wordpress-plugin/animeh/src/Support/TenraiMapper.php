<?php
/**
 * Turning a Tenrai payload into a catalog row.
 *
 * Tenrai's v1 endpoints follow the Jikan v4 compatibility schema, so that is
 * what this reads. Two things follow from it being a *compatibility* schema:
 * fields are frequently null rather than absent, and the same value appears in
 * more than one shape across endpoints (`title` alongside a `titles[]` array,
 * `images.jpg.large_image_url` alongside `images.webp.*`). Both are handled by
 * looking in every place the value is known to appear rather than assuming the
 * one the documentation shows.
 *
 * Only what the app displays is normalised. §6 is explicit that Tenrai's whole
 * payload need not be copied, and copying it would mean a schema migration
 * every time the upstream adds a field.
 *
 * Free of any WordPress dependency, so the mapping is tested directly against
 * recorded payloads.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Maps Tenrai/Jikan-shaped anime payloads onto the catalog's columns.
 */
final class TenraiMapper {

	/**
	 * Normalise one anime entry.
	 *
	 * @param array<string, mixed> $entry Decoded Tenrai anime object.
	 * @return array<string, mixed> Columns for the works table.
	 */
	public static function work( array $entry ): array {
		$title = self::title( $entry );

		return array(
			'kind'           => 'anime',
			'tenrai_id'      => (int) ( $entry['mal_id'] ?? $entry['id'] ?? 0 ),
			'mal_id'         => (int) ( $entry['mal_id'] ?? 0 ),
			'title'          => $title,
			'title_english'  => self::titled( $entry, 'English', 'title_english' ),
			'title_japanese' => self::titled( $entry, 'Japanese', 'title_japanese' ),
			'synonyms'       => self::synonyms( $entry ),
			'synopsis'       => self::text( $entry['synopsis'] ?? '' ),
			'poster_url'     => self::image( $entry['images'] ?? null ),
			'trailer_url'    => self::text( $entry['trailer']['url'] ?? '' ),
			'score'          => self::score( $entry['score'] ?? null ),
			'popularity'     => (int) ( $entry['popularity'] ?? 0 ),
			'year'           => self::year( $entry ),
			'season'         => strtolower( self::text( $entry['season'] ?? '' ) ),
			'status'         => self::status( self::text( $entry['status'] ?? '' ) ),
			'format'         => self::text( $entry['type'] ?? '' ),
			'rating'         => self::text( $entry['rating'] ?? '' ),
			'studio'         => self::first_name( $entry['studios'] ?? array() ),
			'genres'         => self::names( $entry['genres'] ?? array() ),
			'total_episodes' => (int) ( $entry['episodes'] ?? 0 ),
			// Jikan reports a per-episode duration as prose: "24 min per ep".
			'duration_seconds' => self::duration( self::text( $entry['duration'] ?? '' ) ),
		);
	}

	/**
	 * Normalise one entry from `/anime/{id}/episodes`.
	 *
	 * @param array<string, mixed> $entry  Decoded episode object.
	 * @param int                  $season Season number to file it under.
	 * @return array<string, mixed> Columns for the episodes table.
	 */
	public static function episode( array $entry, int $season = 1 ): array {
		return array(
			'season_number' => max( 1, $season ),
			'number'        => (int) ( $entry['mal_id'] ?? $entry['episode_id'] ?? 0 ),
			'title'         => self::text( $entry['title'] ?? '' ),
			'synopsis'      => self::text( $entry['synopsis'] ?? '' ),
			'filler'        => ! empty( $entry['filler'] ) ? 1 : 0,
			'published_at'  => self::date( self::text( $entry['aired'] ?? '' ) ),
		);
	}

	/**
	 * The best available display title.
	 *
	 * @param array<string, mixed> $entry Anime entry.
	 */
	public static function title( array $entry ): string {
		$direct = self::text( $entry['title'] ?? '' );
		if ( '' !== $direct ) {
			return $direct;
		}

		// Newer payloads drop `title` and carry only `titles[]`.
		$default = self::titled( $entry, 'Default', '' );
		if ( '' !== $default ) {
			return $default;
		}

		return self::titled( $entry, 'English', 'title_english' );
	}

	/**
	 * Look up a title by its type, falling back to a flat field.
	 *
	 * @param array<string, mixed> $entry    Anime entry.
	 * @param string               $type     Title type, e.g. "English".
	 * @param string               $fallback Flat key to fall back to.
	 */
	private static function titled( array $entry, string $type, string $fallback ): string {
		$titles = $entry['titles'] ?? null;
		if ( is_array( $titles ) ) {
			foreach ( $titles as $title ) {
				if ( is_array( $title ) && ( $title['type'] ?? '' ) === $type ) {
					$value = self::text( $title['title'] ?? '' );
					if ( '' !== $value ) {
						return $value;
					}
				}
			}
		}

		return '' === $fallback ? '' : self::text( $entry[ $fallback ] ?? '' );
	}

	/**
	 * Alternative titles, as a JSON array.
	 *
	 * @param array<string, mixed> $entry Anime entry.
	 */
	private static function synonyms( array $entry ): string {
		$out = array();

		$flat = $entry['title_synonyms'] ?? array();
		if ( is_array( $flat ) ) {
			foreach ( $flat as $value ) {
				$text = self::text( $value );
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}

		$titles = $entry['titles'] ?? array();
		if ( is_array( $titles ) ) {
			foreach ( $titles as $title ) {
				if ( is_array( $title ) && ( $title['type'] ?? '' ) === 'Synonym' ) {
					$text = self::text( $title['title'] ?? '' );
					if ( '' !== $text ) {
						$out[] = $text;
					}
				}
			}
		}

		$out = array_values( array_unique( $out ) );

		return self::json( $out );
	}

	/**
	 * The largest poster the payload offers.
	 *
	 * WebP first: it is what the CDN serves smallest, and every Android version
	 * the app targets decodes it.
	 *
	 * @param mixed $images The `images` object.
	 */
	public static function image( $images ): string {
		if ( ! is_array( $images ) ) {
			return '';
		}

		foreach ( array( 'webp', 'jpg' ) as $format ) {
			$set = $images[ $format ] ?? null;
			if ( ! is_array( $set ) ) {
				continue;
			}
			foreach ( array( 'large_image_url', 'image_url', 'small_image_url' ) as $size ) {
				$url = self::text( $set[ $size ] ?? '' );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/**
	 * Genre or studio names, as a JSON array.
	 *
	 * @param mixed $list List of `{mal_id, name}` objects.
	 */
	public static function names( $list ): string {
		if ( ! is_array( $list ) ) {
			return '[]';
		}

		$names = array();
		foreach ( $list as $item ) {
			$name = is_array( $item ) ? self::text( $item['name'] ?? '' ) : self::text( $item );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return self::json( array_values( array_unique( $names ) ) );
	}

	/**
	 * The first name in a list, for the single-studio column.
	 *
	 * @param mixed $list List of `{mal_id, name}` objects.
	 */
	private static function first_name( $list ): string {
		if ( ! is_array( $list ) ) {
			return '';
		}
		foreach ( $list as $item ) {
			$name = is_array( $item ) ? self::text( $item['name'] ?? '' ) : self::text( $item );
			if ( '' !== $name ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Broadcast year, from wherever this payload carries it.
	 *
	 * @param array<string, mixed> $entry Anime entry.
	 */
	private static function year( array $entry ): int {
		$year = (int) ( $entry['year'] ?? 0 );
		if ( $year > 0 ) {
			return $year;
		}

		// `/anime/{id}/full` puts it under aired.prop.from.year instead.
		$from = $entry['aired']['prop']['from']['year'] ?? 0;
		if ( is_numeric( $from ) && (int) $from > 0 ) {
			return (int) $from;
		}

		$string = self::text( $entry['aired']['from'] ?? '' );
		if ( '' !== $string && preg_match( '/(\d{4})/', $string, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	/**
	 * Map upstream status text onto our own vocabulary.
	 *
	 * The app switches on this, so it cannot be free text that changes upstream.
	 *
	 * @param string $status Status as reported.
	 */
	public static function status( string $status ): string {
		$normalised = strtolower( trim( $status ) );

		// Order matters: the upstream's most common value is "Finished Airing",
		// which contains "airing". Testing for "airing" first would mark every
		// completed series as still broadcasting.
		return match ( true ) {
			'' === $normalised => '',
			str_contains( $normalised, 'finished' ), str_contains( $normalised, 'complete' ) => 'finished',
			str_contains( $normalised, 'not yet' ), str_contains( $normalised, 'upcoming' ) => 'upcoming',
			str_contains( $normalised, 'currently' ), str_contains( $normalised, 'airing' ) => 'airing',
			default => 'unknown',
		};
	}

	/**
	 * Seconds from a prose duration like "24 min per ep" or "1 hr 35 min".
	 *
	 * @param string $duration Duration as reported.
	 */
	public static function duration( string $duration ): int {
		$seconds = 0;

		if ( preg_match( '/(\d+)\s*hr/i', $duration, $hours ) ) {
			$seconds += (int) $hours[1] * 3600;
		}
		if ( preg_match( '/(\d+)\s*min/i', $duration, $minutes ) ) {
			$seconds += (int) $minutes[1] * 60;
		}
		if ( 0 === $seconds && preg_match( '/(\d+)\s*sec/i', $duration, $secs ) ) {
			$seconds += (int) $secs[1];
		}

		return $seconds;
	}

	/**
	 * A score, clamped to what the column can hold.
	 *
	 * @param mixed $score Score as reported.
	 */
	private static function score( $score ): float {
		if ( ! is_numeric( $score ) ) {
			return 0.0;
		}
		return round( max( 0.0, min( 10.0, (float) $score ) ), 2 );
	}

	/**
	 * An ISO-8601 timestamp as a MySQL datetime, or the zero date.
	 *
	 * @param string $value Timestamp as reported.
	 */
	private static function date( string $value ): string {
		if ( '' === $value ) {
			return '0000-00-00 00:00:00';
		}
		$time = strtotime( $value );
		return false === $time ? '0000-00-00 00:00:00' : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Coerce a possibly-null upstream value to a trimmed string.
	 *
	 * @param mixed $value Any scalar the payload offered.
	 */
	private static function text( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Encode without escaping, so Turkish and Japanese survive readable.
	 *
	 * @param array<int, string> $value List to encode.
	 */
	private static function json( array $value ): string {
		$encoded = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return false === $encoded ? '[]' : $encoded;
	}
}
