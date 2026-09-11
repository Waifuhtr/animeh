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

	it( 'offers to continue a full-length episode across almost all of it', static function (): void {
		$duration = 24 * 60;

		// Five percent of 24 minutes is 72s, clamped to the 30s ceiling.
		same( 30, \Animeh\Support\WatchProgress::start_threshold( $duration ) );
		same( 45, \Animeh\Support\WatchProgress::end_margin( $duration ) );

		same( false, \Animeh\Support\WatchProgress::is_resumable( 29, $duration ) );
		same( true, \Animeh\Support\WatchProgress::is_resumable( 30, $duration ) );
		same( true, \Animeh\Support\WatchProgress::is_resumable( 1394, $duration ) );
		same( false, \Animeh\Support\WatchProgress::is_resumable( 1395, $duration ) );
	} );

	it( 'offers to continue a ninety-second episode, which fixed seconds did not', static function (): void {
		$duration = 90;

		// The old rule was "past 30s and more than 45s left", which left
		// thirteen of the ninety seconds resumable and looked like a dead
		// feature. Both margins are floors here: 5% of 90 is 4.
		same( 10, \Animeh\Support\WatchProgress::start_threshold( $duration ) );
		same( 5, \Animeh\Support\WatchProgress::end_margin( $duration ) );

		same( false, \Animeh\Support\WatchProgress::is_resumable( 9, $duration ) );
		same( true, \Animeh\Support\WatchProgress::is_resumable( 10, $duration ) );
		same( true, \Animeh\Support\WatchProgress::is_resumable( 45, $duration ) );
		same( true, \Animeh\Support\WatchProgress::is_resumable( 84, $duration ) );
		same( false, \Animeh\Support\WatchProgress::is_resumable( 85, $duration ) );
	} );

	it( 'still offers to continue when the length is unknown', static function (): void {
		same( false, \Animeh\Support\WatchProgress::is_resumable( 9, 0 ) );
		same( true, \Animeh\Support\WatchProgress::is_resumable( 10, 0 ) );
		same( true, \Animeh\Support\WatchProgress::is_resumable( 100000, 0 ) );
	} );

	it( 'never underflows on an episode shorter than the margin', static function (): void {
		// The SQL spells the tail as an addition for the same reason: the
		// column is UNSIGNED and duration - margin would wrap.
		same( false, \Animeh\Support\WatchProgress::is_resumable( 1, 3 ) );
		same( false, \Animeh\Support\WatchProgress::is_resumable( 3, 3 ) );
	} );

	it( 'counts an episode complete once the real length replaces the estimate', static function (): void {
		// The catalog said 24 minutes for a 90-second upload. Against the
		// estimate nothing was ever complete; against the measured length,
		// 63 seconds played is enough.
		same( false, \Animeh\Support\WatchProgress::is_complete( 63, 1440 ) );
		same( true, \Animeh\Support\WatchProgress::is_complete( 63, 90 ) );
	} );
} );

