<?php
/**
 * Standalone test runner for the plugin's WordPress-free logic.
 *
 * Composer cannot reach its package host in this environment, so PHPUnit is not
 * available. That is survivable precisely because `src/Support/` has no
 * WordPress dependency: the classes can be required and exercised directly.
 *
 * Usage: php wordpress-plugin/animeh/tests/run.php
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Tests;

use Throwable;

require_once __DIR__ . '/../src/Support/FontFile.php';
require_once __DIR__ . '/../src/Support/AssScript.php';
require_once __DIR__ . '/../src/Support/UrlGuard.php';
require_once __DIR__ . '/../src/Support/Throttle.php';
require_once __DIR__ . '/../src/Support/TestVerdict.php';
require_once __DIR__ . '/../src/Support/PlaylistRewriter.php';
require_once __DIR__ . '/../src/Support/S3Signer.php';
require_once __DIR__ . '/../src/Support/StorageKey.php';
require_once __DIR__ . '/../src/Support/SecretBox.php';
require_once __DIR__ . '/../src/Support/MigrationCode.php';
require_once __DIR__ . '/../src/Support/Snapshot.php';
require_once __DIR__ . '/../src/Support/ApiToken.php';
require_once __DIR__ . '/../src/Support/RateLimit.php';
require_once __DIR__ . '/../src/Support/TenraiMapper.php';
require_once __DIR__ . '/../src/Support/WatchProgress.php';
require_once __DIR__ . '/../src/Support/TmdbMapper.php';
require_once __DIR__ . '/../src/Support/GenreTally.php';
require_once __DIR__ . '/../src/Support/ServiceAccountJwt.php';
require_once __DIR__ . '/../src/Support/FontMatch.php';
require_once __DIR__ . '/../src/Support/ImageResizer.php';
require_once __DIR__ . '/../src/Support/Points.php';
require_once __DIR__ . '/../src/Support/ProfileTheme.php';
require_once __DIR__ . '/../src/Support/FrameFile.php';

// Not Support/, but the two methods exercised below are pure: normalising a
// fingerprint and shaping a statement list touch nothing WordPress owns.
require_once __DIR__ . '/../src/Rest/AppLinks.php';

// Same reasoning: `LeaderboardRepository::rank` turns ordered rows into ranked
// ones and never touches the database. Loading the class is safe — everything
// WordPress-shaped in it is inside a method body.
require_once __DIR__ . '/../src/Storage/CatalogSchema.php';
require_once __DIR__ . '/../src/Storage/LeaderboardRepository.php';

/**
 * Collected results.
 */
final class Runner {

	/** @var array<int, array{group: string, name: string, error: ?string}> */
	public static array $results = array();

	/** @var string */
	public static string $group = '';

	/** @var int */
	public static int $skipped = 0;
}

/**
 * Declare a group of tests.
 *
 * @param string   $name Group name.
 * @param callable $body Test body.
 */
function describe( string $name, callable $body ): void {
	Runner::$group = $name;
	$body();
}

/**
 * Run one test.
 *
 * @param string   $name Test name.
 * @param callable $body Test body.
 */
function it( string $name, callable $body ): void {
	try {
		$body();
		Runner::$results[] = array(
			'group' => Runner::$group,
			'name'  => $name,
			'error' => null,
		);
	} catch ( SkipTest $skip ) {
		++Runner::$skipped;
		Runner::$results[] = array(
			'group' => Runner::$group,
			'name'  => $name . ' (atlandı: ' . $skip->getMessage() . ')',
			'error' => null,
		);
	} catch ( Throwable $error ) {
		Runner::$results[] = array(
			'group' => Runner::$group,
			'name'  => $name,
			'error' => $error->getMessage(),
		);
	}
}

/**
 * Thrown to skip a test whose fixtures are absent.
 */
final class SkipTest extends \Exception {}

/**
 * Skip the current test.
 *
 * @param string $reason Why.
 * @throws SkipTest Always.
 */
function skip( string $reason ): void {
	throw new SkipTest( $reason );
}

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Context.
 * @throws \RuntimeException When they differ.
 */
function same( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException(
			trim( $message . ' — beklenen ' . render( $expected ) . ', gelen ' . render( $actual ) )
		);
	}
}

/**
 * Assert truthiness.
 *
 * @param mixed  $value   Value.
 * @param string $message Context.
 * @throws \RuntimeException When falsy.
 */
function ok( $value, string $message = '' ): void {
	if ( ! $value ) {
		throw new \RuntimeException( '' === $message ? 'doğru bekleniyordu' : $message );
	}
}

/**
 * Render a value for a failure message.
 *
 * @param mixed $value Value.
 */
