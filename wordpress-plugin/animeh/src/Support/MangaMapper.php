<?php
/**
 * Manga metadata, from two sources, in our shape.
 *
 * The first is Tenrai with Jikan behind it. Those two speak the identical
 * schema — Tenrai is a mirror of Jikan v4 — so one mapper reads both, which is
 * the same arrangement [TenraiMapper] already has for anime.
 *
 * The second is the gallery source, which is shaped nothing like them: titles
 * in three languages, no synopsis at all, and everything else expressed as
 * tags. Its own mapper is below.
 *
 * Both produce the same array, because everything downstream — the importer,
 * the admin panel, the app — should not have to know which one a work came
 * from.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Maps manga metadata into the catalogue's shape.
 */
final class MangaMapper {

	/**
	 * One manga from Tenrai or Jikan.
	 *
	 * @param array<string, mixed> $entry The `data` object of `/manga/{id}`.
	 * @return array<string, mixed>
	 */
	public static function from_jikan( array $entry ): array {
		$titles = array();
		foreach ( (array) ( $entry['title_synonyms'] ?? array() ) as $synonym ) {
			if ( is_string( $synonym ) && '' !== trim( $synonym ) ) {
				$titles[] = trim( $synonym );
			}
		}

		return array(
			'kind'           => 'manga',
			'mal_id'         => (int) ( $entry['mal_id'] ?? 0 ),
			'nh_id'          => 0,
			'title'          => self::text( $entry['title'] ?? '' ),
			'title_english'  => self::text( $entry['title_english'] ?? '' ),
			'title_japanese' => self::text( $entry['title_japanese'] ?? '' ),
			'synonyms'       => $titles,
			'synopsis'       => self::text( $entry['synopsis'] ?? '' ),
			'poster_url'     => self::url(
				$entry['images']['webp']['large_image_url']
					?? $entry['images']['jpg']['large_image_url']
					?? ''
			),
			'banner_url'     => '',
			'score'          => (float) ( $entry['score'] ?? 0 ),
			'popularity'     => (int) ( $entry['members'] ?? 0 ),
			// A manga's start year, from the published range rather than a
			// field of its own — Jikan does not carry one for manga.
			'year'           => self::year( $entry ),
			'season'         => '',
			'status'         => self::status( (string) ( $entry['status'] ?? '' ) ),
			'format'         => self::text( $entry['type'] ?? 'Manga' ),
			'author'         => implode( ', ', self::names( $entry['authors'] ?? array() ) ),
			// Serialisation is the magazine it ran in, which is the closest
			// thing a manga has to a studio.
			'studio'         => implode( ', ', self::names( $entry['serializations'] ?? array() ) ),
			'genres'         => self::names( $entry['genres'] ?? array() ),
			'total_episodes' => (int) ( $entry['chapters'] ?? 0 ),
			// Jikan marks the explicit ones with a genre rather than a flag.
			'adult'          => self::adult_by_genre( $entry ),
		);
	}

	/**
	 * One gallery from the tag-based source.
	 *
	 * Everything here is explicit by definition, so `adult` is set and never
	 * asked about: the app puts a question in front of playback, and a work
	 * from this source is exactly what that question is for.
	 *
	 * @param array<string, mixed> $gallery Normalised gallery.
	 * @return array<string, mixed>
	 */
	public static function from_gallery( array $gallery ): array {
		$english  = self::text( $gallery['title']['english'] ?? $gallery['title_english'] ?? '' );
		$japanese = self::text( $gallery['title']['japanese'] ?? $gallery['title_japanese'] ?? '' );
		$pretty   = self::text( $gallery['title']['pretty'] ?? $gallery['title_pretty'] ?? '' );

		$tags = self::gallery_tags( $gallery );

		return array(
			'kind'           => 'manga',
			'mal_id'         => 0,
			'nh_id'          => (int) ( $gallery['id'] ?? 0 ),
			// The pretty title first: the English one carries the bracketed
			// circle, artist and language that the tags already hold, and a
			// catalogue reads better without four copies of the same words.
			'title'          => '' !== $pretty ? $pretty : ( '' !== $english ? $english : $japanese ),
			'title_english'  => $english,
			'title_japanese' => $japanese,
			'synonyms'       => array_values( array_filter( array( $english, $pretty ) ) ),
			'synopsis'       => '',
			'poster_url'     => self::url( $gallery['cover_image'] ?? '' ),
			'banner_url'     => '',
			'score'          => 0.0,
			'popularity'     => (int) ( $gallery['favourites'] ?? 0 ),
			'year'           => self::gallery_year( $gallery ),
			'season'         => '',
			'status'         => 'finished',
			'format'         => 'Doujinshi',
			'author'         => implode( ', ', $tags['artist'] ),
			'studio'         => implode( ', ', $tags['group'] ),
			// The content tags are the genres here; there is no other
			// vocabulary attached to a gallery.
			'genres'         => $tags['tag'],
			'total_episodes' => 1,
			'adult'          => true,
			// Kept apart from the genres so the app can show them under their
			// own headings, the way the source does.
			'taxonomies'     => $tags,
		);
	}

