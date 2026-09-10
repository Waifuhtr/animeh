<?php
/**
 * Does the plugin actually run?
 *
 * Usage: php wordpress-plugin/animeh/tests/smoke/run.php
 *
 * `tests/run.php` proves the pure logic is right. This proves the WordPress
 * layer loads, registers and executes — the half that `php -l` waves through
 * and that unit tests never touch.
 *
 * It exists because of a real 500: a `use` statement that an edit failed to
 * add, so `ChapterNumber::whole()` in `Rest/` resolved to
 * `Animeh\Rest\ChapterNumber` and fatalled. It only fired when the catalogue
 * had a row to format, so every check that ran against an empty database
 * passed it through.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

require __DIR__ . '/wordpress.php';
require __DIR__ . '/rows.php';

use Animeh\Rest\CatalogController;
use Animeh\Rest\MangaController;
use Animeh\Rest\RewardsController;
use Animeh\Storage\CatalogSchema;
use Animeh\Storage\MangaBridge;

$failures = 0;
$passed   = 0;

/**
 * Run one check.
 *
 * @param string   $name Description.
 * @param callable $body The check.
 */
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

$wpdb = $GLOBALS['wpdb'];

/* ── Every class loads ───────────────────────────────────────────────── */

echo "\nSınıflar yükleniyor\n";

$src   = dirname( __DIR__, 2 ) . '/src/';
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src ) );

foreach ( $files as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$class = 'Animeh\\' . str_replace( '/', '\\', substr( $file->getPathname(), strlen( $src ), -4 ) );

	step(
		$class,
		static function () use ( $class ): void {
			if ( ! class_exists( $class ) && ! interface_exists( $class ) ) {
				throw new RuntimeException( 'yüklenemedi' );
			}
		}
	);
}

/* ── Every route registers ───────────────────────────────────────────── */

echo "\nRotalar kaydediliyor\n";

$controllers = array(
	'AuthController', 'CatalogController', 'MeController', 'CommunityController',
	'SocialController', 'AdminController', 'RewardsController', 'MangaController',
	'StorageController', 'FontsController', 'TestController', 'MigrationController',
);

foreach ( $controllers as $controller ) {
	$fqcn = 'Animeh\\Rest\\' . $controller;

	step(
		$controller,
		static function () use ( $fqcn ): void {
			( new $fqcn() )->register_routes();
		}
	);
}

/* ── The read paths the app opens with ───────────────────────────────── */

echo "\nUygulamanın açılışta çağırdığı uçlar\n";

// With rows, because an empty catalogue never reaches a payload builder —
// which is exactly how the bug this file was written for got through.
$wpdb->rows = array(
	'works'    => array( animeh_work_row(), animeh_work_row( array( 'id' => 2, 'kind' => 'manga', 'slug' => 'm' ) ) ),
	'episodes' => array( animeh_episode_row() ),
);

step(
	'GET /catalog/home',
	static function (): void {
		$data = ( new CatalogController() )->home()->get_data();

		foreach ( array( 'hero', 'popular', 'recently_added', 'airing', 'latest_episodes', 'latest_chapters', 'manga', 'continue' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				throw new RuntimeException( "eksik alan: {$key}" );
			}
		}
	}
);

step(
	'GET /catalog/works',
	static function (): void {
		( new CatalogController() )->works( new WP_REST_Request( array( 'page' => 1, 'per_page' => 20, 'sort' => 'recent' ) ) );
	}
);

step(
	'GET /catalog/genres',
	static function (): void {
		( new CatalogController() )->genres();
	}
);

step(
	'GET /leaderboard',
	static function (): void {
		( new RewardsController() )->leaderboard(
			new WP_REST_Request( array( 'metric' => 'episodes', 'limit' => 25 ) )
		);
	}
);

step(
	'GET /admin/manga/bridge',
	static function (): void {
		( new MangaController() )->bridge_status();
	}
);

/* ── A row written before the newest columns existed ─────────────────── */

echo "\nEski şemadan gelen satırlar\n";

$legacy_work = animeh_work_row();
unset( $legacy_work['author'], $legacy_work['nh_id'] );

$legacy_episode = animeh_episode_row();
unset( $legacy_episode['work_kind'], $legacy_episode['page_count'] );

$wpdb->rows = array(
	'works'    => array( $legacy_work ),
	'episodes' => array( $legacy_episode ),
);

