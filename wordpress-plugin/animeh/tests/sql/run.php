<?php
/**
 * The queries, actually executed.
 *
 * Usage: php wordpress-plugin/animeh/tests/sql/run.php
 *
 * `tests/run.php` proves the pure logic. `tests/smoke/run.php` proves the
 * WordPress layer loads and runs. Neither executes a single line of SQL —
 * the smoke runner's `$wpdb` hands back the same fixture rows whatever the
 * query says, and its own header admits it proves nothing about SQL.
 *
 * That gap had a bug in it: a tag page whose header said "2 video" over an
 * empty grid. Everything passed, because nothing had ever run the two
 * queries and compared them.
 *
 * So this creates the plugin's real tables in SQLite, writes rows through the
 * plugin's real repository, and reads them back through the plugin's real
 * queries.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

require __DIR__ . '/../smoke/wordpress.php';
require __DIR__ . '/wpdb-sqlite.php';

use Animeh\Rest\ShortsController;
use Animeh\Storage\ShortsRepository;
use Animeh\Storage\ShortsSchema;

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
	} catch ( Throwable $error ) {
		++$failures;
		echo "  FAIL {$name}\n       " . get_class( $error ) . ': ' . $error->getMessage() . "\n";
	}
}

/** Assert two values match, saying what they were when they do not. */
function same( $want, $got, string $what ): void {
	if ( $want !== $got ) {
		throw new RuntimeException(
			$what . ': ' . var_export( $want, true ) . ' bekleniyordu, ' . var_export( $got, true ) . ' geldi'
		);
	}
}

// --- A real database, with the plugin's real tables ---------------------

$sql = new SqliteWpdb();

$GLOBALS['__delta'] = array();
$GLOBALS['wpdb']    = new FakeWpdb();

// install() writes through $wpdb, so it runs against the recording stub
// first and the statements it produced are then created for real. The
// statements are the plugin's own, unedited.
ShortsSchema::install();

$statements      = $GLOBALS['__delta'];
$GLOBALS['wpdb'] = $sql;

foreach ( $statements as $statement ) {
	$sql->create( $statement );
}

echo "AnimehTok — gerçek sorgular\n";

step(
	'şema kuruluyor',
	static function () use ( $sql ): void {
		$tables = $sql->get_col( "SELECT name FROM sqlite_master WHERE type = 'table'" );

		foreach ( array( 'wp_animeh_shorts', 'wp_animeh_short_tags', 'wp_animeh_short_sounds' ) as $want ) {
			if ( ! in_array( $want, $tables, true ) ) {
				throw new RuntimeException( 'tablo yok: ' . $want );
			}
		}
	}
);

// --- Two videos, both carrying the same tag -----------------------------

$repo = new ShortsRepository();

$first = $repo->create(
	array(
		'user_id'     => 7,
		'slug'        => 'dans-1',
		'description' => 'ilk video #dans',
		'storage_key' => 'animehtok/kullanici7-7/dans-1.mp4',
		'sound_id'    => 0,
		'duration_ms' => 14000,
		'width'       => 1080,
		'height'      => 1920,
		'size_bytes'  => 4200000,
		'published'   => true,
	)
);

$second = $repo->create(
	array(
		'user_id'     => 8,
		'slug'        => 'dans-2',
		'description' => 'ikinci video #dans',
		'storage_key' => 'animehtok/kullanici8-8/dans-2.mp4',
		'sound_id'    => 0,
		'duration_ms' => 9000,
		'width'       => 1920,
		'height'      => 1080,
		'size_bytes'  => 2200000,
		'published'   => true,
	)
);

step(
	'iki video yazıldı',
	static function () use ( $first, $second ): void {
		if ( ! is_int( $first ) || ! is_int( $second ) || $first <= 0 || $second <= 0 ) {
			throw new RuntimeException( 'create() id vermedi' );
		}
	}
);

step(
	'etiket sayısı ile etiket listesi aynı şeyi söylüyor',
	static function () use ( $repo ): void {
		// The bug this file exists for: the header counted two videos and the
		// grid under it was empty. A count and a listing that disagree is not
		// a cosmetic fault — it is the page saying there is something to see
		// and then not showing it.
		$summary = $repo->tag_summary( 'dans' );
		$rows    = $repo->by_tag( 'dans', 21, 0 );

		same( 2, (int) $summary['count'], 'tag_summary sayısı' );
		same( 2, count( $rows ), 'by_tag satır sayısı' );
	}
);

step(
	'etiket sayfası ucu da aynı şeyi söylüyor',
	static function (): void {
		$response = ( new ShortsController() )->tag_page( new WP_REST_Request( array( 'tag' => 'dans', 'per_page' => 21, 'offset' => 0 ) ) );
		$data     = $response->get_data();

		same( (int) $data['count'], count( $data['items'] ), 'başlıktaki sayı ile gridin uzunluğu' );
	}
);

