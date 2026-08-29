<?php
/**
 * SFNT (TrueType/OpenType) reading and validation.
 *
 * The PHP counterpart of the player's `src/fonts/sfnt.ts`. An ASS script asks
 * for a font by family name, but an upload is just a filename — and
 * `DejaVuSans.ttf` is the family "DejaVu Sans". Matching one to the other means
 * reading the family out of the font itself.
 *
 * Deliberately free of any WordPress dependency so it can be unit tested
 * directly.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Reads and validates font files.
 */
final class FontFile {

	/**
	 * Name IDs from the OpenType `name` table.
	 */
	private const NAME_ID_FAMILY               = 1;
	private const NAME_ID_FULL_NAME            = 4;
	private const NAME_ID_POSTSCRIPT           = 6;
	private const NAME_ID_TYPOGRAPHIC_FAMILY   = 16;

	/**
	 * Largest font we will accept, in bytes.
	 *
	 * CJK families legitimately reach the high single-digit megabytes; beyond
	 * that we are almost certainly not looking at a font.
	 */
	public const MAX_BYTES = 32 * 1024 * 1024;

	/**
	 * Family names this file answers to, most specific first.
	 *
	 * @var string[]
	 */
	public readonly array $families;

	/**
	 * Full name, when the file carries one.
	 *
	 * @var string|null
	 */
	public readonly ?string $full_name;

	/**
	 * PostScript name, when the file carries one.
	 *
	 * @var string|null
	 */
	public readonly ?string $postscript_name;

	/**
	 * Detected container format: ttf, otf, ttc or woff/woff2.
	 *
	 * @var string
	 */
	public readonly string $format;

	/**
	 * @param string[]    $families        Family names, most specific first.
	 * @param string|null $full_name       Full name.
	 * @param string|null $postscript_name PostScript name.
	 * @param string      $format          Container format.
	 */
	private function __construct( array $families, ?string $full_name, ?string $postscript_name, string $format ) {
		$this->families        = $families;
		$this->full_name       = $full_name;
		$this->postscript_name = $postscript_name;
		$this->format          = $format;
	}

	/**
	 * Primary family name — what an ASS script will ask for.
	 */
	public function family(): string {
		return $this->families[0] ?? '';
	}

	/**
	 * Parse a font from raw bytes.
	 *
	 * Everything is decided from the content: extensions are attacker-supplied
	 * and say nothing about what a file actually is.
	 *
	 * @param string $bytes Raw file contents.
	 * @return self|null Null when the bytes are not a font we can read.
	 */
	public static function from_string( string $bytes ): ?self {
		$length = strlen( $bytes );
		if ( $length < 12 || $length > self::MAX_BYTES ) {
			return null;
		}

		$tag = substr( $bytes, 0, 4 );

		// WOFF and WOFF2 wrap an sfnt in compression we are not going to undo
		// here. They are accepted for serving, but the family name has to come
		// from elsewhere, so they are reported without one.
		if ( 'wOFF' === $tag ) {
			return new self( array(), null, null, 'woff' );
		}
		if ( 'wOF2' === $tag ) {
			return new self( array(), null, null, 'woff2' );
		}

		// A TrueType Collection holds several fonts; the first one names the file.
		if ( 'ttcf' === $tag ) {
			if ( $length < 16 ) {
				return null;
			}
			$offset = self::uint32( $bytes, 12 );
			$parsed = self::read_names_at( $bytes, $offset );
			return null === $parsed ? null : new self( $parsed[0], $parsed[1], $parsed[2], 'ttc' );
		}

		$parsed = self::read_names_at( $bytes, 0 );
		if ( null === $parsed ) {
			return null;
		}

		$format = ( "\x4f\x54\x54\x4f" === $tag ) ? 'otf' : 'ttf';
		return new self( $parsed[0], $parsed[1], $parsed[2], $format );
	}

	/**
	 * Parse a font from a path on disk.
	 *
	 * @param string $path Absolute path.
	 * @return self|null Null when unreadable or not a font.
	 */
	public static function from_path( string $path ): ?self {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return null;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $bytes ? null : self::from_string( $bytes );
	}

	/**
	 * Whether these bytes look like a font at all.
	 *
	 * @param string $bytes Raw file contents.
	 */
	public static function is_font( string $bytes ): bool {
		return null !== self::from_string( $bytes );
	}

	/**
	 * Key used to compare family names, matching how libass compares them.
	 *
	 * @param string $family Family name.
	 */
	public static function key( string $family ): string {
		$normalised = preg_replace( '/\s+/u', ' ', $family );
		return trim( mb_strtolower( null === $normalised ? $family : $normalised, 'UTF-8' ) );
	}

