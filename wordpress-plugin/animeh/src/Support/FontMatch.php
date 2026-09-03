<?php
/**
 * Deciding whether an uploaded font answers the family a subtitle asked for.
 *
 * An exact name match is the wrong bar, and insisting on it is why a font
 * library that looks complete still renders wrong. Three things go wrong in
 * practice, all of them normal:
 *
 * 1. **The file carries a longer name than the script uses.** A script asks
 *    for `Sans`; the file somebody had to hand is `sans-test.ttf`, whose name
 *    table says "Sans Test". Same typeface, different string.
 * 2. **The script asks for a weight.** `\fnArial Bold` names a face inside a
 *    family. Uploading the family is the right answer, and refusing it because
 *    the word "Bold" is missing helps nobody — the renderer can embolden.
 * 3. **Punctuation and case differ.** `DejaVuSans`, `DejaVu Sans`, `dejavu-sans`
 *    are one font written three ways.
 *
 * What this deliberately does *not* do is match on similarity. "Sans" must
 * never resolve to "Comic Sans": a substituted typeface renders at different
 * metrics and quietly breaks the typesetting the script was written against,
 * which is worse than a missing font somebody can see and upload. So every
 * match below the exact one requires the **first word to be the same**, and
 * everything else is a suffix the two names disagree about.
 *
 * Pure PHP with no WordPress in it, so the rules can be tested directly — and
 * so the Kotlin implementation in the app can be checked against the same
 * expectations.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Family-name matching.
 */
final class FontMatch {

	/**
	 * Words that name a face inside a family rather than the family itself.
	 *
	 * Stripped from both sides before comparing, so "Arial Bold" and "Arial"
	 * are the same family asked for two ways.
	 *
	 * @var string[]
	 */
	private const STYLE_WORDS = array(
		'regular',
		'normal',
		'book',
		'roman',
		'text',
		'thin',
		'hairline',
		'extralight',
		'ultralight',
		'light',
		'medium',
		'semibold',
		'demibold',
		'demi',
		'bold',
		'extrabold',
		'ultrabold',
		'heavy',
		'black',
		'italic',
		'oblique',
		'condensed',
		'cond',
		'narrow',
		'expanded',
		'extended',
		'wide',
	);

	/**
	 * A name reduced to comparable words.
	 *
	 * Case, punctuation and runs of whitespace all go; a camel-cased
	 * "DejaVuSans" is split at its capitals, because that is one name written
	 * without spaces rather than one word.
	 *
	 * @param string $family Family name as written.
	 * @return string[] Lower-case words, in order.
	 */
	public static function words( string $family ): array {
		$name = trim( $family );

		// `@Yu Gothic` is a vertical-writing variant of Yu Gothic, not a
		// family called "@Yu Gothic".
		$name = ltrim( $name, '@' );

		// Split camel case before folding case away, or the boundary is lost.
		$spaced = preg_replace( '/(\p{Ll})(\p{Lu})/u', '$1 $2', $name );
		$spaced = null === $spaced ? $name : $spaced;

		$lower = mb_strtolower( $spaced, 'UTF-8' );

		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * The family's words with the face-naming ones removed.
	 *
	 * Never empty when the name had any letters in it: a family genuinely
	 * called "Bold" would otherwise reduce to nothing and match everything.
	 *
	 * @param string $family Family name as written.
	 * @return string[]
	 */
	public static function base( string $family ): array {
		$words = self::words( $family );

		$kept = array_values(
			array_filter(
				$words,
				static fn( string $word ): bool =>
					! in_array( $word, self::STYLE_WORDS, true ) && ! preg_match( '/^\d+$/', $word )
			)
		);

		return array() === $kept ? $words : $kept;
	}

	/**
	 * A name with every separator taken out.
	 *
	 * The form that makes `DejaVuSans`, `DejaVu Sans` and `dejavu-sans` one
	 * string. Word splitting cannot do this on its own: the camel-cased spelling
	 * can be split at its capitals and the all-lower-case one cannot, so the
	 * two would disagree about where the words are.
	 *
	 * @param string $family Family name as written.
	 */
	public static function compact( string $family ): string {
		return implode( '', self::words( $family ) );
	}

	/**
	 * How well a stored family answers a requested one.
	 *
	 * @param string $wanted    What the subtitle asked for.
	 * @param string $candidate What is on the server.
	 * @return int 0 when it does not answer at all; higher is a better answer.
	 */
	public static function score( string $wanted, string $candidate ): int {
		$wanted_words    = self::words( $wanted );
		$candidate_words = self::words( $candidate );

		if ( array() === $wanted_words || array() === $candidate_words ) {
			return 0;
		}

		if ( self::compact( $wanted ) === self::compact( $candidate ) ) {
			return 100;
		}

		$wanted_base    = self::base( $wanted );
		$candidate_base = self::base( $candidate );

		if ( implode( '', $wanted_base ) === implode( '', $candidate_base ) ) {
			return 90;
		}

		// Everything below here is a guess about a name, so it is only allowed
		// when the families start with the same word. Without that rule "Sans"
		// resolves to "Comic Sans", and a wrong typeface is worse than none.
		if ( $wanted_base[0] !== $candidate_base[0] ) {
			return 0;
		}

		$shared = 0;
		$limit  = min( count( $wanted_base ), count( $candidate_base ) );

		while ( $shared < $limit && $wanted_base[ $shared ] === $candidate_base[ $shared ] ) {
			++$shared;
		}

		if ( $shared === count( $wanted_base ) || $shared === count( $candidate_base ) ) {
			// One name is the whole of the other plus something: "Sans" and
			// "Sans Test". The longer the agreement, the better the answer,
			// and the fewer extra words, the better still.
			$extra = abs( count( $wanted_base ) - count( $candidate_base ) );

			return max( 50, 80 + $shared - $extra );
		}

		return 0;
	}

	/**
	 * The best of several candidates, or null when none answers.
	 *
	 * Ties break on the shorter name, which is the more general family: asked
	 * for "Gothic", "Gothic" beats "Gothic Extra".
	 *
	 * @param string                          $wanted     Requested family.
	 * @param array<int, array<string, mixed>> $candidates Rows carrying a `family`.
	 * @return array<string, mixed>|null
	 */
	public static function best( string $wanted, array $candidates ) {
		$best       = null;
		$best_score = 0;
		$best_words = PHP_INT_MAX;

		foreach ( $candidates as $candidate ) {
			$family = (string) ( $candidate['family'] ?? '' );
			$score  = self::score( $wanted, $family );

			if ( 0 === $score ) {
				continue;
			}

			$words = count( self::words( $family ) );

			if ( $score > $best_score || ( $score === $best_score && $words < $best_words ) ) {
				$best       = $candidate;
				$best_score = $score;
				$best_words = $words;
			}
		}

		return $best;
	}
}