step(
	'yeni sütunları olmayan satırlarla /catalog/home',
	static function (): void {
		( new CatalogController() )->home();
	}
);

// A decimal column comes back from MySQL as a string.
$wpdb->rows = array(
	'works'    => array( animeh_work_row( array( 'kind' => 'manga' ) ) ),
	'episodes' => array( animeh_episode_row( array( 'number' => '10.50' ) ) ),
);

step(
	'ondalıklı bölüm numarası "10.5" olarak gidiyor',
	static function (): void {
		$rail = ( new CatalogController() )->home()->get_data()['latest_chapters'];

		if ( array() === $rail ) {
			throw new RuntimeException( 'manga rayı boş geldi' );
		}
		if ( '10.5' !== $rail[0]['number_label'] ) {
			throw new RuntimeException( 'number_label: ' . var_export( $rail[0]['number_label'], true ) );
		}
		if ( 10 !== $rail[0]['number'] ) {
			throw new RuntimeException( 'number: ' . var_export( $rail[0]['number'], true ) );
		}
	}
);

/* ── The schema, as dbDelta will read it ─────────────────────────────── */

echo "\nŞema\n";

$GLOBALS['__delta'] = array();
CatalogSchema::install();

step(
	'her tablo dbDelta\'ya gidiyor',
	static function (): void {
		if ( count( $GLOBALS['__delta'] ) < 20 ) {
			throw new RuntimeException( 'tablo sayısı: ' . count( $GLOBALS['__delta'] ) );
		}
	}
);

step(
	'yazdığım her sütunu dbDelta da görüyor',
	static function (): void {
		foreach ( $GLOBALS['__delta'] as $statement ) {
			preg_match( '|CREATE TABLE ([^ ]+)|', $statement, $named );
			$table = $named[1] ?? '?';

			if ( 1 !== preg_match( '/CREATE TABLE\s+\S+\s*\((.*)\)[^)]*$/ms', $statement, $parts ) ) {
				throw new RuntimeException( $table . ': ifade ayrıştırılamadı' );
			}

			$declared = array_keys( CatalogSchema::columns_in( $parts[1] ) );
			$visible  = animeh_delta_columns( $statement );

			// This is the check that was missing. `author` was declared and
			// invisible, because a semicolon inside the comment above it cut
			// the statement in half where dbDelta splits.
			$lost = array_diff( $declared, $visible );
			if ( array() !== $lost ) {
				throw new RuntimeException( $table . ': dbDelta görmüyor — ' . implode( ', ', $lost ) );
			}

			// And nothing invented: a comment line read as a column produces
			// an ALTER that cannot parse.
			$extra = array_diff( $visible, $declared );
			if ( array() !== $extra ) {
				throw new RuntimeException( $table . ': sütun olmayan şey sütun sanılıyor — ' . implode( ', ', $extra ) );
			}
		}
	}
);

step(
	'dbDelta\'ya giden ifadede yorum ve iç noktalı virgül yok',
	static function (): void {
		foreach ( $GLOBALS['__delta'] as $statement ) {
			preg_match( '|CREATE TABLE ([^ ]+)|', $statement, $named );
			$table = $named[1] ?? '?';

			if ( 1 === preg_match( '/^\s*--/m', $statement ) ) {
				throw new RuntimeException( $table . ': SQL yorumu kaldı' );
			}
			if ( substr_count( rtrim( $statement, "; \n" ), ';' ) > 0 ) {
				throw new RuntimeException( $table . ': ifadenin içinde noktalı virgül var' );
			}
		}
	}
);

step(
	'manga sütunları şemada',
	static function (): void {
		foreach ( $GLOBALS['__delta'] as $statement ) {
			if ( ! str_contains( $statement, 'animeh_works' ) ) {
				continue;
			}

			foreach ( array( 'author', 'nh_id', 'kind' ) as $column ) {
				if ( ! in_array( $column, animeh_delta_columns( $statement ), true ) ) {
					throw new RuntimeException( 'works tablosunda ' . $column . ' yok' );
				}
			}

			return;
		}

		throw new RuntimeException( 'works tablosu hiç gitmedi' );
	}
);

/* ── Manga: the paths that live inside wp_remote_get ─────────────────── */

echo "\nManga içe aktarma ve kaynaklar\n";

$wpdb->rows = array();

