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
use Animeh\Support\S3Signer;
use Animeh\Support\SecretBox;
use Animeh\Support\StorageKey;
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

/* ── S3Signer ───────────────────────────────────────────────────────────── */

describe( 'S3Signer', static function () {

	// The example published in the AWS SigV4 documentation. The full
	// cross-check against an independent implementation lives in
	// tests/sigv4-crosscheck.mjs; this pins the basics to the run.php suite.
	$key    = 'AKIDEXAMPLE';
	$secret = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
	$stamp  = 1440938160;

	it( 'reproduces the signature published by AWS', static function () use ( $key, $secret, $stamp ) {
		$signer  = new S3Signer( $key, $secret, 'us-east-1', 'service' );
		$headers = $signer->sign_request( 'GET', 'https://example.amazonaws.com/', array(), S3Signer::EMPTY_PAYLOAD_HASH, $stamp );
		ok(
			str_ends_with( $headers['Authorization'], 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31' ),
			'imza eşleşmedi: ' . $headers['Authorization']
		);
	} );

	it( 'adds the payload hash header for S3 and not for other services', static function () use ( $key, $secret, $stamp ) {
		$s3 = ( new S3Signer( $key, $secret, 'us-west-004', 's3' ) )
			->sign_request( 'GET', 'https://s3.example.com/b/o', array(), S3Signer::EMPTY_PAYLOAD_HASH, $stamp );
		ok( isset( $s3['x-amz-content-sha256'] ), 'S3 için payload hash başlığı yok' );

		$other = ( new S3Signer( $key, $secret, 'us-east-1', 'service' ) )
			->sign_request( 'GET', 'https://example.amazonaws.com/', array(), S3Signer::EMPTY_PAYLOAD_HASH, $stamp );
		ok( ! isset( $other['x-amz-content-sha256'] ), 'S3 olmayan servise payload hash eklendi' );
	} );

	it( 'encodes an object key without touching the separators', static function () {
		same( '/anime/one-piece/e1.mp4', S3Signer::encode_key( 'anime/one-piece/e1.mp4' ) );
		same( '/anime/b%C3%B6l%C3%BCm%201.mp4', S3Signer::encode_key( 'anime/bölüm 1.mp4' ) );
		// `+`, `&` and `=` are legal in a key and must not survive unencoded,
		// or the signature covers a different string from the URL sent.
		same( '/a%2Bb/c%26d/e%3Df', S3Signer::encode_key( 'a+b/c&d/e=f' ) );
		same( '/x', S3Signer::encode_key( '/x' ) );
	} );

	it( 'signs the path exactly as it will be sent', static function () use ( $key, $secret, $stamp ) {
		// A presigned URL must carry the same path it signed; any normalisation
		// between the two produces SignatureDoesNotMatch at use time.
		$signer = new S3Signer( $key, $secret, 'us-west-004', 's3' );
		$path   = S3Signer::encode_key( 'bucket/a+b/bölüm 1.mp4' );
		$url    = $signer->presign_url( 'GET', 'https://s3.example.com' . $path, 900, array(), $stamp );
		ok( str_starts_with( $url, 'https://s3.example.com' . $path . '?' ), 'yol değişti: ' . $url );
	} );

	it( 'builds a presigned URL with every required parameter', static function () use ( $key, $secret, $stamp ) {
		$signer = new S3Signer( $key, $secret, 'us-west-004', 's3' );
		$url    = $signer->presign_url( 'PUT', 'https://s3.example.com/bucket/o.mp4', 900, array(), $stamp );

		foreach ( array( 'X-Amz-Algorithm=AWS4-HMAC-SHA256', 'X-Amz-Credential=', 'X-Amz-Date=20150830T123600Z', 'X-Amz-Expires=900', 'X-Amz-SignedHeaders=host', 'X-Amz-Signature=' ) as $needle ) {
			ok( str_contains( $url, $needle ), $needle . ' eksik' );
		}
	} );

	it( 'clamps expiry to the protocol maximum', static function () use ( $key, $secret, $stamp ) {
		$signer = new S3Signer( $key, $secret, 'us-west-004', 's3' );
		// Seven days is the ceiling; a longer request is rejected at use time,
		// which is far harder to debug than clamping here.
		$url = $signer->presign_url( 'GET', 'https://s3.example.com/b/o', 99999999, array(), $stamp );
		ok( str_contains( $url, 'X-Amz-Expires=604800' ), $url );
	} );
} );

/* ── StorageKey ─────────────────────────────────────────────────────────── */

describe( 'StorageKey', static function () {

	it( 'slugs a title into a readable folder name', static function () {
		same( 'one-piece', StorageKey::slug( 'One Piece' ) );
		same( 'attack-on-titan', StorageKey::slug( 'Attack on Titan' ) );
		// A separator inside the title must stay a boundary, not vanish.
		same( 'fate-zero', StorageKey::slug( 'Fate/Zero' ) );
		same( 're-zero-kara-hajimeru', StorageKey::slug( 'Re:Zero kara Hajimeru' ) );
	} );

	it( 'transliterates Turkish characters instead of dropping them', static function () {
		// Stripping these would turn "Çığlık" into "lk".
		same( 'ciglik', StorageKey::slug( 'Çığlık' ) );
		same( 'gunes-savascilari', StorageKey::slug( 'Güneş Savaşçıları' ) );
		same( 'bolum-ozel', StorageKey::slug( 'Bölüm Özel' ) );
	} );

	it( 'falls back to the id when a title cannot be transliterated', static function () {
		// A purely Japanese title yields nothing usable; an empty folder name
		// or a mangled one would both be worse than an explicit fallback.
		same( 'anime-42', StorageKey::slug( '進撃の巨人', 42 ) );
		same( 'anime', StorageKey::slug( '進撃の巨人' ) );
		same( 'anime-7', StorageKey::slug( '   ', 7 ) );
	} );

	it( 'keeps slugs short enough to stay readable', static function () {
		$slug = StorageKey::slug( str_repeat( 'very long title ', 20 ) );
		ok( strlen( $slug ) <= 60, strlen( $slug ) . ' karakter' );
		ok( ! str_ends_with( $slug, '-' ), 'ayraçla bitiyor: ' . $slug );
	} );

	it( 'zero-pads season and episode so the console sorts correctly', static function () {
		// Backblaze sorts keys as strings: without padding, episode 10 files
		// between 1 and 2 and a full season becomes unreadable.
		same( 'anime/one-piece/season-01/episode-001', StorageKey::episode_prefix( 'one-piece', 1, 1 ) );
		same( 'anime/one-piece/season-01/episode-010', StorageKey::episode_prefix( 'one-piece', 1, 10 ) );
		same( 'anime/one-piece/season-02/episode-100', StorageKey::episode_prefix( 'one-piece', 2, 100 ) );

		$keys = array(
			StorageKey::episode_prefix( 'x', 1, 2 ),
			StorageKey::episode_prefix( 'x', 1, 10 ),
			StorageKey::episode_prefix( 'x', 1, 1 ),
		);
		$sorted = $keys;
		sort( $sorted, SORT_STRING );
		same(
			array(
				StorageKey::episode_prefix( 'x', 1, 1 ),
				StorageKey::episode_prefix( 'x', 1, 2 ),
				StorageKey::episode_prefix( 'x', 1, 10 ),
			),
			$sorted
		);
	} );

	it( 'builds media, subtitle and font keys', static function () {
		same(
			'anime/one-piece/season-01/episode-005/master.m3u8',
			StorageKey::episode_file( 'one-piece', 1, 5, 'master.m3u8' )
		);
		same(
			'anime/one-piece/season-01/episode-005/subtitles/tr.ass',
			StorageKey::subtitle_file( 'one-piece', 1, 5, 'tr' )
		);
		// Fonts are shared across an anime: a release typesets every episode
		// with the same faces.
		same( 'anime/one-piece/fonts/DejaVuSans.ttf', StorageKey::font_file( 'one-piece', 'DejaVuSans.ttf' ) );
		same( '_animeh/backups/2026-01-01.json', StorageKey::system_file( 'backups/2026-01-01.json' ) );
	} );

	it( 'refuses a file name that would escape its folder', static function () {
		same( 'passwd', StorageKey::safe_filename( '../../etc/passwd' ) );
		same( 'evil.mp4', StorageKey::safe_filename( '/tmp/evil.mp4' ) );
		same( 'evil.mp4', StorageKey::safe_filename( 'C:\\windows\\evil.mp4' ) );
		same( 'file', StorageKey::safe_filename( '...' ) );
		same( 'file', StorageKey::safe_filename( '' ) );
		same( 'bolum-1.mp4', StorageKey::safe_filename( 'bölüm 1.mp4' ) );
	} );

	it( 'parses an episode key back into its parts', static function () {
		same(
			array( 'slug' => 'one-piece', 'season' => 1, 'episode' => 5, 'file' => 'master.m3u8' ),
			StorageKey::parse_episode_key( 'anime/one-piece/season-01/episode-005/master.m3u8' )
		);
		same(
			array( 'slug' => 'x', 'season' => 2, 'episode' => 10, 'file' => 'subtitles/tr.ass' ),
			StorageKey::parse_episode_key( 'anime/x/season-02/episode-010/subtitles/tr.ass' )
		);
		same( null, StorageKey::parse_episode_key( '_animeh/backups/x.json' ) );
		same( null, StorageKey::parse_episode_key( 'anime/x/fonts/a.ttf' ) );
	} );
} );

/* ── SecretBox ──────────────────────────────────────────────────────────── */

describe( 'SecretBox', static function () {

	it( 'round-trips a secret', static function () {
		$box    = new SecretBox( 'some wordpress salt material' );
		$secret = 'K005abcdefghijklmnopqrstuvwxyz0123456789';
		same( $secret, $box->open( $box->seal( $secret ) ) );
	} );

	it( 'produces a different token every time', static function () {
		// A fresh nonce per seal: two identical secrets must not be visibly
		// identical in the database.
		$box = new SecretBox( 'salt' );
		ok( $box->seal( 'same value' ) !== $box->seal( 'same value' ) );
	} );

	it( 'refuses to open a token sealed under another key', static function () {
		$sealed = ( new SecretBox( 'first key' ) )->seal( 'application-key' );
		// GCM authenticates, so a wrong key fails outright rather than
		// returning plausible rubbish.
		same( '', ( new SecretBox( 'second key' ) )->open( $sealed ) );
	} );

	it( 'refuses a tampered token', static function () {
		$box    = new SecretBox( 'salt' );
		$sealed = $box->seal( 'application-key' );
		// Flip a byte in the ciphertext; the tag must catch it.
		$tampered = substr( $sealed, 0, -4 ) . ( str_ends_with( $sealed, 'A' ) ? 'BBBB' : 'AAAA' );
		same( '', $box->open( $tampered ) );
	} );

	it( 'handles an empty secret without ceremony', static function () {
		$box = new SecretBox( 'salt' );
		same( '', $box->seal( '' ) );
		same( '', $box->open( '' ) );
	} );

	it( 'reads back a value written before encryption existed', static function () {
		// An upgrade must not silently lose credentials stored as plaintext by
		// an earlier version.
		$box = new SecretBox( 'salt' );
		same( 'legacy-plaintext-key', $box->open( 'legacy-plaintext-key' ) );
	} );

	it( 'masks a secret down to something only recognisable', static function () {
		$masked = SecretBox::mask( 'K005abcdefghijklmnopqrstuvwxyz' );
		ok( str_starts_with( $masked, 'K005' ), $masked );
		ok( str_ends_with( $masked, 'wxyz' ), $masked );
		ok( ! str_contains( $masked, 'ghijkl' ), 'maskede gövde sızdı: ' . $masked );
		same( '', SecretBox::mask( '' ) );
		// A short secret reveals nothing at all.
		same( '••••', SecretBox::mask( 'abcd' ) );
	} );
} );

describe( 'MigrationCode', static function () {
	it( 'issues codes from an alphabet with no confusable letters', static function () {
		$code = \Animeh\Support\MigrationCode::generate();
		same( 23, strlen( $code ) );
		ok( (bool) preg_match( '/^[0-9A-Z]{5}(-[0-9A-Z]{5}){3}$/', $code ), $code );
		// I, L, O and U are absent on purpose: they are the characters a
		// person mistypes when copying a code between two screens.
		ok( ! (bool) preg_match( '/[ILOU]/', $code ), 'kod karıştırılabilir harf içeriyor: ' . $code );
	} );

	it( 'does not repeat itself', static function () {
		$seen = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$seen[ \Animeh\Support\MigrationCode::generate() ] = true;
		}
		same( 50, count( $seen ) );
	} );

	it( 'forgives the ways a code gets retyped', static function () {
		$canonical = \Animeh\Support\MigrationCode::normalise( 'ABCDE-FGHJK' );
		same( $canonical, \Animeh\Support\MigrationCode::normalise( 'abcde fghjk' ) );
		same( $canonical, \Animeh\Support\MigrationCode::normalise( '  ABCDEFGHJK  ' ) );
		// The letters that were kept out of the alphabet map onto what the
		// person meant, rather than failing.
		same( '10V0', \Animeh\Support\MigrationCode::normalise( 'IOUO' ) );
	} );

	it( 'never stores the code itself', static function () {
		$code = 'ABCDE-FGHJK-MNPQR-STVWX';
		$hash = \Animeh\Support\MigrationCode::hash( $code, 'site-salt' );
		same( 64, strlen( $hash ) );
		ok( ! str_contains( $hash, 'ABCDE' ), 'hash kodu sızdırıyor' );
		// Different installations must not produce the same hash for the same
		// code, or a code from one site would open another.
		ok( $hash !== \Animeh\Support\MigrationCode::hash( $code, 'other-salt' ), 'hash tuzdan bağımsız' );
	} );

	it( 'accepts a valid code and refuses everything else', static function () {
		$code   = 'ABCDE-FGHJK-MNPQR-STVWX';
		$secret = 'site-salt';
		$hash   = \Animeh\Support\MigrationCode::hash( $code, $secret );
		$issued = 1_700_000_000;

		ok( \Animeh\Support\MigrationCode::verify( $code, $hash, $secret, $issued, $issued + 10 ) );
		ok( \Animeh\Support\MigrationCode::verify( 'abcde fghjk mnpqr stvwx', $hash, $secret, $issued, $issued + 10 ) );
		ok( ! \Animeh\Support\MigrationCode::verify( 'ABCDE-FGHJK-MNPQR-STVWY', $hash, $secret, $issued, $issued + 10 ) );
		ok( ! \Animeh\Support\MigrationCode::verify( $code, $hash, 'wrong-salt', $issued, $issued + 10 ) );
		ok( ! \Animeh\Support\MigrationCode::verify( '', $hash, $secret, $issued, $issued + 10 ) );
		ok( ! \Animeh\Support\MigrationCode::verify( $code, '', $secret, $issued, $issued + 10 ) );
	} );

	it( 'expires on time, and refuses a clock that ran backwards', static function () {
		$code   = 'ABCDE-FGHJK-MNPQR-STVWX';
		$secret = 'site-salt';
		$hash   = \Animeh\Support\MigrationCode::hash( $code, $secret );
		$issued = 1_700_000_000;
		$ttl    = \Animeh\Support\MigrationCode::TTL_SECONDS;

		ok( \Animeh\Support\MigrationCode::verify( $code, $hash, $secret, $issued, $issued + $ttl ) );
		ok( ! \Animeh\Support\MigrationCode::verify( $code, $hash, $secret, $issued, $issued + $ttl + 1 ) );
		// A submission timestamped before the code was issued means the clock
		// moved, and a code cannot be trusted across that.
		ok( ! \Animeh\Support\MigrationCode::verify( $code, $hash, $secret, $issued, $issued - 1 ) );

		same( $ttl, \Animeh\Support\MigrationCode::remaining( $issued, $issued ) );
		same( 0, \Animeh\Support\MigrationCode::remaining( $issued, $issued + $ttl + 100 ) );
	} );
} );

