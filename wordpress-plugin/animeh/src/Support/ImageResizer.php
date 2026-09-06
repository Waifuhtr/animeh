<?php
/**
 * Bringing hand-entered artwork down to the size it is actually drawn at.
 *
 * Poster, banner and cover addresses are typed into a text field, so nothing
 * upstream decides how large the file behind one is. A phone asked to draw a
 * 120dp cover still has to fetch and decode whatever was pasted, and a screen
 * of them at once is what a stutter is made of. TMDB solved this years ago by
 * serving `w500` and `w1280`; this is the same discipline applied to the images
 * that did not come from TMDB.
 *
 * Free of any WordPress dependency so the arithmetic and the format sniffing
 * are verified directly. GD does the pixels — Imagick is not assumed, because
 * shared hosting frequently does not have it — and when neither is present
 * every entry point says so rather than mangling anything.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Shrinks an image to the ceiling for the place it is shown.
 */
final class ImageResizer {

	/**
	 * What each kind of artwork is allowed to be, at most.
	 *
	 * Taken from the sizes TMDB serves, because those already look right
	 * everywhere in this app: a poster is never drawn wider than about half a
	 * phone screen, and the hero banner is the only full-width image there is.
	 *
	 * `bytes` is the second half of the rule. An image can be the right shape
	 * and still be a four-megabyte PNG, and re-encoding that as a JPEG is the
	 * whole win — so a file over its budget is processed even when its
	 * dimensions are already fine.
	 *
	 * @var array<string, array{width: int, height: int, bytes: int}>
	 */
	public const ROLES = array(
		'poster' => array(
			'width'  => 500,
			'height' => 750,
			'bytes'  => 180 * 1024,
		),
		'banner' => array(
			'width'  => 1280,
			'height' => 720,
			'bytes'  => 400 * 1024,
		),
		'still'  => array(
			'width'  => 480,
			'height' => 270,
			'bytes'  => 120 * 1024,
		),
	);

	/**
	 * JPEG quality for what this writes.
	 *
	 * High enough that flat colour and fine linework — which is most of what
	 * anime artwork is — do not visibly band or ring.
	 */
	public const QUALITY = 82;

	/**
	 * Largest source this will even open.
	 *
	 * GD decodes to raw pixels before it can do anything, at four bytes each,
	 * and running out of memory in PHP is a fatal error rather than something
	 * that can be caught and reported. Refusing up front is the only way to
	 * fail politely.
	 */
	private const MAX_PIXELS = 40_000_000;

	/**
	 * Whether this PHP can resize at all.
	 */
	public static function available(): bool {
		return function_exists( 'imagecreatefromstring' )
			&& function_exists( 'imagecopyresampled' )
			&& function_exists( 'imagejpeg' );
	}

	/**
	 * The ceiling for a role, falling back to the poster's.
	 *
	 * @param string $role One of the keys of {@see self::ROLES}.
	 * @return array{width: int, height: int, bytes: int}
	 */
	public static function role( string $role ): array {
		return self::ROLES[ $role ] ?? self::ROLES['poster'];
	}

	/**
	 * What an image should be scaled to, keeping its shape.
	 *
	 * Never enlarges: an image already smaller than the box is left where it
	 * is, because scaling a 200px poster up to 500px produces a larger file
	 * that looks worse.
	 *
	 * @param int $width  Source width.
	 * @param int $height Source height.
	 * @param int $max_w  Widest allowed.
	 * @param int $max_h  Tallest allowed.
	 * @return array{0: int, 1: int} Target width and height.
	 */
	public static function fit( int $width, int $height, int $max_w, int $max_h ): array {
		if ( $width < 1 || $height < 1 ) {
			return array( 0, 0 );
		}

		$scale = min( $max_w / $width, $max_h / $height, 1.0 );

		return array(
			max( 1, (int) round( $width * $scale ) ),
			max( 1, (int) round( $height * $scale ) ),
		);
	}

	/**
	 * What an image is, read from its first bytes rather than its name.
	 *
	 * A URL ending in `.jpg` says nothing about what is behind it, and the
	 * decoder has to be told the truth.
	 *
	 * @param string $bytes File contents.
	 * @return string One of jpeg, png, gif, webp, or an empty string.
	 */
	public static function format( string $bytes ): string {
		if ( strlen( $bytes ) < 12 ) {
			return '';
		}

		if ( "\xFF\xD8\xFF" === substr( $bytes, 0, 3 ) ) {
			return 'jpeg';
		}
		if ( "\x89PNG\r\n\x1A\n" === substr( $bytes, 0, 8 ) ) {
			return 'png';
		}
		if ( 'GIF87a' === substr( $bytes, 0, 6 ) || 'GIF89a' === substr( $bytes, 0, 6 ) ) {
			return 'gif';
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'webp';
		}

		return '';
	}