describe( 'TmdbMapper', static function (): void {
	it( 'joins an image path onto the base and size', static function (): void {
		same(
			'https://image.tmdb.org/t/p/w500/abc.jpg',
			\Animeh\Support\TmdbMapper::image( '/abc.jpg', 'w500' )
		);

		// A configuration call that came back with a trailing slash, and a
		// path that came back without a leading one: both are TMDB's own
		// shapes at different times.
		same(
			'https://image.tmdb.org/t/p/w300/still.jpg',
			\Animeh\Support\TmdbMapper::image( 'still.jpg', 'w300', 'https://image.tmdb.org/t/p/' )
		);
	} );

	it( 'returns nothing for a missing image rather than a URL that 404s', static function (): void {
		same( '', \Animeh\Support\TmdbMapper::image( '', 'w500' ) );
		same( '', \Animeh\Support\TmdbMapper::image( '   ', 'w500' ) );
	} );

	it( 'maps TMDB status onto the catalog vocabulary', static function (): void {
		same( 'airing', \Animeh\Support\TmdbMapper::status( 'Returning Series' ) );
		same( 'airing', \Animeh\Support\TmdbMapper::status( 'In Production' ) );
		same( 'finished', \Animeh\Support\TmdbMapper::status( 'Ended' ) );
		same( 'finished', \Animeh\Support\TmdbMapper::status( 'Canceled' ) );
		same( 'upcoming', \Animeh\Support\TmdbMapper::status( 'Planned' ) );
		same( 'unknown', \Animeh\Support\TmdbMapper::status( 'Something Else' ) );
	} );

	it( 'maps a TV payload onto catalog columns', static function (): void {
		$tv = array(
			'id'                 => 46260,
			'name'               => 'Naruto',
			'original_name'      => 'ナルト',
			'overview'           => 'Bir ninja hikâyesi.',
			'poster_path'        => '/poster.jpg',
			'backdrop_path'      => '/backdrop.jpg',
			'first_air_date'     => '2002-10-03',
			'status'             => 'Ended',
			'genres'             => array(
				array( 'id' => 16, 'name' => 'Animasyon' ),
				array( 'id' => 10759, 'name' => 'Aksiyon & Macera' ),
			),
			'number_of_episodes' => 220,
			'episode_run_time'   => array( 24 ),
			'vote_average'       => 8.3,
		);

		$mapped = \Animeh\Support\TmdbMapper::work( $tv );

		same( 46260, $mapped['tmdb_id'] );
		same( 'Naruto', $mapped['title'] );
		same( 'Bir ninja hikâyesi.', $mapped['synopsis'] );
		same( 'https://image.tmdb.org/t/p/w500/poster.jpg', $mapped['poster_url'] );
		same( 'https://image.tmdb.org/t/p/w1280/backdrop.jpg', $mapped['banner_url'] );
		same( 2002, $mapped['year'] );
		same( 'finished', $mapped['status'] );
		same( array( 'Animasyon', 'Aksiyon & Macera' ), $mapped['genres'] );
		same( 220, $mapped['total_episodes'] );
		same( 1440, $mapped['duration_seconds'] );
	} );

	it( 'gives every TMDB import a format', static function (): void {
		// This mapper only reads the `tv` endpoint, so "TV" is the honest
		// answer and an empty string is not: the discover screen filters on
		// this column exactly, and a work with no format matches no chip —
		// every anime imported from TMDB used to be unreachable that way.
		$mapped = \Animeh\Support\TmdbMapper::work( array( 'id' => 7 ) );
		same( 'TV', $mapped['format'] );

		// Including when TMDB describes the show as something else entirely:
		// `type` is a production category — "Scripted", "Miniseries" — not one
		// of the formats this catalog browses by.
		$mapped = \Animeh\Support\TmdbMapper::work(
			array( 'id' => 8, 'type' => 'Miniseries' )
		);
		same( 'TV', $mapped['format'] );
	} );

	it( 'survives a TV payload with every optional field missing', static function (): void {
		$mapped = \Animeh\Support\TmdbMapper::work( array( 'id' => 7 ) );

		same( 7, $mapped['tmdb_id'] );
		same( '', $mapped['poster_url'] );
		same( '', $mapped['banner_url'] );
		same( 0, $mapped['year'] );
		same( 'unknown', $mapped['status'] );
		same( array(), $mapped['genres'] );
		same( 0, $mapped['duration_seconds'] );
	} );

	it( 'maps an episode onto its still and title', static function (): void {
		$mapped = \Animeh\Support\TmdbMapper::episode(
			array(
				'season_number'  => 1,
				'episode_number' => 3,
				'name'           => 'Sasuke ve Sakura',
				'overview'       => 'Takım kuruluyor.',
				'still_path'     => '/still3.jpg',
				'runtime'        => 23,
				'air_date'       => '2002-10-17',
			)
		);

		same( 3, $mapped['number'] );
		same( 'Sasuke ve Sakura', $mapped['title'] );
		same( 'https://image.tmdb.org/t/p/w300/still3.jpg', $mapped['thumbnail_url'] );
		same( 1380, $mapped['duration_seconds'] );
	} );

	it( 'takes an exact title over a more popular near-miss', static function (): void {
		$results = array(
			array( 'id' => 1, 'name' => 'Naruto Shippuden', 'first_air_date' => '2007-02-15', 'popularity' => 900.0 ),
			array( 'id' => 2, 'name' => 'Naruto', 'first_air_date' => '2002-10-03', 'popularity' => 100.0 ),
		);

		$match = \Animeh\Support\TmdbMapper::best_match( $results, 'Naruto', 2002 );
		same( 2, $match['id'] );
	} );

	it( 'uses the year to separate two shows with the same name', static function (): void {
		$results = array(
			array( 'id' => 10, 'name' => 'Fruits Basket', 'first_air_date' => '2001-07-05', 'popularity' => 50.0 ),
			array( 'id' => 11, 'name' => 'Fruits Basket', 'first_air_date' => '2019-04-06', 'popularity' => 40.0 ),
		);

		same( 11, \Animeh\Support\TmdbMapper::best_match( $results, 'Fruits Basket', 2019 )['id'] );
		same( 10, \Animeh\Support\TmdbMapper::best_match( $results, 'Fruits Basket', 2001 )['id'] );
	} );

	it( 'matches across punctuation and case differences', static function (): void {
		$results = array(
			array( 'id' => 20, 'name' => 'Re:ZERO -Starting Life in Another World-', 'first_air_date' => '2016-04-04' ),
		);

		same(
			20,
			\Animeh\Support\TmdbMapper::best_match( $results, 're zero starting life in another world', 2016 )['id']
		);
	} );

	it( 'refuses to guess when nothing resembles the title', static function (): void {
		$results = array(
			array( 'id' => 30, 'name' => 'Breaking Bad', 'first_air_date' => '2008-01-20', 'popularity' => 5000.0 ),
		);

		// The wrong poster on a card is worse than no poster: popularity alone
		// must never be enough to win a match.
		same( null, \Animeh\Support\TmdbMapper::best_match( $results, 'Mushishi', 2005 ) );
		same( null, \Animeh\Support\TmdbMapper::best_match( array(), 'Mushishi', 2005 ) );
	} );

	it( 'accepts a season that aired across New Year', static function (): void {
		$results = array(
			array( 'id' => 40, 'name' => 'Vinland Saga', 'first_air_date' => '2019-07-08' ),
		);

		same( 40, \Animeh\Support\TmdbMapper::best_match( $results, 'Vinland Saga', 2020 )['id'] );
	} );

	it( 'maps a whole season into episode rows, in order', static function (): void {
		// The shape the import walks: a season payload with its episode list.
		$season = array(
			'season_number' => 2,
			'episodes'      => array(
				array( 'season_number' => 2, 'episode_number' => 1, 'name' => 'Bir', 'still_path' => '/a.jpg', 'runtime' => 24 ),
				array( 'season_number' => 2, 'episode_number' => 2, 'name' => 'İki', 'still_path' => '', 'runtime' => 0 ),
			),
		);

		$rows = array();
		foreach ( $season['episodes'] as $entry ) {
			$rows[] = \Animeh\Support\TmdbMapper::episode( $entry );
		}

		same( 2, count( $rows ) );
		same( 1, $rows[0]['number'] );
		same( 2, $rows[0]['season_number'] );
		same( 'https://image.tmdb.org/t/p/w300/a.jpg', $rows[0]['thumbnail_url'] );
		same( 1440, $rows[0]['duration_seconds'] );

		// A still TMDB does not have stays empty rather than becoming a URL
		// that 404s, and an unknown runtime stays zero rather than guessing.
		same( 'İki', $rows[1]['title'] );
		same( '', $rows[1]['thumbnail_url'] );
		same( 0, $rows[1]['duration_seconds'] );
	} );

	it( 'reads an id out of a pasted TMDB address', static function (): void {
		$id = 96316;

		same( $id, \Animeh\Support\TmdbMapper::extract_id( '96316' ) );
		same( $id, \Animeh\Support\TmdbMapper::extract_id( '  96316  ' ) );
		same( $id, \Animeh\Support\TmdbMapper::extract_id( 'https://www.themoviedb.org/tv/96316' ) );
		same( $id, \Animeh\Support\TmdbMapper::extract_id( 'https://www.themoviedb.org/tv/96316-shijou-saikyou' ) );
		same( $id, \Animeh\Support\TmdbMapper::extract_id( 'themoviedb.org/tr/tv/96316' ) );
		same( $id, \Animeh\Support\TmdbMapper::extract_id( 'https://www.themoviedb.org/tv/96316/seasons' ) );
		same( $id, \Animeh\Support\TmdbMapper::extract_id( 'https://www.themoviedb.org/tv/96316?language=tr-TR' ) );
	} );

	it( 'treats ordinary text as a search, not an id', static function (): void {
		same( 0, \Animeh\Support\TmdbMapper::extract_id( 'Naruto' ) );
		same( 0, \Animeh\Support\TmdbMapper::extract_id( '' ) );
		same( 0, \Animeh\Support\TmdbMapper::extract_id( '86 Eighty Six' ) );

		// A movie URL is not a series id: looking it up as one would fetch
		// whichever unrelated series happens to share the number.
		same( 0, \Animeh\Support\TmdbMapper::extract_id( 'https://www.themoviedb.org/movie/96316' ) );
	} );

	it( 'carries TMDB\'s own adult flag into the mapping', static function (): void {
		same( true, \Animeh\Support\TmdbMapper::work( array( 'id' => 1, 'adult' => true ) )['adult'] );
		same( false, \Animeh\Support\TmdbMapper::work( array( 'id' => 1 ) )['adult'] );
	} );

	it( 'never numbers an imported episode zero', static function (): void {
		// The import skips these: a row with number 0 would sort before the
		// first episode and has no place in the numbering.
		same( 0, \Animeh\Support\TmdbMapper::episode( array( 'name' => 'Özel' ) )['number'] );
	} );
} );