describe( 'Snapshot', static function () {
	$sample = static function (): array {
		return \Animeh\Support\Snapshot::build(
			array(
				'animeh_fonts'         => array(
					array( 'id' => 1, 'family' => 'DejaVu Sans', 'sha256' => str_repeat( 'a', 64 ) ),
					array( 'id' => 2, 'family' => 'Noto Sans', 'sha256' => str_repeat( 'b', 64 ) ),
				),
				'animeh_test_sessions' => array(
					array( 'id' => 7, 'verdict' => 'ok' ),
				),
			),
			array( 'animeh_test_presets' => array( array( 'id' => 'p1' ) ) ),
			array( 'site_url' => 'https://eski.test', 'created_at' => 1_700_000_000 )
		);
	};

	it( 'builds an envelope that validates', static function () use ( $sample ) {
		$envelope = $sample();
		same( 1, $envelope['format'] );
		same( array(), \Animeh\Support\Snapshot::problems( $envelope ) );
		ok( \Animeh\Support\Snapshot::is_valid( $envelope ) );
	} );

	it( 'includes every table even when one is empty', static function () {
		$envelope = \Animeh\Support\Snapshot::build( array( 'animeh_fonts' => array() ), array() );
		foreach ( \Animeh\Support\Snapshot::TABLES as $table ) {
			ok( isset( $envelope['tables'][ $table ] ), 'eksik tablo: ' . $table );
		}
		same( array(), \Animeh\Support\Snapshot::problems( $envelope ) );
	} );

	it( 'refuses to carry the storage credentials', static function () {
		// The snapshot is stored in the bucket. Putting the bucket's own keys
		// inside it would make one readable object equal to the account.
		$envelope = \Animeh\Support\Snapshot::build(
			array(),
			array(
				'animeh_test_presets' => array( 'kept' ),
				'animeh_storage'      => array( 'secret' => 'must-not-travel' ),
			)
		);
		ok( isset( $envelope['options']['animeh_test_presets'] ) );
		ok( ! isset( $envelope['options']['animeh_storage'] ), 'kimlik bilgisi yedeğe girdi' );
		ok( ! str_contains( wp_json_encode_compat( $envelope ), 'must-not-travel' ), 'sır zarfın içinde' );
	} );

	it( 'rejects an envelope that smuggles credentials in', static function () {
		$envelope                              = \Animeh\Support\Snapshot::build( array(), array() );
		$envelope['options']['animeh_storage'] = array( 'secret' => 'x' );
		$envelope['checksum']                  = \Animeh\Support\Snapshot::checksum( $envelope );

		// Even with a correct checksum — so, even hand-edited deliberately —
		// the option is refused rather than imported.
		ok( in_array( 'forbidden_option:animeh_storage', \Animeh\Support\Snapshot::problems( $envelope ), true ) );
	} );

	it( 'notices tampering', static function () use ( $sample ) {
		$envelope                          = $sample();
		$envelope['tables']['animeh_fonts'][0]['family'] = 'Comic Sans MS';
		same( array( 'checksum_mismatch' ), \Animeh\Support\Snapshot::problems( $envelope ) );
	} );

	it( 'checksums the data, not the order PHP built the arrays in', static function () {
		$a = \Animeh\Support\Snapshot::build(
			array( 'animeh_fonts' => array( array( 'id' => 1, 'family' => 'DejaVu Sans' ) ) ),
			array(),
			array( 'created_at' => 1_700_000_000, 'site_url' => 'https://x.test' )
		);
		$b = \Animeh\Support\Snapshot::build(
			array( 'animeh_fonts' => array( array( 'family' => 'DejaVu Sans', 'id' => 1 ) ) ),
			array(),
			array( 'site_url' => 'https://x.test', 'created_at' => 1_700_000_000 )
		);
		same( $a['checksum'], $b['checksum'] );
	} );

	it( 'keeps row order, which is data', static function () {
		$rows = array(
			array( 'id' => 2, 'family' => 'B' ),
			array( 'id' => 1, 'family' => 'A' ),
		);
		$envelope = \Animeh\Support\Snapshot::build( array( 'animeh_fonts' => $rows ), array() );
		same( 2, $envelope['tables']['animeh_fonts'][0]['id'] );
		same( 1, $envelope['tables']['animeh_fonts'][1]['id'] );
	} );

	it( 'refuses a snapshot from a newer plugin', static function () use ( $sample ) {
		$envelope             = $sample();
		$envelope['format']   = \Animeh\Support\Snapshot::FORMAT + 1;
		$envelope['checksum'] = \Animeh\Support\Snapshot::checksum( $envelope );

		// Restoring the parts we recognise and dropping the rest would lose
		// data silently, which is worse than refusing.
		ok( in_array( 'format_too_new', \Animeh\Support\Snapshot::problems( $envelope ), true ) );
		ok( ! \Animeh\Support\Snapshot::is_valid( $envelope ) );
	} );

	it( 'names what is wrong rather than just failing', static function () {
		same( array( 'not_an_object' ), \Animeh\Support\Snapshot::problems( 'nope' ) );

		$problems = \Animeh\Support\Snapshot::problems( array( 'format' => 1 ) );
		ok( in_array( 'missing_tables', $problems, true ) );
		ok( in_array( 'missing_checksum', $problems, true ) );

		$partial = \Animeh\Support\Snapshot::problems(
			array( 'format' => 1, 'tables' => array( 'animeh_fonts' => array() ), 'checksum' => 'x' )
		);
		ok( in_array( 'missing_table:animeh_test_sessions', $partial, true ) );
	} );

	it( 'survives the round trip through storage', static function () use ( $sample ) {
		$envelope = $sample();
		$bytes    = \Animeh\Support\Snapshot::encode( $envelope );

		// Gzipped where the extension exists, and smaller for it.
		ok( strlen( $bytes ) > 0 );
		$back = \Animeh\Support\Snapshot::decode( $bytes );
		ok( \Animeh\Support\Snapshot::is_valid( $back ) );
		same( $envelope['checksum'], $back['checksum'] );
	} );

	it( 'reads a snapshot that was unpacked by hand', static function () use ( $sample ) {
		// An operator who gunzips a snapshot to look inside it must still be
		// able to restore the result, so the reader sniffs rather than trusts
		// the file name.
		$envelope = $sample();
		$plain    = wp_json_encode_compat( $envelope );
		$back     = \Animeh\Support\Snapshot::decode( $plain );
		ok( \Animeh\Support\Snapshot::is_valid( $back ) );
	} );

	it( 'returns null for bytes that are not a snapshot', static function () {
		same( null, \Animeh\Support\Snapshot::decode( '' ) );
		same( null, \Animeh\Support\Snapshot::decode( 'not json at all' ) );
		same( null, \Animeh\Support\Snapshot::decode( "\x1f\x8b broken gzip" ) );
	} );

	it( 'summarises what an operator is about to overwrite', static function () use ( $sample ) {
		$summary = \Animeh\Support\Snapshot::summarise( $sample() );
		ok( $summary['valid'] );
		same( 2, $summary['counts']['animeh_fonts'] );
		same( 1, $summary['counts']['animeh_test_sessions'] );
		same( 'https://eski.test', $summary['origin']['site_url'] );
		same( '2023-11-14T22:13:20+00:00', $summary['created_at'] );
	} );

	it( 'summarises a broken snapshot without throwing', static function () {
		$summary = \Animeh\Support\Snapshot::summarise( array( 'format' => 1 ) );
		ok( ! $summary['valid'] );
		same( 0, $summary['counts']['animeh_fonts'] );
		ok( count( $summary['problems'] ) > 0 );
	} );
} );

