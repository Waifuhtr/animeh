<?php
/**
 * Test cases for the plugin's WordPress-free logic.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Tests;

use Animeh\Support\AssScript;
use Animeh\Support\FontFile;
use Animeh\Support\PlaylistRewriter;
use Animeh\Support\Throttle;
use Animeh\Support\TestVerdict;
use Animeh\Support\UrlGuard;

$repo_root = dirname( __DIR__, 3 );
$font_dir  = $repo_root . '/media/fonts';
$ass_path  = $repo_root . '/tools/subtitle.ass';

/* ── FontFile ───────────────────────────────────────────────────────────── */

describe( 'FontFile', static function () use ( $font_dir ) {

	it( 'reads the family name out of the font, not the filename', static function () use ( $font_dir ) {
		if ( ! is_dir( $font_dir ) ) {
			skip( 'tools/make-test-media.sh çalıştırılmamış' );
		}

		// The whole point: DejaVuSans.ttf is the family "DejaVu Sans".
		$expected = array(
			'DejaVuSans.ttf'     => 'DejaVu Sans',
			'DejaVuSerif.ttf'    => 'DejaVu Serif',
			'DejaVuSansMono.ttf' => 'DejaVu Sans Mono',
		);

		foreach ( $expected as $file => $family ) {
			$font = FontFile::from_path( $font_dir . '/' . $file );
			ok( null !== $font, $file . ' okunamadı' );
			same( $family, $font->family(), $file );
			same( 'ttf', $font->format, $file . ' biçimi' );
			ok( '' !== (string) $font->postscript_name, $file . ' postscript adı yok' );
		}
	} );

	it( 'rejects bytes that are not a font', static function () {
		same( null, FontFile::from_string( '' ) );
		same( null, FontFile::from_string( 'not a font at all, just text' ) );
		same( null, FontFile::from_string( str_repeat( "\x00", 64 ) ) );
		// A PNG header must not be mistaken for a font.
		same( null, FontFile::from_string( "\x89PNG\r\n\x1a\n" . str_repeat( "\x00", 100 ) ) );
	} );

	it( 'rejects a truncated font', static function () use ( $font_dir ) {
		$path = $font_dir . '/DejaVuSans.ttf';
		if ( ! is_readable( $path ) ) {
			skip( 'font korpusu yok' );
		}
		$bytes = (string) file_get_contents( $path );

		// Header intact, body cut: the table directory now points past the end,
		// which is exactly what a crafted upload looks like.
		same( null, FontFile::from_string( substr( $bytes, 0, 2048 ) ), 'kırpılmış dosya kabul edildi' );
		ok( null !== FontFile::from_string( $bytes ), 'tam dosya reddedildi' );
	} );

	it( 'recognises WOFF containers without claiming a family', static function () {
		// WOFF wraps an sfnt in compression this class does not undo; it is
		// servable but its family has to come from elsewhere.
		$woff = 'wOFF' . str_repeat( "\x00", 60 );
		$font = FontFile::from_string( $woff );
		ok( null !== $font, 'WOFF tanınmadı' );
		same( 'woff', $font->format );
		same( '', $font->family() );

		$woff2 = 'wOF2' . str_repeat( "\x00", 60 );
		same( 'woff2', FontFile::from_string( $woff2 )->format );
	} );

	it( 'normalises family names the way libass compares them', static function () {
		same( 'dejavu sans', FontFile::key( 'DejaVu Sans' ) );
		same( 'dejavu sans', FontFile::key( '  DejaVu   Sans  ' ) );
		same( 'dejavu sans', FontFile::key( 'DEJAVU SANS' ) );
		// Turkish dotted/dotless I must fold predictably.
		same( FontFile::key( 'İstanbul Gothic' ), FontFile::key( 'i̇stanbul gothic' ) );
	} );
} );

/* ── AssScript ──────────────────────────────────────────────────────────── */

