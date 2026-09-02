<?php
/**
 * The API behind the in-app admin panel.
 *
 * Every route carries `Permissions::require_manage`. That is the rule from §8
 * made concrete: the app's `is_admin` flag decides which tab to draw and
 * nothing else, and a client that sets it to true reaches exactly these
 * endpoints and is refused by every one of them.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\CatalogRepository;
use Animeh\Storage\LogRepository;
use Animeh\Storage\StorageSettings;
use Animeh\Storage\TenraiClient;
use Animeh\Storage\UserDataRepository;
use Animeh\Support\StorageKey;
use Animeh\Support\TenraiMapper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User_Query;

/**
 * Administrative endpoints.
 */
final class AdminController {

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$guard     = array( Permissions::class, 'require_manage' );

		register_rest_route(
			$namespace,
			'/admin/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'dashboard' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			$namespace,
			'/admin/works',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'works' ),
					'permission_callback' => $guard,
					'args'                => array(
						'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_work' ),
					'permission_callback' => $guard,
					'args'                => $this->work_args(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/works/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_work' ),
					'permission_callback' => $guard,
					'args'                => $this->work_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_work' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/works/(?P<work_id>\d+)/episodes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'episodes' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_episode' ),
					'permission_callback' => $guard,
					'args'                => $this->episode_args(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/episodes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'episode' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_episode' ),
					'permission_callback' => $guard,
					'args'                => $this->episode_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_episode' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/episodes/(?P<episode_id>\d+)/sources',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'sources' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_source' ),
					'permission_callback' => $guard,
					'args'                => $this->source_args(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/sources/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_source' ),
					'permission_callback' => $guard,
					'args'                => $this->source_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_source' ),
					'permission_callback' => $guard,
				),
			)
		);

		// Tenrai: search upstream, then import what was chosen.
		register_rest_route(
			$namespace,
			'/admin/tenrai/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tenrai_search' ),
				'permission_callback' => $guard,
				'args'                => array(
					'q'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'page' => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/tenrai/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tenrai_import' ),
				'permission_callback' => $guard,
				'args'                => array(
					'tenrai_id'        => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'import_episodes'  => array( 'type' => 'boolean', 'default' => true ),
					'publish'          => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/tenrai/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'tenrai_settings' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_tenrai_settings' ),
					'permission_callback' => $guard,
					'args'                => array(
						'base'    => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
						'key'     => array( 'type' => 'string' ),
						'enabled' => array( 'type' => 'boolean', 'default' => true ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'users' ),
				'permission_callback' => $guard,
				'args'                => array(
					'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/users/(?P<id>\d+)/role',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_user_role' ),
				'permission_callback' => $guard,
				'args'                => array(
					'id'   => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'role' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/announcements',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'announcements' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_announcement' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/announcements/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_announcement' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_announcement' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'logs' ),
					'permission_callback' => $guard,
					'args'                => array(
						'level'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
						'code'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_logs' ),
					'permission_callback' => $guard,
				),
			)
		);
	}

	/**
	 * Numbers for the admin home screen.
	 */
	public function dashboard(): WP_REST_Response {
		$counts   = ( new CatalogRepository() )->counts();
		$settings = StorageSettings::load();

		return new WP_REST_Response(
			array(
				'counts'  => $counts,
				'errors'  => ( new LogRepository() )->error_summary( 7 ),
				'storage' => array(
					'configured'    => $settings->is_configured(),
					'bucket'        => $settings->bucket,
					'public_bucket' => $settings->public_bucket,
				),
				'tenrai'  => TenraiClient::public_settings(),
			)
		);
	}

	/**
	 * Works, including unpublished ones.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function works( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new CatalogRepository() )->works(
			array(
				'search'              => $request->get_param( 'search' ),
				'page'                => $request->get_param( 'page' ),
				'per_page'            => $request->get_param( 'per_page' ),
				'include_unpublished' => true,
				'sort'                => 'recent',
			)
		);

		return new WP_REST_Response(
			array(
				'items' => array_map( array( CatalogController::class, 'work_payload' ), $result['items'] ),
				'total' => $result['total'],
			)
		);
	}

	/**
	 * Create or update a work.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_work( WP_REST_Request $request ) {
		$repo = new CatalogRepository();
		$id   = (int) $request->get_param( 'id' );

		$data = array();
		foreach ( array_keys( $this->work_args() ) as $field ) {
			if ( 'id' === $field ) {
				continue;
			}
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		// Lists arrive as arrays and are stored as JSON; a client sending a
		// pre-encoded string is accepted too rather than double-encoded.
		foreach ( array( 'genres', 'synonyms' ) as $list ) {
			if ( isset( $data[ $list ] ) && is_array( $data[ $list ] ) ) {
				$data[ $list ] = (string) wp_json_encode( array_values( array_map( 'strval', $data[ $list ] ) ) );
			}
		}

		if ( 0 === $id ) {
			$data['created_by'] = get_current_user_id();
		}

		$saved = $repo->save_work( $data, $id );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$work = $repo->work( $saved );

		return new WP_REST_Response(
			array( 'work' => null === $work ? null : CatalogController::work_payload( $work ) ),
			0 === $id ? 201 : 200
		);
	}

	/**
	 * Delete a work and everything under it.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_work( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		( new CatalogRepository() )->delete_work( $id );
		( new LogRepository() )->record( 'warning', 'ADMIN_WORK_DELETED', 'Anime silindi', array( 'work_id' => $id ), get_current_user_id() );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Episodes of a work, drafts included.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function episodes( WP_REST_Request $request ): WP_REST_Response {
		$repo     = new CatalogRepository();
		$work_id  = (int) $request->get_param( 'work_id' );
		$episodes = $repo->episodes( $work_id, 0, true );

		// The admin list shows how many sources each episode has, because "no
		// video attached" is the thing an operator is looking for.
		$items = array();
		foreach ( $episodes as $episode ) {
			$item                    = CatalogController::episode_payload( $episode );
			$sources                 = $repo->sources( (int) $episode['id'] );
			$item['source_counts']   = array(
				'video'    => count( array_filter( $sources, static fn( array $s ): bool => 'video' === $s['kind'] ) ),
				'subtitle' => count( array_filter( $sources, static fn( array $s ): bool => 'subtitle' === $s['kind'] ) ),
			);
			$items[]                 = $item;
		}

		return new WP_REST_Response( array( 'items' => $items, 'total' => count( $items ) ) );
	}

	/**
	 * One episode with its sources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function episode( WP_REST_Request $request ) {
		$repo    = new CatalogRepository();
		$episode = $repo->episode( (int) $request->get_param( 'id' ) );

		if ( null === $episode ) {
			return new WP_Error( 'NOT_FOUND', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'episode' => CatalogController::episode_payload( $episode ),
				'sources' => array_map(
					array( self::class, 'admin_source_payload' ),
					$repo->sources( (int) $episode['id'] )
				),
			)
		);
	}

	/**
	 * Create or update an episode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_episode( WP_REST_Request $request ) {
		$repo = new CatalogRepository();
		$id   = (int) $request->get_param( 'id' );

		if ( $id > 0 ) {
			$existing = $repo->episode( $id );
			if ( null === $existing ) {
				return new WP_Error( 'NOT_FOUND', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
			}
			$work_id = (int) $existing['work_id'];
		} else {
			$work_id = (int) $request->get_param( 'work_id' );
			if ( null === $repo->work( $work_id ) ) {
				return new WP_Error( 'NOT_FOUND', __( 'Anime bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
			}
		}

		$data = array();
		foreach ( array_keys( $this->episode_args() ) as $field ) {
			if ( in_array( $field, array( 'id', 'work_id' ), true ) ) {
				continue;
			}
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		$saved = $repo->save_episode( $work_id, $data, $id );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$episode = $repo->episode( $saved );

		return new WP_REST_Response(
			array( 'episode' => null === $episode ? null : CatalogController::episode_payload( $episode ) ),
			0 === $id ? 201 : 200
		);
	}

	/**
	 * Delete an episode.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_episode( WP_REST_Request $request ): WP_REST_Response {
		( new CatalogRepository() )->delete_episode( (int) $request->get_param( 'id' ) );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Sources attached to an episode.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function sources( WP_REST_Request $request ): WP_REST_Response {
		$sources = ( new CatalogRepository() )->sources( (int) $request->get_param( 'episode_id' ) );

		return new WP_REST_Response(
			array( 'items' => array_map( array( self::class, 'admin_source_payload' ), $sources ) )
		);
	}

	/**
	 * Attach or update a media source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_source( WP_REST_Request $request ) {
		$repo = new CatalogRepository();
		$id   = (int) $request->get_param( 'id' );

		if ( $id > 0 ) {
			$existing = $repo->source( $id );
			if ( null === $existing ) {
				return new WP_Error( 'NOT_FOUND', __( 'Kaynak bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
			}
			$episode_id = (int) $existing['episode_id'];
		} else {
			$episode_id = (int) $request->get_param( 'episode_id' );
		}

		$episode = $repo->episode( $episode_id );
		if ( null === $episode ) {
			return new WP_Error( 'NOT_FOUND', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$data = array(
			'episode_id'   => $episode_id,
			'work_id'      => (int) $episode['work_id'],
			'kind'         => (string) $request->get_param( 'kind' ),
			'label'        => (string) $request->get_param( 'label' ),
			'language'     => (string) $request->get_param( 'language' ),
			'storage_key'  => (string) $request->get_param( 'storage_key' ),
			'external_url' => (string) $request->get_param( 'external_url' ),
			'mime'         => (string) $request->get_param( 'mime' ),
			'height'       => (int) $request->get_param( 'height' ),
			'size_bytes'   => (int) $request->get_param( 'size_bytes' ),
			'is_default'   => (bool) $request->get_param( 'is_default' ),
			'sort_order'   => (int) $request->get_param( 'sort_order' ),
		);

		// A storage key is only meaningful under the plugin's own root; one
		// pointing elsewhere is either a mistake or an attempt to have the
		// server sign a URL for someone else's object.
		if ( '' !== $data['storage_key'] && ! str_starts_with( $data['storage_key'], StorageKey::ROOT . '/' ) ) {
			return new WP_Error(
				'VALIDATION_ERROR',
				__( 'Depolama anahtarı anime/ altında olmalı.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $data['storage_key'] && '' === $data['external_url'] ) {
			return new WP_Error(
				'VALIDATION_ERROR',
				__( 'Depolama anahtarı ya da dış adres gerekli.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$saved = $repo->save_source( $data, $id );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$stored = $repo->source( $saved );

		return new WP_REST_Response(
			array( 'source' => null === $stored ? null : self::admin_source_payload( $stored ) ),
			0 === $id ? 201 : 200
		);
	}

	/**
	 * A stored source, typed.
	 *
	 * `$wpdb` hands every column back as a string, so a row goes out with
	 * `"is_default": "1"` and `"size_bytes": "0"` unless it is cast here. A
	 * client that expects a boolean cannot parse `"1"`, and one wrong column
	 * fails the whole response rather than that single field.
	 *
	 * @param array<string, mixed> $source Raw row.
	 * @return array<string, mixed>
	 */
	public static function admin_source_payload( array $source ): array {
		return array(
			'id'           => (int) $source['id'],
			'episode_id'   => (int) $source['episode_id'],
			'work_id'      => (int) $source['work_id'],
			'kind'         => (string) $source['kind'],
			'label'        => (string) $source['label'],
			'language'     => (string) $source['language'],
			'storage_key'  => (string) $source['storage_key'],
			'external_url' => (string) $source['external_url'],
			'mime'         => (string) $source['mime'],
			'height'       => (int) $source['height'],
			'size_bytes'   => (int) $source['size_bytes'],
			'is_default'   => (bool) $source['is_default'],
			'sort_order'   => (int) $source['sort_order'],
		);
	}

	/**
	 * Detach a source.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_source( WP_REST_Request $request ): WP_REST_Response {
		( new CatalogRepository() )->delete_source( (int) $request->get_param( 'id' ) );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Search Tenrai, marking what is already in the catalog.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tenrai_search( WP_REST_Request $request ) {
		$response = ( new TenraiClient() )->search_anime(
			(string) $request->get_param( 'q' ),
			(int) $request->get_param( 'page' )
		);

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$repo    = new CatalogRepository();
		$results = array();

		foreach ( (array) ( $response['data'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$mapped   = TenraiMapper::work( $entry );
			$existing = $repo->work_by_tenrai_id( (int) $mapped['tenrai_id'] );

			$results[] = array(
				'tenrai_id'      => (int) $mapped['tenrai_id'],
				'title'          => (string) $mapped['title'],
				'title_english'  => (string) $mapped['title_english'],
				'poster_url'     => (string) $mapped['poster_url'],
				'year'           => (int) $mapped['year'],
				'score'          => (float) $mapped['score'],
				'format'         => (string) $mapped['format'],
				'status'         => (string) $mapped['status'],
				'total_episodes' => (int) $mapped['total_episodes'],
				'synopsis'       => (string) $mapped['synopsis'],
				// So the panel can offer "update" rather than a second import.
				'imported_id'    => null === $existing ? 0 : (int) $existing['id'],
			);
		}

		return new WP_REST_Response(
			array(
				'items'      => $results,
				'pagination' => $response['pagination'] ?? array(),
			)
		);
	}

	/**
	 * Import one anime, and optionally its episode list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tenrai_import( WP_REST_Request $request ) {
		$tenrai_id = (int) $request->get_param( 'tenrai_id' );
		$client    = new TenraiClient();
		$response  = $client->anime( $tenrai_id );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$entry = $response['data'] ?? null;
		if ( ! is_array( $entry ) ) {
			return new WP_Error( 'TENRAI_ERROR', __( 'Tenrai bu anime için veri döndürmedi.', 'animeh' ), array( 'status' => 404 ) );
		}

		$repo   = new CatalogRepository();
		$mapped = TenraiMapper::work( $entry );

		// Re-importing updates the existing row rather than creating a second
		// one; the operator is refreshing metadata, not adding a duplicate.
		$existing = $repo->work_by_tenrai_id( $tenrai_id );
		$work_id  = null === $existing ? 0 : (int) $existing['id'];

		if ( null !== $existing ) {
			// Local edits win over upstream on these: someone typed them.
			$mapped['banner_url'] = (string) $existing['banner_url'];
			$mapped['slug']       = (string) $existing['slug'];
			$mapped['published']  = (int) $existing['published'];
		} else {
			$mapped['published']  = $request->get_param( 'publish' ) ? 1 : 0;
			$mapped['created_by'] = get_current_user_id();
		}

		$saved = $repo->save_work( $mapped, $work_id );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$imported_episodes = 0;

		if ( $request->get_param( 'import_episodes' ) ) {
			$episodes = $client->episodes( $tenrai_id );

			if ( is_array( $episodes ) ) {
				foreach ( $episodes as $entry_episode ) {
					$row = TenraiMapper::episode( $entry_episode, 1 );
					if ( $row['number'] <= 0 ) {
						continue;
					}

					// Imported episodes stay unpublished: metadata arriving is
					// not the same as a video being attached, and publishing
					// here would put an unplayable episode in the app.
					$row['published']        = 0;
					$row['duration_seconds'] = (int) $mapped['duration_seconds'];

					$result = $repo->save_episode( $saved, $row );
					if ( ! $result instanceof WP_Error ) {
						++$imported_episodes;
					}
				}
			}
		}

		( new LogRepository() )->record(
			'info',
			'TENRAI_IMPORT',
			'Tenrai içe aktarma',
			array( 'tenrai_id' => $tenrai_id, 'work_id' => $saved, 'episodes' => $imported_episodes ),
			get_current_user_id()
		);

		$work = $repo->work( $saved );

		return new WP_REST_Response(
			array(
				'work'              => null === $work ? null : CatalogController::work_payload( $work ),
				'imported_episodes' => $imported_episodes,
				'updated'           => null !== $existing,
			),
			null === $existing ? 201 : 200
		);
	}

	/**
	 * Tenrai configuration, without the key.
	 */
	public function tenrai_settings(): WP_REST_Response {
		return new WP_REST_Response( array( 'tenrai' => TenraiClient::public_settings() ) );
	}

	/**
	 * Save Tenrai configuration.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_tenrai_settings( WP_REST_Request $request ): WP_REST_Response {
		TenraiClient::save_settings(
			array(
				'base'    => (string) $request->get_param( 'base' ),
				'key'     => (string) $request->get_param( 'key' ),
				'enabled' => (bool) $request->get_param( 'enabled' ),
			)
		);

		return new WP_REST_Response( array( 'tenrai' => TenraiClient::public_settings() ) );
	}

	/**
	 * A page of users.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function users( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( (int) $request->get_param( 'per_page' ), 100 ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$search   = (string) $request->get_param( 'search' );

		$query = new WP_User_Query(
			array(
				'number'         => $per_page,
				'offset'         => ( $page - 1 ) * $per_page,
				'orderby'        => 'registered',
				'order'          => 'DESC',
				'search'         => '' === $search ? '' : '*' . $search . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			)
		);

		$stats = new UserDataRepository();
		$items = array();

		foreach ( $query->get_results() as $user ) {
			$payload          = AuthController::user_payload( (int) $user->ID );
			$payload['stats'] = $stats->stats( (int) $user->ID );
			$items[]          = $payload;
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => (int) $query->get_total(),
			)
		);
	}

	/**
	 * Change a user's role.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_user_role( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$role    = (string) $request->get_param( 'role' );

		// Editing roles is a heavier privilege than managing the catalog, so it
		// is gated separately rather than on the plugin's own capability.
		if ( ! current_user_can( 'promote_users' ) ) {
			return new WP_Error( 'AUTH_ERROR', __( 'Rol değiştirme yetkin yok.', 'animeh' ), array( 'status' => 403 ) );
		}

		if ( $user_id === get_current_user_id() ) {
			// Locking yourself out of your own admin panel is a support ticket
			// nobody can answer.
			return new WP_Error( 'VALIDATION_ERROR', __( 'Kendi rolünü değiştiremezsin.', 'animeh' ), array( 'status' => 400 ) );
		}

		if ( ! array_key_exists( $role, wp_roles()->get_names() ) ) {
			return new WP_Error( 'VALIDATION_ERROR', __( 'Bilinmeyen rol.', 'animeh' ), array( 'status' => 400 ) );
		}

		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return new WP_Error( 'NOT_FOUND', __( 'Kullanıcı bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$user->set_role( $role );

		( new LogRepository() )->record(
			'warning',
			'ADMIN_ROLE_CHANGED',
			'Kullanıcı rolü değişti',
			array( 'user_id' => $user_id, 'role' => $role ),
			get_current_user_id()
		);

		return new WP_REST_Response( array( 'user' => AuthController::user_payload( $user_id ) ) );
	}

	/**
	 * Every announcement.
	 */
	public function announcements(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => ( new LogRepository() )->all_announcements() ) );
	}

	/**
	 * Create or update an announcement.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_announcement( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$id = ( new LogRepository() )->save_announcement( $body, (int) $request->get_param( 'id' ) );

		return new WP_REST_Response( array( 'id' => $id ), 201 );
	}

	/**
	 * Delete an announcement.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_announcement( WP_REST_Request $request ): WP_REST_Response {
		( new LogRepository() )->delete_announcement( (int) $request->get_param( 'id' ) );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * A page of log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function logs( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new LogRepository() )->entries(
			array(
				'level'    => $request->get_param( 'level' ),
				'code'     => $request->get_param( 'code' ),
				'search'   => $request->get_param( 'search' ),
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response( $result );
	}

	/**
	 * Empty the log.
	 */
	public function clear_logs(): WP_REST_Response {
		( new LogRepository() )->clear();

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Argument schema for a work.
	 *
	 * @return array<string, mixed>
	 */
	private function work_args(): array {
		return array(
			'id'               => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'title'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'title_english'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'title_japanese'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'slug'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ),
			'synopsis'         => array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ),
			'poster_url'       => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'banner_url'       => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'trailer_url'      => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'score'            => array( 'type' => 'number' ),
			'year'             => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'season'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'status'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'format'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'rating'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'studio'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'genres'           => array( 'type' => array( 'array', 'string' ) ),
			'synonyms'         => array( 'type' => array( 'array', 'string' ) ),
			'total_episodes'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'duration_seconds' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'tenrai_id'        => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'published'        => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * Argument schema for an episode.
	 *
	 * @return array<string, mixed>
	 */
	private function episode_args(): array {
		return array(
			'id'               => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'work_id'          => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'season_number'    => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
			'number'           => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'title'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'synopsis'         => array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ),
			'thumbnail_url'    => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'duration_seconds' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			// Signed: -1 means "not marked", which absint would flatten to 0 —
			// a real marker at the very start of the episode.
			'intro_start'      => array( 'type' => 'integer' ),
			'intro_end'        => array( 'type' => 'integer' ),
			'outro_start'      => array( 'type' => 'integer' ),
			'filler'           => array( 'type' => 'boolean' ),
			'published'        => array( 'type' => 'boolean' ),
			'published_at'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
		);
	}

	/**
	 * Argument schema for a source.
	 *
	 * @return array<string, mixed>
	 */
	private function source_args(): array {
		return array(
			'id'           => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'episode_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'kind'         => array( 'type' => 'string', 'default' => 'video', 'enum' => array( 'video', 'subtitle', 'font' ) ),
			'label'        => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'language'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'storage_key'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'external_url' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ),
			'mime'         => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'height'       => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			'size_bytes'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			'is_default'   => array( 'type' => 'boolean', 'default' => false ),
			'sort_order'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
		);
	}
}
