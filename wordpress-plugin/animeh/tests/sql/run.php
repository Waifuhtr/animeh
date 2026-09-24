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
use Animeh\Storage\CatalogSchema;
use Animeh\Storage\LeaderboardRepository;
use Animeh\Storage\ShortsRepository;
use Animeh\Storage\ShortsSchema;
use Animeh\Storage\UserDataRepository;
use Animeh\Support\Points;

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

step(
	'çok izlenen video keşfette öne geçiyor',
	static function () use ( $repo, $sql ): void {
		// What was asked for, in one check: between two videos of the same age,
		// the one people watched is the one offered next.
		$quiet = $repo->create(
			array(
				'user_id'     => 11,
				'slug'        => 'sessiz',
				'description' => 'kimse izlemedi',
				'storage_key' => 'animehtok/k11/sessiz.mp4',
				'sound_id'    => 0,
				'published'   => true,
			)
		);

		$loud = $repo->create(
			array(
				'user_id'     => 11,
				'slug'        => 'izlenen',
				'description' => 'herkes izledi',
				'storage_key' => 'animehtok/k11/izlenen.mp4',
				'sound_id'    => 0,
				'published'   => true,
			)
		);

		// Both written this second, so age cannot be what decides it. The
		// quiet one is the *newer* row, which is what the old ordering would
		// have put first.
		$sql->query( 'UPDATE ' . ShortsSchema::shorts() . ' SET view_count = 400 WHERE id = ' . (int) $loud );

		$order = array_map(
			static fn( array $row ): int => (int) $row['id'],
			$repo->for_you( 0, 30, 0 )
		);

		$louder = array_search( (int) $loud, $order, true );
		$quieter = array_search( (int) $quiet, $order, true );

		if ( false === $louder || false === $quieter || $louder > $quieter ) {
			throw new RuntimeException( 'izlenen video öne geçmedi: ' . implode( ',', $order ) );
		}

		$repo->delete( (int) $loud );
		$repo->delete( (int) $quiet );
	}
);

step(
	'yeni video eski bir hitin arkasında kalmıyor',
	static function () use ( $repo, $sql ): void {
		// The other half of the bargain. A feed sorted only by how watched
		// something is, is a feed where nothing new can ever become watched,
		// because nothing new is ever shown.
		$hit = $repo->create(
			array(
				'user_id'     => 12,
				'slug'        => 'eski-hit',
				'description' => 'eski ama çok izlenmiş',
				'storage_key' => 'animehtok/k12/eski-hit.mp4',
				'sound_id'    => 0,
				'published'   => true,
			)
		);

		$sql->query(
			'UPDATE ' . ShortsSchema::shorts() .
			" SET view_count = 100000, created_at = '2020-01-01 00:00:00' WHERE id = " . (int) $hit
		);

		$today = $repo->create(
			array(
				'user_id'     => 12,
				'slug'        => 'bugun',
				'description' => 'bugün yüklendi',
				'storage_key' => 'animehtok/k12/bugun.mp4',
				'sound_id'    => 0,
				'published'   => true,
			)
		);

		// An hour ago rather than this second: written now, `created_at >= now`
		// is true even with no window at all, and the check would pass on the
		// clock rather than on the rule.
		$sql->query(
			'UPDATE ' . ShortsSchema::shorts() .
			" SET created_at = '" . gmdate( 'Y-m-d H:i:s', time() - 3600 ) . "' WHERE id = " . (int) $today
		);

		$order = array_map(
			static fn( array $row ): int => (int) $row['id'],
			$repo->for_you( 0, 30, 0 )
		);

		if ( array_search( (int) $today, $order, true ) > array_search( (int) $hit, $order, true ) ) {
			throw new RuntimeException( 'yeni video eski hitin arkasında kaldı' );
		}

		$repo->delete( (int) $hit );
		$repo->delete( (int) $today );
	}
);