// The bridge, as her site answers it.
update_option(
	'animeh_manga_bridge',
	array(
		'url'          => 'https://manga.test/wp-json/animeh-bridge/v1',
		'key'          => 'bridge-key',
		'connected_at' => '',
		'site'         => 'Manga',
	),
	false
);

$bridge_manga = array(
	'items' => array(
		array(
			'id'            => 41,
			'slug'          => 'ornek-manga',
			'title'         => 'Örnek Manga',
			'synopsis'      => 'Bir açıklama.',
			'permalink'     => 'https://manga.test/manga/ornek-manga/',
			'cover'         => 'https://f004.backblazeb2.com/file/kova/kapak.jpg',
			'modified_gmt'  => '2026-01-02 03:04:05',
			'created_gmt'   => '2025-12-01 00:00:00',
			'meta'          => array(
				'alternative_titles' => 'Example Manga',
				'year'               => 2024,
				'score'              => 8.4,
				'mal_id'             => 1234,
				'author'             => 'Bir Yazar',
				'nsfw'               => false,
			),
			'characters'    => array(),
			'taxonomies'    => array(
				'genre'  => array( 'Aksiyon', 'Dram' ),
				'status' => array( 'Devam Ediyor' ),
				'artist' => array( 'Bir Çizer' ),
			),
			'chapter_count' => 2,
		),
	),
	'page'  => 1,
	'pages' => 1,
	'total' => 1,
);

$bridge_chapters = array(
	'items' => array(
		array(
			'id'           => 501,
			'manga_id'     => 41,
			'number'       => '10.5',
			'title'        => 'Bölüm 10.5',
			'permalink'    => 'https://manga.test/bolum/501/',
			'created_gmt'  => '2026-01-02 03:04:05',
			'modified_gmt' => '2026-01-02 03:04:05',
			'storage'      => 'b2',
			'path'         => 'manga/41/10-5',
			'pages'        => array(
				array(
					'position' => 1,
					'file'     => '01.webp',
					'url'      => 'https://f004.backblazeb2.com/file/kova/manga/41/10-5/01.webp',
					'fallback' => 'https://kova.s3.eu-central-003.backblazeb2.com/manga/41/10-5/01.webp',
				),
			),
		),
	),
	'page'  => 1,
	'pages' => 1,
	'total' => 1,
);

step(
	'köprü adresi: sitenin kendi adresi de kabul ediliyor',
	static function (): void {
		$expected = 'https://manga.test/wp-json/animeh-bridge/v1';

		foreach (
			array(
				'https://manga.test',
				'https://manga.test/',
				'https://manga.test/wp-json',
				'https://manga.test/wp-json/animeh-bridge/v1',
				'https://manga.test/wp-json/animeh-bridge/v1/',
			) as $typed
		) {
			$stored = MangaBridge::save( $typed, 'bridge-key' );

			if ( $expected !== $stored['url'] ) {
				throw new RuntimeException( $typed . ' → ' . $stored['url'] );
			}
		}
	}
);

step(
	'köprü adresi yanlışsa istek nereye gidiyor',
	static function (): void {
		animeh_http_reset();
		MangaBridge::save( 'https://manga.test', 'bridge-key' );
		MangaBridge::manga( 1, 3 );

		$asked = animeh_http_log()[0] ?? '';
		if ( ! str_starts_with( $asked, 'https://manga.test/wp-json/animeh-bridge/v1/manga?' ) ) {
			throw new RuntimeException( 'istek: ' . $asked );
		}
	}
);

step(
	'POST /admin/manga/sync — köprü yanıt veriyor',
	static function () use ( $bridge_manga, $bridge_chapters ): void {
		animeh_http_reset();
		animeh_http_reply( '/manga?', 200, $bridge_manga );
		animeh_http_reply( '/manga/41/chapters', 200, $bridge_chapters );

		$response = ( new MangaController() )->sync( new WP_REST_Request( array( 'page' => 1, 'reset' => true ) ) );

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( $response->get_error_code() . ': ' . $response->get_error_message() );
		}

		$data = $response->get_data();
		if ( true !== $data['done'] ) {
			throw new RuntimeException( 'done değil: ' . var_export( $data['done'], true ) );
		}
		if ( array() === $data['imported'] ) {
			throw new RuntimeException( 'hiç manga aktarılmadı' );
		}
		if ( 1 !== $data['chapters'] ) {
			throw new RuntimeException( 'bölüm sayısı: ' . var_export( $data['chapters'], true ) );
		}
	}
);

