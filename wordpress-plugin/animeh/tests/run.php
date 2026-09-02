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