	/**
	 * Read the name table of the sfnt starting at `$base`.
	 *
	 * @param string $bytes Raw file contents.
	 * @param int    $base  Offset of the sfnt header.
	 * @return array{0: string[], 1: ?string, 2: ?string}|null
	 */
	private static function read_names_at( string $bytes, int $base ): ?array {
		$length = strlen( $bytes );
		if ( $base + 12 > $length ) {
			return null;
		}

		$version = self::uint32( $bytes, $base );
		// 0x00010000 TrueType outlines, 'OTTO' CFF outlines, 'true'/'typ1' legacy.
		$known = array( 0x00010000, 0x4f54544f, 0x74727565, 0x74797031 );
		if ( ! in_array( $version, $known, true ) ) {
			return null;
		}

		$num_tables  = self::uint16( $bytes, $base + 4 );
		$name_offset = -1;
		$name_length = 0;

		for ( $i = 0; $i < $num_tables; $i++ ) {
			$record = $base + 12 + ( $i * 16 );
			if ( $record + 16 > $length ) {
				return null;
			}
			// Every table must sit inside the file: a directory pointing past
			// the end is the signature of a truncated or crafted upload.
			$table_offset = self::uint32( $bytes, $record + 8 );
			$table_length = self::uint32( $bytes, $record + 12 );
			if ( $table_offset + $table_length > $length ) {
				return null;
			}
			if ( "\x6e\x61\x6d\x65" === substr( $bytes, $record, 4 ) ) {
				$name_offset = $table_offset;
				$name_length = $table_length;
			}
		}

		if ( $name_offset < 0 || $name_offset + 6 > $length ) {
			return null;
		}

		$count       = self::uint16( $bytes, $name_offset + 2 );
		$string_base = $name_offset + self::uint16( $bytes, $name_offset + 4 );

		$typographic     = array();
		$families        = array();
		$full_name       = null;
		$postscript_name = null;

		for ( $i = 0; $i < $count; $i++ ) {
			$record = $name_offset + 6 + ( $i * 12 );
			if ( $record + 12 > $length ) {
				break;
			}
			$platform_id = self::uint16( $bytes, $record );
			$encoding_id = self::uint16( $bytes, $record + 2 );
			$name_id     = self::uint16( $bytes, $record + 6 );
			$str_length  = self::uint16( $bytes, $record + 8 );
			$str_offset  = self::uint16( $bytes, $record + 10 );

			$wanted = array(
				self::NAME_ID_FAMILY,
				self::NAME_ID_FULL_NAME,
				self::NAME_ID_POSTSCRIPT,
				self::NAME_ID_TYPOGRAPHIC_FAMILY,
			);
			if ( ! in_array( $name_id, $wanted, true ) ) {
				continue;
			}

			$start = $string_base + $str_offset;
			if ( $start + $str_length > $length || $start + $str_length > $name_offset + $name_length ) {
				continue;
			}

			$value = self::decode_name( substr( $bytes, $start, $str_length ), $platform_id, $encoding_id );
			if ( '' === $value ) {
				continue;
			}

			switch ( $name_id ) {
				case self::NAME_ID_TYPOGRAPHIC_FAMILY:
					self::push_unique( $typographic, $value );
					break;
				case self::NAME_ID_FAMILY:
					self::push_unique( $families, $value );
					break;
				case self::NAME_ID_FULL_NAME:
					$full_name ??= $value;
					break;
				case self::NAME_ID_POSTSCRIPT:
					$postscript_name ??= $value;
					break;
			}
		}

		// Typographic family (16) is the modern, most specific answer; it wins
		// over the legacy family (1) when both are present.
		$all = $typographic;
		foreach ( $families as $family ) {
			self::push_unique( $all, $family );
		}
		if ( array() === $all && null !== $full_name ) {
			$all[] = $full_name;
		}
		if ( array() === $all ) {
			return null;
		}

		return array( $all, $full_name, $postscript_name );
	}

	/**
	 * Append a value if no case-insensitive match is already present.
	 *
	 * @param string[] $list  List to append to, by reference.
	 * @param string   $value Value to append.
	 */
	private static function push_unique( array &$list, string $value ): void {
		foreach ( $list as $existing ) {
			if ( 0 === strcasecmp( $existing, $value ) ) {
				return;
			}
		}
		$list[] = $value;
	}

	/**
	 * Decode a name record's string according to its platform.
	 *
	 * @param string $raw         Raw string bytes.
	 * @param int    $platform_id Platform identifier.
	 * @param int    $encoding_id Encoding identifier.
	 */
	private static function decode_name( string $raw, int $platform_id, int $encoding_id ): string {
		// Platform 3 (Windows) and 0 (Unicode) store UTF-16BE; platform 1
		// (Macintosh) with encoding 0 is MacRoman, which is ASCII for names.
		$is_utf16 = ( 3 === $platform_id || 0 === $platform_id );
		$encoding = $is_utf16 ? 'UTF-16BE' : ( 0 === $encoding_id ? 'Windows-1252' : 'UTF-8' );

		$decoded = @mb_convert_encoding( $raw, 'UTF-8', $encoding ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_string( $decoded ) ) {
			return '';
		}
		return trim( str_replace( "\0", '', $decoded ) );
	}

	/**
	 * Big-endian unsigned 16-bit read.
	 *
	 * @param string $bytes  Buffer.
	 * @param int    $offset Offset.
	 */
	private static function uint16( string $bytes, int $offset ): int {
		$unpacked = unpack( 'n', substr( $bytes, $offset, 2 ) );
		return false === $unpacked ? 0 : (int) $unpacked[1];
	}

	/**
	 * Big-endian unsigned 32-bit read.
	 *
	 * @param string $bytes  Buffer.
	 * @param int    $offset Offset.
	 */
	private static function uint32( string $bytes, int $offset ): int {
		$unpacked = unpack( 'N', substr( $bytes, $offset, 4 ) );
		return false === $unpacked ? 0 : (int) $unpacked[1];
	}
}