function render( $value ): string {
	if ( is_array( $value ) ) {
		return wp_json_encode_compat( $value );
	}
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( null === $value ) {
		return 'null';
	}
	return is_scalar( $value ) ? (string) $value : gettype( $value );
}

/**
 * JSON encoding without WordPress.
 *
 * @param mixed $value Value.
 */
function wp_json_encode_compat( $value ): string {
	$encoded = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return false === $encoded ? '?' : $encoded;
}

/**
 * A structurally valid PNG of the given size.
 *
 * Built by hand rather than by GD: GD is not always compiled in here, and the
 * parser under test only reads the signature, the IHDR dimensions and where
 * `acTL` sits relative to `IDAT` — so a file that satisfies those is a fair
 * test of it and a fixture nobody has to commit.
 *
 * @param int $width  Pixels.
 * @param int $height Pixels.
 * @return string
 */
function png_bytes( int $width, int $height ): string {
	$ihdr = pack( 'NN', $width, $height ) . "\x08\x06\x00\x00\x00";

	return "\x89PNG\r\n\x1a\n"
		. png_chunk( 'IHDR', $ihdr )
		. png_chunk( 'IDAT', "\x78\x9c\x00\x00\x00\x00\x01" )
		. png_chunk( 'IEND', '' );
}

/**
 * One PNG chunk: length, type, payload, CRC.
 *
 * @param string $type    Four characters.
 * @param string $payload Bytes.
 * @return string
 */
function png_chunk( string $type, string $payload ): string {
	return pack( 'N', strlen( $payload ) ) . $type . $payload . pack( 'N', crc32( $type . $payload ) );
}

/**
 * A GIF with the given number of image descriptors.
 *
 * No global colour table, so the first descriptor starts at a fixed offset —
 * which is also the offset the parser computes, so the two agree by
 * construction rather than by luck.
 *
 * @param int $width  Pixels.
 * @param int $height Pixels.
 * @param int $frames How many images.
 * @return string
 */
function gif_bytes( int $width, int $height, int $frames ): string {
	$gif = 'GIF89a' . pack( 'vv', $width, $height ) . "\x00\x00\x00";

	for ( $i = 0; $i < $frames; $i++ ) {
		// Graphic control extension: introducer, label, then one sub-block.
		$gif .= "\x21\xf9" . "\x04" . "\x00\x00\x00\x00" . "\x00";
		// Image descriptor: left, top, width, height, packed — no local table.
		$gif .= "\x2c" . pack( 'vvvv', 0, 0, $width, $height ) . "\x00";
		// Minimum code size, one block of data, terminator.
		$gif .= "\x02" . "\x02\x4c\x01" . "\x00";
	}

	return $gif . "\x3b";
}

/**
 * An extended-format WebP, optionally flagged and shaped as an animation.
 *
 * @param int  $width    Pixels.
 * @param int  $height   Pixels.
 * @param bool $animated Whether to set the flag and add a frame chunk.
 * @return string
 */
function webp_vp8x_bytes( int $width, int $height, bool $animated ): string {
	$flags   = $animated ? "\x02" : "\x00";
	$payload = $flags . "\x00\x00\x00" . uint24_le( $width - 1 ) . uint24_le( $height - 1 );
	$body    = 'WEBP' . 'VP8X' . pack( 'V', strlen( $payload ) ) . $payload;

	if ( $animated ) {
		$body .= 'ANIM' . pack( 'V', 6 ) . "\x00\x00\x00\x00\x00\x00";
		$body .= 'ANMF' . pack( 'V', 16 ) . str_repeat( "\x00", 16 );
	}

	return 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
}

/**
 * Three little-endian bytes.
 *
 * @param int $value Number.
 * @return string
 */
function uint24_le( int $value ): string {
	return chr( $value & 0xFF ) . chr( ( $value >> 8 ) & 0xFF ) . chr( ( $value >> 16 ) & 0xFF );
}

/**
 * Print results and return the process exit code.
 */
function report(): int {
	$failures = 0;
	$group    = null;

	foreach ( Runner::$results as $result ) {
		if ( $result['group'] !== $group ) {
			$group = $result['group'];
			echo "\n" . $group . "\n";
		}
		if ( null === $result['error'] ) {
			echo '  ok   ' . $result['name'] . "\n";
		} else {
			++$failures;
			echo '  FAIL ' . $result['name'] . "\n";
			echo '       ' . $result['error'] . "\n";
		}
	}

	$total = count( Runner::$results );
	echo "\n" . ( $total - $failures ) . '/' . $total . " test geçti";
	if ( Runner::$skipped > 0 ) {
		echo ' (' . Runner::$skipped . ' atlandı)';
	}
	echo "\n";

	return $failures > 0 ? 1 : 0;
}

require_once __DIR__ . '/cases.php';

exit( report() );