describe( 'AssScript', static function () use ( $ass_path ) {

	it( 'finds every font family the script can ask for', static function () use ( $ass_path ) {
		if ( ! is_readable( $ass_path ) ) {
			skip( 'tools/subtitle.ass yok' );
		}
		$content = (string) file_get_contents( $ass_path );

		// The same expected list the player's TypeScript suite asserts, so the
		// two implementations cannot drift apart unnoticed.
		same(
			array( 'Animeh Nonexistent Gothic', 'DejaVu Sans', 'DejaVu Sans Mono', 'DejaVu Serif' ),
			AssScript::font_families( $content )
		);
	} );

	it( 'picks up a font named only by an inline override', static function () {
		$script    = "[Events]\nDialogue: 0,0:00:00.00,0:00:01.00,Default,,0,0,0,,{\\fnRoboto Slab\\b1}merhaba";
		$families = AssScript::font_families( $script );
		ok( in_array( 'Roboto Slab', $families, true ), '\\fn override kaçırıldı' );
	} );

	it( 'strips the vertical-writing prefix', static function () {
		$script = "[Events]\nDialogue: 0,0:00:00.00,0:00:01.00,Default,,0,0,0,,{\\fn@MS Gothic}metin";
		same( array( 'MS Gothic' ), AssScript::font_families( $script ) );
	} );

	it( 'reads styles and resolution', static function () use ( $ass_path ) {
		if ( ! is_readable( $ass_path ) ) {
			skip( 'tools/subtitle.ass yok' );
		}
		$content = (string) file_get_contents( $ass_path );

		$styles = AssScript::styles( $content );
		same( 5, count( $styles ) );

		$by_name = array();
		foreach ( $styles as $style ) {
			$by_name[ $style['name'] ] = $style;
		}
		same( 'DejaVu Sans Mono', $by_name['Karaoke']['fontname'] );
		same( 60.0, $by_name['Karaoke']['fontsize'] );
		same( true, $by_name['Karaoke']['bold'] );
		same( true, $by_name['Italics']['italic'] );
		same( false, $by_name['Default']['bold'] );

		same( array( 'x' => 1920, 'y' => 1080 ), AssScript::play_res( $content ) );
		ok( AssScript::dialogue_count( $content ) >= 12, 'diyalog sayısı düşük' );
	} );

	it( 'survives a script with no styles section', static function () {
		same( array(), AssScript::font_families( "[Script Info]\nTitle: bos\n" ) );
		same( array(), AssScript::styles( '' ) );
		same( 0, AssScript::dialogue_count( '' ) );
	} );

	it( 'handles CRLF line endings and a BOM', static function () {
		$script = "\xEF\xBB\xBF[V4+ Styles]\r\n"
			. "Format: Name, Fontname, Fontsize\r\n"
			. "Style: Default,Noto Sans,48\r\n";
		same( array( 'Noto Sans' ), AssScript::font_families( $script ) );
	} );
} );

/* ── UrlGuard ───────────────────────────────────────────────────────────── */