describe( 'ApiToken', static function () {
	it( 'mints a recognisable, URL-safe token', static function () {
		$token = \Animeh\Support\ApiToken::generate();
		ok( str_starts_with( $token, 'ahp_' ), $token );
		// base64url of 32 bytes, unpadded, plus the prefix.
		same( 47, strlen( $token ) );
		ok( ! str_contains( $token, '+' ) && ! str_contains( $token, '/' ) && ! str_contains( $token, '=' ), $token );
		ok( \Animeh\Support\ApiToken::looks_valid( $token ) );
	} );

	it( 'does not repeat itself', static function () {
		$seen = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$seen[ \Animeh\Support\ApiToken::generate() ] = true;
		}
		same( 100, count( $seen ) );
	} );

	it( 'rejects anything not shaped like one of ours', static function () {
		// Checked before the database is touched, so a flood of junk costs
		// nothing.
		ok( ! \Animeh\Support\ApiToken::looks_valid( '' ) );
		ok( ! \Animeh\Support\ApiToken::looks_valid( 'ahp_short' ) );
		ok( ! \Animeh\Support\ApiToken::looks_valid( str_repeat( 'a', 47 ) ) );
		ok( ! \Animeh\Support\ApiToken::looks_valid( 'ahp_' . str_repeat( '!', 43 ) ) );
		ok( ! \Animeh\Support\ApiToken::looks_valid( 'ahp_' . str_repeat( 'a', 44 ) ) );
	} );

	it( 'reads the token out of an Authorization header', static function () {
		$token = \Animeh\Support\ApiToken::generate();
		same( $token, \Animeh\Support\ApiToken::from_header( 'Bearer ' . $token ) );
		// The scheme is case-insensitive per RFC 7235 and clients differ.
		same( $token, \Animeh\Support\ApiToken::from_header( 'bearer ' . $token ) );
		same( $token, \Animeh\Support\ApiToken::from_header( "  Bearer   {$token}  " ) );
	} );

	it( 'ignores headers that are not a bearer token', static function () {
		$token = \Animeh\Support\ApiToken::generate();
		same( '', \Animeh\Support\ApiToken::from_header( '' ) );
		same( '', \Animeh\Support\ApiToken::from_header( 'Basic dXNlcjpwYXNz' ) );
		same( '', \Animeh\Support\ApiToken::from_header( $token ) );
		same( '', \Animeh\Support\ApiToken::from_header( 'Bearer not-our-token' ) );
	} );

	it( 'stores a hash, never the token', static function () {
		$token = \Animeh\Support\ApiToken::generate();
		$hash  = \Animeh\Support\ApiToken::hash( $token );
		same( 64, strlen( $hash ) );
		ok( ! str_contains( $hash, substr( $token, 4, 12 ) ), 'hash token sızdırıyor' );
	} );

	it( 'knows when a token has expired', static function () {
		$now = 1_700_000_000;
		ok( ! \Animeh\Support\ApiToken::is_expired( $now + 1, $now ) );
		ok( \Animeh\Support\ApiToken::is_expired( $now, $now ) );
		ok( \Animeh\Support\ApiToken::is_expired( $now - 1, $now ) );
	} );

	it( 'masks a token down to something only recognisable', static function () {
		$masked = \Animeh\Support\ApiToken::mask( 'ahp_abcdefghijklmnopqrstuvwxyz' );
		ok( str_starts_with( $masked, 'ahp_abcd' ), $masked );
		ok( str_ends_with( $masked, 'wxyz' ), $masked );
		ok( ! str_contains( $masked, 'ijklmno' ), 'maskede gövde sızdı: ' . $masked );
	} );
} );

