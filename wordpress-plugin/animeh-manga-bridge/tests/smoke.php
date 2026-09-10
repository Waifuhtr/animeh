<?php
/**
 * Does the bridge actually run?
 *
 * Usage: php wordpress-plugin/animeh-manga-bridge/tests/smoke.php
 *
 * Written after a live 500. `authorised()` called `key()` — the PHP builtin,
 * which needs an array — because a rename landed on the function's definition
 * and not on its one call site. There is even a comment above the definition
 * explaining why the name had to change. `php -l` parses it happily: inside a
 * namespace an unqualified function call falls back to the global one, so
 * `key()` resolves to the builtin and throws ArgumentCountError at runtime.
 *
 * The lesson is the same one the catalogue's missing `use` taught: a file that
 * is never executed is a file that is never checked. So this stubs the
 * WordPress functions the bridge touches, registers its routes and calls every
 * one of them with fixture posts.
 *
 * @package AnimehBridge
 */

declare( strict_types = 1 );

define( 'ABSPATH', '/tmp/wp/' );

$GLOBALS['__options'] = array();
$GLOBALS['__routes']  = array();
$GLOBALS['__meta']    = array();
$GLOBALS['__terms']   = array();
$GLOBALS['__posts']   = array();

function __( $t, $d = '' ) { return $t; }
function esc_attr( $v ) { return $v; }
function esc_url( $v ) { return $v; }
function esc_html( $v ) { return $v; }
function esc_html__( $t, $d = '' ) { return $t; }
function esc_html_e( $t, $d = '' ) { echo $t; }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function wp_generate_password( $l = 12, ...$rest ) { return str_repeat( 'k', (int) $l ); }
function add_action( $hook, $fn, ...$rest ) { $GLOBALS['__hooks'][ $hook ][] = $fn; return true; }
function add_options_page( ...$a ) { return ''; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__routes'][ $ns . $route ] = $args; return true; }
function get_bloginfo( $k ) { return 'Manga Sitesi'; }
function home_url( $p = '' ) { return 'https://manga.test' . $p; }
function rest_url( $p = '' ) { return 'https://manga.test/wp-json/' . $p; }
function current_user_can( $c ) { return true; }
function check_admin_referer( ...$a ) { return true; }
function wp_nonce_field( ...$a ) { return ''; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_get_upload_dir() { return array( 'basedir' => '/tmp/uploads', 'baseurl' => 'https://manga.test/wp-content/uploads' ); }
function get_permalink( $p = null ) { return 'https://manga.test/?p=' . ( is_object( $p ) ? $p->ID : (int) $p ); }
function get_the_post_thumbnail_url( $p = null, $size = '' ) { return 'https://manga.test/kapak.jpg'; }
function wp_count_posts( $type ) { return (object) array( 'publish' => 'manga' === $type ? 2 : 7 ); }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
function get_the_terms( $id, $taxonomy ) { return $GLOBALS['__terms'][ $id ][ $taxonomy ] ?? false; }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public function __construct( public $data = null, public int $status = 200 ) {}
	public function get_data() { return $this->data; }
}
class WP_REST_Request {
	public function __construct( private array $params = array(), private array $headers = array() ) {}
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_header( $k ) { return $this->headers[ $k ] ?? ''; }
}
class WP_Post {
	public int $ID = 0;
	public string $post_name = '';
	public string $post_title = '';
	public string $post_content = '';
	public string $post_modified_gmt = '';
	public string $post_date_gmt = '';
}

/** Answers with whatever fixture matches the requested post type. */
class WP_Query {
	public array $posts = array();
	public int $max_num_pages = 1;
	public int $found_posts = 0;

	public function __construct( array $args = array() ) {
		$this->posts       = $GLOBALS['__posts'][ $args['post_type'] ?? '' ] ?? array();
		$this->found_posts = count( $this->posts );
	}
}

class FakeWpdb {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$placeholders = preg_match_all( '/%[dsfF]/', (string) $sql );
		if ( $placeholders !== count( $args ) ) {
			throw new RuntimeException( "prepare(): {$placeholders} yer tutucu, " . count( $args ) . ' argüman' );
		}
		return (string) $sql;
	}
	public function get_var( $sql = null ) { return 3; }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../animeh-manga-bridge.php';

/* ── Fixtures ────────────────────────────────────────────────────────── */

$manga                    = new WP_Post();
$manga->ID                = 41;
$manga->post_name         = 'ornek-manga';
$manga->post_title        = 'Örnek Manga';
$manga->post_content      = '<p>Bir açıklama.</p>';
$manga->post_modified_gmt = '2026-01-02 03:04:05';
$manga->post_date_gmt     = '2025-12-01 00:00:00';

$chapter                    = new WP_Post();
$chapter->ID                = 501;
$chapter->post_title        = 'Bölüm 10.5';
$chapter->post_modified_gmt = '2026-01-02 03:04:05';
$chapter->post_date_gmt     = '2026-01-02 03:04:05';

$GLOBALS['__posts'] = array( 'manga' => array( $manga ), 'chapter' => array( $chapter ) );

$GLOBALS['__meta'] = array(
	41  => array(
		'manga_alternative_titles' => 'Example Manga',
		'manga_year'               => '2024',
		'manga_score'              => '8.4',
		'manga_jikan_id'           => '1234',
		'manga_author'             => 'Bir Yazar',
		'manga_is_nsfw'            => '0',
		'manga_characters'         => array( 'Biri', 'Başkası' ),
	),
	501 => array(
		'chapter_manga_id'   => '41',
		'chapter_number'     => '10.5',
		'chapter_path'       => 'manga/41/10-5',
		'chapter_image_list' => array( '10.webp', '2.webp', '1.webp' ),
		'storage_provider'   => 'b2',
	),
);

$GLOBALS['__terms'] = array(
	41 => array(
		'genre'        => array( (object) array( 'name' => 'Aksiyon' ), (object) array( 'name' => 'Dram' ) ),
		'manga_status' => array( (object) array( 'name' => 'Devam Ediyor' ) ),
	),
);

$GLOBALS['__options'] = array(
	'b2_bucket_name'  => 'kova',
	'b2_download_url' => 'https://f004.backblazeb2.com',
	'b2_s3_endpoint'  => 'https://s3.eu-central-003.backblazeb2.com',
);

/* ── Checks ──────────────────────────────────────────────────────────── */

$failures = 0;
$passed   = 0;

function step( string $name, callable $body ): void {
	global $failures, $passed;

	try {
		$body();
		++$passed;
		echo "  ok   {$name}\n";
	} catch ( Throwable $e ) {
		++$failures;
		echo "  FAIL {$name}\n";
		echo '       ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
		echo '       ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
}

echo "\nKöprü\n";

// Routes only exist once rest_api_init has fired.
foreach ( $GLOBALS['__hooks']['rest_api_init'] ?? array() as $fn ) {
	$fn();
}

step(
	'üç rota kaydoluyor',
	static function (): void {
		foreach ( array( 'animeh-bridge/v1/ping', 'animeh-bridge/v1/manga', 'animeh-bridge/v1/manga/(?P<id>\d+)/chapters' ) as $route ) {
			if ( ! isset( $GLOBALS['__routes'][ $route ] ) ) {
				throw new RuntimeException( 'eksik: ' . $route );
			}
		}
	}
);

step(
	'doğru anahtar kabul ediliyor',
	static function (): void {
		$key     = \Animeh\Bridge\bridge_key();
		$request = new WP_REST_Request( array(), array( \Animeh\Bridge\HEADER => $key ) );

		if ( true !== \Animeh\Bridge\guard( $request ) ) {
			throw new RuntimeException( 'anahtar reddedildi' );
		}
	}
);

step(
	'anahtar sorgu dizesinden de okunuyor',
	static function (): void {
		$request = new WP_REST_Request( array( 'key' => \Animeh\Bridge\bridge_key() ) );

		if ( true !== \Animeh\Bridge\guard( $request ) ) {
			throw new RuntimeException( 'anahtar reddedildi' );
		}
	}
);

step(
	'yanlış anahtar 401',
	static function (): void {
		$request = new WP_REST_Request( array(), array( \Animeh\Bridge\HEADER => 'yanlış' ) );
		$result  = \Animeh\Bridge\guard( $request );

		if ( ! $result instanceof WP_Error ) {
			throw new RuntimeException( 'geçersiz anahtar kabul edildi' );
		}
		if ( 401 !== (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			throw new RuntimeException( 'durum: ' . var_export( $result->get_error_data(), true ) );
		}
	}
);

step(
	'anahtarsız istek 401',
	static function (): void {
		if ( ! \Animeh\Bridge\guard( new WP_REST_Request() ) instanceof WP_Error ) {
			throw new RuntimeException( 'anahtarsız istek kabul edildi' );
		}
	}
);

step(
	'GET /ping',
	static function (): void {
		$data = \Animeh\Bridge\ping()->get_data();

		if ( true !== $data['ok'] || 'Manga Sitesi' !== $data['site'] ) {
			throw new RuntimeException( var_export( $data, true ) );
		}
		if ( 2 !== $data['manga'] || 7 !== $data['chapters'] ) {
			throw new RuntimeException( 'sayımlar: ' . var_export( $data, true ) );
		}
		if ( 'kova' !== $data['storage']['b2_bucket'] ) {
			throw new RuntimeException( 'depolama gitmedi' );
		}
	}
);

step(
	'GET /manga',
	static function (): void {
		$data = \Animeh\Bridge\manga_list( new WP_REST_Request( array( 'page' => 1, 'per_page' => 10 ) ) )->get_data();

		if ( 1 !== count( $data['items'] ) ) {
			throw new RuntimeException( 'öğe sayısı: ' . count( $data['items'] ) );
		}

		$item = $data['items'][0];
		if ( 41 !== $item['id'] || 'Örnek Manga' !== $item['title'] ) {
			throw new RuntimeException( var_export( $item, true ) );
		}
		if ( 'Bir açıklama.' !== $item['synopsis'] ) {
			throw new RuntimeException( 'özet: ' . var_export( $item['synopsis'], true ) );
		}
		if ( 1234 !== $item['meta']['mal_id'] || 'Bir Yazar' !== $item['meta']['author'] ) {
			throw new RuntimeException( 'meta: ' . var_export( $item['meta'], true ) );
		}
		if ( array( 'Aksiyon', 'Dram' ) !== $item['taxonomies']['genre'] ) {
			throw new RuntimeException( 'türler: ' . var_export( $item['taxonomies']['genre'], true ) );
		}
		// A taxonomy with no terms is an empty list, never the `false` that
		// get_the_terms() answers with — the other end types it as a list.
		if ( array() !== $item['taxonomies']['artist'] ) {
			throw new RuntimeException( 'boş taksonomi: ' . var_export( $item['taxonomies']['artist'], true ) );
		}
		if ( 3 !== $item['chapter_count'] ) {
			throw new RuntimeException( 'bölüm sayısı: ' . var_export( $item['chapter_count'], true ) );
		}
	}
);

step(
	'GET /manga/{id}/chapters',
	static function (): void {
		$data = \Animeh\Bridge\chapter_list( new WP_REST_Request( array( 'id' => 41, 'page' => 1 ) ) )->get_data();

		if ( 1 !== count( $data['items'] ) ) {
			throw new RuntimeException( 'öğe sayısı: ' . count( $data['items'] ) );
		}

		$chapter = $data['items'][0];
		if ( '10.5' !== $chapter['number'] || 41 !== $chapter['manga_id'] ) {
			throw new RuntimeException( var_export( $chapter, true ) );
		}
		if ( 3 !== count( $chapter['pages'] ) ) {
			throw new RuntimeException( 'sayfa sayısı: ' . count( $chapter['pages'] ) );
		}

		// Doğal sıralama: 10 sondadır, "1, 10, 2" değil.
		$names = array_column( $chapter['pages'], 'file' );
		if ( array( '1.webp', '2.webp', '10.webp' ) !== $names ) {
			throw new RuntimeException( 'sıra: ' . implode( ', ', $names ) );
		}

		$first = $chapter['pages'][0];
		if ( 'https://f004.backblazeb2.com/file/kova/manga/41/10-5/1.webp' !== $first['url'] ) {
			throw new RuntimeException( 'adres: ' . $first['url'] );
		}
		if ( 'https://kova.s3.eu-central-003.backblazeb2.com/manga/41/10-5/1.webp' !== $first['fallback'] ) {
			throw new RuntimeException( 'yedek adres: ' . $first['fallback'] );
		}
	}
);

step(
	'eski bayraklar hâlâ kazanıyor',
	static function (): void {
		$GLOBALS['__meta'][ 900 ] = array( 'storage_provider' => 'local', 'is_b2_hosted' => '1' );
		if ( 'b2' !== \Animeh\Bridge\storage_provider( 900 ) ) {
			throw new RuntimeException( 'is_b2_hosted yok sayıldı' );
		}

		$GLOBALS['__meta'][ 901 ] = array( 'is_bunny_hosted' => '1' );
		if ( 'bunny' !== \Animeh\Bridge\storage_provider( 901 ) ) {
			throw new RuntimeException( 'is_bunny_hosted yok sayıldı' );
		}

		$GLOBALS['__meta'][ 902 ] = array();
		if ( 'local' !== \Animeh\Bridge\storage_provider( 902 ) ) {
			throw new RuntimeException( 'varsayılan local değil' );
		}
	}
);

step(
	'CDN adresi varsa indirme adresinin önüne geçiyor',
	static function (): void {
		$GLOBALS['__options']['b2_cdn_url'] = 'https://cdn.manga.test';

		$pages = \Animeh\Bridge\page_urls( 'b2', 'yol', array( 'a.webp' ) );

		unset( $GLOBALS['__options']['b2_cdn_url'] );

		if ( 'https://cdn.manga.test/yol/a.webp' !== $pages[0]['url'] ) {
			throw new RuntimeException( $pages[0]['url'] );
		}
	}
);

step(
	'boşluklu dosya adı adreste kodlanıyor',
	static function (): void {
		$pages = \Animeh\Bridge\page_urls( 'b2', 'yol', array( 'bir sayfa.webp' ) );

		if ( ! str_ends_with( $pages[0]['url'], '/bir%20sayfa.webp' ) ) {
			throw new RuntimeException( $pages[0]['url'] );
		}
		// The name itself stays readable: it is shown, not requested.
		if ( 'bir sayfa.webp' !== $pages[0]['file'] ) {
			throw new RuntimeException( $pages[0]['file'] );
		}
	}
);

step(
	'yolu olmayan bölüm boş liste',
	static function (): void {
		if ( array() !== \Animeh\Bridge\page_urls( 'b2', '', array( 'a.webp' ) ) ) {
			throw new RuntimeException( 'yolsuz bölüm sayfa üretti' );
		}
	}
);

echo "\n" . $passed . '/' . ( $passed + $failures ) . " kontrol geçti\n";

exit( $failures > 0 ? 1 : 0 );