	/**
	 * A search result row, for either source.
	 *
	 * @param array<string, mixed> $work Mapped work.
	 * @param string               $source 'jikan' or 'gallery'.
	 * @return array<string, mixed>
	 */
	public static function search_row( array $work, string $source ): array {
		return array(
			'source'     => $source,
			'id'         => 'gallery' === $source ? (int) $work['nh_id'] : (int) $work['mal_id'],
			'title'      => (string) $work['title'],
			'title_english' => (string) $work['title_english'],
			'poster_url' => (string) $work['poster_url'],
			'year'       => (int) $work['year'],
			'score'      => (float) $work['score'],
			'chapters'   => (int) $work['total_episodes'],
			'format'     => (string) $work['format'],
			'adult'      => (bool) $work['adult'],
		);
	}

	/**
	 * The tag buckets a gallery carries, whichever shape it arrived in.
	 *
	 * @param array<string, mixed> $gallery Gallery.
	 * @return array<string, string[]>
	 */
	private static function gallery_tags( array $gallery ): array {
		$buckets = array(
			'tag'       => array(),
			'artist'    => array(),
			'parody'    => array(),
			'group'     => array(),
			'language'  => array(),
			'category'  => array(),
			'character' => array(),
		);

		// Already sorted by the caller.
		if ( isset( $gallery['taxonomies'] ) && is_array( $gallery['taxonomies'] ) ) {
			$aliases = array(
				'manga_tag'            => 'tag',
				'manga_artist'         => 'artist',
				'manga_parody'         => 'parody',
				'manga_group'          => 'group',
				'manga_language'       => 'language',
				'manga_category'       => 'category',
				'manga_character_tax'  => 'character',
			);

			foreach ( $aliases as $from => $to ) {
				foreach ( (array) ( $gallery['taxonomies'][ $from ] ?? array() ) as $name ) {
					if ( is_string( $name ) && '' !== trim( $name ) ) {
						$buckets[ $to ][] = trim( $name );
					}
				}
			}

			return array_map( 'array_values', array_map( 'array_unique', $buckets ) );
		}

		// A flat list of `{type, name}`, which is the other shape it comes in.
		foreach ( (array) ( $gallery['tags'] ?? array() ) as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}

			$type = (string) ( $tag['type'] ?? '' );
			$name = trim( (string) ( $tag['name'] ?? '' ) );

			if ( '' === $name || ! isset( $buckets[ $type ] ) ) {
				continue;
			}

			$buckets[ $type ][] = $name;
		}

		return array_map( 'array_values', array_map( 'array_unique', $buckets ) );
	}

	/**
	 * The year a gallery was uploaded.
	 *
	 * @param array<string, mixed> $gallery Gallery.
	 * @return int
	 */
	private static function gallery_year( array $gallery ): int {
		$uploaded = $gallery['upload_date'] ?? 0;

		if ( is_numeric( $uploaded ) && (int) $uploaded > 0 ) {
			return (int) gmdate( 'Y', (int) $uploaded );
		}

		if ( is_string( $uploaded ) && '' !== $uploaded ) {
			$time = strtotime( $uploaded );
			if ( false !== $time ) {
				return (int) gmdate( 'Y', $time );
			}
		}

		return 0;
	}

	/**
	 * The start year of a published range.
	 *
	 * @param array<string, mixed> $entry Manga.
	 * @return int
	 */
	private static function year( array $entry ): int {
		$from = $entry['published']['from'] ?? '';
		if ( is_string( $from ) && '' !== $from ) {
			$time = strtotime( $from );
			if ( false !== $time ) {
				return (int) gmdate( 'Y', $time );
			}
		}

		$prop = $entry['published']['prop']['from']['year'] ?? 0;

		return (int) $prop;
	}

	/**
	 * Their publishing states, as ours.
	 *
	 * @param string $status Source status.
	 * @return string
	 */
	private static function status( string $status ): string {
		$lower = strtolower( trim( $status ) );

		if ( str_contains( $lower, 'publishing' ) || str_contains( $lower, 'ongoing' ) ) {
			return 'airing';
		}
		if ( str_contains( $lower, 'finished' ) || str_contains( $lower, 'complete' ) ) {
			return 'finished';
		}
		if ( str_contains( $lower, 'not yet' ) || str_contains( $lower, 'upcoming' ) ) {
			return 'upcoming';
		}

		return '';
	}

	/**
	 * Whether the genre list marks this one explicit.
	 *
	 * @param array<string, mixed> $entry Manga.
	 * @return bool
	 */
	private static function adult_by_genre( array $entry ): bool {
		$explicit = array( 'hentai', 'erotica', 'ecchi' );

		foreach ( self::names( $entry['genres'] ?? array() ) as $genre ) {
			if ( in_array( strtolower( $genre ), $explicit, true ) ) {
				return true;
			}
		}

		foreach ( self::names( $entry['explicit_genres'] ?? array() ) as $genre ) {
			if ( '' !== $genre ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The `name` of every entry in one of their lists.
	 *
	 * @param mixed $list List.
	 * @return string[]
	 */
	private static function names( $list ): array {
		$names = array();

		foreach ( (array) $list as $item ) {
			$name = is_array( $item ) ? ( $item['name'] ?? '' ) : $item;
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				$names[] = trim( $name );
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Trimmed text, or empty.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * A URL, or empty.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	private static function url( $value ): string {
		$text = self::text( $value );

		return 1 === preg_match( '#^https?://#i', $text ) ? $text : '';
	}
}
