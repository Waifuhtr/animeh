<?php
/**
 * Avatar-frame validation, by looking at the bytes.
 *
 * The same rule as fonts (§15) and for the same reason: an extension and a
 * browser-declared mime type are both supplied by whoever is uploading. What
 * a file is, is what its first bytes say it is.
 *
 * Three formats, because a glowing ring around a circular avatar is an
 * animation with soft transparent edges, and only two of the three do that
 * well:
 *
 * - **animated WebP** — the right answer. Alpha is 8-bit, the files are small,
 *   and quality holds up at 288 pixels.
 * - **APNG** — same quality, several times the size.
 * - **GIF** — universal, but 256 colours and one-bit transparency, so a soft
 *   glow gets a hard sawtooth edge. Accepted because it always plays.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Reads a frame file's format, dimensions and whether it moves.
 */
final class FrameFile {

	/**
	 * Largest frame accepted.
	 *
	 * An animated 288×288 WebP is tens of kilobytes; three megabytes is room
	 * for a careless APNG export and still small enough that a phone on a bad
	 * connection is not punished for looking at the shop.
	 */
	public const MAX_BYTES = 3 * 1024 * 1024;

	/** Smallest square accepted. */
	public const MIN_SIDE = 64;

	/** Largest square accepted. */
	public const MAX_SIDE = 1024;

	/**
	 * Inspect a frame file.
	 *
	 * @param string $bytes Whole file.
	 * @return array{format: string, mime: string, width: int, height: int, animated: bool}|null
	 *   Null when the bytes are not one of the three formats, or are truncated.
	 */
	public static function inspect( string $bytes ): ?array {
		if ( strlen( $bytes ) < 16 ) {
			return null;
		}

		$png = self::png( $bytes );
		if ( null !== $png ) {
			return $png;
		}

		$gif = self::gif( $bytes );
		if ( null !== $gif ) {
			return $gif;
		}

		return self::webp( $bytes );
	}

	/** The file is not square. */
	public const REJECT_NOT_SQUARE = 'not_square';

	/** The file is square but the wrong size. */
	public const REJECT_OUT_OF_RANGE = 'out_of_range';

	/**
	 * Whether an inspected file is usable as a frame.
	 *
	 * Square is required, not preferred. A frame is drawn as a ring around a
	 * circular avatar; a rectangular one has to be stretched to fit, and a
	 * stretched ring is visibly an oval on every profile that wears it.
	 *
	 * Returns a code rather than a sentence, because this class is one of the
	 * ones that must not touch WordPress — `__()` here would make the whole
	 * `Support/` layer untestable outside a WordPress install, which is the
	 * one thing this environment cannot provide. The caller writes the
	 * message; see [FrameRepository::store].
	 *
	 * @param array{format: string, mime: string, width: int, height: int, animated: bool} $info Inspection.
	 * @return string Empty when acceptable, otherwise one of the REJECT_ codes.
	 */
	public static function rejection( array $info ): string {
		$width  = (int) $info['width'];
		$height = (int) $info['height'];

		if ( $width !== $height ) {
			return self::REJECT_NOT_SQUARE;
		}

		if ( $width < self::MIN_SIDE || $width > self::MAX_SIDE ) {
			return self::REJECT_OUT_OF_RANGE;
		}

		return '';
	}

	/**
	 * PNG, and whether it is an APNG.
	 *
	 * @param string $bytes File.
	 * @return array{format: string, mime: string, width: int, height: int, animated: bool}|null
	 */
	private static function png( string $bytes ): ?array {
		if ( "\x89PNG\r\n\x1a\n" !== substr( $bytes, 0, 8 ) ) {
			return null;
		}

		// IHDR is required to be the first chunk, so its two dimensions sit at
		// a fixed offset.
		if ( 'IHDR' !== substr( $bytes, 12, 4 ) || strlen( $bytes ) < 24 ) {
			return null;
		}

		$dimensions = unpack( 'Nwidth/Nheight', substr( $bytes, 16, 8 ) );
		if ( false === $dimensions ) {
			return null;
		}

		// An animation control chunk is only an animation when it comes before
		// the first frame of image data; after it, decoders ignore it and so
		// does every viewer.
		$actl     = strpos( $bytes, 'acTL' );
		$idat     = strpos( $bytes, 'IDAT' );
		$animated = false !== $actl && ( false === $idat || $actl < $idat );

		return array(
			'format'   => $animated ? 'apng' : 'png',
			'mime'     => 'image/png',
			'width'    => (int) $dimensions['width'],
			'height'   => (int) $dimensions['height'],
			'animated' => $animated,
		);
	}

