<?php
/**
 * Hashtags and mentions inside a caption.
 *
 * Pure: no WordPress, no database. The app has to find the same tags in the
 * same string so it can underline them, and the server has to find them so it
 * can index them — two implementations of one rule, which is exactly the shape
 * that drifts. Keeping this side small and tested is what makes the drift
 * visible when it happens.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Reads `#tag` and `@name` out of a caption.
 */
final class Hashtag {

	/**
	 * Longest tag kept, in characters.
	 *
	 * Long enough for a real phrase, short enough that a caption cannot become
	 * one tag holding a paragraph.
	 */
	public const MAX_LENGTH = 60;

	/**
	 * Most tags taken from one caption.
	 *
	 * A caption of nothing but tags is the oldest trick on every platform this
	 * imitates; past this many the rest are simply text.
	 */
	public const MAX_TAGS = 20;

	/**
	 * Characters a tag may contain, after the `#`.
	 *
	 * Letters — Turkish ones included — digits and the underscore. A tag ends
	 * at a space, at punctuation, and at the next `#`, so "#anime#manga" is
	 * two tags rather than one.
	 */
	private const TAG = '/#([\p{L}\p{N}_]+)/u';

	/**
	 * The same, for `@name`.
	 */
	private const MENTION = '/@([\p{L}\p{N}_.]+)/u';

	/**
	 * Every tag in a caption, in the order written, without repeats.
	 *
	 * @param string $text Caption as typed.
	 * @return array<int, string>
	 */
	public static function tags( string $text ): array {
		return self::collect( $text, self::TAG );
	}

	/**
	 * Every mentioned name in a caption.
	 *
	 * @param string $text Caption as typed.
	 * @return array<int, string>
	 */
	public static function mentions( string $text ): array {
		return self::collect( $text, self::MENTION );
	}

	/**
	 * The form a tag is looked up by.
	 *
	 * Turkish is the reason this exists rather than a call to `strtolower`.
	 * The capital `İ` lowercases to `i` followed by a combining dot under the
	 * default Unicode rules, so "#İZLE" and "#izle" fold to different strings
	 * and a tag page built on one finds nothing written with the other. The
	 * dotted pair is mapped explicitly, before folding, so both spellings meet
	 * at the same key.
	 *
	 * @param string $tag Tag as written, without the `#`.
	 */
	public static function key( string $tag ): string {
		$value = strtr(
			$tag,
			array(
				'İ' => 'i',
				'I' => 'i',
				'ı' => 'i',
				'Ş' => 's',
				'ş' => 's',
				'Ğ' => 'g',
				'ğ' => 'g',
				'Ü' => 'u',
				'ü' => 'u',
				'Ö' => 'o',
				'ö' => 'o',
				'Ç' => 'c',
				'ç' => 'c',
			)
		);

		$value = mb_strtolower( $value, 'UTF-8' );

		// Everything else that is not a letter or a digit goes, so "#Çok_İyi"
		// and "#cokiyi" are the same page.
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value ) ?? '';

		return mb_substr( $value, 0, self::MAX_LENGTH, 'UTF-8' );
	}

	/**
	 * Whether a string could be a tag at all.
	 *
	 * @param string $tag Tag as written, without the `#`.
	 */
	public static function valid( string $tag ): bool {
		return '' !== self::key( $tag );
	}

	/**
	 * Matches of one pattern, deduplicated by {@see self::key()}.
	 *
	 * @param string $text    Caption.
	 * @param string $pattern Regex with one capture group.
	 * @return array<int, string>
	 */
	private static function collect( string $text, string $pattern ): array {
		// Returns the match count, or false when the subject is not valid
		// UTF-8 — which a caption typed on a phone will not be, but a caption
		// posted by something else might.
		$found = preg_match_all( $pattern, $text, $matches );

		if ( ! $found ) {
			return array();
		}

		$seen = array();
		$out  = array();

		foreach ( (array) ( $matches[1] ?? array() ) as $raw ) {
			$value = mb_substr( (string) $raw, 0, self::MAX_LENGTH, 'UTF-8' );
			$key   = self::key( $value );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = $value;

			if ( count( $out ) >= self::MAX_TAGS ) {
				break;
			}
		}

		return $out;
	}
}
