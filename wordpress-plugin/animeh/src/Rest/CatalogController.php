<?php
/**
 * What the app reads: works, episodes, and how to play one.
 *
 * The interesting endpoint here is `/episodes/{id}/play`. Everything the
 * player needs for one episode arrives in a single response — video renditions
 * with signed URLs and their fallbacks, subtitle tracks, the fonts those
 * subtitles ask for, the skip markers, the neighbouring episodes and the
 * viewer's own resume position. One round trip, because on the connection this
 * app is built for, four requests before the first frame is four chances to
 * stall.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\CatalogRepository;
use Animeh\Storage\CatalogSchema;
use Animeh\Storage\FontRepository;
use Animeh\Storage\LogRepository;
use Animeh\Storage\UserDataRepository;
use Animeh\Storage\B2Client;
use Animeh\Storage\StorageSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The catalog's read endpoints.
 */
final class CatalogController {

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;

		// Browsing is public: a catalog nobody can see before signing up is a
		// catalog nobody signs up for. Playback is what requires an account.
		register_rest_route(
			$namespace,
			'/catalog/works',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'works' ),
				'permission_callback' => '__return_true',
				'args'                => $this->list_args(),
			)
		);

		register_rest_route(
			$namespace,
			'/catalog/works/(?P<id>[\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'work' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/catalog/works/(?P<id>\d+)/episodes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'episodes' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'season' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/catalog/home',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'home' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$namespace,
			'/catalog/genres',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'genres' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$namespace,
			'/episodes/(?P<id>\d+)/play',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'play' ),
				// Playback needs an account: this is the endpoint that mints
				// signed storage URLs.
				'permission_callback' => array( AuthController::class, 'require_login' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/announcements',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'announcements' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A page of works.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function works( WP_REST_Request $request ): WP_REST_Response {
		$repo   = new CatalogRepository();
		$result = $repo->works(
			array(
				'search'    => $request->get_param( 'search' ),
				'genre'     => $request->get_param( 'genre' ),
				'year'      => $request->get_param( 'year' ),
				'season'    => $request->get_param( 'season' ),
				'status'    => $request->get_param( 'status' ),
				'min_score' => $request->get_param( 'min_score' ),
				'sort'      => $request->get_param( 'sort' ),
				'page'      => $request->get_param( 'page' ),
				'per_page'  => $request->get_param( 'per_page' ),
			)
		);

		$response = new WP_REST_Response(
			array(
				'items' => array_map( array( self::class, 'work_payload' ), $result['items'] ),
				'total' => $result['total'],
				'page'  => max( 1, (int) $request->get_param( 'page' ) ),
			)
		);

		// The app pages on these rather than guessing when to stop.
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	/**
	 * One work, with its seasons and the viewer's relationship to it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function work( WP_REST_Request $request ) {
		$repo  = new CatalogRepository();
		$ident = (string) $request->get_param( 'id' );

		// Numeric means an id, anything else a slug — one route for both, so a
		// deep link and an internal reference use the same endpoint.
		$work = ctype_digit( $ident ) ? $repo->work( (int) $ident ) : $repo->work_by_slug( $ident );

		if ( null === $work || ( empty( $work['published'] ) && ! Permissions::current_user_can_manage() ) ) {
			return $this->not_found();
		}

		$payload             = self::work_payload( $work );
		$payload['seasons']  = $repo->seasons( (int) $work['id'] );
		$payload['synopsis'] = (string) $work['synopsis'];

		if ( is_user_logged_in() ) {
			$user_data              = new UserDataRepository();
			$user_id                = get_current_user_id();
			$payload['is_favorite'] = $user_data->in_list( $user_id, (int) $work['id'], 'favorite' );
			$payload['in_watchlist'] = $user_data->in_list( $user_id, (int) $work['id'], 'watchlist' );
		}

		return new WP_REST_Response( $payload );
	}

	/**
	 * Episodes of a work.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function episodes( WP_REST_Request $request ) {
		$repo = new CatalogRepository();
		$work = $repo->work( (int) $request->get_param( 'id' ) );

		if ( null === $work ) {
			return $this->not_found();
		}

		$episodes = $repo->episodes(
			(int) $work['id'],
			(int) $request->get_param( 'season' ),
			Permissions::current_user_can_manage()
		);

		// One query for every position rather than one per episode: a 500
		// episode list would otherwise be 500 round trips to the database.
		$progress = is_user_logged_in()
			? $this->progress_map( get_current_user_id(), $episodes )
			: array();

		$items = array();
		foreach ( $episodes as $episode ) {
			$item = self::episode_payload( $episode );
			$id   = (int) $episode['id'];

			if ( isset( $progress[ $id ] ) ) {
				$item['progress'] = array(
					'position_seconds' => (int) $progress[ $id ]['position_seconds'],
					'duration_seconds' => (int) $progress[ $id ]['duration_seconds'],
					'completed'        => (bool) $progress[ $id ]['completed'],
				);
			}

			$items[] = $item;
		}

		return new WP_REST_Response( array( 'items' => $items, 'total' => count( $items ) ) );
	}

	/**
	 * The home screen, assembled server-side.
	 *
	 * Five rails in one response. The alternative — a request per rail — is
	 * five chances for a slow connection to leave the screen half-drawn.
	 */
	public function home(): WP_REST_Response {
		$repo = new CatalogRepository();

		// The five most popular, as the slider is meant to show.
		$hero     = $repo->works( array( 'sort' => 'popular', 'per_page' => 5 ) );
		$popular  = $repo->works( array( 'sort' => 'popular', 'per_page' => 20 ) );
		$recent   = $repo->works( array( 'sort' => 'recent', 'per_page' => 20 ) );
		$airing   = $repo->works( array( 'status' => 'airing', 'sort' => 'score', 'per_page' => 20 ) );

		$payload = array(
			'hero'            => array_map( array( self::class, 'work_payload' ), $hero['items'] ),
			'popular'         => array_map( array( self::class, 'work_payload' ), $popular['items'] ),
			'recently_added'  => array_map( array( self::class, 'work_payload' ), $recent['items'] ),
			'airing'          => array_map( array( self::class, 'work_payload' ), $airing['items'] ),
			'latest_episodes' => array_map( array( self::class, 'latest_episode_payload' ), $repo->latest_episodes( 20 ) ),
			'continue'        => array(),
		);

		if ( is_user_logged_in() ) {
			$payload['continue'] = array_map(
				array( self::class, 'history_payload' ),
				( new UserDataRepository() )->continue_watching( get_current_user_id(), 20 )
			);
		}

		return new WP_REST_Response( $payload );
	}

	/**
	 * Genres present in the catalog, for the discover screen's chips.
	 */
	public function genres(): WP_REST_Response {
		return new WP_REST_Response( array( 'genres' => ( new CatalogRepository() )->genres() ) );
	}

	/**
	 * Everything needed to start one episode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function play( WP_REST_Request $request ) {
		$repo    = new CatalogRepository();
		$episode = $repo->episode( (int) $request->get_param( 'id' ) );

		if ( null === $episode ) {
			return $this->not_found();
		}

		$work = $repo->work( (int) $episode['work_id'] );
		if ( null === $work ) {
			return $this->not_found();
		}

		$visible = ! empty( $episode['published'] ) && ! empty( $work['published'] );
		if ( ! $visible && ! Permissions::current_user_can_manage() ) {
			return $this->not_found();
		}

		$settings = StorageSettings::load();
		$client   = $settings->is_configured() ? new B2Client( $settings ) : null;

		$videos = array();
		foreach ( $repo->sources( (int) $episode['id'], 'video' ) as $source ) {
			$videos[] = $this->playable( $source, $settings, $client );
		}

		if ( array() === $videos ) {
			( new LogRepository() )->error(
				'VIDEO_ERROR',
				'Bölümün oynatılabilir kaynağı yok',
				array( 'episode_id' => $episode['id'] ),
				get_current_user_id()
			);

			return new WP_Error(
				'VIDEO_ERROR',
				__( 'Bu bölümün video kaynağı henüz eklenmemiş.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$subtitles = array();
		foreach ( $repo->sources( (int) $episode['id'], 'subtitle' ) as $source ) {
			$subtitles[] = $this->playable( $source, $settings, $client );
		}

		$next     = $repo->adjacent_episode( $episode, 1 );
		$previous = $repo->adjacent_episode( $episode, -1 );
		$progress = ( new UserDataRepository() )->progress( get_current_user_id(), (int) $episode['id'] );

		return new WP_REST_Response(
			array(
				'episode'   => self::episode_payload( $episode ),
				'work'      => self::work_payload( $work ),
				'videos'    => $videos,
				'subtitles' => $subtitles,
				// Fonts are per work, not per episode: a series uses the same
				// typefaces throughout, and re-listing them per episode would
				// make the app re-download them on every next-episode.
				'fonts'     => $this->fonts_for( $repo, (int) $work['id'] ),
				'markers'   => array(
					'intro_start' => (int) $episode['intro_start'],
					'intro_end'   => (int) $episode['intro_end'],
					'outro_start' => (int) $episode['outro_start'],
				),
				'next'      => null === $next ? null : self::episode_payload( $next ),
				'previous'  => null === $previous ? null : self::episode_payload( $previous ),
				'resume'    => null === $progress ? null : array(
					'position_seconds' => (int) $progress['position_seconds'],
					'duration_seconds' => (int) $progress['duration_seconds'],
					// Sent so the player carries on counting from where it left
					// off rather than demanding the threshold again in one go.
					'watched_seconds'  => (int) ( $progress['watched_seconds'] ?? 0 ),
					'completed'        => (bool) $progress['completed'],
				),
			)
		);
	}

	/**
	 * Announcements for the current reader.
	 */
	public function announcements(): WP_REST_Response {
		$rows = ( new LogRepository() )->announcements( Permissions::current_user_can_manage() );

		return new WP_REST_Response( array( 'announcements' => $rows ) );
	}

	/**
	 * Turn a source row into something the player can open.
	 *
	 * Signed URLs are minted per request and expire, so a link scraped out of
	 * one response stops working — which is the point of a private bucket.
	 *
	 * @param array<string, mixed> $source   Source row.
	 * @param StorageSettings      $settings Bucket configuration.
	 * @param B2Client|null        $client   Open client, when storage is configured.
	 * @return array<string, mixed>
	 */
	private function playable( array $source, StorageSettings $settings, ?B2Client $client ): array {
		$urls = array();

		$key = (string) $source['storage_key'];
		if ( '' !== $key && null !== $client ) {
			if ( $settings->public_bucket ) {
				// Friendly URL first, S3 behind it: §"Friendly URL geçişi" —
				// the CDN edge is the flaky one, and the direct address is the
				// same bytes.
				$friendly = $settings->friendly_url( $key );
				if ( '' !== $friendly ) {
					$urls[] = $friendly;
				}
				$urls[] = $settings->s3_url( $key );
			} else {
				$urls[] = $client->presign_get( $key );
			}
		}

		// An external URL is a fallback, not a replacement: a source may be
		// hosted outside the bucket entirely.
		$external = (string) $source['external_url'];
		if ( '' !== $external ) {
			$urls[] = $external;
		}

		return array(
			'id'         => (int) $source['id'],
			'kind'       => (string) $source['kind'],
			'label'      => (string) $source['label'],
			'language'   => (string) $source['language'],
			'mime'       => (string) $source['mime'],
			'height'     => (int) $source['height'],
			'size_bytes' => (int) $source['size_bytes'],
			'is_default' => (bool) $source['is_default'],
			'url'        => $urls[0] ?? '',
			'fallback_urls' => array_values( array_slice( $urls, 1 ) ),
			'expires_in' => $settings->public_bucket ? 0 : $settings->link_ttl,
		);
	}

	/**
	 * Fonts available for a work: those registered against it, plus the
	 * site-wide library the subtitle may also need.
	 *
	 * @param CatalogRepository $repo    Catalog.
	 * @param int               $work_id Work id.
	 * @return array<int, array<string, mixed>>
	 */
	private function fonts_for( CatalogRepository $repo, int $work_id ): array {
		$fonts = array();

		foreach ( $repo->work_fonts( $work_id ) as $source ) {
			$fonts[] = array(
				'family' => (string) $source['label'],
				'url'    => (string) $source['external_url'],
				'origin' => 'work',
			);
		}

		foreach ( FontRepository::all() as $font ) {
			$fonts[] = array(
				'family' => (string) $font['family'],
				'url'    => (string) ( $font['url'] ?? '' ),
				'origin' => 'library',
			);
		}

		return $fonts;
	}

	/**
	 * Positions for a batch of episodes, keyed by episode id.
	 *
	 * @param int                              $user_id  User.
	 * @param array<int, array<string, mixed>> $episodes Episode rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function progress_map( int $user_id, array $episodes ): array {
		global $wpdb;

		if ( array() === $episodes ) {
			return array();
		}

		$ids = array_map( static fn( array $episode ): int => (int) $episode['id'], $episodes );

		// The ids are cast to int above, so the list is safe to interpolate —
		// and %d placeholders cannot be generated for a variable-length IN.
		$in    = implode( ',', $ids );
		$table = CatalogSchema::history();

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT episode_id, position_seconds, duration_seconds, completed FROM {$table} WHERE user_id = %d AND episode_id IN ({$in})",
				$user_id
			),
			ARRAY_A
		);

		$map = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$map[ (int) $row['episode_id'] ] = $row;
		}

		return $map;
	}

	/**
	 * The public shape of a work.
	 *
	 * @param array<string, mixed> $work Work row.
	 * @return array<string, mixed>
	 */
	public static function work_payload( array $work ): array {
		return array(
			'id'             => (int) $work['id'],
			'slug'           => (string) $work['slug'],
			'kind'           => (string) $work['kind'],
			'title'          => (string) $work['title'],
			'title_english'  => (string) $work['title_english'],
			'title_japanese' => (string) $work['title_japanese'],
			'synopsis'       => (string) $work['synopsis'],
			'poster_url'     => (string) $work['poster_url'],
			'banner_url'     => (string) $work['banner_url'],
			'trailer_url'    => (string) $work['trailer_url'],
			'score'          => (float) $work['score'],
			'year'           => (int) $work['year'],
			'season'         => (string) $work['season'],
			'status'         => (string) $work['status'],
			'format'         => (string) $work['format'],
			'rating'         => (string) $work['rating'],
			'studio'         => (string) $work['studio'],
			'genres'         => self::json_list( (string) $work['genres'] ),
			'synonyms'       => self::json_list( (string) $work['synonyms'] ),
			'total_episodes' => (int) $work['total_episodes'],
			'duration_seconds' => (int) $work['duration_seconds'],
			'published'      => (bool) $work['published'],
			'updated_at'     => (string) $work['updated_at'],
		);
	}

	/**
	 * The public shape of an episode.
	 *
	 * @param array<string, mixed> $episode Episode row.
	 * @return array<string, mixed>
	 */
	public static function episode_payload( array $episode ): array {
		return array(
			'id'               => (int) $episode['id'],
			'work_id'          => (int) $episode['work_id'],
			'season_number'    => (int) $episode['season_number'],
			'number'           => (int) $episode['number'],
			'title'            => (string) $episode['title'],
			'synopsis'         => (string) $episode['synopsis'],
			'thumbnail_url'    => (string) $episode['thumbnail_url'],
			// What to draw when the episode has no image of its own; absent
			// from queries that do not join the work, hence the fallback.
			'work_poster'      => (string) ( $episode['work_poster'] ?? '' ),
			'duration_seconds' => (int) $episode['duration_seconds'],
			'filler'           => (bool) $episode['filler'],
			'published'        => (bool) $episode['published'],
			'published_at'     => (string) $episode['published_at'],
		);
	}

	/**
	 * An episode row joined to its work, for the "new episodes" rail.
	 *
	 * @param array<string, mixed> $row Joined row.
	 * @return array<string, mixed>
	 */
	public static function latest_episode_payload( array $row ): array {
		$payload                = self::episode_payload( $row );
		$payload['work_title']  = (string) ( $row['work_title'] ?? '' );
		$payload['work_slug']   = (string) ( $row['work_slug'] ?? '' );
		$payload['work_poster'] = (string) ( $row['work_poster'] ?? '' );

		return $payload;
	}

	/**
	 * A history row, as the continue-watching rail wants it.
	 *
	 * @param array<string, mixed> $row Joined row.
	 * @return array<string, mixed>
	 */
	public static function history_payload( array $row ): array {
		return array(
			'work_id'          => (int) $row['work_id'],
			'work_title'       => (string) ( $row['work_title'] ?? '' ),
			'work_slug'        => (string) ( $row['work_slug'] ?? '' ),
			'poster_url'       => (string) ( $row['poster_url'] ?? '' ),
			'episode_id'       => (int) $row['episode_id'],
			'episode_number'   => (int) ( $row['episode_number'] ?? 0 ),
			'season_number'    => (int) ( $row['season_number'] ?? 1 ),
			'episode_title'    => (string) ( $row['episode_title'] ?? '' ),
			'thumbnail_url'    => (string) ( $row['thumbnail_url'] ?? '' ),
			'position_seconds' => (int) $row['position_seconds'],
			'duration_seconds' => (int) $row['duration_seconds'],
			'watched_seconds'  => (int) ( $row['watched_seconds'] ?? 0 ),
			'completed'        => (bool) $row['completed'],
			'updated_at'       => (string) $row['updated_at'],
		);
	}

	/**
	 * Decode a stored JSON list, tolerating a row written before it was one.
	 *
	 * @param string $json Stored value.
	 * @return array<int, string>
	 */
	private static function json_list( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $decoded ), static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * The one 404 body, so the app has a single shape to handle.
	 */
	private function not_found(): WP_Error {
		return new WP_Error(
			'NOT_FOUND',
			__( 'İçerik bulunamadı.', 'animeh' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Shared argument schema for the list endpoints.
	 *
	 * @return array<string, mixed>
	 */
	private function list_args(): array {
		return array(
			'search'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'genre'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'year'      => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			'season'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
			'status'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
			'min_score' => array( 'type' => 'number', 'default' => 0 ),
			'sort'      => array(
				'type'    => 'string',
				'default' => 'recent',
				'enum'    => array( 'recent', 'oldest', 'score', 'popular', 'title', 'year' ),
			),
			'page'      => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
			'per_page'  => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
		);
	}
}
