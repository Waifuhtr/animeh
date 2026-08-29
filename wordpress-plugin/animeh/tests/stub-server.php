<?php
/**
 * A WordPress-shaped stand-in for driving the admin panel in a browser.
 *
 * WordPress itself cannot be installed in the development container, so the
 * plugin's PHP that touches WordPress APIs cannot be exercised here. What can
 * be exercised is everything else: the panel's real JavaScript, the REST
 * contract it depends on, the font upload flow, and the throttling proxy —
 * against a server that answers exactly as the plugin's controllers do.
 *
 * The plugin's own WordPress-free classes do the real work behind these
 * routes, so the validation and font parsing under test are the shipped code,
 * not a re-implementation.
 *
 * Usage: php -S 127.0.0.1:876 -t wordpress-plugin/animeh wordpress-plugin/animeh/tests/stub-server.php
 *
 * @package Animeh
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../src/Support/FontFile.php';
require_once __DIR__ . '/../src/Support/AssScript.php';
require_once __DIR__ . '/../src/Support/UrlGuard.php';
require_once __DIR__ . '/../src/Support/Throttle.php';
require_once __DIR__ . '/../src/Support/TestVerdict.php';
require_once __DIR__ . '/../src/Support/PlaylistRewriter.php';

use Animeh\Support\FontFile;
use Animeh\Support\PlaylistRewriter;
use Animeh\Support\Throttle;
use Animeh\Support\TestVerdict;
use Animeh\Support\UrlGuard;

const STUB_STATE_DIR = __DIR__ . '/.stub-state';
const STUB_NONCE     = 'stub-nonce';

/**
 * Read the stub's persisted state.
 *
 * @return array<string, mixed>
 */
function stub_state(): array {
	$path = STUB_STATE_DIR . '/state.json';
	if ( ! is_file( $path ) ) {
		return array(
			'fonts'    => array(),
			'sessions' => array(),
			'presets'  => array(),
			'next_id'  => 1,
		);
	}
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : array(
		'fonts'    => array(),
		'sessions' => array(),
		'presets'  => array(),
		'next_id'  => 1,
	);
}

/**
 * Persist the stub's state.
 *
 * @param array<string, mixed> $state State.
 */