// --- Sıralama ve profil istatistikleri, gerçek geçmiş/puan tablolarında ---
//
// `LeaderboardRepository`'nin dört tahtası ve `UserDataRepository`'nin
// anime/manga ayrımı, `history`/`works`/`points` tablolarına hiç dokunmadan
// önce hiç çalıştırılmamıştı — ikisi de alt sorgu, HAVING, CASE WHEN taşıyor,
// tam da elle okunarak "doğru görünüp" gerçekte yanlış olabilecek türden SQL.

echo "\nSıralama ve profil — gerçek geçmiş/puan tabloları\n";

// Aynı kayıt-sonra-oynat numarası: CatalogSchema kendi tablolarını her
// zamanki gibi dbDelta() ile kaydettiriyor, bu sefer aynı SQLite bağlantısına
// ekleniyor — Shorts tabloları zaten orada duruyor.
$GLOBALS['__delta'] = array();
$GLOBALS['wpdb']    = new FakeWpdb();

CatalogSchema::install();

$catalog_statements = $GLOBALS['__delta'];
$GLOBALS['wpdb']     = $sql;

foreach ( $catalog_statements as $statement ) {
	$sql->create( $statement );
}

step(
	'katalog şeması da kuruluyor',
	static function () use ( $sql ): void {
		$tables = $sql->get_col( "SELECT name FROM sqlite_master WHERE type = 'table'" );

		foreach ( array( 'wp_animeh_works', 'wp_animeh_episodes', 'wp_animeh_history', 'wp_animeh_points' ) as $want ) {
			if ( ! in_array( $want, $tables, true ) ) {
				throw new RuntimeException( 'tablo yok: ' . $want );
			}
		}
	}
);

// Üç yapım: iki bölümlü bir anime (A), tek bölümlü bir anime (B), üç
// bölümlü bir manga (C). İki izleyici: 501 sadece A'yı bitiriyor; 502 A'nın
// yarısını, B'yi kısmen izliyor ve C'nin tamamını okuyor — üç anime ve manga
// ölçütünün üçünün de birbirinden ayrı sayılmasını görmek için, tek bir
// izleyicinin hem izlediği hem okuduğu bir karışım gerekiyordu.

$work = static function ( int $id, string $kind, string $slug ) use ( $sql ): void {
	$sql->insert(
		CatalogSchema::works(),
		array(
			'id'        => $id,
			'kind'      => $kind,
			'slug'      => $slug,
			'title'     => $slug,
			'synonyms'  => '[]',
			'synopsis'  => '',
			'genres'    => '[]',
			'published' => 1,
		)
	);
};

$work( 101, CatalogSchema::KIND_ANIME, 'anime-a' );
$work( 102, CatalogSchema::KIND_ANIME, 'anime-b' );
$work( 103, CatalogSchema::KIND_MANGA, 'manga-c' );

$episode = static function ( int $id, int $work_id, float $number ) use ( $sql ): void {
	$sql->insert(
		CatalogSchema::episodes(),
		array(
			'id'        => $id,
			'work_id'   => $work_id,
			'number'    => $number,
			'title'     => 'e' . $id,
			'synopsis'  => '',
			'published' => 1,
		)
	);
};

$episode( 201, 101, 1 );
$episode( 202, 101, 2 );
$episode( 203, 102, 1 );
$episode( 204, 103, 1 );
$episode( 205, 103, 2 );
$episode( 206, 103, 3 );

$watch = static function (
	int $user_id,
	int $work_id,
	int $episode_id,
	int $watched_seconds,
	int $completed
) use ( $sql ): void {
	$sql->insert(
		CatalogSchema::history(),
		array(
			'user_id'         => $user_id,
			'work_id'         => $work_id,
			'episode_id'      => $episode_id,
			'duration_seconds' => max( $watched_seconds, 1 ),
			'watched_seconds' => $watched_seconds,
			'completed'       => $completed,
			'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
		)
	);
};