	/**
	 * Whether this image is worth rewriting for this role.
	 *
	 * Either of the two rules is enough: too big on screen, or too big on the
	 * wire. Both being satisfied is what "already fine" means, and an image
	 * that is already fine is left completely alone — re-encoding a TMDB
	 * `w500` poster would only lose a little of it.
	 *
	 * @param int $width  Source width.
	 * @param int $height Source height.
	 * @param int $length Source size in bytes.
	 * @param string $role Where it is shown.
	 */
	public static function should_shrink( int $width, int $height, int $length, string $role ): bool {
		$box = self::role( $role );

		return $width > $box['width'] || $height > $box['height'] || $length > $box['bytes'];
	}

	/**
	 * Whether GD could hold this image without killing the process.
	 *
	 * Two full canvases — the source and the target — plus room for PHP's own
	 * work. A limit of -1 means no limit, which is the only case where the
	 * answer is unconditionally yes.
	 *
	 * @param int $width  Source width.
	 * @param int $height Source height.
	 * @param int $limit  `memory_limit` in bytes, or -1.
	 * @param int $used   Bytes already allocated.
	 */
	public static function fits_in_memory( int $width, int $height, int $limit, int $used ): bool {
		if ( $width * $height > self::MAX_PIXELS ) {
			return false;
		}
		if ( $limit < 0 ) {
			return true;
		}

		// Four bytes a pixel for the truecolor canvas, doubled for the copy
		// being resampled into, and a fifth of the limit left for everything
		// that is not an image.
		$needed = $width * $height * 4 * 2;

		return $needed < ( $limit - $used ) * 0.8;
	}

	/**
	 * Resize and re-encode, or return null when there is nothing to do.
	 *
	 * Null is not a failure the caller has to recover from — it means "keep
	 * what you had", which is always a working answer.
	 *
	 * @param string $bytes Source file contents.
	 * @param string $role  Where the image is shown.
	 * @return string|null JPEG bytes, or null.
	 */
	public static function shrink( string $bytes, string $role ): ?string {
		if ( ! self::available() ) {
			return null;
		}

		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || (int) $size[0] < 1 || (int) $size[1] < 1 ) {
			return null;
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];

		if ( '' === self::format( $bytes ) ) {
			return null;
		}
		if ( ! self::should_shrink( $width, $height, strlen( $bytes ), $role ) ) {
			return null;
		}
		if ( ! self::fits_in_memory( $width, $height, self::memory_limit(), memory_get_usage( true ) ) ) {
			return null;
		}

		$source = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $source ) {
			return null;
		}

		$box = self::role( $role );
		list( $target_w, $target_h ) = self::fit( $width, $height, $box['width'], $box['height'] );

		$canvas = imagecreatetruecolor( $target_w, $target_h );
		if ( false === $canvas ) {
			imagedestroy( $source );
			return null;
		}

		// JPEG has no transparency, so whatever was see-through has to become
		// a colour. The app's own background rather than white: these images
		// are drawn on a near-black screen, and a cut-out character on a white
		// card is the one result that would look broken.
		$ground = imagecolorallocate( $canvas, 0x0B, 0x0A, 0x10 );
		if ( false !== $ground ) {
			imagefilledrectangle( $canvas, 0, 0, $target_w, $target_h, $ground );
		}

		imagecopyresampled( $canvas, $source, 0, 0, 0, 0, $target_w, $target_h, $width, $height );
		imagedestroy( $source );

		ob_start();
		$ok = imagejpeg( $canvas, null, self::QUALITY );
		$out = (string) ob_get_clean();
		imagedestroy( $canvas );

		if ( ! $ok || '' === $out ) {
			return null;
		}

		// A rewrite that came out larger is not an optimisation. Small source
		// images that are already well compressed land here.
		return strlen( $out ) < strlen( $bytes ) ? $out : null;
	}

	/**
	 * `memory_limit` in bytes, or -1 when there is none.
	 */
	private static function memory_limit(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return -1;
		}

		return self::bytes( $raw );
	}

	/**
	 * Parse a PHP shorthand size such as `256M`.
	 *
	 * @param string $value Shorthand byte value.
	 */
	public static function bytes( string $value ): int {
		$value = trim( $value );
		$unit  = strtolower( substr( $value, -1 ) );
		$plain = (int) $value;

		return match ( $unit ) {
			'g'     => $plain * 1024 * 1024 * 1024,
			'm'     => $plain * 1024 * 1024,
			'k'     => $plain * 1024,
			default => $plain,
		};
	}
}