function stub_save( array $state ): void {
	if ( ! is_dir( STUB_STATE_DIR ) ) {
		mkdir( STUB_STATE_DIR, 0755, true );
	}
	file_put_contents( STUB_STATE_DIR . '/state.json', json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
}

/**
 * Send a JSON response and stop.
 *
 * @param mixed $body   Response body.
 * @param int   $status HTTP status.
 */
function stub_json( $body, int $status = 200 ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * Send a WordPress-shaped REST error.
 *
 * @param string $code    Error code.
 * @param string $message Message.
 * @param int    $status  HTTP status.
 */
function stub_error( string $code, string $message, int $status ): void {
	stub_json(
		array(
			'code'    => $code,
			'message' => $message,
			'data'    => array( 'status' => $status ),
		),
		$status
	);
}

/**
 * Reject a request that did not carry the REST nonce.
 *
 * WordPress rejects a cookie-authenticated REST call without `X-WP-Nonce`;
 * mirroring that here keeps the panel honest about sending it.
 */
function stub_require_nonce(): void {
	$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
	if ( STUB_NONCE !== $nonce ) {
		stub_error( 'rest_cookie_invalid_nonce', 'Cookie nonce is invalid', 403 );
	}
}

$path   = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$query  = array();
parse_str( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY ), $query );

/* ── Test isolation ─────────────────────────────────────────────────────── */

// Wipes the stub's state so a test run starts from nothing. Tests must not
// depend on being the first to run against a fresh server.
if ( '/__reset' === $path ) {
	if ( is_dir( STUB_STATE_DIR ) ) {
		$entries = glob( STUB_STATE_DIR . '/{,.}[!.,!..]*', GLOB_BRACE ) ?: array();
		foreach ( $entries as $entry ) {
			if ( is_file( $entry ) ) {
				unlink( $entry );
			} elseif ( is_dir( $entry ) ) {
				foreach ( glob( $entry . '/*' ) ?: array() as $nested ) {
					unlink( $nested );
				}
				rmdir( $entry );
			}
		}
	}
	stub_json( array( 'reset' => true ) );
}

/* ── The admin page ─────────────────────────────────────────────────────── */

if ( '/' === $path || '/index.html' === $path ) {
	$state  = stub_state();
	$config = array(
		'version'   => '0.1.0-stub',
		'restUrl'   => '/wp-json/animeh/v1',
		'nonce'     => STUB_NONCE,
		'proxy'     => array(
			'url'   => '/proxy',
			'nonce' => STUB_NONCE,
		),
		'assets'    => array(
			'worker'     => '/assets/jassub/jassub-worker.js',
			'wasm'       => '/assets/jassub/jassub-worker.wasm',
			'modernWasm' => '/assets/jassub/jassub-worker-modern.wasm',
			'player'     => '/assets/player/animeh-player.js',
		),
		'settings'  => array(
			'host_allowlist' => array(),
			// The corpus is served from the loopback interface, which the URL
			// guard blocks by design. The stub opts out so the panel itself can
			// be exercised; `UrlGuard` keeps its own tests.
			'allow_any_host' => true,
		),
		'presets'   => $state['presets'],
		'screen'    => ( $query['page'] ?? '' ) === 'animeh-fonts' ? 'fonts' : 'test',
		'adminUrl'  => '/',
		'fontsPage' => 'animeh-fonts',
		'testPage'  => 'animeh-player-test',
	);

	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="tr"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<title>Animeh — Player Test (stub)</title>';
	echo '<link rel="stylesheet" href="/assets/player/animeh-player.css">';
	echo '<link rel="stylesheet" href="/assets/admin/admin.css">';
	// A few of WordPress's own classes, so the panel lays out as it will in situ.
	echo '<style>body{margin:0;background:#f0f0f1;font-family:system-ui,sans-serif;font-size:13px}';
	echo '.wrap{margin:10px 20px}.button{padding:4px 10px;border:1px solid #2271b1;background:#f6f7f7;';
	echo 'color:#2271b1;border-radius:3px;cursor:pointer;font:inherit;min-height:30px}';
	echo '.button-primary{background:#2271b1;color:#fff}.button-hero{padding:8px 14px;font-size:14px}';
	echo '.button-link-delete{border:0;background:none;color:#b32d2e}';
	echo 'input,select{padding:4px 8px;border:1px solid #8c8f94;border-radius:3px;font:inherit}';
	echo 'table.widefat{width:100%;border-collapse:collapse;background:#fff}';
	echo 'table.widefat td,table.widefat th{padding:8px;border-bottom:1px solid #f0f0f1;text-align:left}';
	echo '</style></head><body>';
	echo '<div class="wrap animeh-admin">';
	echo '<h1>' . ( 'fonts' === $config['screen'] ? 'Fontlar' : 'Player Test' ) . '</h1>';
	echo '<div id="animeh-test-root" class="animeh-admin__root"></div>';
	echo '<div id="animeh-fonts-root" class="animeh-admin__root"></div>';
	echo '</div>';
	echo '<script>window.ANIMEH_ADMIN = ' . json_encode( $config ) . ';</script>';
	echo '<script type="module" src="/assets/admin/admin.js"></script>';
	echo '</body></html>';
	exit;
}

/* ── Throttling media proxy ─────────────────────────────────────────────── */

if ( '/proxy' === $path ) {
	if ( ( $query['_wpnonce'] ?? '' ) !== STUB_NONCE ) {
		http_response_code( 403 );
		exit;
	}

	$src = (string) ( $query['src'] ?? '' );
	$kbps = (int) ( $query['kbps'] ?? 0 );
	$fail = (int) ( $query['fail'] ?? 0 );

	if ( $fail > 0 ) {
		$counter_path = STUB_STATE_DIR . '/fail-' . md5( $src ) . '.txt';
		$count        = is_file( $counter_path ) ? (int) file_get_contents( $counter_path ) : 0;
		if ( $count < $fail ) {
			if ( ! is_dir( STUB_STATE_DIR ) ) {
				mkdir( STUB_STATE_DIR, 0755, true );
			}
			file_put_contents( $counter_path, (string) ( $count + 1 ) );
			http_response_code( 503 );
			exit;
		}
	}

	// The corpus is on disk; resolve the URL back to a local file rather than
	// looping a request through the same dev server.
	$local = stub_local_path( $src );
	if ( null === $local ) {
		http_response_code( 502 );
		exit;
	}

	// Playlists are rewritten by the shipped class, exactly as the plugin does.
	if ( PlaylistRewriter::looks_like_playlist( $src, '' ) ) {
		$rewritten = PlaylistRewriter::rewrite(
			(string) file_get_contents( $local ),
			$src,
			static function ( string $url ) use ( $kbps, $fail ): string {
				$args = array(
					'src'      => $url,
					'_wpnonce' => STUB_NONCE,
				);
				if ( $kbps > 0 ) {
					$args['kbps'] = $kbps;
				}
				if ( $fail > 0 ) {
					$args['fail'] = $fail;
				}
				return '/proxy?' . http_build_query( $args );
			}
		);
		header( 'Content-Type: application/vnd.apple.mpegurl' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: no-store' );
		header( 'Content-Length: ' . strlen( $rewritten ) );
		echo $rewritten;
		exit;
	}

	stub_stream_file( $local, new Throttle( $kbps ) );
	exit;
}

/* ── REST API ───────────────────────────────────────────────────────────── */

if ( str_starts_with( $path, '/wp-json/animeh/v1' ) ) {
	stub_require_nonce();
	$route = substr( $path, strlen( '/wp-json/animeh/v1' ) );
	$state = stub_state();

	// Fonts.
	if ( '/fonts' === $route && 'GET' === $method ) {
		stub_json( array( 'fonts' => array_values( $state['fonts'] ) ) );
	}

	if ( '/fonts' === $route && 'POST' === $method ) {
		$file = $_FILES['font'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) {
			stub_error( 'animeh_font_missing', 'Yüklenecek dosya bulunamadı.', 400 );
		}

		$bytes = (string) file_get_contents( (string) $file['tmp_name'] );
		$font  = FontFile::from_string( $bytes );
		if ( null === $font ) {
			stub_error( 'animeh_font_invalid', 'Bu dosya geçerli bir font değil.', 415 );
		}

		$family = $font->family();
		if ( '' === $family ) {
			$family = pathinfo( (string) $file['name'], PATHINFO_FILENAME );
		}

		$sha = hash( 'sha256', $bytes );
		foreach ( $state['fonts'] as $existing ) {
			if ( $existing['sha256'] === $sha ) {
				stub_json( array( 'font' => $existing ), 201 );
			}
		}

		if ( ! is_dir( STUB_STATE_DIR . '/fonts' ) ) {
			mkdir( STUB_STATE_DIR . '/fonts', 0755, true );
		}
		$stored = $sha . '.' . ( 'otf' === $font->format ? 'otf' : 'ttf' );
		file_put_contents( STUB_STATE_DIR . '/fonts/' . $stored, $bytes );

		$row = array(
			'id'              => $state['next_id']++,
			'family'          => $family,
			'family_key'      => FontFile::key( $family ),
			'postscript_name' => (string) $font->postscript_name,
			'filename'        => (string) $file['name'],
			'format'          => $font->format,
			'size_bytes'      => strlen( $bytes ),
			'sha256'          => $sha,
			'uploaded_by'     => 1,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'url'             => '/stub-fonts/' . $stored,
		);
		$state['fonts'][] = $row;
		stub_save( $state );
		stub_json( array( 'font' => $row ), 201 );
	}

	if ( str_starts_with( $route, '/fonts/resolve' ) && 'GET' === $method ) {
		$family = (string) ( $query['family'] ?? '' );
		$key    = FontFile::key( $family );
		foreach ( $state['fonts'] as $font ) {
			if ( $font['family_key'] === $key ) {
				stub_json(
					array(
						'family' => $font['family'],
						'url'    => $font['url'],
						'format' => $font['format'],
					)
				);
			}
		}
		stub_error( 'animeh_font_not_found', 'Bu font kayıtlı değil.', 404 );
	}

	if ( preg_match( '#^/fonts/(\d+)$#', $route, $matches ) && 'DELETE' === $method ) {
		$id    = (int) $matches[1];
		$before = count( $state['fonts'] );
		$state['fonts'] = array_values(
			array_filter( $state['fonts'], static fn( array $font ): bool => $font['id'] !== $id )
		);
		if ( count( $state['fonts'] ) === $before ) {
			stub_error( 'animeh_font_not_found', 'Font bulunamadı.', 404 );
		}
		stub_save( $state );
		stub_json( array( 'deleted' => true, 'id' => $id ) );
	}

	// Presets.
	if ( '/test/presets' === $route && 'GET' === $method ) {
		stub_json( array( 'presets' => $state['presets'] ) );
	}

	if ( '/test/presets' === $route && 'POST' === $method ) {
		$body   = json_decode( (string) file_get_contents( 'php://input' ), true );
		$body   = is_array( $body ) ? $body : array();
		$preset = array(
			'id'            => bin2hex( random_bytes( 8 ) ),
			'label'         => (string) ( $body['label'] ?? '' ),
			'source_url'    => (string) ( $body['source_url'] ?? '' ),
			'source_type'   => (string) ( $body['source_type'] ?? 'auto' ),
			'subtitle_url'  => (string) ( $body['subtitle_url'] ?? '' ),
			'throttle_kbps' => (int) ( $body['throttle_kbps'] ?? 0 ),
		);
		$state['presets'][] = $preset;
		stub_save( $state );
		stub_json( array( 'preset' => $preset ), 201 );
	}

	// Sessions.
	if ( '/test/sessions' === $route && 'POST' === $method ) {
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		$body = is_array( $body ) ? $body : array();

		$url = (string) ( $body['source_url'] ?? '' );
		// The shipped guard decides, exactly as the plugin's controller does.
		$check = UrlGuard::check( $url, array() );
		if ( ! $check->allowed() && 'private_address' !== $check->reason ) {
			stub_error( 'animeh_url_rejected', 'Bu URL kullanılamaz: ' . (string) $check->reason, 400 );
		}

		$session = array(
			'id'            => $state['next_id']++,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'source_url'    => $url,
			'source_type'   => (string) ( $body['source_type'] ?? 'auto' ),
			'subtitle_url'  => (string) ( $body['subtitle_url'] ?? '' ),
			'throttle_kbps' => (int) ( $body['throttle_kbps'] ?? 0 ),
			'verdict'       => 'pending',
			'metrics'       => array(),
			'font_report'   => array(),
			'events'        => array(),
		);
		$state['sessions'][ (string) $session['id'] ] = $session;
		stub_save( $state );
		stub_json( array( 'session' => $session ), 201 );
	}

	if ( preg_match( '#^/test/sessions/(\d+)$#', $route, $matches ) && 'PATCH' === $method ) {
		$id  = (string) $matches[1];
		if ( ! isset( $state['sessions'][ $id ] ) ) {
			stub_error( 'animeh_session_not_found', 'Test oturumu bulunamadı.', 404 );
		}
		$body    = json_decode( (string) file_get_contents( 'php://input' ), true );
		$body    = is_array( $body ) ? $body : array();
		$session = $state['sessions'][ $id ];

		if ( isset( $body['metrics'] ) && is_array( $body['metrics'] ) ) {
			$session['metrics'] = $body['metrics'];
		}
		if ( isset( $body['font_report'] ) && is_array( $body['font_report'] ) ) {
			$session['font_report'] = $body['font_report'];
		}
		if ( isset( $body['events'] ) && is_array( $body['events'] ) ) {
			$session['events'] = array_slice( array_merge( $session['events'], $body['events'] ), -500 );
		}
		if ( isset( $body['states'] ) && is_array( $body['states'] ) ) {
			$session['verdict'] = TestVerdict::decide(
				array_map( 'strval', $body['states'] ),
				$session['metrics']
			);
		}

		$state['sessions'][ $id ] = $session;
		stub_save( $state );
		stub_json( array( 'session' => $session ) );
	}

	if ( '/test/sessions' === $route && 'GET' === $method ) {
		$sessions = array_values( $state['sessions'] );
		usort( $sessions, static fn( array $a, array $b ): int => $b['id'] <=> $a['id'] );
		stub_json( array( 'sessions' => $sessions, 'total' => count( $sessions ) ) );
	}

	stub_error( 'rest_no_route', 'No route was found matching the URL and request method.', 404 );
}

/* ── Static files ───────────────────────────────────────────────────────── */

if ( str_starts_with( $path, '/stub-fonts/' ) ) {
	$file = STUB_STATE_DIR . '/fonts/' . basename( $path );
	if ( is_file( $file ) ) {
		header( 'Content-Type: font/ttf' );
		header( 'Access-Control-Allow-Origin: *' );
		readfile( $file );
		exit;
	}
	http_response_code( 404 );
	exit;
}

// Media corpus, with Range support.
if ( str_starts_with( $path, '/media/' ) ) {
	$local = realpath( dirname( __DIR__, 3 ) . '/media/' . substr( $path, strlen( '/media/' ) ) );
	$base  = realpath( dirname( __DIR__, 3 ) . '/media' );
	if ( false !== $local && false !== $base && str_starts_with( $local, $base ) && is_file( $local ) ) {
		stub_stream_file( $local, new Throttle( (int) ( $query['kbps'] ?? 0 ) ) );
		exit;
	}
	http_response_code( 404 );
	exit;
}

// Everything else is served by the built-in server from the plugin directory.
return false;

/* ── Helpers ────────────────────────────────────────────────────────────── */

/**
 * Map a URL the panel produced back onto the local corpus.
 *
 * @param string $src Source URL.
 */
function stub_local_path( string $src ): ?string {
	$path = parse_url( $src, PHP_URL_PATH );
	if ( ! is_string( $path ) || ! str_starts_with( $path, '/media/' ) ) {
		return null;
	}
	$local = realpath( dirname( __DIR__, 3 ) . '/media/' . substr( $path, strlen( '/media/' ) ) );
	$base  = realpath( dirname( __DIR__, 3 ) . '/media' );
	if ( false === $local || false === $base || ! str_starts_with( $local, $base ) || ! is_file( $local ) ) {
		return null;
	}
	return $local;
}

/**
 * Stream a file with Range support and optional pacing.
 *
 * @param string   $file     Absolute path.
 * @param Throttle $throttle Pacing.
 */
function stub_stream_file( string $file, Throttle $throttle ): void {
	$size  = (int) filesize( $file );
	$start = 0;
	$end   = $size - 1;

	$types = array(
		'm3u8' => 'application/vnd.apple.mpegurl',
		'ts'   => 'video/mp2t',
		'm4s'  => 'video/iso.segment',
		'mp4'  => 'video/mp4',
		'mkv'  => 'video/x-matroska',
		'webm' => 'video/webm',
		'ass'  => 'text/plain; charset=utf-8',
		'ttf'  => 'font/ttf',
	);
	$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

	header( 'Content-Type: ' . ( $types[ $ext ] ?? 'application/octet-stream' ) );
	header( 'Accept-Ranges: bytes' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: no-store' );

	$range = $_SERVER['HTTP_RANGE'] ?? '';
	if ( '' !== $range && preg_match( '/bytes=(\d*)-(\d*)/', $range, $matches ) ) {
		if ( '' !== $matches[1] ) {
			$start = (int) $matches[1];
		}
		if ( '' !== $matches[2] ) {
			$end = min( (int) $matches[2], $size - 1 );
		}
		if ( $start > $end || $start >= $size ) {
			http_response_code( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}
		http_response_code( 206 );
		header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
	}

	header( 'Content-Length: ' . ( $end - $start + 1 ) );

	$handle = fopen( $file, 'rb' );
	if ( false === $handle ) {
		http_response_code( 500 );
		exit;
	}
	fseek( $handle, $start );

	$remaining = $end - $start + 1;
	while ( $remaining > 0 && ! feof( $handle ) ) {
		$chunk = fread( $handle, (int) min( $throttle->chunk_size, $remaining ) );
		if ( false === $chunk || '' === $chunk ) {
			break;
		}
		echo $chunk;
		flush();
		$remaining -= strlen( $chunk );

		$delay = $throttle->delay_for( strlen( $chunk ) );
		if ( $delay > 0 ) {
			usleep( $delay );
		}
	}
	fclose( $handle );
}