	/**
	 * GIF, and whether it has more than one frame.
	 *
	 * @param string $bytes File.
	 * @return array{format: string, mime: string, width: int, height: int, animated: bool}|null
	 */
	private static function gif( string $bytes ): ?array {
		$magic = substr( $bytes, 0, 6 );
		if ( 'GIF87a' !== $magic && 'GIF89a' !== $magic ) {
			return null;
		}

		$dimensions = unpack( 'vwidth/vheight', substr( $bytes, 6, 4 ) );
		if ( false === $dimensions ) {
			return null;
		}

		// Image descriptors, not graphic control extensions: a single-frame
		// GIF may still carry one control block for its transparency index.
		// Counted rather than walked, which would mean decoding every LZW
		// block to find where the next descriptor begins.
		$frames = 0;
		$offset = 13 + self::gif_colour_table_size( $bytes );
		$length = strlen( $bytes );

		while ( $offset < $length && $frames < 2 ) {
			$marker = $bytes[ $offset ];

			if ( "\x2C" === $marker ) {
				++$frames;
				$offset += 10;
				// Local colour table, if the descriptor declares one.
				if ( $offset <= $length && ( ord( $bytes[ $offset - 1 ] ) & 0x80 ) !== 0 ) {
					$offset += 3 * ( 1 << ( ( ord( $bytes[ $offset - 1 ] ) & 0x07 ) + 1 ) );
				}
				++$offset; // LZW minimum code size.
				$offset = self::gif_skip_blocks( $bytes, $offset );
				continue;
			}

			if ( "\x21" === $marker ) {
				$offset += 2; // Introducer and label.
				$offset  = self::gif_skip_blocks( $bytes, $offset );
				continue;
			}

			// Trailer, or something this parser does not understand. Either
			// way there is nothing further worth counting.
			break;
		}

		return array(
			'format'   => 'gif',
			'mime'     => 'image/gif',
			'width'    => (int) $dimensions['width'],
			'height'   => (int) $dimensions['height'],
			'animated' => $frames > 1,
		);
	}

	/**
	 * Size in bytes of a GIF's global colour table, zero when absent.
	 *
	 * @param string $bytes File.
	 * @return int
	 */
	private static function gif_colour_table_size( string $bytes ): int {
		$packed = ord( $bytes[10] );
		if ( 0 === ( $packed & 0x80 ) ) {
			return 0;
		}

		return 3 * ( 1 << ( ( $packed & 0x07 ) + 1 ) );
	}

	/**
	 * Walk a GIF sub-block chain to the byte after its terminator.
	 *
	 * @param string $bytes  File.
	 * @param int    $offset Start of the first sub-block's length byte.
	 * @return int
	 */
	private static function gif_skip_blocks( string $bytes, int $offset ): int {
		$length = strlen( $bytes );

		while ( $offset < $length ) {
			$size = ord( $bytes[ $offset ] );
			++$offset;
			if ( 0 === $size ) {
				return $offset;
			}
			$offset += $size;
		}

		return $length;
	}

	/**
	 * WebP, and whether it is animated.
	 *
	 * @param string $bytes File.
	 * @return array{format: string, mime: string, width: int, height: int, animated: bool}|null
	 */
	private static function webp( string $bytes ): ?array {
		if ( 'RIFF' !== substr( $bytes, 0, 4 ) || 'WEBP' !== substr( $bytes, 8, 4 ) ) {
			return null;
		}

		$chunk = substr( $bytes, 12, 4 );

		// Extended file format: canvas size lives in VP8X, and animation is a
		// flag there plus at least one frame chunk.
		if ( 'VP8X' === $chunk ) {
			if ( strlen( $bytes ) < 30 ) {
				return null;
			}

			$flags  = ord( $bytes[20] );
			$width  = self::uint24_le( substr( $bytes, 24, 3 ) ) + 1;
			$height = self::uint24_le( substr( $bytes, 27, 3 ) ) + 1;
			// The flag says the file may animate; an ANMF chunk says it does.
			$animated = 0 !== ( $flags & 0x02 ) && false !== strpos( $bytes, 'ANMF' );

			return array(
				'format'   => $animated ? 'webp-animated' : 'webp',
				'mime'     => 'image/webp',
				'width'    => $width,
				'height'   => $height,
				'animated' => $animated,
			);
		}

		// Simple lossy: a VP8 keyframe header, dimensions fourteen bytes in.
		if ( 'VP8 ' === $chunk && strlen( $bytes ) >= 30 ) {
			$dimensions = unpack( 'vwidth/vheight', substr( $bytes, 26, 4 ) );
			if ( false === $dimensions ) {
				return null;
			}

			return array(
				'format'   => 'webp',
				'mime'     => 'image/webp',
				// The top two bits of each are the scale, not the size.
				'width'    => (int) $dimensions['width'] & 0x3FFF,
				'height'   => (int) $dimensions['height'] & 0x3FFF,
				'animated' => false,
			);
		}

		// Simple lossless: fourteen bits of width then fourteen of height,
		// packed across four bytes after the signature, each stored one less
		// than the real value.
		if ( 'VP8L' === $chunk && strlen( $bytes ) >= 25 ) {
			$packed = unpack( 'Vbits', substr( $bytes, 21, 4 ) );
			if ( false === $packed ) {
				return null;
			}
			$bits = (int) $packed['bits'];

			return array(
				'format'   => 'webp',
				'mime'     => 'image/webp',
				'width'    => ( $bits & 0x3FFF ) + 1,
				'height'   => ( ( $bits >> 14 ) & 0x3FFF ) + 1,
				'animated' => false,
			);
		}

		return null;
	}

	/**
	 * Three little-endian bytes as an integer.
	 *
	 * @param string $bytes Exactly three bytes.
	 * @return int
	 */
	private static function uint24_le( string $bytes ): int {
		if ( 3 !== strlen( $bytes ) ) {
			return 0;
		}

		return ord( $bytes[0] ) | ( ord( $bytes[1] ) << 8 ) | ( ord( $bytes[2] ) << 16 );
	}
}