describe( 'GenreTally', static function (): void {
	it( 'counts a genre once per work, not once per episode', static function (): void {
		$top = \Animeh\Support\GenreTally::top(
			array(
				array( 'Aksiyon', 'Aksiyon', 'Fantezi' ),
				array( 'Aksiyon' ),
			)
		);

		same( 2, count( $top ) );
		same( 'Aksiyon', $top[0]['name'] );
		same( 2, $top[0]['count'] );
		same( 'Fantezi', $top[1]['name'] );
		same( 1, $top[1]['count'] );
	} );

	it( 'breaks ties alphabetically so the wheel does not reshuffle', static function (): void {
		// Two genres on one each, given in the awkward order. Without a
		// deterministic tie-break the slices would swap between loads.
		$a = \Animeh\Support\GenreTally::top( array( array( 'Zombi' ), array( 'Aksiyon' ) ) );
		$b = \Animeh\Support\GenreTally::top( array( array( 'Aksiyon' ), array( 'Zombi' ) ) );

		same( 'Aksiyon', $a[0]['name'] );
		same( 'Aksiyon', $b[0]['name'] );
	} );

	it( 'keeps only the strongest few', static function (): void {
		$lists = array();
		foreach ( range( 1, 9 ) as $n ) {
			// Genre n appears n times, so the order is strictly decreasing.
			$lists[] = array_fill( 0, 1, 'Tur' . $n );
			for ( $i = 1; $i < $n; $i++ ) {
				$lists[] = array( 'Tur' . $n );
			}
		}

		$top = \Animeh\Support\GenreTally::top( $lists, 5 );

		same( 5, count( $top ) );
		same( 'Tur9', $top[0]['name'] );
		same( 'Tur5', $top[4]['name'] );
	} );

	it( 'survives someone who has watched nothing', static function (): void {
		same( array(), \Animeh\Support\GenreTally::top( array() ) );
		same( array(), \Animeh\Support\GenreTally::top( array( array(), array( '' ), array( '  ' ) ) ) );
	} );
} );

describe( 'ServiceAccountJwt', static function (): void {
	it( 'encodes base64 the way a URL wants it', static function (): void {
		// The two characters standard base64 uses that a URL cannot carry,
		// and the padding a JWT never has.
		same( 'Pz8_Pw', \Animeh\Support\ServiceAccountJwt::base64url( "\x3f\x3f\x3f\x3f" ) );
		same( '_-8', \Animeh\Support\ServiceAccountJwt::base64url( "\xff\xef" ) );
		same( '', \Animeh\Support\ServiceAccountJwt::base64url( '' ) );
	} );

	it( 'builds the claims Google checks', static function (): void {
		$payload = \Animeh\Support\ServiceAccountJwt::payload( 'robot@animeh.iam.gserviceaccount.com', 1000 );
		$parts   = explode( '.', $payload );

		same( 2, count( $parts ) );

		$decode = static fn ( string $p ): array => json_decode(
			base64_decode( strtr( $p, '-_', '+/' ) ),
			true
		);

		$header = $decode( $parts[0] );
		$claims = $decode( $parts[1] );

		same( 'RS256', $header['alg'] );
		same( 'robot@animeh.iam.gserviceaccount.com', $claims['iss'] );
		same( \Animeh\Support\ServiceAccountJwt::TOKEN_URL, $claims['aud'] );
		same( 1000, $claims['iat'] );
		same( 4600, $claims['exp'] );
	} );

	it( 'signs with the account key and verifies against its public half', static function (): void {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			skip( 'openssl yok' );
		}

		$pair = openssl_pkey_new(
			array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA )
		);

		if ( false === $pair ) {
			skip( 'anahtar üretilemedi' );
		}

		openssl_pkey_export( $pair, $private );
		$public = openssl_pkey_get_details( $pair )['key'];

		$payload   = \Animeh\Support\ServiceAccountJwt::payload( 'robot@example.com', 1000 );
		$assertion = \Animeh\Support\ServiceAccountJwt::sign( $payload, $private );

		ok( null !== $assertion );

		$parts     = explode( '.', (string) $assertion );
		same( 3, count( $parts ) );

		$signature = base64_decode( strtr( $parts[2], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[2] ) % 4 ) % 4 ) );

		same( 1, openssl_verify( $payload, $signature, $public, OPENSSL_ALGO_SHA256 ) );
	} );

	it( 'accepts a key whose newlines survived a form field as backslash-n', static function (): void {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			skip( 'openssl yok' );
		}

		$pair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		if ( false === $pair ) {
			skip( 'anahtar üretilemedi' );
		}

		openssl_pkey_export( $pair, $private );

		// What a copy-paste through a textarea or a JSON string does to a PEM.
		$mangled = str_replace( "\n", '\\n', $private );

		ok( null !== \Animeh\Support\ServiceAccountJwt::sign( 'payload', $mangled ) );
	} );

	it( 'refuses an unusable key rather than signing nonsense', static function (): void {
		same( null, \Animeh\Support\ServiceAccountJwt::sign( 'payload', 'not a key' ) );
	} );

	it( 'pulls the three fields that matter out of the key file', static function (): void {
		$json = json_encode(
			array(
				'type'         => 'service_account',
				'project_id'   => 'animeh-1234',
				'client_email' => 'robot@animeh.iam.gserviceaccount.com',
				'private_key'  => '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n',
				'extra'        => 'ignored',
			)
		);

		$parsed = \Animeh\Support\ServiceAccountJwt::parse( (string) $json );

		same( 'animeh-1234', $parsed['project_id'] );
		same( 'robot@animeh.iam.gserviceaccount.com', $parsed['client_email'] );

		// Anything that is not the key file is refused, so a half-pasted
		// value cannot be saved and then fail silently at send time.
		same( null, \Animeh\Support\ServiceAccountJwt::parse( 'nope' ) );
		same( null, \Animeh\Support\ServiceAccountJwt::parse( '{"project_id":"x"}' ) );
	} );
} );

