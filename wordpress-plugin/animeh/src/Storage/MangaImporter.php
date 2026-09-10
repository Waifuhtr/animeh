<?php
/**
 * The manga site's library, brought over and then made independent of it.
 *
 * Two jobs, deliberately separate:
 *
 * **Sync** reads the bridge and writes rows. Cheap, quick, and safe to run
 * again — every write is keyed on the other site's post id, so a second run
 * updates rather than duplicates.
 *
 * **Mirror** copies each page image into our own bucket and points the row at
 * the copy. Expensive, slow, and the whole reason for doing any of this: after
 * it has run, the manga site can close tomorrow and nothing in the app
 * notices. Until it has run, the pages still work — they are served from her
 * site, which is what the `external_url` column is for.
 *
 * Both are cursored and batched, because a shared host's reverse proxy stops
 * listening after about thirty seconds and a hundred chapters is not a thing
 * one request can do.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\B2Url;
use Animeh\Support\ChapterNumber;
use Animeh\Support\StorageKey;
use WP_Error;

/**
 * Pulls the manga catalogue across and mirrors its images.
 */
final class MangaImporter {

	/**
	 * Option holding where the sync got to.
	 */
	private const STATE_OPTION = 'animeh_manga_sync_state';

	/**
	 * Manga read per sync call.
	 *
	 * Each one costs a bridge request for the manga plus one per page of its
	 * chapters, so this is small on purpose.
	 */
	public const SYNC_BATCH = 3;

	/**
	 * Pages copied per mirror call.
	 *
	 * Each is a download from her host and an upload to ours — call it a
	 * second each on a bad day. Twenty-five keeps a call under a proxy's
	 * patience with room to spare.
	 */
	public const MIRROR_BATCH = 25;

	/**
	 * Longest a single page download may take.
	 */
	private const FETCH_TIMEOUT = 20;

	/**
	 * Largest page image accepted.
	 *
	 * A manga page is a few hundred kilobytes. Anything past this is either a
	 * scan nobody needed at that size or a mistake, and either way it is not
	 * worth the memory on a shared host.
	 */
	private const MAX_PAGE_BYTES = 12 * 1024 * 1024;

	/**
	 * Where the last sync stopped.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$stored = get_option( self::STATE_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'page'        => max( 1, (int) ( $stored['page'] ?? 1 ) ),
			'pages'       => (int) ( $stored['pages'] ?? 0 ),
			'total'       => (int) ( $stored['total'] ?? 0 ),
			'imported'    => (int) ( $stored['imported'] ?? 0 ),
			'chapters'    => (int) ( $stored['chapters'] ?? 0 ),
			'pages_seen'  => (int) ( $stored['pages_seen'] ?? 0 ),
			'finished_at' => (string) ( $stored['finished_at'] ?? '' ),
			'last_error'  => (string) ( $stored['last_error'] ?? '' ),
		);
	}

	/**
	 * Forget where the sync got to, so the next run starts from the top.
	 */
	public static function reset(): void {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Import one batch of manga, with their chapters and page addresses.
	 *
	 * @param int $page Which page of the bridge's listing, or 0 to continue.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function sync( int $page = 0 ) {
		$state = self::state();
		$page  = $page > 0 ? $page : $state['page'];

		$listing = MangaBridge::manga( $page, self::SYNC_BATCH );

		if ( $listing instanceof WP_Error ) {
			$state['last_error'] = $listing->get_error_message();
			update_option( self::STATE_OPTION, $state, false );

			return $listing;
		}

		$items = is_array( $listing['items'] ?? null ) ? $listing['items'] : array();
		$repo  = new CatalogRepository();

		$imported = array();
		$chapters = 0;
		$pages    = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$work_id = self::upsert_manga( $repo, $item );

			if ( $work_id instanceof WP_Error ) {
				$state['last_error'] = $work_id->get_error_message();
				update_option( self::STATE_OPTION, $state, false );

				return $work_id;
			}

			// Zero is the one thing worth passing over: an entry with no id on
			// the other side is not something this end can do anything about.
			if ( $work_id <= 0 ) {
				continue;
			}

			$counts    = self::sync_chapters( $repo, $work_id, (int) $item['id'] );
			$chapters += $counts['chapters'];
			$pages    += $counts['pages'];

			$imported[] = array(
				'work_id'  => $work_id,
				'title'    => (string) ( $item['title'] ?? '' ),
				'chapters' => $counts['chapters'],
			);
		}

		$total_pages = max( 1, (int) ( $listing['pages'] ?? 1 ) );
		$done        = $page >= $total_pages;

