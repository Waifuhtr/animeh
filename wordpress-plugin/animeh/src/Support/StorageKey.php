<?php
/**
 * Object key layout for the media bucket.
 *
 * Backblaze has no real directories — a key is one flat string and `/` is only
 * a display convention — but the console groups on it, so the layout decides
 * whether an operator can find anything by hand. That is the whole point of
 * naming folders after the anime rather than after its database id.
 *
 * Free of any WordPress dependency, so the slugging and padding rules are
 * verified directly.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Builds and parses media object keys.
 */
final class StorageKey {

	/**
	 * Everything the plugin owns lives under this prefix.
	 *
	 * A bucket may be shared with other things; keeping to one root means the
	 * plugin can list, back up or clean up its own objects without touching
	 * anything it did not put there.
	 */
	public const ROOT = 'anime';

	/**
	 * Prefix for the plugin's own bookkeeping, kept away from media.
	 */
	public const SYSTEM_ROOT = '_animeh';

	/**
	 * Transliterations applied before slugging.
	 *
	 * Turkish first, because the titles this library holds are Turkish-facing
	 * and `ı`/`ş`/`ğ` would otherwise be stripped to nothing.
	 *
	 * @var array<string, string>
	 */
	private const TRANSLITERATIONS = array(
		'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
		'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
		'â' => 'a', 'î' => 'i', 'û' => 'u',
		'ä' => 'a', 'Ä' => 'a', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
		'á' => 'a', 'à' => 'a', 'ó' => 'o', 'ò' => 'o', 'ñ' => 'n', 'å' => 'a',
		'ø' => 'o', 'æ' => 'ae',
	);

	/**
	 * Longest slug kept, so a key stays readable and well inside S3's limit.
	 */
	private const MAX_SLUG = 60;

	/**
	 * Turn a title into a folder-safe slug.
	 *
	 * Titles that transliterate to nothing — a purely Japanese or Korean title,
	 * say — fall back to the anime's id rather than producing an empty folder
	 * or a mangled one.
	 *
	 * @param string $title Anime title as entered.
	 * @param int    $id    Anime id, used when the title yields no usable slug.
	 */
	public static function slug( string $title, int $id = 0 ): string {
		$value = strtr( $title, self::TRANSLITERATIONS );
		$value = mb_strtolower( $value, 'UTF-8' );

		// Anything outside the safe set becomes a separator, then runs of
		// separators collapse. Done in this order so "Fate/Zero" reads as
		// "fate-zero" rather than losing the boundary.
		$value = preg_replace( '/[^a-z0-9]+/u', '-', $value ) ?? '';
		$value = trim( $value, '-' );

		if ( mb_strlen( $value, 'UTF-8' ) > self::MAX_SLUG ) {
			$value = rtrim( mb_substr( $value, 0, self::MAX_SLUG, 'UTF-8' ), '-' );
		}

		if ( '' === $value ) {
			return $id > 0 ? 'anime-' . $id : 'anime';
		}
		return $value;
	}

	/**
	 * Folder for one anime.
	 *
	 * @param string $slug Slug from {@see self::slug()}.
	 */
	public static function anime_prefix( string $slug ): string {
		return self::ROOT . '/' . $slug;
	}

	/**
	 * Folder for one episode.
	 *
	 * Numbers are zero-padded because the Backblaze console sorts keys as
	 * strings: without it episode 10 files between 1 and 2, and a season of
	 * twenty-six episodes becomes unreadable.
	 *
	 * @param string $slug    Anime slug.
	 * @param int    $season  Season number.
	 * @param int    $episode Episode number.
	 */
	public static function episode_prefix( string $slug, int $season, int $episode ): string {
		return sprintf(
			'%s/season-%02d/episode-%03d',
			self::anime_prefix( $slug ),
			max( 0, $season ),
			max( 0, $episode )
		);
	}

	/**
	 * Key for a file belonging to an episode.
	 *
	 * @param string $slug     Anime slug.
	 * @param int    $season   Season number.
	 * @param int    $episode  Episode number.
	 * @param string $filename File name within the episode folder.
	 */
	public static function episode_file( string $slug, int $season, int $episode, string $filename ): string {
		return self::episode_prefix( $slug, $season, $episode ) . '/' . self::safe_filename( $filename );
	}

	/**
	 * Key for a subtitle belonging to an episode.
	 *
	 * @param string $slug     Anime slug.
	 * @param int    $season   Season number.
	 * @param int    $episode  Episode number.
	 * @param string $language Language tag, e.g. `tr`.
	 * @param string $format   Subtitle extension without the dot.
	 */
	public static function subtitle_file( string $slug, int $season, int $episode, string $language, string $format = 'ass' ): string {
		$language = preg_replace( '/[^a-zA-Z0-9-]/', '', $language ) ?? 'und';
		$format   = preg_replace( '/[^a-z0-9]/', '', strtolower( $format ) ) ?? 'ass';
		return self::episode_prefix( $slug, $season, $episode ) . '/subtitles/' . ( '' === $language ? 'und' : $language ) . '.' . $format;
	}

	/**
	 * Key for a font. Fonts are shared across a whole anime, not per episode:
	 * a release typesets every episode with the same faces.
	 *
	 * @param string $slug     Anime slug.
	 * @param string $filename Font file name.
	 */
	public static function font_file( string $slug, string $filename ): string {
		return self::anime_prefix( $slug ) . '/fonts/' . self::safe_filename( $filename );
	}

	/**
	 * Key for a plugin bookkeeping object.
	 *
	 * @param string $name Path under the system root.
	 */
	public static function system_file( string $name ): string {
		return self::SYSTEM_ROOT . '/' . ltrim( $name, '/' );
	}

	/**
	 * Reduce a file name to something safe to place in a key.
	 *
	 * Path separators and traversal are removed rather than escaped: a key is
	 * built from operator input, and a name that walks out of its folder is
	 * never what was meant.
	 *
	 * @param string $filename Proposed file name.
	 */
	public static function safe_filename( string $filename ): string {
		$name = basename( str_replace( '\\', '/', $filename ) );
		$name = strtr( $name, self::TRANSLITERATIONS );
		$name = preg_replace( '/[^A-Za-z0-9._-]+/u', '-', $name ) ?? '';
		$name = trim( $name, '-.' );
		// Leading dots would make the object invisible in some tooling, and an
		// empty name would silently write to the folder itself.
		return '' === $name ? 'file' : $name;
	}

	/**
	 * Split an episode key back into its parts.
	 *
	 * Used when reconciling what the bucket holds against what the database
	 * says it holds.
	 *
	 * @param string $key Object key.
	 * @return array{slug: string, season: int, episode: int, file: string}|null
	 */
	public static function parse_episode_key( string $key ): ?array {
		$pattern = '#^' . preg_quote( self::ROOT, '#' ) . '/([^/]+)/season-(\d+)/episode-(\d+)/(.+)$#';
		if ( 1 !== preg_match( $pattern, $key, $matches ) ) {
			return null;
		}
		return array(
			'slug'    => $matches[1],
			'season'  => (int) $matches[2],
			'episode' => (int) $matches[3],
			'file'    => $matches[4],
		);
	}
}