describe( 'RateLimit', static function () {
	it( 'aligns every caller on the same window', static function () {
		// Two requests a second apart inside one window must land on the same
		// key, or the limit counts nothing.
		same(
			\Animeh\Support\RateLimit::window_start( 900, 1_700_000_000 ),
			\Animeh\Support\RateLimit::window_start( 900, 1_700_000_001 )
		);
		same( 1_699_999_200, \Animeh\Support\RateLimit::window_start( 900, 1_700_000_000 ) );
	} );

	it( 'gives a new key when the window rolls over', static function () {
		$a = \Animeh\Support\RateLimit::key( 'login', '1.2.3.4', 900, 1_699_999_500 );
		$b = \Animeh\Support\RateLimit::key( 'login', '1.2.3.4', 900, 1_700_000_400 );
		ok( $a !== $b, 'pencere değişince anahtar değişmedi' );
	} );

	it( 'separates buckets and actors', static function () {
		$now = 1_700_000_000;
		ok(
			\Animeh\Support\RateLimit::key( 'login', '1.2.3.4', 900, $now )
			!== \Animeh\Support\RateLimit::key( 'register', '1.2.3.4', 900, $now )
		);
		ok(
			\Animeh\Support\RateLimit::key( 'login', '1.2.3.4', 900, $now )
			!== \Animeh\Support\RateLimit::key( 'login', '5.6.7.8', 900, $now )
		);
	} );

	it( 'does not put the actor in the key in plain text', static function () {
		// The key becomes an option_name; an IP address does not belong there
		// in readable form.
		$key = \Animeh\Support\RateLimit::key( 'login', '203.0.113.7', 900, 1_700_000_000 );
		ok( ! str_contains( $key, '203.0.113.7' ), $key );
	} );

	it( 'allows up to the limit and not past it', static function () {
		ok( \Animeh\Support\RateLimit::allows( 0, 10 ) );
		ok( \Animeh\Support\RateLimit::allows( 9, 10 ) );
		ok( ! \Animeh\Support\RateLimit::allows( 10, 10 ) );
		ok( ! \Animeh\Support\RateLimit::allows( 99, 10 ) );
	} );

	it( 'never tells a caller to retry immediately', static function () {
		// Retry-After: 0 invites the request that is being prevented.
		for ( $offset = 0; $offset < 900; $offset += 137 ) {
			$retry = \Animeh\Support\RateLimit::retry_after( 900, 1_699_999_500 + $offset );
			ok( $retry >= 1 && $retry <= 900, 'retry_after = ' . $retry );
		}
		// At the very start of a window the whole window is left; one second
		// before it ends, exactly one second is.
		same( 900, \Animeh\Support\RateLimit::retry_after( 900, 1_699_999_200 ) );
		same( 1, \Animeh\Support\RateLimit::retry_after( 900, 1_700_000_099 ) );
	} );
} );