// 501 — yalnızca izleyen: A'nın iki bölümünü de bitiriyor, B'ye hiç dokunmuyor.
$watch( 501, 101, 201, 600, 1 );
$watch( 501, 101, 202, 700, 1 );

// 502 — karışık: A'nın yalnızca ilk bölümünü bitiriyor, B'yi bitirmeden
// izliyor, C'nin (manga) üç bölümünü de okuyup bitiriyor.
$watch( 502, 101, 201, 600, 1 );
$watch( 502, 102, 203, 100, 0 );
$watch( 502, 103, 204, 15, 1 );
$watch( 502, 103, 205, 20, 1 );
$watch( 502, 103, 206, 25, 1 );

$award = static function ( int $user_id, int $episode_id, int $delta, string $reason ) use ( $sql ): void {
	$sql->insert(
		CatalogSchema::points(),
		array(
			'user_id'    => $user_id,
			'delta'      => $delta,
			'reason'     => $reason,
			'award_key'  => $reason . ':' . $episode_id . ':' . $user_id,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		)
	);
};

// 501: iki bölüm × 20 = 40 puan.
$award( 501, 201, Points::PER_EPISODE, Points::REASON_EPISODE );
$award( 501, 202, Points::PER_EPISODE, Points::REASON_EPISODE );

// 502: bir bölüm (20) + üç manga bölümü × 10 (30) = 50 puan — 501'den fazla,
// izlediği bölüm sayısı 501'den az olsa bile.
$award( 502, 201, Points::PER_EPISODE, Points::REASON_EPISODE );
$award( 502, 204, Points::PER_CHAPTER, Points::REASON_CHAPTER );
$award( 502, 205, Points::PER_CHAPTER, Points::REASON_CHAPTER );
$award( 502, 206, Points::PER_CHAPTER, Points::REASON_CHAPTER );

// 503: hiç izlemedi/okumadı, yalnızca bir çerçeve için puan harcadı. Tahtaya
// hiç girmemesi gereken kullanıcı bu.
$award( 503, 0, -50, Points::REASON_FRAME );

step(
	'dört tahta da birbirinden gerçekten farklı — biri diğerinin üstüne kurulu değil',
	static function (): void {
		// Kasıtlı: her ölçüt başka bir kullanıcıyı birinci çıkarıyor. Aynı
		// sorgu şablonu kopyalanıp filtre unutulsaydı, en az ikisi aynı
		// sırayı verirdi.
		$works    = LeaderboardRepository::board( LeaderboardRepository::METRIC_WORKS );
		$episodes = LeaderboardRepository::board( LeaderboardRepository::METRIC_EPISODES );
		$seconds  = LeaderboardRepository::board( LeaderboardRepository::METRIC_SECONDS );
		$points   = LeaderboardRepository::board( LeaderboardRepository::METRIC_POINTS );

		// Anime (works): 502 iki yapıma dokundu (A, B), 501 yalnızca birine (A).
		same( 502, $works[0]['user_id'], 'en çok yapım — 502' );
		same( 2, $works[0]['value'], 'en çok yapım değeri' );
		same( 501, $works[1]['user_id'], 'en çok yapım — ikinci 501' );
		same( 1, $works[1]['value'], 'ikincinin değeri' );

		// Bölüm: 501 iki bölüm bitirdi, 502 bir tane (mangası sayılmıyor).
		same( 501, $episodes[0]['user_id'], 'en çok bölüm — 501' );
		same( 2, $episodes[0]['value'], 'en çok bölüm değeri' );
		same( 502, $episodes[1]['user_id'], 'en çok bölüm — ikinci 502' );
		same( 1, $episodes[1]['value'], 'ikincinin değeri' );

		// Süre: 501 1300 saniye, 502 700 (mangadaki sayfa saniyeleri hariç).
		same( 501, $seconds[0]['user_id'], 'en çok süre — 501' );
		same( 1300, $seconds[0]['value'], 'en çok süre değeri' );
		same( 502, $seconds[1]['user_id'], 'en çok süre — ikinci 502' );
		same( 700, $seconds[1]['value'], 'ikincinin değeri' );

		// Puan: 502 manga sayesinde 501'i geçiyor — az bölüm, çok puan.
		same( 502, $points[0]['user_id'], 'en çok puan — 502' );
		same( 50, $points[0]['value'], 'en çok puan değeri' );
		same( 501, $points[1]['user_id'], 'en çok puan — ikinci 501' );
		same( 40, $points[1]['value'], 'ikincinin değeri' );

		// 503 sadece harcadı, hiç kazanmadı: tahtada hiç yok.
		foreach ( $points as $row ) {
			if ( 503 === (int) $row['user_id'] ) {
				throw new RuntimeException( 'yalnızca harcayan kullanıcı puan tahtasında görünüyor' );
			}
		}
	}
);