		$state['page']       = $done ? 1 : $page + 1;
		$state['pages']      = $total_pages;
		$state['total']      = (int) ( $listing['total'] ?? 0 );
		$state['imported']   = $done ? 0 : $state['imported'] + count( $imported );
		$state['chapters']   = $done ? 0 : $state['chapters'] + $chapters;
		$state['pages_seen'] = $done ? 0 : $state['pages_seen'] + $pages;
		$state['last_error'] = '';
		if ( $done ) {
			$state['finished_at'] = current_time( 'mysql', true );
		}

		update_option( self::STATE_OPTION, $state, false );

		return array(
			'done'      => $done,
			'page'      => $page,
			'pages'     => $total_pages,
			'total'     => (int) ( $listing['total'] ?? 0 ),
			'imported'  => $imported,
			'chapters'  => $chapters,
			'page_rows' => $pages,
			'next'      => $done ? 0 : $page + 1,
		);
	}

	/**
	 * Create or update the work behind one manga.
	 *
	 * Matched on the bridge's own post id, kept in `nh_id`'s neighbour column
	 * — see [self::remote_key] — so the same manga synced twice is the same
	 * work rather than two.
	 *
	 * @param CatalogRepository    $repo Catalogue.
	 * @param array<string, mixed> $item Bridge payload.
	 * @return int|WP_Error Work id, 0 when the entry has no id, or the
	 *                      database's refusal.
	 */
	private static function upsert_manga( CatalogRepository $repo, array $item ) {
		$remote_id = (int) ( $item['id'] ?? 0 );
		if ( $remote_id <= 0 ) {
			return 0;
		}

		$existing = self::work_by_remote( $remote_id );
		$meta     = is_array( $item['meta'] ?? null ) ? $item['meta'] : array();
		$tax      = is_array( $item['taxonomies'] ?? null ) ? $item['taxonomies'] : array();

		$synonyms = array_values(
			array_filter(
				array_map(
					'trim',
					explode( ',', (string) ( $meta['alternative_titles'] ?? '' ) )
				)
			)
		);

		$data = array(
			'kind'           => CatalogSchema::KIND_MANGA,
			'mal_id'         => (int) ( $meta['mal_id'] ?? 0 ),
			'title'          => (string) ( $item['title'] ?? '' ),
			'synopsis'       => (string) ( $item['synopsis'] ?? '' ),
			'poster_url'     => (string) ( $item['cover'] ?? '' ),
			'score'          => (float) ( $meta['score'] ?? 0 ),
			'year'           => (int) ( $meta['year'] ?? 0 ),
			'status'         => self::status_from_terms( (array) ( $tax['status'] ?? array() ) ),
			'format'         => 'Manga',
			'author'         => (string) ( $meta['author'] ?? '' ),
			'studio'         => implode( ', ', array_slice( (array) ( $tax['group'] ?? array() ), 0, 3 ) ),
			'synonyms'       => wp_json_encode( $synonyms ),
			'genres'         => wp_json_encode( self::genres_from_terms( $tax ) ),
			'total_episodes' => (int) ( $item['chapter_count'] ?? 0 ),
			'published'      => 1,
			'adult'          => ! empty( $meta['nsfw'] ) ? 1 : 0,
		);

		if ( null === $existing ) {
			// The slug the other site used, so a link shared from there still
			// finds the same work here.
			$data['slug'] = (string) ( $item['slug'] ?? '' );

			$id = $repo->save_work( $data );
			if ( $id instanceof WP_Error ) {
				// Handed back rather than counted as a skip. A row that will
				// not write is not one bad manga, it is the database refusing,
				// and the run that swallowed it spent nine pages reporting
				// success while importing nothing.
				return $id;
			}

			self::remember_remote( (int) $id, $remote_id );

			return (int) $id;
		}

		// A title somebody has since corrected here is not overwritten by the
		// one over there, and neither is the poster: this end is where the
		// catalogue is curated once a work has arrived.
		unset( $data['title'] );
		if ( '' !== (string) $existing['poster_url'] ) {
			unset( $data['poster_url'] );
		}
		if ( '' !== (string) $existing['synopsis'] ) {
			unset( $data['synopsis'] );
		}

		$saved = $repo->save_work( $data, (int) $existing['id'] );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		return (int) $existing['id'];
	}

	/**
	 * Pull every chapter of one manga.
	 *
	 * @param CatalogRepository $repo      Catalogue.
	 * @param int               $work_id   Our work.
	 * @param int               $remote_id Their manga.
	 * @return array{chapters: int, pages: int}
	 */
	private static function sync_chapters( CatalogRepository $repo, int $work_id, int $remote_id ): array {
		$chapters = 0;
		$pages    = 0;
		$page     = 1;

		do {
			$listing = MangaBridge::chapters( $remote_id, $page, 20 );

			if ( $listing instanceof WP_Error ) {
				break;
			}

			foreach ( (array) ( $listing['items'] ?? array() ) as $chapter ) {
				if ( ! is_array( $chapter ) ) {
					continue;
				}

				$written = self::upsert_chapter( $repo, $work_id, $chapter );
				if ( $written > 0 ) {
					++$chapters;
					$pages += $written;
				}
			}

			$total = max( 1, (int) ( $listing['pages'] ?? 1 ) );
			++$page;
		} while ( $page <= $total && $page <= 50 );

		return array(
			'chapters' => $chapters,
			'pages'    => $pages,
		);
	}

	/**
	 * Create or update one chapter and its page rows.
	 *
	 * @param CatalogRepository    $repo    Catalogue.
	 * @param int                  $work_id Work.
	 * @param array<string, mixed> $chapter Bridge payload.
	 * @return int How many pages the chapter now has.
	 */
	private static function upsert_chapter( CatalogRepository $repo, int $work_id, array $chapter ): int {
		global $wpdb;

		$number = ChapterNumber::parse( $chapter['number'] ?? $chapter['title'] ?? 0 );
		if ( $number <= 0 ) {
			return 0;
		}

		$pages = array_values( array_filter( (array) ( $chapter['pages'] ?? array() ), 'is_array' ) );

		// Every chapter is season one. Manga do not have seasons, and the
		// column is part of the key that makes a chapter unique within a work.
		$existing = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::episodes() . ' WHERE work_id = %d AND season_number = 1 AND number = %f',
				$work_id,
				$number
			),
			ARRAY_A
		);

		$data = array(
			'season_number' => 1,
			'number'        => $number,
			'title'         => self::chapter_title( (string) ( $chapter['title'] ?? '' ), $number ),
			'thumbnail_url' => (string) ( $pages[0]['url'] ?? '' ),
			'published'     => 1,
			'published_at'  => self::datetime( (string) ( $chapter['created_gmt'] ?? '' ) ),
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

		return self::write_pages( $episode_id, $work_id, $pages );
	}

	/**
	 * Replace a chapter's page rows with the ones the bridge just gave.
	 *
	 * Rows that already carry a mirrored copy keep it: their `storage_key`
	 * points at our bucket, and throwing that away would mean downloading
	 * every page again on the next sync.
	 *
	 * @param int                              $episode_id Chapter.
	 * @param int                              $work_id    Work.
	 * @param array<int, array<string, mixed>> $pages      Bridge pages.
	 * @return int How many pages the chapter has.
	 */
	private static function write_pages( int $episode_id, int $work_id, array $pages ): int {
		global $wpdb;

		if ( array() === $pages ) {
			return 0;
		}

		$table = CatalogSchema::sources();

		$mirrored = array();
		$rows     = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT sort_order, storage_key FROM {$table} WHERE episode_id = %d AND kind = 'page' AND storage_key <> ''",
				$episode_id
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$mirrored[ (int) $row['sort_order'] ] = (string) $row['storage_key'];
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE episode_id = %d AND kind = 'page'", $episode_id )
		);

		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $pages as $page ) {
			$position = (int) ( $page['position'] ?? ++$count );
			$url      = (string) ( $page['url'] ?? '' );

			if ( '' === $url ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'episode_id'   => $episode_id,
					'work_id'      => $work_id,
					'kind'         => 'page',
					'label'        => (string) ( $page['file'] ?? '' ),
					'storage_key'  => $mirrored[ $position ] ?? '',
					'external_url' => $url,
					'mime'         => self::mime_for( (string) ( $page['file'] ?? $url ) ),
					'sort_order'   => $position,
					'created_at'   => $now,
				)
			);

			++$count;
		}

		return $count;
	}

	/* ── Mirroring ───────────────────────────────────────────────────── */

	/**
	 * How much is still living on somebody else's server.
	 *
	 * @return array{total: int, mirrored: int, pending: int}
	 */
	public static function mirror_progress(): array {
		global $wpdb;

		$table = CatalogSchema::sources();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE kind = 'page'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE kind = 'page' AND storage_key <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return array(
			'total'    => $total,
			'mirrored' => $done,
			'pending'  => max( 0, $total - $done ),
		);
	}

	/**
	 * Copy one batch of pages into our own bucket.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function mirror() {
		global $wpdb;

		$settings = StorageSettings::load();
		if ( '' === $settings->bucket || '' === $settings->key_id ) {
			return new WP_Error(
				'animeh_mirror_storage',
				__( 'Depolama ayarlanmadan kopyalama yapılamaz.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$table  = CatalogSchema::sources();
		$works  = CatalogSchema::works();
		$eps    = CatalogSchema::episodes();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT s.id, s.episode_id, s.external_url, s.label, s.sort_order, e.number, w.slug
				 FROM {$table} s
				 INNER JOIN {$eps} e ON e.id = s.episode_id
				 INNER JOIN {$works} w ON w.id = s.work_id
				 WHERE s.kind = 'page' AND s.storage_key = '' AND s.external_url <> ''
				 ORDER BY s.id ASC
				 LIMIT %d",
				self::MIRROR_BATCH
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();

		if ( array() === $rows ) {
			return array_merge(
				self::mirror_progress(),
				array(
					'done'   => true,
					'copied' => 0,
					'failed' => array(),
				)
			);
		}

		$client = new B2Client( $settings );
		$copied = 0;
		$failed = array();

		foreach ( $rows as $row ) {
			$key = StorageKey::chapter_page(
				(string) $row['slug'],
				(float) $row['number'],
				(int) $row['sort_order'],
				(string) ( '' !== (string) $row['label'] ? $row['label'] : $row['external_url'] )
			);

			$result = self::copy_one( $client, (string) $row['external_url'], $key );

			if ( $result instanceof WP_Error ) {
				$failed[] = array(
					'id'      => (int) $row['id'],
					'url'     => (string) $row['external_url'],
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'storage_key' => $key,
					'size_bytes'  => $result,
				),
				array( 'id' => (int) $row['id'] )
			);

			++$copied;
		}

		return array_merge(
			self::mirror_progress(),
			array(
				'done'   => false,
				'copied' => $copied,
				'failed' => $failed,
			)
		);
	}

	/**
	 * Fetch one page and put it in our bucket.
	 *
	 * Tries the other B2 address when the first refuses: the friendly host
	 * fails on its own, sometimes for minutes, and giving up on a page for
	 * that reason would leave a hole in a chapter that is otherwise complete.
	 *
	 * @param B2Client $client Storage.
	 * @param string   $url    Where it is now.
	 * @param string   $key    Where it should be.
	 * @return int|WP_Error Bytes written.
	 */
	private static function copy_one( B2Client $client, string $url, string $key ) {
		$body = self::fetch( $url );

		if ( $body instanceof WP_Error ) {
			$alternate = B2Url::alternate( $url, self::remote_endpoint() );
			if ( '' === $alternate ) {
				return $body;
			}

			$body = self::fetch( $alternate );
			if ( $body instanceof WP_Error ) {
				return $body;
			}
		}

		$put = $client->put_object( $key, $body, self::mime_for( $url ) );

		if ( $put instanceof WP_Error ) {
			return $put;
		}

		return strlen( $body );
	}

	/**
	 * Download one image.
	 *
	 * @param string $url Address.
	 * @return string|WP_Error Bytes.
	 */
	private static function fetch( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => self::FETCH_TIMEOUT,
				'headers'  => array( 'Accept' => 'image/*' ),
				'stream'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'animeh_mirror_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'Görsel indirilemedi (%d).', 'animeh' ),
					$code
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error( 'animeh_mirror_empty', __( 'Görsel boş geldi.', 'animeh' ) );
		}

		if ( strlen( $body ) > self::MAX_PAGE_BYTES ) {
			return new WP_Error( 'animeh_mirror_large', __( 'Görsel çok büyük.', 'animeh' ) );
		}

		return $body;
	}

	/**
	 * The manga site's S3 endpoint, learned from its own settings.
	 *
	 * @return string
	 */
	private static function remote_endpoint(): string {
		$stored = get_option( 'animeh_manga_bridge_storage', array() );
		$stored = is_array( $stored ) ? $stored : array();

		return (string) ( $stored['b2_s3_endpoint'] ?? '' );
	}

	/**
	 * Remember what the manga site said about its own storage.
	 *
	 * Read from the handshake so a page that fails on the friendly host can be
	 * retried on the S3 one without asking her to type the endpoint twice.
	 *
	 * @param array<string, mixed> $storage The `storage` block of `/ping`.
	 */
	public static function remember_storage( array $storage ): void {
		update_option( 'animeh_manga_bridge_storage', $storage, false );
	}

	/* ── Small helpers ───────────────────────────────────────────────── */

	/**
	 * Meta key holding the other site's post id for a work.
	 */
	private const REMOTE_META = '_animeh_manga_remote_id';

	/**
	 * The work a remote manga was imported as, if any.
	 *
	 * @param int $remote_id Their post id.
	 * @return array<string, mixed>|null
	 */
	private static function work_by_remote( int $remote_id ): ?array {
		global $wpdb;

		$map = get_option( self::REMOTE_META, array() );
		$map = is_array( $map ) ? $map : array();

		$work_id = (int) ( $map[ $remote_id ] ?? 0 );
		if ( $work_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . CatalogSchema::works() . ' WHERE id = %d',
				$work_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Record which work a remote manga became.
	 *
	 * An option rather than a column: it is a bookkeeping detail of one
	 * importer, and a column would have to be explained to everything that
	 * reads a work.
	 *
	 * @param int $work_id   Ours.
	 * @param int $remote_id Theirs.
	 */
	private static function remember_remote( int $work_id, int $remote_id ): void {
		$map = get_option( self::REMOTE_META, array() );
		$map = is_array( $map ) ? $map : array();

		$map[ $remote_id ] = $work_id;

		update_option( self::REMOTE_META, $map, false );
	}

	/**
	 * A chapter title worth showing, or none at all.
	 *
	 * The other site titles every chapter "{Manga} - Bölüm 12", which in a
	 * list under the manga's own name reads as the same words twice. When
	 * that is all a title says, it is dropped and the app falls back to
	 * numbering it itself.
	 *
	 * @param string $title  Their title.
	 * @param float  $number Chapter number.
	 * @return string
	 */
	private static function chapter_title( string $title, float $number ): string {
		$label = ChapterNumber::label( $number );
		$clean = trim( $title );

		$patterns = array(
			'/^.*?[-–—]\s*(bölüm|chapter|ch\.?)\s*' . preg_quote( $label, '/' ) . '\s*$/iu',
			'/^(bölüm|chapter|ch\.?)\s*' . preg_quote( $label, '/' ) . '\s*$/iu',
		);

		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $clean ) ) {
				return '';
			}
		}

		return $clean;
	}

	/**
	 * Their status terms, as ours.
	 *
	 * @param string[] $terms Term names.
	 * @return string
	 */
	private static function status_from_terms( array $terms ): string {
		foreach ( $terms as $term ) {
			$lower = strtolower( (string) $term );

			if ( str_contains( $lower, 'devam' ) || str_contains( $lower, 'ongoing' ) || str_contains( $lower, 'publishing' ) ) {
				return 'airing';
			}
			if ( str_contains( $lower, 'bitti' ) || str_contains( $lower, 'tamamlan' ) || str_contains( $lower, 'finish' ) || str_contains( $lower, 'complete' ) ) {
				return 'finished';
			}
			if ( str_contains( $lower, 'yakında' ) || str_contains( $lower, 'upcoming' ) ) {
				return 'upcoming';
			}
		}

		return '';
	}

	/**
	 * The genre list, gathered from the taxonomies that carry one.
	 *
	 * @param array<string, mixed> $tax Their taxonomies.
	 * @return string[]
	 */
	private static function genres_from_terms( array $tax ): array {
		$names = array();

		foreach ( array( 'genre', 'category', 'tag' ) as $key ) {
			foreach ( (array) ( $tax[ $key ] ?? array() ) as $name ) {
				if ( is_string( $name ) && '' !== trim( $name ) ) {
					$names[] = trim( $name );
				}
			}
		}

		return array_values( array_slice( array_unique( $names ), 0, 24 ) );
	}

	/**
	 * A stored datetime, or the zero date.
	 *
	 * @param string $value GMT datetime.
	 * @return string
	 */
	private static function datetime( string $value ): string {
		$time = '' !== $value ? strtotime( $value ) : false;

		return false === $time ? '0000-00-00 00:00:00' : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * A content type from a filename or URL.
	 *
	 * @param string $name File name or address.
	 * @return string
	 */
	private static function mime_for( string $name ): string {
		$path      = (string) wp_parse_url( $name, PHP_URL_PATH );
		$extension = strtolower( (string) pathinfo( '' !== $path ? $path : $name, PATHINFO_EXTENSION ) );

		switch ( $extension ) {
			case 'png':
				return 'image/png';
			case 'webp':
				return 'image/webp';
			case 'gif':
				return 'image/gif';
			case 'avif':
				return 'image/avif';
			default:
				return 'image/jpeg';
		}
	}
}
