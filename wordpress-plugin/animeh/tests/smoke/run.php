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

echo "\n" . $passed . '/' . ( $passed + $failures ) . " kontrol geçti\n";

exit( $failures > 0 ? 1 : 0 );