describe( 'UrlGuard', static function () {

	/**
	 * A resolver that answers from a fixed table, so the rules are tested
	 * without depending on DNS.
	 *
	 * @param array<string, string[]> $table Host to addresses.
	 */
	$resolver = static function ( array $table ): callable {
		return static function ( string $host ) use ( $table ): array {
			return $table[ $host ] ?? array();
		};
	};

	it( 'allows a public host', static function () use ( $resolver ) {
		$result = UrlGuard::check(
			'https://cdn.example.com/anime/master.m3u8',
			array(),
			$resolver( array( 'cdn.example.com' => array( '93.184.216.34' ) ) )
		);
		ok( $result->allowed(), 'reddedildi: ' . (string) $result->reason );
	} );

	it( 'rejects non-http schemes', static function () {
		same( 'unsupported_scheme', UrlGuard::check( 'file:///etc/passwd' )->reason );
		same( 'unsupported_scheme', UrlGuard::check( 'gopher://example.com/' )->reason );
		same( 'malformed_url', UrlGuard::check( 'not a url' )->reason );
	} );

	it( 'blocks loopback and private addresses', static function () {
		same( 'private_address', UrlGuard::check( 'http://127.0.0.1/' )->reason );
		same( 'private_address', UrlGuard::check( 'http://10.0.0.5/' )->reason );
		same( 'private_address', UrlGuard::check( 'http://192.168.1.1/' )->reason );
		same( 'private_address', UrlGuard::check( 'http://172.16.0.1/' )->reason );
		same( 'private_address', UrlGuard::check( 'http://[::1]/' )->reason );
	} );

	it( 'blocks the cloud metadata address', static function () {
		// The single most valuable SSRF target on a hosted site.
		same( 'private_address', UrlGuard::check( 'http://169.254.169.254/latest/meta-data/' )->reason );
	} );

	it( 'blocks IPv4-mapped IPv6 loopback', static function () {
		// ::ffff:127.0.0.1 must be judged by its IPv4 half.
		ok( UrlGuard::is_blocked_address( '::ffff:127.0.0.1' ), 'eşlenmiş loopback geçti' );
		ok( UrlGuard::is_blocked_address( '::ffff:10.0.0.1' ), 'eşlenmiş özel adres geçti' );
		ok( ! UrlGuard::is_blocked_address( '::ffff:93.184.216.34' ), 'eşlenmiş genel adres bloklandı' );
	} );

	it( 'rejects a host whose addresses are not all public', static function () use ( $resolver ) {
		// A name answering with one public and one private address must fail:
		// checking only the first would connect to the private one.
		$result = UrlGuard::check(
			'https://rebind.example.com/x',
			array(),
			$resolver( array( 'rebind.example.com' => array( '93.184.216.34', '127.0.0.1' ) ) )
		);
		same( 'private_address', $result->reason );
	} );

	it( 'rejects credentials embedded in the URL', static function () {
		same( 'credentials_in_url', UrlGuard::check( 'https://user:pass@example.com/x' )->reason );
	} );

	it( 'rejects an unresolvable host', static function () use ( $resolver ) {
		same( 'unresolvable_host', UrlGuard::check( 'https://nowhere.invalid/x', array(), $resolver( array() ) )->reason );
	} );

	it( 'enforces the host allowlist', static function () use ( $resolver ) {
		$dns = $resolver(
			array(
				'cdn.example.com'   => array( '93.184.216.34' ),
				'other.example.net' => array( '93.184.216.34' ),
			)
		);

		same( 'host_not_allowed', UrlGuard::check( 'https://other.example.net/x', array( 'cdn.example.com' ), $dns )->reason );
		ok( UrlGuard::check( 'https://cdn.example.com/x', array( 'cdn.example.com' ), $dns )->allowed() );
	} );

	it( 'matches subdomains for a leading-dot allowlist entry', static function () {
		ok( UrlGuard::host_allowed( 'media.example.com', array( '.example.com' ) ) );
		ok( UrlGuard::host_allowed( 'example.com', array( '.example.com' ) ) );
		ok( ! UrlGuard::host_allowed( 'notexample.com', array( '.example.com' ) ) );
		ok( ! UrlGuard::host_allowed( 'example.com.evil.net', array( 'example.com' ) ) );
	} );
} );

/* ── Throttle ───────────────────────────────────────────────────────────── */