step(
	'harcayan ama hiç kazanmayan kullanıcının sırası yok, tahtadaki toplam onu saymıyor',
	static function (): void {
		$standing = LeaderboardRepository::standing( LeaderboardRepository::METRIC_POINTS, 503 );

		same( 0, $standing['rank'], '503 sırası' );
		same( 0, $standing['value'], '503 değeri' );

		$leader = LeaderboardRepository::standing( LeaderboardRepository::METRIC_POINTS, 502 );

		same( 1, $leader['rank'], '502 sırası' );
		same( 50, $leader['value'], '502 değeri' );
		same( 2, $leader['total'], 'puan tahtasındaki toplam kişi — 503 hariç' );
	}
);

step(
	'stats() mangayı anime sayılarına karıştırmıyor',
	static function (): void {
		$data = new UserDataRepository();

		$mixed = $data->stats( 502 );

		same( 2, $mixed['episodes_started'], '502 anime: başlanan bölüm' );
		same( 1, $mixed['episodes_completed'], '502 anime: biten bölüm — mangadakiler dahil değil' );
		same( 700, $mixed['seconds_watched'], '502 anime: izlenen saniye — sayfalar dahil değil' );
		same( 0, $mixed['works_completed'], '502 anime: biten seri — A da B de tam bitmedi' );

		same( 3, $mixed['manga']['chapters_started'], '502 manga: başlanan bölüm' );
		same( 3, $mixed['manga']['chapters_completed'], '502 manga: biten bölüm' );
		same( 60, $mixed['manga']['pages_read'], '502 manga: okunan sayfa' );
		same( 1, $mixed['manga']['works_completed'], '502 manga: biten seri — C tamamen okundu' );

		$anime_only = $data->stats( 501 );

		same( 2, $anime_only['episodes_completed'], '501: biten bölüm' );
		same( 1, $anime_only['works_completed'], '501: biten seri — A tamamen izlendi' );
		same( 0, $anime_only['manga']['chapters_started'], '501: hiç manga okumadı' );
	}
);

step(
	'watched_works() her satırı doğru kind ile etiketliyor',
	static function (): void {
		$data = new UserDataRepository();
		$rows = $data->watched_works( 502 );

		same( 3, count( $rows ), '502 kaç yapıma dokundu' );

		$by_kind = array();
		foreach ( $rows as $row ) {
			$by_kind[ $row['kind'] ][] = $row['slug'];
		}

		sort( $by_kind[ CatalogSchema::KIND_ANIME ] );

		same( array( 'anime-a', 'anime-b' ), $by_kind[ CatalogSchema::KIND_ANIME ], 'anime yapımları' );
		same( array( 'manga-c' ), $by_kind[ CatalogSchema::KIND_MANGA ] ?? array(), 'manga yapımı' );
	}
);

echo "\n" . $passed . '/' . ( $passed + $failures ) . " kontrol geçti\n";

exit( $failures > 0 ? 1 : 0 );
