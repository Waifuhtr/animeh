<?php
/**
 * Manga: reading, importing and the two metadata sources.
 *
 * The catalogue itself needed no new endpoints — a manga is a work with
 * `kind = manga`, and a chapter is one of its episodes, so browsing, the
 * library, history, progress and the points that come with finishing
 * something all worked the moment the rows existed. What is here is the two
 * things that genuinely differ: a chapter is read rather than played, and its
 * pages came from somewhere else and have to be brought home.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\B2Client;
use Animeh\Storage\CatalogRepository;
use Animeh\Storage\CatalogSchema;
use Animeh\Storage\GalleryClient;
use Animeh\Storage\MangaBridge;
use Animeh\Storage\MangaImporter;
use Animeh\Storage\StorageSettings;
use Animeh\Storage\TenraiClient;
use Animeh\Storage\UserDataRepository;
use Animeh\Support\B2Url;
use Animeh\Support\ChapterNumber;
use Animeh\Support\MangaMapper;
use Animeh\Support\PageOrder;
use Animeh\Support\StorageKey;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The manga endpoints.
 */
final class MangaController {

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$signed_in = array( AuthController::class, 'require_login' );
		$moderate  = array( Permissions::class, 'require_moderate' );
		$manage    = array( Permissions::class, 'require_manage' );