describe( 'Throttle', static function () {

	it( 'is inert when no rate is set', static function () {
		$throttle = new Throttle( 0 );
		ok( ! $throttle->enabled() );
		same( 0, $throttle->delay_for( 100000 ) );
		same( 0.0, $throttle->seconds_for( 100000 ) );
	} );

	it( 'converts kbps to bytes per second', static function () {
		same( 87500, ( new Throttle( 700 ) )->bytes_per_second );
		same( 375000, ( new Throttle( 3000 ) )->bytes_per_second );
	} );

	it( 'paces a transfer to the requested rate', static function () {
		$throttle = new Throttle( 800 ); // 100_000 bytes/s.
		same( 100000, $throttle->bytes_per_second );
		// One second's worth of bytes should cost about one second.
		same( 1_000_000, $throttle->delay_for( 100000 ) );
		same( 1.0, $throttle->seconds_for( 100000 ) );
	} );

	it( 'keeps chunks inside sane bounds', static function () {
		// Very slow links must not drop to single-byte writes.
		ok( ( new Throttle( 64 ) )->chunk_size >= 4096 );
		// Very fast ones must not block for a long time on one write.
		ok( ( new Throttle( 100000 ) )->chunk_size <= 256 * 1024 );
	} );
} );

/* ── TestVerdict ────────────────────────────────────────────────────────── */

describe( 'TestVerdict', static function () {

	it( 'reduces check states to the worst one', static function () {
		same( 'bad', TestVerdict::from_states( array( 'ok', 'warn', 'bad' ) ) );
		same( 'pending', TestVerdict::from_states( array( 'ok', 'pending', 'warn' ) ) );
		same( 'warn', TestVerdict::from_states( array( 'ok', 'warn' ) ) );
		same( 'ok', TestVerdict::from_states( array( 'ok', 'ok' ) ) );
		// An empty run has not earned a pass.
		same( 'pending', TestVerdict::from_states( array() ) );
	} );

	it( 'flags slow startup and repeated rebuffering', static function () {
		same( array(), TestVerdict::notes( array( 'startupTimeMs' => 600, 'rebufferCount' => 1 ) ) );
		ok( in_array( 'slow_startup', TestVerdict::notes( array( 'startupTimeMs' => 9000 ) ), true ) );
		ok( in_array( 'frequent_rebuffering', TestVerdict::notes( array( 'rebufferCount' => 6 ) ), true ) );
		ok( in_array( 'errors_logged', TestVerdict::notes( array( 'errors' => array( array( 'code' => 'X' ) ) ) ), true ) );
	} );

	it( 'downgrades an all-green run whose numbers were rough', static function () {
		$states = array( 'ok', 'ok' );
		same( 'ok', TestVerdict::decide( $states, array( 'startupTimeMs' => 500, 'rebufferCount' => 0 ) ) );
		// Checks passed but startup took nine seconds: that is not a clean pass.
		same( 'warn', TestVerdict::decide( $states, array( 'startupTimeMs' => 9000 ) ) );
	} );

	it( 'keeps a failure a failure regardless of the numbers', static function () {
		same( 'bad', TestVerdict::decide( array( 'ok', 'bad' ), array( 'startupTimeMs' => 100 ) ) );
	} );
} );

/* ── PlaylistRewriter ───────────────────────────────────────────────────── */