step(
	'POST /admin/manga/sync — köprü ulaşılamıyor, sebebi yazıyor',
	static function (): void {
		animeh_http_reset();

		$response = ( new MangaController() )->sync( new WP_REST_Request( array( 'page' => 1 ) ) );

		if ( ! $response instanceof WP_Error ) {
			throw new RuntimeException( 'hata bekleniyordu' );
		}
		if ( '' === $response->get_error_message() ) {
			throw new RuntimeException( 'mesajsız hata' );
		}
	}
);

step(
	'yazamayan içe aktarma "tamamlandı" demiyor',
	static function () use ( $wpdb, $bridge_manga, $bridge_chapters ): void {
		animeh_http_reset();
		animeh_http_reply( '/manga?', 200, $bridge_manga );
		animeh_http_reply( '/manga/41/chapters', 200, $bridge_chapters );

		// What her site actually did: the works table had no `author` column,
		// so every insert was refused. The run reported nine pages and
		// "tamamlandı" while importing nothing.
		$wpdb->fail_insert = "Unknown column 'author' in 'INSERT INTO'";

		$response = ( new MangaController() )->sync( new WP_REST_Request( array( 'page' => 1, 'reset' => true ) ) );

		$wpdb->fail_insert = '';

		if ( ! $response instanceof WP_Error ) {
			throw new RuntimeException(
				'başarı bildirildi: ' . wp_json_encode( $response->get_data() )
			);
		}
		if ( ! str_contains( $response->get_error_message(), 'author' ) ) {
			throw new RuntimeException( 'sebep kayboldu: ' . $response->get_error_message() );
		}
	}
);

step(
	'ulaşılamayan köprü bir kez daha deneniyor',
	static function (): void {
		animeh_http_reset();
		MangaBridge::save( 'https://manga.test', 'bridge-key' );

		$result = MangaBridge::manga( 1, 3 );

		if ( ! $result instanceof WP_Error ) {
			throw new RuntimeException( 'hata bekleniyordu' );
		}
		if ( 2 !== count( animeh_http_log() ) ) {
			throw new RuntimeException( 'deneme sayısı: ' . count( animeh_http_log() ) );
		}
	}
);

step(
	'yanıt veren köprü ikinci kez sorulmuyor',
	static function () use ( $bridge_manga ): void {
		animeh_http_reset();
		// A 500 is an answer: asking again would only double the wait.
		animeh_http_reply( '/manga?', 500, '' );

		MangaBridge::manga( 1, 3 );

		if ( 1 !== count( animeh_http_log() ) ) {
			throw new RuntimeException( 'deneme sayısı: ' . count( animeh_http_log() ) );
		}
	}
);

step(
	'POST /admin/manga/mirror',
	static function (): void {
		animeh_http_reset();
		update_option(
			'animeh_storage',
			array(
				'region'        => 'eu-central-003',
				'bucket'        => 'kova',
				'endpoint'      => 'https://s3.eu-central-003.backblazeb2.com',
				'key_id'        => 'anahtar',
				'secret'        => '',
				'public_bucket' => true,
			),
			false
		);

		$response = ( new MangaController() )->mirror();

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( $response->get_error_code() . ': ' . $response->get_error_message() );
		}
	}
);

step(
	'GET /admin/manga/search?source=gallery — kapalıyken sebebi söylüyor',
	static function (): void {
		animeh_http_reset();
		update_option( 'animeh_gallery_source', array( 'enabled' => false, 'key' => '' ), false );

		$response = ( new MangaController() )->search(
			new WP_REST_Request( array( 'q' => '177013', 'source' => 'gallery', 'page' => 1 ) )
		);

		if ( ! $response instanceof WP_Error ) {
			throw new RuntimeException( 'kapalı kaynak hata döndürmeli' );
		}
		if ( 400 !== (int) ( $response->get_error_data()['status'] ?? 0 ) ) {
			throw new RuntimeException( 'durum: ' . var_export( $response->get_error_data(), true ) );
		}
	}
);