		// Reading needs an account, exactly as playing does: the catalogue is
		// public and the content is not.
		register_rest_route(
			$namespace,
			'/chapters/(?P<id>\d+)/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pages' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/bridge',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'bridge_status' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_bridge' ),
					'permission_callback' => $manage,
					'args'                => array(
						'url'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ),
						'key'  => array( 'type' => 'string', 'default' => '' ),
						'test' => array( 'type' => 'boolean', 'default' => true ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => $moderate,
				'args'                => array(
					// Zero continues from wherever the last call stopped,
					// which is what the panel sends; a number restarts there.
					'page'  => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'reset' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/mirror',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mirror' ),
				'permission_callback' => $moderate,
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => $moderate,
				'args'                => array(
					'q'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'source' => array(
						'type'    => 'string',
						'default' => 'tenrai',
						'enum'    => array( 'tenrai', 'gallery' ),
					),
					'page'   => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $moderate,
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'source' => array(
						'type'    => 'string',
						'default' => 'tenrai',
						'enum'    => array( 'tenrai', 'gallery' ),
					),
					// A gallery is also a chapter: its pages are the thing.
					// A metadata-only import leaves the chapters to the sync.
					'with_pages' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);

		// Chapters, added and edited here rather than only arriving from
		// somewhere else: a manga that was typed in by hand needs the same
		// surface as one that was imported.
		register_rest_route(
			$namespace,
			'/admin/manga/works/(?P<id>\d+)/chapters',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_chapters' ),
					'permission_callback' => $moderate,
					'args'                => array(
						'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_chapter' ),
					'permission_callback' => $moderate,
					'args'                => array(
						'id'         => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						// Decimal on purpose: 10.5 is a chapter of its own.
						'number'     => array( 'type' => 'number', 'required' => true ),
						'chapter_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
						'title'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'published'  => array( 'type' => 'boolean', 'default' => true ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/chapters/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_chapter' ),
				'permission_callback' => $moderate,
				'args'                => array(
					'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/chapters/(?P<id>\d+)/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_pages' ),
					'permission_callback' => $moderate,
					'args'                => array(
						'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_pages' ),
					'permission_callback' => $moderate,
					'args'                => array(
						'id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						// Off by default: adding a second batch to a chapter
						// that already has pages is the normal case.
						'replace' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_pages' ),
					'permission_callback' => $moderate,
					'args'                => array(
						'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/manga/gallery',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'gallery_settings' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_gallery_settings' ),
					'permission_callback' => $manage,
					'args'                => array(
						'enabled' => array( 'type' => 'boolean', 'default' => false ),
						'key'     => array( 'type' => 'string', 'default' => '' ),
					),
				),
			)
		);
	}

	/* ── Reading ─────────────────────────────────────────────────────── */

	/**
	 * Every page of one chapter, with both addresses for each.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pages( WP_REST_Request $request ) {
		$repo    = new CatalogRepository();
		$chapter = $repo->episode( (int) $request->get_param( 'id' ) );

		if ( null === $chapter ) {
			return new WP_Error( 'animeh_chapter_missing', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$work = $repo->work( (int) $chapter['work_id'] );
		if ( null === $work ) {
			return new WP_Error( 'animeh_work_missing', __( 'Manga bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$settings = StorageSettings::load();
		$client   = '' !== $settings->bucket && '' !== $settings->key_id ? new B2Client( $settings ) : null;
		$remote   = self::remote_endpoint();

		$pages = array();
		foreach ( $repo->pages( (int) $chapter['id'] ) as $page ) {
			$pages[] = self::page_payload( $page, $settings, $client, $remote );
		}

		$next     = $repo->adjacent_episode( $chapter, 1 );
		$previous = $repo->adjacent_episode( $chapter, -1 );
		$progress = ( new UserDataRepository() )->progress( get_current_user_id(), (int) $chapter['id'] );

		return new WP_REST_Response(
			array(
				'chapter'  => CatalogController::episode_payload(
					array_merge( $chapter, array( 'page_count' => count( $pages ) ) )
				),
				'work'     => CatalogController::work_payload( $work ),
				'pages'    => $pages,
				'next'     => null !== $next ? CatalogController::episode_payload( $next ) : null,
				'previous' => null !== $previous ? CatalogController::episode_payload( $previous ) : null,
				'progress' => null !== $progress
					? array(
						'position' => (int) $progress['position_seconds'],
						'total'    => (int) $progress['duration_seconds'],
						'completed' => (bool) $progress['completed'],
					)
					: null,
			)
		);
	}

	/**
	 * One page, as an address and every alternative to it.
	 *
	 * The order is deliberate and is the answer to "friendly URL bozuksa":
	 * our own bucket first, because after a mirror run that is where the file
	 * really lives; the same object's S3 address second, because the friendly
	 * host fails by itself and the S3 one keeps answering; and the manga
	 * site's own URL last, so a page that has not been copied yet still opens.
	 *
	 * @param array<string, mixed> $page     Source row.
	 * @param StorageSettings      $settings Storage.
	 * @param B2Client|null        $client   Storage client, when configured.
	 * @param string               $remote   The manga site's S3 endpoint.
	 * @return array<string, mixed>
	 */
	private static function page_payload( array $page, StorageSettings $settings, ?B2Client $client, string $remote ): array {
		$urls = array();

		$key = (string) $page['storage_key'];
		if ( '' !== $key && null !== $client ) {
			if ( $settings->public_bucket ) {
				$friendly = $settings->friendly_url( $key );
				if ( '' !== $friendly ) {
					$urls[] = $friendly;
				}
				$urls[] = $settings->s3_url( $key );
			} else {
				$urls[] = $client->presign_get( $key );
			}
		}

		$external = (string) $page['external_url'];
		if ( '' !== $external ) {
			$urls[] = $external;

			// And its own S3 form, which is the address that keeps working on
			// the days the manga site's friendly host does not.
			$alternate = B2Url::alternate( $external, $remote );
			if ( '' !== $alternate ) {
				$urls[] = $alternate;
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );

		return array(
			'position'      => (int) $page['sort_order'],
			'url'           => $urls[0] ?? '',
			'fallback_urls' => array_values( array_slice( $urls, 1 ) ),
			'mime'          => (string) $page['mime'],
			'height'        => (int) $page['height'],
			'size_bytes'    => (int) $page['size_bytes'],
			// True once the file is ours. The panel shows it; the reader does
			// not care which one it got, only that one of them opened.
			'mirrored'      => '' !== (string) $page['storage_key'],
		);
	}

	/* ── Chapters, by hand ───────────────────────────────────────────── */

	/**
	 * Largest page a single upload may carry.
	 *
	 * A manga page is a few hundred kilobytes. Twenty megabytes is far past
	 * anything legitimate and stops one bad file filling the bucket.
	 */
	private const MAX_PAGE_BYTES = 20971520;

	/**
	 * Most pages one chapter may hold.
	 */
	private const MAX_PAGES = 400;

	/**
	 * Every chapter of one manga, for the panel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_chapters( WP_REST_Request $request ) {
		$repo = new CatalogRepository();
		$work = $repo->work( (int) $request->get_param( 'id' ) );

		if ( null === $work ) {
			return new WP_Error( 'animeh_work_missing', __( 'Manga bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$items = array();
		foreach ( $repo->episodes( (int) $work['id'], 0, true ) as $chapter ) {
			$items[] = CatalogController::episode_payload( $chapter );
		}

		return new WP_REST_Response(
			array(
				'work'  => CatalogController::work_payload( $work ),
				'items' => $items,
			)
		);
	}

	/**
	 * Create or rename one chapter.
	 *
	 * Its cover is the manga's, always. A chapter thumbnail that has to be
	 * chosen is a field nobody fills in, and a reader full of grey rectangles
	 * is worse than one where every chapter wears the cover it belongs to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_chapter( WP_REST_Request $request ) {
		$repo = new CatalogRepository();
		$work = $repo->work( (int) $request->get_param( 'id' ) );

		if ( null === $work ) {
			return new WP_Error( 'animeh_work_missing', __( 'Manga bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$number = ChapterNumber::parse( $request->get_param( 'number' ) );
		if ( $number <= 0 ) {
			return new WP_Error( 'animeh_chapter_number', __( 'Bölüm numarası gerekli.', 'animeh' ), array( 'status' => 400 ) );
		}

		$chapter_id = (int) $request->get_param( 'chapter_id' );

		// A number already in use is the same chapter, not a second one: the
		// panel sends a number and expects to land on the chapter it names.
		if ( $chapter_id <= 0 ) {
			$chapter_id = self::chapter_by_number( (int) $work['id'], $number );
		}

		$data = array(
			'season_number' => 1,
			'number'        => $number,
			'title'         => (string) $request->get_param( 'title' ),
			'thumbnail_url' => (string) $work['poster_url'],
			'published'     => $request->get_param( 'published' ) ? 1 : 0,
			'published_at'  => current_time( 'mysql', true ),
		);

		$saved = $repo->save_episode( (int) $work['id'], $data, $chapter_id );

		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$chapter = $repo->episode( (int) $saved );

		return new WP_REST_Response(
			array(
				'chapter' => null !== $chapter ? CatalogController::episode_payload( $chapter ) : null,
			),
			$chapter_id > 0 ? 200 : 201
		);
	}

	/**
	 * Remove a chapter and the page rows under it.
	 *
	 * The objects in the bucket are left where they are. Deleting a chapter in
	 * the panel is routine and undoing it is not, so the row goes and the
	 * bytes stay until somebody clears the bucket deliberately.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_chapter( WP_REST_Request $request ) {
		$repo    = new CatalogRepository();
		$chapter = $repo->episode( (int) $request->get_param( 'id' ) );

		if ( null === $chapter ) {
			return new WP_Error( 'animeh_chapter_missing', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$repo->delete_episode( (int) $chapter['id'] );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * The pages of a chapter, as the panel lists them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_pages( WP_REST_Request $request ) {
		$repo    = new CatalogRepository();
		$chapter = $repo->episode( (int) $request->get_param( 'id' ) );

		if ( null === $chapter ) {
			return new WP_Error( 'animeh_chapter_missing', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$settings = StorageSettings::load();
		$client   = '' !== $settings->bucket && '' !== $settings->key_id ? new B2Client( $settings ) : null;
		$remote   = self::remote_endpoint();

		$pages = array();
		foreach ( $repo->pages( (int) $chapter['id'] ) as $page ) {
			$pages[] = self::page_payload( $page, $settings, $client, $remote );
		}

		return new WP_REST_Response( array( 'pages' => $pages ) );
	}

	/**
	 * Add pages to a chapter, from loose images or from a zip.
	 *
	 * Both shapes land in the same place because both are how a chapter
	 * actually arrives: a folder of images picked from the phone, or the zip
	 * it was downloaded as. The order is the file names — `1.jpg … 24.jpg` —
	 * compared as numbers, because sorted as text page 10 comes before page 2
	 * and the chapter is silently shuffled.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_pages( WP_REST_Request $request ) {
		$repo    = new CatalogRepository();
		$chapter = $repo->episode( (int) $request->get_param( 'id' ) );

		if ( null === $chapter ) {
			return new WP_Error( 'animeh_chapter_missing', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$work = $repo->work( (int) $chapter['work_id'] );
		if ( null === $work ) {
			return new WP_Error( 'animeh_work_missing', __( 'Manga bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unset',
				__( 'Önce depolama ayarlarını yap: sayfalar kendi kovamıza yükleniyor.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$files = $request->get_file_params();
		$named = self::collect_pages( $files );

		if ( $named instanceof WP_Error ) {
			return $named;
		}
		if ( array() === $named ) {
			return new WP_Error(
				'animeh_no_pages',
				__( 'Görsel bulunamadı. Sayfaları seç ya da bir zip yükle.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		if ( $request->get_param( 'replace' ) ) {
			$repo->delete_pages( (int) $chapter['id'] );
		}

		$slug   = (string) $work['slug'];
		$number = ChapterNumber::parse( $chapter['number'] ?? 1 );
		$client = new B2Client( $settings );
		$start  = count( $repo->pages( (int) $chapter['id'] ) );

		if ( $start + count( $named ) > self::MAX_PAGES ) {
			return new WP_Error(
				'animeh_too_many_pages',
				sprintf(
					/* translators: %d: page limit */
					__( 'Bir bölümde en fazla %d sayfa olabilir.', 'animeh' ),
					self::MAX_PAGES
				),
				array( 'status' => 400 )
			);
		}