describe( 'PlaylistRewriter', static function () {

	$wrap = static fn( string $url ): string => '/proxy?src=' . rawurlencode( $url );

	it( 'resolves relative references against the playlist address', static function () {
		$base = 'https://cdn.example.com/anime/21/s1/e1/master.m3u8';
		same( 'https://cdn.example.com/anime/21/s1/e1/720p/index.m3u8', PlaylistRewriter::resolve_url( $base, '720p/index.m3u8' ) );
		same( 'https://cdn.example.com/anime/21/s1/seg.ts', PlaylistRewriter::resolve_url( $base, '../seg.ts' ) );
		same( 'https://cdn.example.com/other/x.ts', PlaylistRewriter::resolve_url( $base, '/other/x.ts' ) );
		same( 'https://other.example.net/x.ts', PlaylistRewriter::resolve_url( $base, '//other.example.net/x.ts' ) );
		same( 'https://elsewhere.example/x.ts', PlaylistRewriter::resolve_url( $base, 'https://elsewhere.example/x.ts' ) );
		// A port on the base must survive resolution.
		same( 'http://127.0.0.1:8765/media/a/seg.ts', PlaylistRewriter::resolve_url( 'http://127.0.0.1:8765/media/a/index.m3u8', 'seg.ts' ) );
	} );

	it( 'never climbs above the root', static function () {
		same(
			'https://cdn.example.com/x.ts',
			PlaylistRewriter::resolve_url( 'https://cdn.example.com/a/b.m3u8', '../../../x.ts' )
		);
	} );

	it( 'rewrites variant playlists in a master playlist', static function () use ( $wrap ) {
		$master = "#EXTM3U\n"
			. "#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360\n"
			. "360p/index.m3u8\n"
			. "#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1280x720\n"
			. "720p/index.m3u8\n";

		$result = PlaylistRewriter::rewrite( $master, 'https://cdn.example.com/a/master.m3u8', $wrap );

		// Tags survive untouched; only the URI lines change.
		ok( str_contains( $result, '#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360' ) );
		ok( str_contains( $result, '/proxy?src=' . rawurlencode( 'https://cdn.example.com/a/360p/index.m3u8' ) ) );
		ok( str_contains( $result, '/proxy?src=' . rawurlencode( 'https://cdn.example.com/a/720p/index.m3u8' ) ) );
		ok( ! str_contains( $result, "\n360p/index.m3u8" ), 'ham URI kaldı' );
	} );

	it( 'rewrites segments, init segments and keys', static function () use ( $wrap ) {
		$media = "#EXTM3U\n"
			. "#EXT-X-MAP:URI=\"init.mp4\"\n"
			. "#EXT-X-KEY:METHOD=AES-128,URI=\"https://keys.example/k1\",IV=0x00\n"
			. "#EXTINF:2.000,\n"
			. "seg000.m4s\n"
			. "#EXT-X-ENDLIST\n";

		$result = PlaylistRewriter::rewrite( $media, 'https://cdn.example.com/a/720p/index.m3u8', $wrap );

		ok( str_contains( $result, 'URI="/proxy?src=' . rawurlencode( 'https://cdn.example.com/a/720p/init.mp4' ) . '"' ) );
		// An absolute key URI stays absolute but still goes through the proxy.
		ok( str_contains( $result, 'URI="/proxy?src=' . rawurlencode( 'https://keys.example/k1' ) . '"' ) );
		ok( str_contains( $result, '/proxy?src=' . rawurlencode( 'https://cdn.example.com/a/720p/seg000.m4s' ) ) );
		// Attributes other than URI must be left alone.
		ok( str_contains( $result, 'METHOD=AES-128' ) );
		ok( str_contains( $result, 'IV=0x00' ) );
		ok( str_contains( $result, '#EXT-X-ENDLIST' ) );
	} );

	it( 'leaves tags without a URI attribute alone', static function () use ( $wrap ) {
		$playlist = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:2\n#EXTINF:2.0,\nseg.ts\n";
		$result   = PlaylistRewriter::rewrite( $playlist, 'https://cdn.example.com/a/i.m3u8', $wrap );
		ok( str_contains( $result, '#EXT-X-VERSION:7' ) );
		ok( str_contains( $result, '#EXT-X-TARGETDURATION:2' ) );
		ok( str_contains( $result, '#EXTINF:2.0,' ) );
	} );

	it( 'recognises playlists by content type or extension', static function () {
		ok( PlaylistRewriter::looks_like_playlist( 'https://x/a.m3u8', '' ) );
		ok( PlaylistRewriter::looks_like_playlist( 'https://x/a', 'application/vnd.apple.mpegurl' ) );
		ok( PlaylistRewriter::looks_like_playlist( 'https://x/a.m3u8?token=1', 'application/octet-stream' ) );
		ok( ! PlaylistRewriter::looks_like_playlist( 'https://x/a.mkv', 'video/x-matroska' ) );
		ok( ! PlaylistRewriter::looks_like_playlist( 'https://x/seg.ts', 'video/mp2t' ) );
	} );
} );
