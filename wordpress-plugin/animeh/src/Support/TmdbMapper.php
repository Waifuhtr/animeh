<?php
/**
 * Turning TMDB's shapes into this catalog's shapes.
 *
 * Free of WordPress, like the rest of Support/, so the mapping can be tested
 * against real payloads without a site. That matters more here than usual:
 * TMDB is the source for artwork, and a mapping mistake shows up as a blank
 * poster on every card rather than as an error anyone would notice.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Maps TMDB TV payloads onto catalog rows.
 */
final class TmdbMapper {

	/**
	 * Where TMDB serves images from.
	 *
	 * The API publishes this under /configuration and asks clients to read it
	 * rather than hard-code it. This is the fallback for when that call has
	 * not been made yet, not a replacement for it.
	 */
	public const IMAGE_BASE = 'https://image.tmdb.org/t/p/';

	/** Poster size to request. w500 is what a phone card actually needs. */
	public const POSTER_SIZE = 'w500';

	/** Backdrop size, used for the hero slider. */
	public const BACKDROP_SIZE = 'w1280';

	/** Episode still size. */
	public const STILL_SIZE = 'w300';

	/**
	 * Absolute URL for one image path.
	 *
	 * TMDB returns paths like "/abc123.jpg" and expects the client to join
	 * them to a base and a size. An empty path yields an empty string rather
	 * than a URL that would 404 — an empty `poster_url` is a missing poster,
	 * which the app already draws a placeholder for.
	 *
	 * @param string $path Path from TMDB, with its leading slash.
	 * @param string $size Size segment, e.g. "w500".
	 * @param string $base Image base, ending in a slash.
	 */
	public static function image( string $path, string $size, string $base = self::IMAGE_BASE ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}

		return rtrim( $base, '/' ) . '/' . trim( $size, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * TMDB's status vocabulary, in this catalog's terms.
	 *
	 * @param string $status TMDB status string.
	 */
	public static function status( string $status ): string {
		switch ( strtolower( trim( $status ) ) ) {
			case 'returning series':
			case 'in production':
				return 'airing';
			case 'ended':
			case 'canceled':
			case 'cancelled':
				return 'finished';
			case 'planned':
			case 'pilot':
				return 'upcoming';
			default:
				return 'unknown';
		}
	}

	/**
	 * The fields worth taking from a TMDB TV record.
	 *
	 * Only ever the fields TMDB is actually good for. The title and the year
	 * come back too so a caller can offer them, but importing is a merge
	 * decided by the caller — this returns what is available, not what should
	 * be written.
	 *
	 * @param array<string, mixed> $tv   TMDB TV details payload.
	 * @param string               $base Image base from /configuration.
	 * @return array<string, mixed>
	 */
	public static function work( array $tv, string $base = self::IMAGE_BASE ): array {
		$first_air = (string) ( $tv['first_air_date'] ?? '' );
		$genres    = array();

		foreach ( (array) ( $tv['genres'] ?? array() ) as $genre ) {
			if ( is_array( $genre ) && isset( $genre['name'] ) ) {
				$genres[] = (string) $genre['name'];
			}
		}

		// TMDB gives runtime as a list of typical lengths, newest convention
		// first; the first entry is the one that describes the show.
		$runtimes = array_values( array_filter( array_map( 'intval', (array) ( $tv['episode_run_time'] ?? array() ) ) ) );

		return array(
			'tmdb_id'          => (int) ( $tv['id'] ?? 0 ),
			'title'            => (string) ( $tv['name'] ?? '' ),
			'title_english'    => (string) ( $tv['original_name'] ?? '' ),
			'synopsis'         => (string) ( $tv['overview'] ?? '' ),
			'poster_url'       => self::image( (string) ( $tv['poster_path'] ?? '' ), self::POSTER_SIZE, $base ),
			'banner_url'       => self::image( (string) ( $tv['backdrop_path'] ?? '' ), self::BACKDROP_SIZE, $base ),
			'year'             => self::year( $first_air ),
			'status'           => self::status( (string) ( $tv['status'] ?? '' ) ),
			'genres'           => $genres,
			'total_episodes'   => (int) ( $tv['number_of_episodes'] ?? 0 ),
			'duration_seconds' => array() === $runtimes ? 0 : $runtimes[0] * 60,
			'score'            => round( (float) ( $tv['vote_average'] ?? 0 ), 2 ),
			// TMDB's own flag. Taken as a starting point rather than the last
			// word: it is set for pornography and little else, and plenty of
			// series this catalog would want to warn about are not marked.
			'adult'            => (bool) ( $tv['adult'] ?? false ),
		);
	}

	/**
	 * One episode from a TMDB season payload.
	 *
	 * @param array<string, mixed> $episode TMDB episode entry.
	 * @param string               $base    Image base.
	 * @return array<string, mixed>
	 */
	public static function episode( array $episode, string $base = self::IMAGE_BASE ): array {
		$runtime = (int) ( $episode['runtime'] ?? 0 );

		return array(
			'season_number'    => (int) ( $episode['season_number'] ?? 1 ),
			'number'           => (int) ( $episode['episode_number'] ?? 0 ),
			'title'            => (string) ( $episode['name'] ?? '' ),
			'synopsis'         => (string) ( $episode['overview'] ?? '' ),
			'thumbnail_url'    => self::image( (string) ( $episode['still_path'] ?? '' ), self::STILL_SIZE, $base ),
			'duration_seconds' => $runtime > 0 ? $runtime * 60 : 0,
			'published_at'     => (string) ( $episode['air_date'] ?? '' ),
		);
	}

	/**
	 * Year out of a TMDB date, zero when there is not one.
	 *
	 * @param string $date "YYYY-MM-DD", or an empty string.
	 */
	public static function year( string $date ): int {
		if ( ! preg_match( '/^(\d{4})/', trim( $date ), $matches ) ) {
			return 0;
		}

		return (int) $matches[1];
	}

	/**
	 * The search result most likely to be the show being imported.
	 *
	 * Search is where an automatic artwork fill goes wrong: "Naruto" matches
	 * four shows, and picking the wrong one puts the wrong poster on the card
	 * silently. The scoring is deliberately conservative — an exact title and
	 * a matching year outrank popularity, and popularity only breaks ties.
	 *
	 * @param array<int, array<string, mixed>> $results TMDB search results.
	 * @param string                           $title   Title being matched.
	 * @param int                              $year    Year, or zero if unknown.
	 * @return array<string, mixed>|null
	 */
	public static function best_match( array $results, string $title, int $year = 0 ) {
		$wanted = self::normalise( $title );
		$best   = null;
		$score  = -1.0;

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$names = array(
				self::normalise( (string) ( $result['name'] ?? '' ) ),
				self::normalise( (string) ( $result['original_name'] ?? '' ) ),
			);

			$current = 0.0;

			if ( '' !== $wanted && in_array( $wanted, $names, true ) ) {
				$current += 100.0;
			} elseif ( '' !== $wanted ) {
				foreach ( $names as $name ) {
					if ( '' !== $name && ( str_contains( $name, $wanted ) || str_contains( $wanted, $name ) ) ) {
						$current += 40.0;
						break;
					}
				}
			}

			if ( $year > 0 ) {
				$result_year = self::year( (string) ( $result['first_air_date'] ?? '' ) );
				if ( $result_year === $year ) {
					$current += 50.0;
				} elseif ( $result_year > 0 && abs( $result_year - $year ) === 1 ) {
					// A season that aired across New Year is filed under either
					// year depending on the source; one off is not a mismatch.
					$current += 20.0;
				}
			}

			// Popularity is a tie-breaker and nothing more: capped so it can
			// never outweigh a title or a year.
			$current += min( 9.0, (float) ( $result['popularity'] ?? 0 ) / 100.0 );

			if ( $current > $score ) {
				$score = $current;
				$best  = $result;
			}
		}

		// Nothing matched on either title or year: better to return nothing and
		// let a human choose than to attach the most popular unrelated show.
		return $score >= 20.0 ? $best : null;
	}