		$written = 0;
		$failed  = array();
		$now     = current_time( 'mysql', true );

		foreach ( array_values( $named ) as $index => $page ) {
			$position = $start + $index + 1;
			$key      = StorageKey::chapter_page( $slug, $number, $position, $page['name'] );

			$put = $client->put_object( $key, $page['body'], self::mime_for( $page['name'] ) );

			if ( $put instanceof WP_Error ) {
				$failed[] = array(
					'name'    => $page['name'],
					'message' => $put->get_error_message(),
				);
				continue;
			}

			$repo->save_source(
				array(
					'episode_id'  => (int) $chapter['id'],
					'work_id'     => (int) $work['id'],
					'kind'        => 'page',
					'label'       => $page['name'],
					'storage_key' => $key,
					'size_bytes'  => strlen( $page['body'] ),
					'sort_order'  => $position,
					'created_at'  => $now,
				)
			);

			++$written;
		}

		// The chapter wears the manga's cover, and gains a count.
		$repo->save_episode(
			(int) $work['id'],
			array( 'thumbnail_url' => (string) $work['poster_url'] ),
			(int) $chapter['id']
		);

		return new WP_REST_Response(
			array(
				'written' => $written,
				'failed'  => $failed,
				'pages'   => count( $repo->pages( (int) $chapter['id'] ) ),
			)
		);
	}

	/**
	 * Drop every page of a chapter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clear_pages( WP_REST_Request $request ) {
		$repo    = new CatalogRepository();
		$chapter = $repo->episode( (int) $request->get_param( 'id' ) );

		if ( null === $chapter ) {
			return new WP_Error( 'animeh_chapter_missing', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$repo->delete_pages( (int) $chapter['id'] );

		return new WP_REST_Response( array( 'cleared' => true ) );
	}

	/**
	 * Every page in the request, named and in reading order.
	 *
	 * @param array<string, mixed> $files `$_FILES`, as REST hands it over.
	 * @return array<int, array{name: string, body: string}>|WP_Error
	 */
	private static function collect_pages( array $files ) {
		$loose = array();

		foreach ( array( 'file', 'files', 'page', 'pages' ) as $field ) {
			foreach ( self::normalise_uploads( $files[ $field ] ?? null ) as $upload ) {
				$name = (string) ( $upload['name'] ?? '' );

				if ( 'zip' === strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
					$unpacked = self::unzip_pages( (string) $upload['tmp_name'] );

					if ( $unpacked instanceof WP_Error ) {
						return $unpacked;
					}

					$loose = array_merge( $loose, $unpacked );
					continue;
				}

				if ( ! PageOrder::is_image( $name ) ) {
					continue;
				}

				$body = self::read_upload( (string) $upload['tmp_name'] );
				if ( '' === $body ) {
					continue;
				}

				$loose[ $name ] = $body;
			}
		}

		$ordered = array();
		foreach ( PageOrder::sort( array_keys( $loose ) ) as $name ) {
			$ordered[] = array(
				'name' => $name,
				'body' => $loose[ $name ],
			);
		}

		return $ordered;
	}

	/**
	 * The images inside a zip, by name.
	 *
	 * @param string $path Uploaded archive.
	 * @return array<string, string>|WP_Error
	 */
	private static function unzip_pages( string $path ) {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return new WP_Error(
				'animeh_no_zip',
				__( 'Sunucuda zip desteği yok. Sayfaları tek tek seçerek yükleyebilirsin.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'animeh_bad_zip', __( 'Zip açılamadı.', 'animeh' ), array( 'status' => 400 ) );
		}

		$found = array();

		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$name = (string) $zip->getNameIndex( $index );

			if ( ! PageOrder::is_image( $name ) ) {
				continue;
			}

			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) || (int) $stat['size'] > self::MAX_PAGE_BYTES ) {
				continue;
			}

			$body = $zip->getFromIndex( $index );
			if ( ! is_string( $body ) || '' === $body ) {
				continue;
			}

			// Keyed by the name inside the archive, so two folders holding a
			// `1.jpg` each do not overwrite one another.
			$found[ $name ] = $body;
		}

		$zip->close();

		return $found;
	}

	/**
	 * One uploaded file's bytes, or an empty string.
	 *
	 * @param string $path Temporary path.
	 */
	private static function read_upload( string $path ): string {
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		if ( ! is_uploaded_file( $path ) && ! defined( 'ANIMEH_TESTING' ) ) {
			return '';
		}
		if ( filesize( $path ) > self::MAX_PAGE_BYTES ) {
			return '';
		}

		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_string( $body ) ? $body : '';
	}

	/**
	 * One or many uploads under a field, flattened.
	 *
	 * @param mixed $field One `$_FILES` entry.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalise_uploads( $field ): array {
		if ( ! is_array( $field ) || ! isset( $field['tmp_name'] ) ) {
			return array();
		}

		if ( ! is_array( $field['tmp_name'] ) ) {
			return UPLOAD_ERR_OK === (int) ( $field['error'] ?? UPLOAD_ERR_NO_FILE )
				? array( $field )
				: array();
		}

		$uploads = array();
		foreach ( array_keys( $field['tmp_name'] ) as $index ) {
			if ( UPLOAD_ERR_OK !== (int) ( $field['error'][ $index ] ?? UPLOAD_ERR_NO_FILE ) ) {
				continue;
			}

			$uploads[] = array(
				'name'     => (string) ( $field['name'][ $index ] ?? '' ),
				'tmp_name' => (string) $field['tmp_name'][ $index ],
				'error'    => UPLOAD_ERR_OK,
			);
		}

		return $uploads;
	}

	/**
	 * A chapter of this manga with this number, if there is one.
	 *
	 * @param int   $work_id Manga.
	 * @param float $number  Chapter number.
	 */
	private static function chapter_by_number( int $work_id, float $number ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT id FROM ' . CatalogSchema::episodes() . ' WHERE work_id = %d AND number = %f LIMIT 1',
				$work_id,
				$number
			)
		);
	}

	/**
	 * Content type from a file name.
	 *
	 * @param string $name File name.
	 */
	private static function mime_for( string $name ): string {
		return match ( strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'bmp'  => 'image/bmp',
			default => 'image/jpeg',
		};
	}

	/* ── The bridge ──────────────────────────────────────────────────── */

	/**
	 * Where the import stands.
	 *
	 * @return WP_REST_Response
	 */
	public function bridge_status(): WP_REST_Response {
		$settings = MangaBridge::settings();

		return new WP_REST_Response(
			array(
				'url'          => $settings['url'],
				// Never the key itself. The panel shows whether one is stored
				// and nothing more; there is no screen that needs to read it
				// back and every reason not to send it.
				'has_key'      => '' !== $settings['key'],
				'connected_at' => $settings['connected_at'],
				'site'         => $settings['site'],
				'sync'         => MangaImporter::state(),
				'mirror'       => MangaImporter::mirror_progress(),
				'counts'       => self::counts(),
			)
		);
	}

	/**
	 * Store the bridge address and key, and say hello.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_bridge( WP_REST_Request $request ) {
		MangaBridge::save(
			(string) $request->get_param( 'url' ),
			(string) $request->get_param( 'key' )
		);

		if ( ! $request->get_param( 'test' ) ) {
			return $this->bridge_status();
		}

		$ping = MangaBridge::ping();

		if ( $ping instanceof WP_Error ) {
			return $ping;
		}

		// Remembered so a page that fails on her friendly host can be retried
		// on her S3 one without asking for the endpoint a second time.
		if ( isset( $ping['storage'] ) && is_array( $ping['storage'] ) ) {
			MangaImporter::remember_storage( $ping['storage'] );
		}

		$status               = $this->bridge_status()->get_data();
		$status['remote']     = $ping;

		return new WP_REST_Response( $status );
	}

	/**
	 * Import one batch of manga.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync( WP_REST_Request $request ) {
		if ( $request->get_param( 'reset' ) ) {
			MangaImporter::reset();
		}

		$result = MangaImporter::sync( (int) $request->get_param( 'page' ) );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$result['counts'] = self::counts();
		$result['mirror'] = MangaImporter::mirror_progress();

		return new WP_REST_Response( $result );
	}

	/**
	 * Copy one batch of pages into our bucket.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function mirror() {
		$result = MangaImporter::mirror();

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/* ── Metadata sources ────────────────────────────────────────────── */

	/**
	 * Search one of the two sources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$query  = trim( (string) $request->get_param( 'q' ) );
		$source = (string) $request->get_param( 'source' );
		$page   = max( 1, (int) $request->get_param( 'page' ) );

		if ( '' === $query ) {
			return new WP_REST_Response( array( 'source' => $source, 'items' => array() ) );
		}

		if ( 'gallery' === $source ) {
			$results = ( new GalleryClient() )->search( $query, $page );

			if ( $results instanceof WP_Error ) {
				return $results;
			}

			$items = array();
			foreach ( $results as $gallery ) {
				$items[] = MangaMapper::search_row( MangaMapper::from_gallery( $gallery ), 'gallery' );
			}

			return new WP_REST_Response( array( 'source' => 'gallery', 'items' => $items ) );
		}

		$response = ( new TenraiClient() )->search_manga( $query, $page );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$items = array();
		foreach ( (array) ( $response['data'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) ) {
				$items[] = MangaMapper::search_row( MangaMapper::from_jikan( $entry ), 'tenrai' );
			}
		}

		return new WP_REST_Response( array( 'source' => 'tenrai', 'items' => $items ) );
	}

	/**
	 * Bring one manga into the catalogue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$source = (string) $request->get_param( 'source' );

		$mapped = 'gallery' === $source
			? $this->gallery_work( $id )
			: $this->tenrai_work( $id );

		if ( $mapped instanceof WP_Error ) {
			return $mapped;
		}

		$repo     = new CatalogRepository();
		$existing = self::existing_work( $mapped['work'] );

		$row = array(
			'kind'           => CatalogSchema::KIND_MANGA,
			'mal_id'         => (int) $mapped['work']['mal_id'],
			'nh_id'          => (int) $mapped['work']['nh_id'],
			'title'          => (string) $mapped['work']['title'],
			'title_english'  => (string) $mapped['work']['title_english'],
			'title_japanese' => (string) $mapped['work']['title_japanese'],
			'synonyms'       => wp_json_encode( $mapped['work']['synonyms'] ),
			'synopsis'       => (string) $mapped['work']['synopsis'],
			'poster_url'     => (string) $mapped['work']['poster_url'],
			'score'          => (float) $mapped['work']['score'],
			'popularity'     => (int) $mapped['work']['popularity'],
			'year'           => (int) $mapped['work']['year'],
			'status'         => (string) $mapped['work']['status'],
			'format'         => (string) $mapped['work']['format'],
			'author'         => (string) $mapped['work']['author'],
			'studio'         => (string) $mapped['work']['studio'],
			'genres'         => wp_json_encode( $mapped['work']['genres'] ),
			'total_episodes' => (int) $mapped['work']['total_episodes'],
			'adult'          => $mapped['work']['adult'] ? 1 : 0,
			'published'      => 1,
			'created_by'     => get_current_user_id(),
		);

		if ( null !== $existing ) {
			// A title somebody corrected here stands. Everything else is
			// refreshed, which is the point of importing the same work twice.
			unset( $row['title'] );
			$work_id = (int) $existing['id'];
			$repo->save_work( $row, $work_id );
		} else {
			$saved = $repo->save_work( $row );
			if ( $saved instanceof WP_Error ) {
				return $saved;
			}
			$work_id = (int) $saved;
		}

		$chapters = 0;
		if ( $request->get_param( 'with_pages' ) && array() !== $mapped['pages'] ) {
			$chapters = $this->write_gallery_chapter( $repo, $work_id, $mapped['pages'] );
		}

		// Read back rather than echo what was sent: the row is what the app
		// will see everywhere else. If it cannot be read the import still
		// happened, so the id is reported and the payload is simply absent —
		// formatting an empty row would fill the response with warnings and,
		// on a host that prints them, break the JSON around it.
		$saved_row = $repo->work( $work_id );

		return new WP_REST_Response(
			array(
				'work_id'  => $work_id,
				'created'  => null === $existing,
				'chapters' => $chapters,
				'work'     => null !== $saved_row ? CatalogController::work_payload( $saved_row ) : null,
			)
		);
	}

	/**
	 * One manga from Tenrai, mapped.
	 *
	 * @param int $id MAL id.
	 * @return array{work: array<string, mixed>, pages: array<int, array<string, mixed>>}|WP_Error
	 */
	private function tenrai_work( int $id ) {
		$response = ( new TenraiClient() )->manga( $id );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$entry = $response['data'] ?? null;
		if ( ! is_array( $entry ) ) {
			return new WP_Error( 'animeh_manga_format', __( 'Kaynak yanıtı okunamadı.', 'animeh' ), array( 'status' => 502 ) );
		}

		return array(
			'work'  => MangaMapper::from_jikan( $entry ),
			// Tenrai carries metadata and never pages; chapters come from the
			// bridge or are added by hand.
			'pages' => array(),
		);
	}

	/**
	 * One gallery, mapped, with its pages.
	 *
	 * @param int $id Gallery id.
	 * @return array{work: array<string, mixed>, pages: array<int, array<string, mixed>>}|WP_Error
	 */
	private function gallery_work( int $id ) {
		$gallery = ( new GalleryClient() )->gallery( $id );

		if ( $gallery instanceof WP_Error ) {
			return $gallery;
		}

		return array(
			'work'  => MangaMapper::from_gallery( $gallery ),
			'pages' => (array) ( $gallery['pages'] ?? array() ),
		);
	}

	/**
	 * A gallery's pages, as chapter one.
	 *
	 * A gallery is one book rather than a serial, so it becomes a single
	 * chapter. The pages start out pointing at the source and are copied into
	 * our bucket by the same mirror job that handles the bridge's, which is
	 * why they are written as ordinary page rows here.
	 *
	 * @param CatalogRepository                $repo    Catalogue.
	 * @param int                              $work_id Work.
	 * @param array<int, array<string, mixed>> $pages   Page list.
	 * @return int Chapters written.
	 */
	private function write_gallery_chapter( CatalogRepository $repo, int $work_id, array $pages ): int {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::episodes() . ' WHERE work_id = %d AND season_number = 1 AND number = 1',
				$work_id
			),
			ARRAY_A
		);

		// The chapter's cover is the manga's. A first page is whatever the
		// artist put there — a title card, a blank, a warning — and a shelf of
		// those reads as broken next to a shelf of covers.
		$work = $repo->work( $work_id );

		$data = array(
			'season_number' => 1,
			'number'        => 1,
			'title'         => '',
			'thumbnail_url' => null !== $work ? (string) $work['poster_url'] : '',
			'published'     => 1,
			'published_at'  => current_time( 'mysql', true ),
		);

		if ( null === $existing ) {
			$episode_id = $repo->save_episode( $work_id, $data );
			if ( $episode_id instanceof WP_Error ) {
				return 0;
			}
			$episode_id = (int) $episode_id;
		} else {
			$episode_id = (int) $existing['id'];
			$repo->save_episode( $work_id, $data, $episode_id );
		}

		$table = CatalogSchema::sources();
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE episode_id = %d AND kind = 'page' AND storage_key = ''", $episode_id )
		);

		$now = current_time( 'mysql', true );
		foreach ( $pages as $index => $page ) {
			$url = (string) ( $page['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}

			$position = (int) ( $page['position'] ?? $index + 1 );

			$already = (int) $wpdb->get_var(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
					"SELECT COUNT(*) FROM {$table} WHERE episode_id = %d AND kind = 'page' AND sort_order = %d",
					$episode_id,
					$position
				)
			);

			if ( $already > 0 ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'episode_id'   => $episode_id,
					'work_id'      => $work_id,
					'kind'         => 'page',
					'label'        => (string) ( $page['file'] ?? '' ),
					'external_url' => $url,
					'sort_order'   => $position,
					'created_at'   => $now,
				)
			);
		}

		return 1;
	}

	/**
	 * The work a source id already belongs to, if any.
	 *
	 * @param array<string, mixed> $work Mapped work.
	 * @return array<string, mixed>|null
	 */
	private static function existing_work( array $work ): ?array {
		global $wpdb;

		$table = CatalogSchema::works();
		$kind  = CatalogSchema::KIND_MANGA;

		if ( (int) $work['nh_id'] > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					"SELECT * FROM {$table} WHERE kind = %s AND nh_id = %d",
					$kind,
					(int) $work['nh_id']
				),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				return $row;
			}
		}

		if ( (int) $work['mal_id'] > 0 ) {
			// Scoped by kind: MAL numbers anime and manga separately, so id 5
			// is two different things and matching without this would attach a
			// manga's chapters to an anime.
			$row = $wpdb->get_row(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					"SELECT * FROM {$table} WHERE kind = %s AND mal_id = %d",
					$kind,
					(int) $work['mal_id']
				),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				return $row;
			}
		}

		return null;
	}

	/* ── Gallery source settings ─────────────────────────────────────── */

	/**
	 * Whether the gallery source is on.
	 *
	 * @return WP_REST_Response
	 */
	public function gallery_settings(): WP_REST_Response {
		$settings = GalleryClient::settings();

		return new WP_REST_Response(
			array(
				'enabled' => $settings['enabled'],
				'has_key' => '' !== $settings['key'],
			)
		);
	}

	/**
	 * Turn the gallery source on or off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_gallery_settings( WP_REST_Request $request ): WP_REST_Response {
		GalleryClient::save_settings(
			array(
				'enabled' => (bool) $request->get_param( 'enabled' ),
				'key'     => (string) $request->get_param( 'key' ),
			)
		);

		return $this->gallery_settings();
	}

	/* ── Small helpers ───────────────────────────────────────────────── */

	/**
	 * How much manga there is here.
	 *
	 * @return array{works: int, chapters: int, pages: int}
	 */
	private static function counts(): array {
		global $wpdb;

		$works    = CatalogSchema::works();
		$episodes = CatalogSchema::episodes();
		$sources  = CatalogSchema::sources();
		$kind     = CatalogSchema::KIND_MANGA;

		return array(
			'works'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$works} WHERE kind = %s", $kind ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			'chapters' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$episodes} e INNER JOIN {$works} w ON w.id = e.work_id WHERE w.kind = %s", $kind ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			'pages'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sources} WHERE kind = 'page'" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * The manga site's S3 endpoint, as its handshake reported it.
	 *
	 * @return string
	 */
	private static function remote_endpoint(): string {
		$stored = get_option( 'animeh_manga_bridge_storage', array() );
		$stored = is_array( $stored ) ? $stored : array();

		return (string) ( $stored['b2_s3_endpoint'] ?? '' );
	}
}