describe( 'TenraiMapper', static function () {
	$entry = static function (): array {
		return array(
			'mal_id'  => 16498,
			'url'     => 'https://example.test/anime/16498',
			'images'  => array(
				'jpg'  => array( 'image_url' => 'https://cdn.test/a.jpg', 'large_image_url' => 'https://cdn.test/a-l.jpg' ),
				'webp' => array( 'image_url' => 'https://cdn.test/a.webp', 'large_image_url' => 'https://cdn.test/a-l.webp' ),
			),
			'trailer' => array( 'url' => 'https://youtube.test/watch?v=x' ),
			'titles'  => array(
				array( 'type' => 'Default', 'title' => 'Shingeki no Kyojin' ),
				array( 'type' => 'English', 'title' => 'Attack on Titan' ),
				array( 'type' => 'Japanese', 'title' => '進撃の巨人' ),
				array( 'type' => 'Synonym', 'title' => 'AoT' ),
			),
			'title'   => 'Shingeki no Kyojin',
			'title_synonyms' => array( 'SnK' ),
			'type'     => 'TV',
			'episodes' => 25,
			'status'   => 'Finished Airing',
			'duration' => '24 min per ep',
			'rating'   => 'R - 17+ (violence & profanity)',
			'score'    => 8.54,
			'popularity' => 1,
			'synopsis' => 'Centuries ago…',
			'year'     => 2013,
			'season'   => 'spring',
			'studios'  => array( array( 'mal_id' => 858, 'name' => 'Wit Studio' ) ),
			'genres'   => array(
				array( 'mal_id' => 1, 'name' => 'Action' ),
				array( 'mal_id' => 8, 'name' => 'Drama' ),
			),
		);
	};

	it( 'maps a full entry onto the catalog columns', static function () use ( $entry ) {
		$work = \Animeh\Support\TenraiMapper::work( $entry() );

		same( 16498, $work['tenrai_id'] );
		same( 'Shingeki no Kyojin', $work['title'] );
		same( 'Attack on Titan', $work['title_english'] );
		same( '進撃の巨人', $work['title_japanese'] );
		same( 'Wit Studio', $work['studio'] );
		same( 2013, $work['year'] );
		same( 'spring', $work['season'] );
		same( 25, $work['total_episodes'] );
		same( 8.54, $work['score'] );
		same( '["Action","Drama"]', $work['genres'] );
	} );

	it( 'prefers webp, and the largest size offered', static function () use ( $entry ) {
		// Smallest bytes over the wire, and every Android version the app
		// targets decodes it.
		same( 'https://cdn.test/a-l.webp', \Animeh\Support\TenraiMapper::work( $entry() )['poster_url'] );
	} );

	it( 'falls back through the image shapes', static function () {
		same(
			'https://cdn.test/only.jpg',
			\Animeh\Support\TenraiMapper::image( array( 'jpg' => array( 'image_url' => 'https://cdn.test/only.jpg' ) ) )
		);
		same( '', \Animeh\Support\TenraiMapper::image( null ) );
		same( '', \Animeh\Support\TenraiMapper::image( array() ) );
		same( '', \Animeh\Support\TenraiMapper::image( array( 'jpg' => array( 'image_url' => null ) ) ) );
	} );

	it( 'survives a payload with nulls everywhere', static function () {
		// Jikan-compatible responses carry nulls rather than omitting fields,
		// and a mapper that assumes strings dies on the first one.
		$work = \Animeh\Support\TenraiMapper::work(
			array(
				'mal_id'   => 1,
				'title'    => 'Cowboy Bebop',
				'synopsis' => null,
				'score'    => null,
				'episodes' => null,
				'duration' => null,
				'studios'  => null,
				'genres'   => null,
				'images'   => null,
				'season'   => null,
				'year'     => null,
			)
		);

		same( 'Cowboy Bebop', $work['title'] );
		same( '', $work['synopsis'] );
		same( 0.0, $work['score'] );
		same( 0, $work['total_episodes'] );
		same( '[]', $work['genres'] );
		same( '', $work['poster_url'] );
	} );

	it( 'finds a title when only the titles array is present', static function () {
		$work = \Animeh\Support\TenraiMapper::work(
			array(
				'mal_id' => 5,
				'titles' => array( array( 'type' => 'Default', 'title' => 'Fullmetal Alchemist' ) ),
			)
		);
		same( 'Fullmetal Alchemist', $work['title'] );
	} );

	it( 'collects synonyms from both places they appear', static function () use ( $entry ) {
		$synonyms = json_decode( \Animeh\Support\TenraiMapper::work( $entry() )['synonyms'], true );
		ok( in_array( 'SnK', $synonyms, true ), 'title_synonyms alınmadı' );
		ok( in_array( 'AoT', $synonyms, true ), 'titles[] synonym alınmadı' );
	} );

	it( 'maps upstream status onto our own vocabulary', static function () {
		// The app switches on this, so it cannot be free text that changes
		// upstream.
		same( 'finished', \Animeh\Support\TenraiMapper::status( 'Finished Airing' ) );
		same( 'airing', \Animeh\Support\TenraiMapper::status( 'Currently Airing' ) );
		same( 'upcoming', \Animeh\Support\TenraiMapper::status( 'Not yet aired' ) );
		same( 'unknown', \Animeh\Support\TenraiMapper::status( 'Cancelled' ) );
		same( '', \Animeh\Support\TenraiMapper::status( '' ) );
	} );

	it( 'reads a prose duration as seconds', static function () {
		same( 1440, \Animeh\Support\TenraiMapper::duration( '24 min per ep' ) );
		same( 5700, \Animeh\Support\TenraiMapper::duration( '1 hr 35 min' ) );
		same( 3600, \Animeh\Support\TenraiMapper::duration( '1 hr' ) );
		same( 30, \Animeh\Support\TenraiMapper::duration( '30 sec per ep' ) );
		same( 0, \Animeh\Support\TenraiMapper::duration( 'Unknown' ) );
	} );

	it( 'clamps a score into what the column holds', static function () {
		same( 10.0, \Animeh\Support\TenraiMapper::work( array( 'score' => 99 ) )['score'] );
		same( 0.0, \Animeh\Support\TenraiMapper::work( array( 'score' => -5 ) )['score'] );
		same( 0.0, \Animeh\Support\TenraiMapper::work( array( 'score' => 'not a number' ) )['score'] );
	} );

	it( 'digs the year out of wherever the payload put it', static function () {
		// `/anime/{id}/full` reports it under aired.prop rather than `year`.
		same(
			1998,
			\Animeh\Support\TenraiMapper::work(
				array( 'aired' => array( 'prop' => array( 'from' => array( 'year' => 1998 ) ) ) )
			)['year']
		);
		same(
			2009,
			\Animeh\Support\TenraiMapper::work( array( 'aired' => array( 'from' => '2009-04-05T00:00:00+00:00' ) ) )['year']
		);
		same( 0, \Animeh\Support\TenraiMapper::work( array() )['year'] );
	} );

	it( 'maps an episode entry', static function () {
		$episode = \Animeh\Support\TenraiMapper::episode(
			array(
				'mal_id'  => 12,
				'title'   => 'Wound',
				'aired'   => '2013-06-22T00:00:00+00:00',
				'filler'  => false,
				'synopsis' => null,
			),
			2
		);

		same( 12, $episode['number'] );
		same( 2, $episode['season_number'] );
		same( 'Wound', $episode['title'] );
		same( 0, $episode['filler'] );
		same( '2013-06-22 00:00:00', $episode['published_at'] );
	} );

	it( 'gives an unaired episode the zero date rather than a wrong one', static function () {
		same(
			'0000-00-00 00:00:00',
			\Animeh\Support\TenraiMapper::episode( array( 'mal_id' => 1, 'aired' => null ) )['published_at']
		);
	} );

	it( 'never encodes non-Latin titles into escapes', static function () {
		// A genre list read back into the app has to still be readable.
		same( '["アクション"]', \Animeh\Support\TenraiMapper::names( array( array( 'name' => 'アクション' ) ) ) );
	} );
} );