step(
	'sayfalama etiket sayfasını boşaltmıyor',
	static function () use ( $repo ): void {
		// The reported fault was a header counting two videos over an empty
		// grid. Paging is the one way the two can legitimately disagree, so
		// the boundaries are checked rather than assumed: the first page holds
		// everything there is, and only a page past the end is empty.
		same( 2, count( $repo->by_tag( 'dans', 21, 0 ) ), 'ilk sayfa' );
		same( 1, count( $repo->by_tag( 'dans', 1, 0 ) ), 'bir kişilik sayfa' );
		same( 1, count( $repo->by_tag( 'dans', 21, 1 ) ), 'bir atlayınca' );
		same( 0, count( $repo->by_tag( 'dans', 21, 2 ) ), 'sonun ötesi' );
	}
);

step(
	'yayından kaldırılan video ne sayılıyor ne listeleniyor',
	static function () use ( $repo ): void {
		$hidden = $repo->create(
			array(
				'user_id'     => 9,
				'slug'        => 'gizli-dans',
				'description' => 'yayınlanmamış #dans',
				'storage_key' => 'animehtok/kullanici9-9/gizli-dans.mp4',
				'sound_id'    => 0,
				'published'   => false,
			)
		);

		$summary = $repo->tag_summary( 'dans' );

		same( 2, (int) $summary['count'], 'sayı' );
		same( 2, count( $repo->by_tag( 'dans', 21, 0 ) ), 'liste' );

		$repo->delete( (int) $hidden );
	}
);

step(
	'silinen video etiketini de götürüyor',
	static function () use ( $repo ): void {
		// An orphan tag row would be counted by a header whose grid could
		// never find the video, which is exactly the shape of the fault this
		// file was written for.
		$doomed = $repo->create(
			array(
				'user_id'     => 9,
				'slug'        => 'silinecek',
				'description' => 'gidici #dans',
				'storage_key' => 'animehtok/kullanici9-9/silinecek.mp4',
				'sound_id'    => 0,
				'published'   => true,
			)
		);

		same( 3, (int) $repo->tag_summary( 'dans' )['count'], 'silmeden önce' );

		$repo->delete( (int) $doomed );

		same( 2, (int) $repo->tag_summary( 'dans' )['count'], 'sildikten sonra' );
		same( 2, count( $repo->by_tag( 'dans', 21, 0 ) ), 'sildikten sonra liste' );

		// And the row itself is gone, not merely hidden behind a join: an
		// orphan is invisible today only because every query happens to join
		// the videos, and that is not a thing to rely on.
		global $wpdb;
		same(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . ShortsSchema::tags() . ' WHERE short_id = %d',
					(int) $doomed
				)
			),
			'artık etiket satırı'
		);
	}
);

step(
	'yazıldığı hâli anahtarından farklı olan etiket de bulunuyor',
	static function () use ( $repo ): void {
		// Both halves of the page have to look at the folded key rather than
		// at the spelling. A video tagged `#Dans` is stored with tag `Dans`
		// and key `dans`, so a query that reaches for the written column finds
		// nothing while the count — which folds — still says one. Every
		// fixture above is spelled the way it folds, which is exactly the case
		// that cannot tell the two apart.
		$id = $repo->create(
			array(
				'user_id'     => 9,
				'slug'        => 'buyuk-harf',
				'description' => 'büyük harfli #Sahne',
				'storage_key' => 'animehtok/kullanici9-9/buyuk-harf.mp4',
				'sound_id'    => 0,
				'published'   => true,
			)
		);

		foreach ( array( 'Sahne', 'sahne', 'SAHNE' ) as $spelling ) {
			same( 1, (int) $repo->tag_summary( $spelling )['count'], "'" . $spelling . "' sayısı" );
			same( 1, count( $repo->by_tag( $spelling, 21, 0 ) ), "'" . $spelling . "' listesi" );
		}

		$repo->delete( (int) $id );
	}
);

step(
	'ses ve yaratıcı sayfaları da gerçekten satır döndürüyor',
	static function () use ( $repo ): void {
		$controller = new ShortsController();

		$sound = $repo->create_sound( 'Orijinal ses', 'kullanici', 7 );
		$id    = $repo->create(
			array(
				'user_id'     => 7,
				'slug'        => 'sesli',
				'description' => 'sesli video',
				'storage_key' => 'animehtok/kullanici7-7/sesli.mp4',
				'sound_id'    => $sound,
				'published'   => true,
			)
		);

		$page = $controller->sound_page( new WP_REST_Request( array( 'id' => $sound, 'per_page' => 21, 'offset' => 0 ) ) );
		if ( $page instanceof WP_Error ) {
			throw new RuntimeException( 'ses sayfası hata verdi' );
		}
		same( 1, count( $page->get_data()['items'] ), 'ses sayfası' );

		$mine = $controller->creator( new WP_REST_Request( array( 'id' => 7, 'per_page' => 21, 'offset' => 0 ) ) );
		if ( $mine instanceof WP_Error ) {
			throw new RuntimeException( 'yaratıcı sayfası hata verdi' );
		}
		if ( count( $mine->get_data()['items'] ) < 1 ) {
			throw new RuntimeException( 'yaratıcı sayfası boş döndü' );
		}

		$repo->delete( (int) $id );
	}
);

echo "\n" . $passed . '/' . ( $passed + $failures ) . " kontrol geçti\n";

exit( $failures > 0 ? 1 : 0 );