step(
	'GET /admin/manga/search?source=gallery — numara ile',
	static function (): void {
		animeh_http_reset();
		update_option( 'animeh_gallery_source', array( 'enabled' => true, 'key' => '' ), false );
		delete_transient( 'animeh_gallery_cdn' );

		animeh_http_reply( '/api/v2/cdn', 200, array( 'https://cdn1.test' ) );
		animeh_http_reply(
			'/api/v2/galleries/177013',
			200,
			array(
				'id'        => 177013,
				'media_id'  => '987654',
				'title'     => array( 'english' => 'Example', 'pretty' => 'Example' ),
				'num_pages' => 2,
				'tags'      => array(
					array( 'type' => 'artist', 'name' => 'Bir Çizer' ),
					array( 'type' => 'tag', 'name' => 'Bir Etiket' ),
				),
				'images'    => array(
					'cover' => array( 't' => 'j', 'w' => 350, 'h' => 500 ),
					'pages' => array(
						array( 't' => 'j', 'w' => 1200, 'h' => 1700 ),
						array( 't' => 'w', 'w' => 1200, 'h' => 1700 ),
					),
				),
			)
		);

		$response = ( new MangaController() )->search(
			new WP_REST_Request( array( 'q' => '177013', 'source' => 'gallery', 'page' => 1 ) )
		);

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( $response->get_error_code() . ': ' . $response->get_error_message() );
		}

		$items = $response->get_data()['items'];
		if ( array() === $items ) {
			throw new RuntimeException( 'numara aranınca sonuç gelmedi: ' . implode( ' , ', animeh_http_log() ) );
		}
		if ( 177013 !== (int) $items[0]['id'] ) {
			throw new RuntimeException( 'id: ' . var_export( $items[0]['id'], true ) );
		}
	}
);

step(
	'GET /admin/manga/search?source=gallery — isimle sorulunca ne istediğini söylüyor',
	static function (): void {
		animeh_http_reset();

		$response = ( new MangaController() )->search(
			new WP_REST_Request( array( 'q' => 'bir isim', 'source' => 'gallery', 'page' => 1 ) )
		);

		if ( ! $response instanceof WP_Error ) {
			throw new RuntimeException( 'numara olmayan sorgu hata döndürmeli' );
		}
		if ( 400 !== (int) ( $response->get_error_data()['status'] ?? 0 ) ) {
			throw new RuntimeException( 'durum: ' . var_export( $response->get_error_data(), true ) );
		}
		if ( array() !== animeh_http_log() ) {
			throw new RuntimeException( 'kaynağa boşuna gidildi: ' . implode( ' , ', animeh_http_log() ) );
		}
	}
);

step(
	'GET /admin/manga/search?source=gallery — adres yapıştırılınca',
	static function (): void {
		animeh_http_reset();
		animeh_http_reply( '/api/v2/cdn', 200, array( 'https://cdn1.test' ) );
		animeh_http_reply(
			'/api/v2/galleries/177013',
			200,
			array( 'id' => 177013, 'media_id' => '9', 'title' => array( 'pretty' => 'Example' ), 'images' => array() )
		);

		$response = ( new MangaController() )->search(
			new WP_REST_Request( array( 'q' => 'https://nhentai.net/g/177013/', 'source' => 'gallery', 'page' => 1 ) )
		);

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( $response->get_error_code() . ': ' . $response->get_error_message() );
		}
		if ( array() === $response->get_data()['items'] ) {
			throw new RuntimeException( 'adresten numara çıkarılamadı' );
		}
	}
);

step(
	'POST /admin/manga/import — galeriden',
	static function (): void {
		$response = ( new MangaController() )->import(
			new WP_REST_Request( array( 'id' => 177013, 'source' => 'gallery', 'with_pages' => true ) )
		);

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( $response->get_error_code() . ': ' . $response->get_error_message() );
		}
		if ( ( $response->get_data()['work_id'] ?? 0 ) <= 0 ) {
			throw new RuntimeException( 'work_id yok: ' . var_export( $response->get_data(), true ) );
		}
	}
);

step(
	'GET /chapters/{id}/pages',
	static function () use ( $wpdb ): void {
		$wpdb->rows = array(
			'works'    => array( animeh_work_row( array( 'kind' => 'manga' ) ) ),
			'episodes' => array( animeh_episode_row( array( 'number' => '10.50' ) ) ),
		);

		$response = ( new MangaController() )->pages( new WP_REST_Request( array( 'id' => 1 ) ) );

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( $response->get_error_code() . ': ' . $response->get_error_message() );
		}

		$wpdb->rows = array();
	}
);

echo "\n" . $passed . '/' . ( $passed + $failures ) . " kontrol geçti\n";

exit( $failures > 0 ? 1 : 0 );