	/**
	 * The TMDB id in a search box, when there is one.
	 *
	 * Searching by title does not always reach the show you meant — several
	 * series share a name, and an anime's Turkish, romanised and English
	 * titles are three different searches. The id is unambiguous, and it is
	 * sitting in the address bar of the page you are already looking at, so
	 * pasting either the number or the whole URL is the fastest route to a
	 * specific title.
	 *
	 * Accepts a bare number, or any themoviedb.org URL for a TV show —
	 * "/tv/96316", "/tv/96316-title", with or without a scheme, a language
	 * prefix, a trailing path or a query string. A movie URL yields nothing,
	 * because this catalog imports series and looking a movie id up as a
	 * series would fetch some unrelated show that happens to share the number.
	 *
	 * @param string $query What was typed.
	 * @return int The id, or zero when the text is an ordinary search.
	 */
	public static function extract_id( string $query ): int {
		$query = trim( $query );

		if ( '' === $query ) {
			return 0;
		}

		// A bare number is an id. Nothing else this catalog searches for is
		// digits alone, so there is no ambiguity to resolve.
		if ( preg_match( '/^\d+$/', $query ) ) {
			return (int) $query;
		}

		// themoviedb.org/tv/96316, /tr/tv/96316-baslik, ?language=…, /seasons.
		if ( preg_match( '#themoviedb\.org/(?:[a-z]{2}(?:-[A-Za-z]{2})?/)?tv/(\d+)#i', $query, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	/**
	 * Titles compared without the noise that makes two spellings differ.
	 *
	 * @param string $value Title.
	 */
	public static function normalise( string $value ): string {
		$value = mb_strtolower( trim( $value ), 'UTF-8' );
		$value = str_replace( array( '–', '—', '’', '‘', '“', '”' ), array( '-', '-', "'", "'", '"', '"' ), $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );

		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
	}
}