describe( 'WatchProgress', static function (): void {

	it( 'counts a 24 minute episode at about seventeen minutes', static function () {
		$duration = 24 * 60;

		same( 1008, \Animeh\Support\WatchProgress::threshold( $duration ) ); // 16m48s.
		same( false, \Animeh\Support\WatchProgress::is_complete( 16 * 60, $duration ) );
		same( true, \Animeh\Support\WatchProgress::is_complete( 17 * 60, $duration ) );
	} );

	it( 'counts a 10 minute episode at about seven', static function () {
		$duration = 10 * 60;

		same( 420, \Animeh\Support\WatchProgress::threshold( $duration ) );
		same( false, \Animeh\Support\WatchProgress::is_complete( 6 * 60, $duration ) );
		same( true, \Animeh\Support\WatchProgress::is_complete( 7 * 60, $duration ) );
	} );

	it( 'does not count an episode that was skipped to the end', static function () {
		// The whole point: the playhead is at the credits, nothing was watched.
		same( false, \Animeh\Support\WatchProgress::is_complete( 12, 24 * 60 ) );
	} );

	it( 'counts nothing when the length is unknown', static function () {
		same( 0, \Animeh\Support\WatchProgress::threshold( 0 ) );
		same( false, \Animeh\Support\WatchProgress::is_complete( 9999, 0 ) );
	} );

	it( 'credits ordinary playback between two reports', static function () {
		same( 105, \Animeh\Support\WatchProgress::accumulate( 100, 200, 205, 10 ) );
	} );

	it( 'credits nothing for a jump forward', static function () {
		// 200 -> 900 in one ten-second interval is a seek, not viewing.
		same( 100, \Animeh\Support\WatchProgress::accumulate( 100, 200, 900, 10 ) );
	} );

	it( 'credits nothing for a rewind, which was already counted', static function () {
		same( 100, \Animeh\Support\WatchProgress::accumulate( 100, 500, 200, 10 ) );
	} );

	it( 'accumulates a full episode a report at a time', static function () {
		$watched = 0;
		for ( $at = 0; $at < 1440; $at += 10 ) {
			$watched = \Animeh\Support\WatchProgress::accumulate( $watched, $at, $at + 10, 10 );
		}

		same( 1440, $watched );
		same( true, \Animeh\Support\WatchProgress::is_complete( $watched, 1440 ) );
	} );

	it( 'leaves a skimmed episode short of the threshold', static function () {
		// Watch ten seconds, jump a minute, repeat: a lot of ground covered,
		// very little of it seen.
		$watched = 0;
		$at      = 0;
		while ( $at < 1440 ) {
			$watched = \Animeh\Support\WatchProgress::accumulate( $watched, $at, $at + 10, 10 );
			$at     += 10;
			$watched = \Animeh\Support\WatchProgress::accumulate( $watched, $at, $at + 60, 10 );
			$at     += 60;
		}

		same( false, \Animeh\Support\WatchProgress::is_complete( $watched, 1440 ) );
	} );
} );