describe( 'AppLinks', static function (): void {
	it( 'accepts a fingerprint in either of the forms tools print it', static function (): void {
		$colons = 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89';

		// keytool prints the colon form in upper case; apksigner prints the
		// same bytes as plain lower-case hex. Both are the same certificate,
		// and being told one of them is invalid is an hour nobody gets back.
		same( $colons, \Animeh\Rest\AppLinks::normalise( $colons ) );
		same( $colons, \Animeh\Rest\AppLinks::normalise( strtolower( str_replace( ':', '', $colons ) ) ) );
		same( $colons, \Animeh\Rest\AppLinks::normalise( '  ' . strtolower( $colons ) . "\n" ) );
	} );

	it( 'refuses anything that is not a SHA-256 fingerprint', static function (): void {
		// A SHA-1 line pasted from the same keytool output. Android would
		// fetch the file, parse it and reject it, which looks exactly like
		// the setting having done nothing.
		same( '', \Animeh\Rest\AppLinks::normalise( 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01' ) );
		same( '', \Animeh\Rest\AppLinks::normalise( '' ) );
		same( '', \Animeh\Rest\AppLinks::normalise( 'SHA-256:' ) );
	} );

	it( 'builds the statement list Android verifies against', static function (): void {
		$statements = \Animeh\Rest\AppLinks::statements( array( 'AA:BB' ) );

		same( 1, count( $statements ) );
		same( 'delegate_permission/common.handle_all_urls', $statements[0]['relation'][0] );
		same( 'android_app', $statements[0]['target']['namespace'] );
		same( 'com.animeh.app', $statements[0]['target']['package_name'] );
		same( array( 'AA:BB' ), $statements[0]['target']['sha256_cert_fingerprints'] );

		// A list, not an object: json_encode turns a gapped array into the
		// latter, and Android's verifier refuses the file outright.
		$encoded = json_encode( $statements );
		ok( str_starts_with( (string) $encoded, '[' ) );
	} );
} );

describe( 'FontMatch', static function (): void {
	$score = static fn( string $a, string $b ): int => \Animeh\Support\FontMatch::score( $a, $b );

	it( 'treats one name written three ways as one font', static function () use ( $score ): void {
		same( 100, $score( 'DejaVu Sans', 'dejavu sans' ) );
		same( 100, $score( 'DejaVuSans', 'DejaVu Sans' ) );
		same( 100, $score( 'dejavu-sans', 'DejaVu Sans' ) );
		same( 100, $score( '@Yu Gothic', 'Yu Gothic' ) );
	} );

	it( 'answers a weight from the family it belongs to', static function () use ( $score ): void {
		// A script naming a face inside a family is asking for the family; the
		// renderer emboldens. Refusing over the word "Bold" helps nobody.
		same( 90, $score( 'Arial Bold', 'Arial' ) );
		same( 90, $score( 'Arial', 'Arial Bold Italic' ) );
		same( 90, $score( 'Roboto Condensed Light', 'Roboto Condensed' ) );
	} );

	it( 'answers a short name from a longer file', static function () use ( $score ): void {
		// The reported case: the script asks for Sans, the file to hand is
		// sans-test.ttf, whose name table says "Sans Test".
		ok( $score( 'Sans', 'Sans Test' ) > 0 );
		ok( $score( 'Sans Test', 'Sans' ) > 0 );
		ok( $score( 'Animeh Gothic', 'Animeh Gothic Extra' ) > 0 );
	} );

	it( 'never substitutes a different family', static function () use ( $score ): void {
		// The rule that makes the rest of this safe: a wrong typeface renders
		// at the wrong metrics and breaks the typesetting silently, which is
		// worse than a font somebody can see is missing.
		same( 0, $score( 'Sans', 'Comic Sans' ) );
		same( 0, $score( 'Gothic', 'Century Gothic' ) );
		same( 0, $score( 'Arial', 'Helvetica' ) );
		same( 0, $score( 'Sans', '' ) );
	} );

	it( 'prefers the closest family when several answer', static function (): void {
		$candidates = array(
			array( 'family' => 'Sans Test Extra' ),
			array( 'family' => 'Sans' ),
			array( 'family' => 'Comic Sans' ),
		);

		$best = \Animeh\Support\FontMatch::best( 'Sans', $candidates );
		same( 'Sans', $best['family'] );

		$exact = \Animeh\Support\FontMatch::best( 'Sans Test Extra', $candidates );
		same( 'Sans Test Extra', $exact['family'] );

		same( null, \Animeh\Support\FontMatch::best( 'Nothing Like It', $candidates ) );
	} );

	it( 'keeps a family that is only made of style words', static function (): void {
		// "Black" is a real family name. Stripping it would leave nothing,
		// and a font with no words matches everything.
		same( array( 'black' ), \Animeh\Support\FontMatch::base( 'Black' ) );
		same( 100, \Animeh\Support\FontMatch::score( 'Black', 'black' ) );
	} );
} );

describe( 'ImageResizer', static function (): void {
	$fit = static fn( int $w, int $h, string $role ): array =>
		\Animeh\Support\ImageResizer::fit(
			$w,
			$h,
			\Animeh\Support\ImageResizer::role( $role )['width'],
			\Animeh\Support\ImageResizer::role( $role )['height']
		);

	it( 'scales a poster into its box and keeps its shape', static function () use ( $fit ): void {
		// A 2:3 poster is exactly the box's shape, so both edges land.
		same( array( 500, 750 ), $fit( 2000, 3000, 'poster' ) );
		// A squarer one is limited by its height, not its width.
		same( array( 500, 625 ), $fit( 1600, 2000, 'poster' ) );
		// And a very wide one by its width.
		same( array( 1280, 540 ), $fit( 2560, 1080, 'banner' ) );
	} );

	it( 'never enlarges what is already small', static function () use ( $fit ): void {
		// Scaling up produces a bigger file that looks worse; there is no
		// version of that which is an optimisation.
		same( array( 300, 450 ), $fit( 300, 450, 'poster' ) );
		same( array( 1, 1 ), $fit( 1, 1, 'banner' ) );
		same( array( 0, 0 ), $fit( 0, 0, 'poster' ) );
	} );

	it( 'reads the format from the bytes, not the name', static function (): void {
		$format = static fn( string $b ): string => \Animeh\Support\ImageResizer::format( $b );

		same( 'jpeg', $format( "\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 16 ) ) );
		same( 'png', $format( "\x89PNG\r\n\x1A\n" . str_repeat( "\x00", 16 ) ) );
		same( 'gif', $format( 'GIF89a' . str_repeat( "\x00", 16 ) ) );
		same( 'webp', $format( 'RIFF' . '1234' . 'WEBP' . str_repeat( "\x00", 16 ) ) );
		// An HTML error page served with an image URL is the common case.
		same( '', $format( '<!doctype html><html>404' . str_repeat( ' ', 16 ) ) );
		same( '', $format( 'short' ) );
	} );

	it( 'leaves alone what is already the right size', static function (): void {
		$should = static fn( int $w, int $h, int $len, string $role ): bool =>
			\Animeh\Support\ImageResizer::should_shrink( $w, $h, $len, $role );

		// A TMDB w500 poster: right shape, small file, nothing to gain.
		ok( ! $should( 500, 750, 60 * 1024, 'poster' ) );

		// Right shape, wrong weight — a PNG export of the same picture.
		ok( $should( 500, 750, 3 * 1024 * 1024, 'poster' ) );

		// Wrong shape, small file — a key visual straight off a fan site.
		ok( $should( 2000, 3000, 90 * 1024, 'poster' ) );
	} );

	it( 'refuses an image it could not hold', static function (): void {
		$fits = static fn( int $w, int $h, int $limit ): bool =>
			\Animeh\Support\ImageResizer::fits_in_memory( $w, $h, $limit, 0 );

		// Running out of memory in PHP is fatal and uncatchable, so the
		// answer has to be worked out before the decoder is handed anything.
		ok( $fits( 2000, 3000, 256 * 1024 * 1024 ) );
		ok( ! $fits( 8000, 8000, 256 * 1024 * 1024 ) );

		// No limit at all is the one unconditional yes, up to the ceiling
		// that exists so a decompression bomb still gets refused.
		ok( $fits( 6000, 6000, -1 ) );
		ok( ! $fits( 9000, 9000, -1 ) );
	} );

	it( 'parses the shorthand PHP writes memory limits in', static function (): void {
		$bytes = static fn( string $v ): int => \Animeh\Support\ImageResizer::bytes( $v );

		same( 256 * 1024 * 1024, $bytes( '256M' ) );
		same( 2 * 1024 * 1024 * 1024, $bytes( '2G' ) );
		same( 512 * 1024, $bytes( '512K' ) );
		same( 1024, $bytes( '1024' ) );
	} );

	it( 'shrinks a real image and stops when there is nothing left to do', static function (): void {
		if ( ! \Animeh\Support\ImageResizer::available() ) {
			skip( 'GD yok' );
			return;
		}

		// Built here rather than committed: the point is the round trip
		// through GD, and a fixture would only be a slower way to do it.
		$image = imagecreatetruecolor( 2400, 3600 );
		for ( $y = 0; $y < 3600; $y += 40 ) {
			$colour = imagecolorallocate( $image, (int) ( $y / 14 ) % 256, 120, 200 );
			imagefilledrectangle( $image, 0, $y, 2400, $y + 39, $colour );
		}
		ob_start();
		imagepng( $image );
		$source = (string) ob_get_clean();
		imagedestroy( $image );

		$smaller = \Animeh\Support\ImageResizer::shrink( $source, 'poster' );
		ok( null !== $smaller );

		$size = getimagesizefromstring( (string) $smaller );
		same( 500, (int) $size[0] );
		same( 750, (int) $size[1] );
		same( 'jpeg', \Animeh\Support\ImageResizer::format( (string) $smaller ) );
		ok( strlen( (string) $smaller ) < strlen( $source ) );

		// Run again on its own output: already within the box and under the
		// budget, so it is left exactly as it is.
		same( null, \Animeh\Support\ImageResizer::shrink( (string) $smaller, 'poster' ) );
	} );
} );

describe( 'Points', function (): void {
	it( 'bir bölüm için sabit ücret', function (): void {
		same( 20, \Animeh\Support\Points::PER_EPISODE );
	} );

	it( 'ödeme anahtarları bölüme ve çerçeveye göre ayrı', function (): void {
		same( 'episode:412', \Animeh\Support\Points::episode_key( 412 ) );
		same( 'frame:7', \Animeh\Support\Points::frame_key( 7 ) );
		// Aynı sayı, farklı anahtar: 7 numaralı bölümün ödemesi 7 numaralı
		// çerçevenin satın alımını engellememeli.
		ok( \Animeh\Support\Points::episode_key( 7 ) !== \Animeh\Support\Points::frame_key( 7 ) );
	} );

	it( 'bakiye kontrolü sınırda geçer, altında geçmez', function (): void {
		ok( \Animeh\Support\Points::affordable( 200, 200 ) );
		ok( \Animeh\Support\Points::affordable( 201, 200 ) );
		ok( ! \Animeh\Support\Points::affordable( 199, 200 ) );
		// Bedava bir şey her bakiyeyle alınabilir.
		ok( \Animeh\Support\Points::affordable( 0, 0 ) );
		// Negatif fiyat bir hediye değil, bir hata.
		ok( ! \Animeh\Support\Points::affordable( 1000, -5 ) );
	} );

	it( 'hediye tavanı iki yönde de tutuyor', function (): void {
		same( 500, \Animeh\Support\Points::clamp_grant( 500 ) );
		same( 100000, \Animeh\Support\Points::clamp_grant( 99999999 ) );
		same( -100000, \Animeh\Support\Points::clamp_grant( -99999999 ) );
		// Sıfır bir işlem değil.
		same( 0, \Animeh\Support\Points::clamp_grant( 0 ) );
	} );
} );

describe( 'ProfileTheme', function (): void {
	it( 'paletteki her slug geçerli', function (): void {
		foreach ( \Animeh\Support\ProfileTheme::THEMES as $theme ) {
			ok( \Animeh\Support\ProfileTheme::valid( $theme ), $theme );
		}
		same( 12, count( \Animeh\Support\ProfileTheme::THEMES ) );
	} );

	it( 'varsayılan paletin içinde', function (): void {
		ok( \Animeh\Support\ProfileTheme::valid( \Animeh\Support\ProfileTheme::DEFAULT_THEME ) );
	} );

	it( 'tanımadığı her şey varsayılana düşer', function (): void {
		$default = \Animeh\Support\ProfileTheme::DEFAULT_THEME;
		same( $default, \Animeh\Support\ProfileTheme::normalise( '' ) );
		same( $default, \Animeh\Support\ProfileTheme::normalise( null ) );
		same( $default, \Animeh\Support\ProfileTheme::normalise( array( 'ocean' ) ) );
		same( $default, \Animeh\Support\ProfileTheme::normalise( '<script>' ) );
		same( $default, \Animeh\Support\ProfileTheme::normalise( '#ff0000' ) );
		// Büyük harf ve boşluk bir hata değil, bir yazım.
		same( 'ocean', \Animeh\Support\ProfileTheme::normalise( '  OCEAN ' ) );
	} );
} );

describe( 'FrameFile', function (): void {
	it( 'çöp veriyi reddeder', function (): void {
		same( null, \Animeh\Support\FrameFile::inspect( '' ) );
		same( null, \Animeh\Support\FrameFile::inspect( 'merhaba dünya, bu bir resim değil' ) );
		// Uzantısı doğru olsa bile içeriği yanlışsa geçmez.
		same( null, \Animeh\Support\FrameFile::inspect( str_repeat( "\x00", 4096 ) ) );
	} );

	it( 'PNG ölçülerini IHDR başlığından okur', function (): void {
		$png = png_bytes( 288, 288 );
		$info = \Animeh\Support\FrameFile::inspect( $png );
		ok( null !== $info );
		same( 288, $info['width'] );
		same( 288, $info['height'] );
		same( false, $info['animated'] );
		same( 'png', $info['format'] );
		same( '', \Animeh\Support\FrameFile::rejection( $info ) );
	} );

	it( 'acTL varsa APNG, IDAT’tan sonraysa değil', function (): void {
		$png = png_bytes( 288, 288 );

		// IHDR ile IDAT arasına bir animasyon kontrol bloğu: APNG.
		$at       = strpos( $png, 'IDAT' ) - 4;
		$animated = substr( $png, 0, $at ) . "\x00\x00\x00\x08acTL" . substr( $png, $at );
		$info     = \Animeh\Support\FrameFile::inspect( $animated );
		same( 'apng', $info['format'] );
		same( true, $info['animated'] );

		// Aynı blok sonda: hiçbir görüntüleyici oynatmaz, biz de saymayız.
		$info = \Animeh\Support\FrameFile::inspect( $png . 'acTL' );
		same( 'png', $info['format'] );
		same( false, $info['animated'] );
	} );

	it( 'tek kareli ve çok kareli GIF’i ayırır', function (): void {
		$single = gif_bytes( 288, 288, 1 );
		$info   = \Animeh\Support\FrameFile::inspect( $single );
		same( 'gif', $info['format'] );
		same( 288, $info['width'] );
		same( false, $info['animated'] );

		$many = gif_bytes( 288, 288, 3 );
		$info = \Animeh\Support\FrameFile::inspect( $many );
		same( true, $info['animated'] );
	} );

	it( 'animasyonlu WebP’i VP8X bayrağı ve ANMF ile tanır', function (): void {
		$still = webp_vp8x_bytes( 288, 288, false );
		$info  = \Animeh\Support\FrameFile::inspect( $still );
		same( 'webp', $info['format'] );
		same( 288, $info['width'] );
		same( 288, $info['height'] );
		same( false, $info['animated'] );

		$moving = webp_vp8x_bytes( 288, 288, true );
		$info   = \Animeh\Support\FrameFile::inspect( $moving );
		same( 'webp-animated', $info['format'] );
		same( true, $info['animated'] );
	} );

	it( 'kare olmayanı ve ölçüsü tutmayanı gerekçesiyle reddeder', function (): void {
		$wide = \Animeh\Support\FrameFile::inspect( png_bytes( 288, 144 ) );
		ok( '' !== \Animeh\Support\FrameFile::rejection( $wide ) );

		$tiny = \Animeh\Support\FrameFile::inspect( png_bytes( 32, 32 ) );
		ok( '' !== \Animeh\Support\FrameFile::rejection( $tiny ) );

		$huge = \Animeh\Support\FrameFile::inspect( png_bytes( 2048, 2048 ) );
		ok( '' !== \Animeh\Support\FrameFile::rejection( $huge ) );

		// Sınırların kendisi kabul: 64 ve 1024 dışarıda değil, içeride.
		same( '', \Animeh\Support\FrameFile::rejection( \Animeh\Support\FrameFile::inspect( png_bytes( 64, 64 ) ) ) );
		same( '', \Animeh\Support\FrameFile::rejection( \Animeh\Support\FrameFile::inspect( png_bytes( 1024, 1024 ) ) ) );
	} );
} );

describe( 'Leaderboard', function (): void {
	it( 'sıra numarası eşitlikte paylaşılır, sonrası atlar', function (): void {
		$ranked = \Animeh\Storage\LeaderboardRepository::rank(
			array(
				array( 'user_id' => 1, 'value' => 90 ),
				array( 'user_id' => 2, 'value' => 40 ),
				array( 'user_id' => 3, 'value' => 40 ),
				array( 'user_id' => 4, 'value' => 10 ),
			)
		);

		same( 1, $ranked[0]['rank'] );
		same( 2, $ranked[1]['rank'] );
		same( 2, $ranked[2]['rank'] );
		// İki kişi ikinciyse üçüncü yoktur.
		same( 4, $ranked[3]['rank'] );
	} );

	it( 'boş tablo boş liste', function (): void {
		same( array(), \Animeh\Storage\LeaderboardRepository::rank( array() ) );
	} );

	it( 'yalnızca bilinen üç ölçüt kabul edilir', function (): void {
		ok( \Animeh\Storage\LeaderboardRepository::valid( 'works' ) );
		ok( \Animeh\Storage\LeaderboardRepository::valid( 'seconds' ) );
		ok( \Animeh\Storage\LeaderboardRepository::valid( 'episodes' ) );
		// SQL’e giden tek şey bu isim olduğu için, listede olmayan hiçbir
		// şeyin geçmemesi bir güvenlik kontrolü.
		ok( ! \Animeh\Storage\LeaderboardRepository::valid( 'value; DROP TABLE' ) );
		ok( ! \Animeh\Storage\LeaderboardRepository::valid( '' ) );
	} );
} );

describe( 'B2Url', function (): void {
	it( 'iki adresi de kurar', function (): void {
		same(
			'https://f005.backblazeb2.com/file/animeh/anime/naruto/chapter-00105/001.webp',
			\Animeh\Support\B2Url::friendly( 'https://f005.backblazeb2.com', 'animeh', 'anime/naruto/chapter-00105/001.webp' )
		);
		same(
			'https://animeh.s3.us-west-004.backblazeb2.com/anime/naruto/chapter-00105/001.webp',
			\Animeh\Support\B2Url::s3( 's3.us-west-004.backblazeb2.com', 'animeh', 'anime/naruto/chapter-00105/001.webp' )
		);
	} );

	it( 'S3 endpointinde şema varsa temizler', function (): void {
		same(
			'https://kova.s3.eu-central-003.backblazeb2.com/a/b.jpg',
			\Animeh\Support\B2Url::s3( 'https://s3.eu-central-003.backblazeb2.com/', 'kova', 'a/b.jpg' )
		);
	} );

	it( 'eğik çizgileri kodlamaz, boşluğu kodlar', function (): void {
		$url = \Animeh\Support\B2Url::friendly( 'https://f005.backblazeb2.com', 'k', 'a b/c d.jpg' );
		// Klasör ayracı ayraç kalmalı; olmazsa adı içinde eğik çizgi olan
		// bambaşka bir nesne adreslenir.
		ok( str_contains( $url, '/a%20b/c%20d.jpg' ), $url );
		ok( ! str_contains( $url, '%2F' ), $url );
	} );

	it( 'friendly adresi parçalarına ayırır', function (): void {
		$parts = \Animeh\Support\B2Url::parse_friendly(
			'https://f004.backblazeb2.com/file/manga-images/manga/12/chapter_1_abc/003.webp'
		);
		same( 'manga-images', $parts['bucket'] );
		same( 'manga/12/chapter_1_abc/003.webp', $parts['key'] );
	} );

	it( 'sorgu dizesini anahtarın parçası saymaz', function (): void {
		$parts = \Animeh\Support\B2Url::parse_friendly(
			'https://f004.backblazeb2.com/file/kova/a/b.jpg?Authorization=xyz'
		);
		same( 'a/b.jpg', $parts['key'] );
	} );

	it( 'friendly olmayanı ayrıştırmaz', function (): void {
		same( null, \Animeh\Support\B2Url::parse_friendly( 'https://kova.s3.x.backblazeb2.com/a/b.jpg' ) );
		same( null, \Animeh\Support\B2Url::parse_friendly( 'https://site.com/wp-content/uploads/a.jpg' ) );
	} );

	it( 'friendly bozulursa S3 karşılığını verir', function (): void {
		same(
			'https://manga-images.s3.us-east-005.backblazeb2.com/manga/12/ch1/003.webp',
			\Animeh\Support\B2Url::alternate(
				'https://f004.backblazeb2.com/file/manga-images/manga/12/ch1/003.webp',
				's3.us-east-005.backblazeb2.com'
			)
		);
		// Endpoint bilinmiyorsa uydurmaz.
		same( '', \Animeh\Support\B2Url::alternate( 'https://f004.backblazeb2.com/file/k/a.jpg', '' ) );
		// Zaten S3 ise verilecek ikinci adres yok — friendly host hesaba özel
		// ve adreste yazmıyor.
		same( '', \Animeh\Support\B2Url::alternate( 'https://k.s3.x.backblazeb2.com/a.jpg', 's3.x.backblazeb2.com' ) );
	} );

	it( 'eksik parçayla adres kurmaz', function (): void {
		same( '', \Animeh\Support\B2Url::friendly( '', 'k', 'a.jpg' ) );
		same( '', \Animeh\Support\B2Url::friendly( 'https://h', '', 'a.jpg' ) );
		same( '', \Animeh\Support\B2Url::s3( 's3.x', 'k', '' ) );
	} );
} );

describe( 'ChapterNumber', function (): void {
	it( 'sayıyı olduğu gibi okur', function (): void {
		same( 10.0, \Animeh\Support\ChapterNumber::parse( 10 ) );
		same( 10.5, \Animeh\Support\ChapterNumber::parse( 10.5 ) );
		same( 10.5, \Animeh\Support\ChapterNumber::parse( '10.5' ) );
	} );

	it( 'virgüllü yazımı da kabul eder', function (): void {
		same( 10.5, \Animeh\Support\ChapterNumber::parse( '10,5' ) );
	} );

	it( 'başlığın içindeki sayıyı bulur', function (): void {
		same( 12.0, \Animeh\Support\ChapterNumber::parse( 'Bölüm 12' ) );
		same( 10.5, \Animeh\Support\ChapterNumber::parse( 'Chapter 10.5 - Ekstra' ) );
	} );

	it( 'sayı yoksa sıfır', function (): void {
		same( 0.0, \Animeh\Support\ChapterNumber::parse( 'Son Bölüm' ) );
		same( 0.0, \Animeh\Support\ChapterNumber::parse( '' ) );
		same( 0.0, \Animeh\Support\ChapterNumber::parse( null ) );
	} );

	it( 'etiket gereksiz sıfır taşımaz', function (): void {
		same( '10', \Animeh\Support\ChapterNumber::label( 10 ) );
		same( '10', \Animeh\Support\ChapterNumber::label( 10.0 ) );
		same( '10.5', \Animeh\Support\ChapterNumber::label( 10.5 ) );
		same( '10.25', \Animeh\Support\ChapterNumber::label( 10.25 ) );
	} );

	it( 'tam kısım eski istemciler için bozulmaz', function (): void {
		same( 10, \Animeh\Support\ChapterNumber::whole( 10.5 ) );
		same( 10, \Animeh\Support\ChapterNumber::whole( '10' ) );
	} );

	it( '10.5 ile 10 aynı bölüm değil', function (): void {
		ok( \Animeh\Support\ChapterNumber::parse( '10.5' ) !== \Animeh\Support\ChapterNumber::parse( '10' ) );
		same( 1, \Animeh\Support\ChapterNumber::compare( '10.5', '10' ) );
		same( -1, \Animeh\Support\ChapterNumber::compare( '9.9', '10' ) );
		same( 0, \Animeh\Support\ChapterNumber::compare( '10', 10.0 ) );
	} );
} );

describe( 'Points: bölüm ile chapter aynı değil', function (): void {
	it( 'anime bölümü 20, manga bölümü 10', function (): void {
		same( 20, \Animeh\Support\Points::per_finish( 'anime' ) );
		same( 10, \Animeh\Support\Points::per_finish( 'manga' ) );
	} );

	it( 'bilinmeyen tür animeye düşer', function (): void {
		same( 20, \Animeh\Support\Points::per_finish( '' ) );
		same( 20, \Animeh\Support\Points::per_finish( 'kitap' ) );
	} );

	it( 'sabitlerle uyumlu', function (): void {
		same( \Animeh\Support\Points::PER_EPISODE, \Animeh\Support\Points::per_finish( 'anime' ) );
		same( \Animeh\Support\Points::PER_CHAPTER, \Animeh\Support\Points::per_finish( 'manga' ) );
	} );

	it( 'okuma kendi sebebiyle yazılıyor', function (): void {
		ok( in_array( \Animeh\Support\Points::REASON_CHAPTER, \Animeh\Support\Points::REASONS, true ) );
		ok( \Animeh\Support\Points::REASON_CHAPTER !== \Animeh\Support\Points::REASON_EPISODE );
	} );
} );

describe( 'PageOrder', function (): void {
	it( 'sayfaları isimdeki sayıya göre sıralar', function (): void {
		same(
			array( '1.jpg', '2.jpg', '10.jpg' ),
			\Animeh\Support\PageOrder::pages( array( '10.jpg', '1.jpg', '2.jpg' ) )
		);
	} );

	it( 'metin sıralaması olsaydı 10 ikinci gelirdi', function (): void {
		$names = array( '1.jpg', '10.jpg', '2.jpg' );
		sort( $names );
		same( array( '1.jpg', '10.jpg', '2.jpg' ), $names );
		same(
			array( '1.jpg', '2.jpg', '10.jpg' ),
			\Animeh\Support\PageOrder::pages( $names )
		);
	} );

	it( 'sıfırlı ve önekli adlar da aynı yere düşer', function (): void {
		same(
			array( '01.webp', 'page_2.webp', 'sayfa-10.webp' ),
			\Animeh\Support\PageOrder::pages( array( 'sayfa-10.webp', '01.webp', 'page_2.webp' ) )
		);
	} );

	it( 'zip içindeki çöpü almaz', function (): void {
		same(
			array( '1.jpg' ),
			\Animeh\Support\PageOrder::pages(
				array( '1.jpg', 'notes.txt', '__MACOSX/._1.jpg', '.DS_Store', 'bolum/' )
			)
		);
	} );

	it( 'numarasız adlar sona gider', function (): void {
		same(
			array( '1.jpg', '2.jpg', 'kapak.jpg' ),
			\Animeh\Support\PageOrder::pages( array( 'kapak.jpg', '2.jpg', '1.jpg' ) )
		);
	} );

	it( 'klasör adındaki sayı sayfanın sırasını belirlemez', function (): void {
		same(
			array( 'bolum-12/1.jpg', 'bolum-12/2.jpg' ),
			\Animeh\Support\PageOrder::pages( array( 'bolum-12/2.jpg', 'bolum-12/1.jpg' ) )
		);
	} );
} );

describe( 'StorageKey manga düzeni', function (): void {
	it( 'manga kendi kökünde', function (): void {
		same( 'manga/amai-tsuyu', \Animeh\Support\StorageKey::manga_prefix( 'amai-tsuyu' ) );
		same( 'manga/amai-tsuyu/bolum-0001', \Animeh\Support\StorageKey::chapter_prefix( 'amai-tsuyu', 1.0 ) );
	} );

	it( 'yarım bölüm okunur kalıyor', function (): void {
		same( 'manga/x/bolum-0010.5', \Animeh\Support\StorageKey::chapter_prefix( 'x', 10.5 ) );
		same( 'manga/x/bolum-0010.25', \Animeh\Support\StorageKey::chapter_prefix( 'x', 10.25 ) );
	} );

	it( 'konsolda 10 ile 2 arasında sıra bozulmuyor', function (): void {
		$keys = array(
			\Animeh\Support\StorageKey::chapter_prefix( 'x', 10.0 ),
			\Animeh\Support\StorageKey::chapter_prefix( 'x', 2.0 ),
			\Animeh\Support\StorageKey::chapter_prefix( 'x', 1.0 ),
		);
		sort( $keys );
		same(
			array( 'manga/x/bolum-0001', 'manga/x/bolum-0002', 'manga/x/bolum-0010' ),
			$keys
		);
	} );

	it( 'sayfa adı sırayı taşıyor, uzantı korunuyor', function (): void {
		same( 'manga/x/bolum-0001/007.webp', \Animeh\Support\StorageKey::chapter_page( 'x', 1.0, 7, 'page_7.WEBP' ) );
		same( 'manga/x/bolum-0001/001.jpg', \Animeh\Support\StorageKey::chapter_page( 'x', 1.0, 1, 'nosuffix' ) );
	} );

	it( 'anime kökü karışmıyor', function (): void {
		ok( ! str_starts_with( \Animeh\Support\StorageKey::chapter_prefix( 'x', 1.0 ), 'anime/' ) );
		ok( str_starts_with( \Animeh\Support\StorageKey::episode_prefix( 'x', 1, 1 ), 'anime/' ) );
	} );
} );

describe( 'GalleryRef', function (): void {
	it( 'numarayı okur', function (): void {
		same( 177013, \Animeh\Support\GalleryRef::id( '177013' ) );
		same( 177013, \Animeh\Support\GalleryRef::id( ' 177013 ' ) );
		same( 177013, \Animeh\Support\GalleryRef::id( '#177013' ) );
	} );

	it( 'yapıştırılan adresten çıkarır', function (): void {
		same( 177013, \Animeh\Support\GalleryRef::id( 'https://nhentai.net/g/177013/' ) );
		same( 177013, \Animeh\Support\GalleryRef::id( 'https://nhentai.net/g/177013/12/' ) );
		same( 177013, \Animeh\Support\GalleryRef::id( 'g/177013' ) );
	} );

	it( 'isim numara değildir', function (): void {
		same( 0, \Animeh\Support\GalleryRef::id( 'bir manga adı' ) );
		same( 0, \Animeh\Support\GalleryRef::id( '' ) );
		// Bir kelimenin içindeki sayı bir referans değil.
		same( 0, \Animeh\Support\GalleryRef::id( 'chapter12' ) );
		same( 0, \Animeh\Support\GalleryRef::id( 'https://nhentai.net/search/?q=aaa' ) );
	} );

	it( 'numara mı diye sorulabilir', function (): void {
		ok( \Animeh\Support\GalleryRef::looks_like_id( '177013' ) );
		ok( ! \Animeh\Support\GalleryRef::looks_like_id( 'bir isim' ) );
	} );
} );

describe( 'MangaMapper', function (): void {
	it( 'Jikan/Tenrai mangasını katalog şekline çevirir', function (): void {
		$mapped = \Animeh\Support\MangaMapper::from_jikan(
			array(
				'mal_id'         => 13,
				'title'          => 'One Piece',
				'title_english'  => 'One Piece',
				'title_japanese' => 'ONE PIECE',
				'title_synonyms' => array( 'OP' ),
				'synopsis'       => 'Deniz.',
				'images'         => array( 'webp' => array( 'large_image_url' => 'https://cdn/op.webp' ) ),
				'score'          => 9.22,
				'members'        => 500000,
				'status'         => 'Publishing',
				'type'           => 'Manga',
				'chapters'       => 1100,
				'published'      => array( 'from' => '1997-07-22T00:00:00+00:00' ),
				'authors'        => array( array( 'name' => 'Oda, Eiichiro' ) ),
				'serializations' => array( array( 'name' => 'Shounen Jump (Weekly)' ) ),
				'genres'         => array( array( 'name' => 'Action' ), array( 'name' => 'Adventure' ) ),
			)
		);

		same( 'manga', $mapped['kind'] );
		same( 13, $mapped['mal_id'] );
		same( 'One Piece', $mapped['title'] );
		same( 1997, $mapped['year'] );
		same( 'airing', $mapped['status'] );
		same( 'Oda, Eiichiro', $mapped['author'] );
		same( 'Shounen Jump (Weekly)', $mapped['studio'] );
		same( array( 'Action', 'Adventure' ), $mapped['genres'] );
		same( 1100, $mapped['total_episodes'] );
		same( false, $mapped['adult'] );
	} );

	it( 'açık içerik türü yetişkin bayrağını kaldırır', function (): void {
		$mapped = \Animeh\Support\MangaMapper::from_jikan(
			array( 'mal_id' => 1, 'title' => 'X', 'genres' => array( array( 'name' => 'Hentai' ) ) )
		);
		same( true, $mapped['adult'] );
	} );

	it( 'galeri kaynağından geleni her zaman yetişkin sayar', function (): void {
		$mapped = \Animeh\Support\MangaMapper::from_gallery(
			array(
				'id'          => 177013,
				'title'       => array( 'english' => '[Circle] Ad', 'japanese' => 'タイトル', 'pretty' => 'Ad' ),
				'cover_image' => 'https://cdn/1/cover.jpg',
				'num_pages'   => 20,
				'upload_date' => 1500000000,
				'tags'        => array(
					array( 'type' => 'artist', 'name' => 'çizer' ),
					array( 'type' => 'group', 'name' => 'grup' ),
					array( 'type' => 'tag', 'name' => 'etiket' ),
					array( 'type' => 'language', 'name' => 'japanese' ),
				),
			)
		);

		same( true, $mapped['adult'] );
		same( 177013, $mapped['nh_id'] );
		// Süslü başlık tercih edilir: İngilizce olan zaten etiketlerde duran
		// çember ve dili parantez içinde tekrar ediyor.
		same( 'Ad', $mapped['title'] );
		same( 'çizer', $mapped['author'] );
		same( 'grup', $mapped['studio'] );
		same( array( 'etiket' ), $mapped['genres'] );
		same( 2017, $mapped['year'] );
	} );

	it( 'galeri etiketleri düz listede de gelse ayrışır', function (): void {
		$mapped = \Animeh\Support\MangaMapper::from_gallery(
			array(
				'id'         => 5,
				'title'      => array( 'pretty' => 'Ad' ),
				'taxonomies' => array(
					'manga_artist' => array( 'a1', 'a1', 'a2' ),
					'manga_tag'    => array( 't1' ),
				),
			)
		);

		// Tekrarlar teke iner.
		same( 'a1, a2', $mapped['author'] );
		same( array( 't1' ), $mapped['genres'] );
	} );

	it( 'arama satırı iki kaynak için de aynı şekilde', function (): void {
		$row = \Animeh\Support\MangaMapper::search_row(
			\Animeh\Support\MangaMapper::from_jikan( array( 'mal_id' => 13, 'title' => 'One Piece' ) ),
			'tenrai'
		);
		same( 'tenrai', $row['source'] );
		same( 13, $row['id'] );

		$row = \Animeh\Support\MangaMapper::search_row(
			\Animeh\Support\MangaMapper::from_gallery( array( 'id' => 99, 'title' => array( 'pretty' => 'X' ) ) ),
			'gallery'
		);
		same( 'gallery', $row['source'] );
		same( 99, $row['id'] );
		same( true, $row['adult'] );
	} );
} );
