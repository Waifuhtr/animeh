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
use Animeh\Storage\ModerationRepository;
use Animeh\Storage\ReviewRepository;
use Animeh\Storage\StorageSettings;
use Animeh\Storage\TenraiClient;
use Animeh\Storage\TmdbClient;
use Animeh\Storage\UserDataRepository;
use Animeh\Support\StorageKey;
use Animeh\Support\TenraiMapper;
use Animeh\Support\TmdbMapper;
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
		$moderate  = array( Permissions::class, 'require_moderate' );

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

		// TMDB: the artwork and the Turkish synopses Tenrai does not carry.
		register_rest_route(
			$namespace,
			'/admin/tmdb/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'tmdb_settings' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_tmdb_settings' ),
					'permission_callback' => $guard,
					'args'                => array(
						'key'      => array( 'type' => 'string' ),
						'language' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'enabled'  => array( 'type' => 'boolean', 'default' => true ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/tmdb/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tmdb_search' ),
				'permission_callback' => $guard,
				'args'                => array(
					'q'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'year' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'page' => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/tmdb/artwork',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tmdb_artwork' ),
				'permission_callback' => $guard,
				'args'                => array(
					'work_id'   => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'tmdb_id'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'episodes'  => array( 'type' => 'boolean', 'default' => true ),
					'synopsis'  => array( 'type' => 'boolean', 'default' => true ),
					'overwrite' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/client-config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'client_config' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_client_config' ),
					'permission_callback' => $guard,
					'args'                => array(
						'api_base'          => array( 'type' => 'string', 'default' => '' ),
						'registration_open' => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		// Moderation: the report queue, sanctions, and who may act on them.
		// These carry `require_moderate` rather than `require_manage` — a
		// moderator's whole job is here, and nothing else in this controller.
		register_rest_route(
			$namespace,
			'/admin/reports',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'reports' ),
				'permission_callback' => $moderate,
				'args'                => array(
					'status' => array( 'type' => 'string', 'default' => 'open', 'sanitize_callback' => 'sanitize_key' ),
					'limit'  => array( 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/reports/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_report' ),
				'permission_callback' => $moderate,
				'args'                => array(
					'id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'action' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/users/(?P<id>\d+)/ban',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ban_user' ),
					'permission_callback' => $moderate,
					'args'                => array(
						'id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'reason' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'note'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
						// Zero is permanent; anything else is that many days.
						'days'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'lift_ban' ),
					'permission_callback' => $moderate,
					'args'                => array(
						'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/bans',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'bans' ),
				'permission_callback' => $moderate,
			)
		);

		// Adding a moderator is an administrator's call, not a moderator's:
		// otherwise the role could grant itself more holders without asking.
		register_rest_route(
			$namespace,
			'/admin/moderators',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'moderators' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_moderator' ),
					'permission_callback' => $guard,
					'args'                => array(
						'email' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/moderators/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_moderator' ),
				'permission_callback' => $guard,
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
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
				'tmdb'    => TmdbClient::public_settings(),
				'reports' => ( new ModerationRepository() )->open_report_count(),
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
	 * TMDB configuration, without the key.
	 */
	public function tmdb_settings(): WP_REST_Response {
		return new WP_REST_Response( array( 'tmdb' => TmdbClient::public_settings() ) );
	}

	/**
	 * Save TMDB configuration.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_tmdb_settings( WP_REST_Request $request ): WP_REST_Response {
		TmdbClient::save_settings(
			array(
				'key'      => (string) $request->get_param( 'key' ),
				'language' => (string) $request->get_param( 'language' ),
				'enabled'  => (bool) $request->get_param( 'enabled' ),
			)
		);

		return new WP_REST_Response( array( 'tmdb' => TmdbClient::public_settings() ) );
	}

	/**
	 * Search TMDB, so a mismatched title can be corrected by hand.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tmdb_search( WP_REST_Request $request ) {
		$response = ( new TmdbClient() )->search_tv(
			(string) $request->get_param( 'q' ),
			(int) $request->get_param( 'year' ),
			(int) $request->get_param( 'page' )
		);

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$base  = TmdbClient::image_base();
		$items = array();

		foreach ( (array) ( $response['results'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$items[] = array(
				'tmdb_id'    => (int) ( $entry['id'] ?? 0 ),
				'title'      => (string) ( $entry['name'] ?? '' ),
				'original'   => (string) ( $entry['original_name'] ?? '' ),
				'synopsis'   => (string) ( $entry['overview'] ?? '' ),
				'year'       => TmdbMapper::year( (string) ( $entry['first_air_date'] ?? '' ) ),
				'poster_url' => TmdbMapper::image( (string) ( $entry['poster_path'] ?? '' ), TmdbMapper::POSTER_SIZE, $base ),
			);
		}

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Fill a work's artwork — and optionally its episodes' — from TMDB.
	 *
	 * This is the reason the integration exists: an imported catalog has
	 * numbering and titles but blank episode thumbnails, and TMDB is where the
	 * stills are. Existing values are kept unless `overwrite` is set, so a
	 * poster someone chose by hand is not replaced by a run of this.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tmdb_artwork( WP_REST_Request $request ) {
		$work_id   = (int) $request->get_param( 'work_id' );
		$overwrite = (bool) $request->get_param( 'overwrite' );
		$repo      = new CatalogRepository();
		$work      = $repo->work( $work_id );

		if ( null === $work ) {
			return new WP_Error( 'NOT_FOUND', __( 'Anime bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$client  = new TmdbClient();
		$tmdb_id = (int) $request->get_param( 'tmdb_id' );

		// Remembered from a previous run unless this call names one, so a
		// correction made by hand once stays made.
		if ( $tmdb_id <= 0 ) {
			$tmdb_id = (int) ( $work['tmdb_id'] ?? 0 );
		}

		if ( $tmdb_id <= 0 ) {
			$found = $client->search_tv( (string) $work['title'], (int) $work['year'] );
			if ( $found instanceof WP_Error ) {
				return $found;
			}

			$match = TmdbMapper::best_match(
				(array) ( $found['results'] ?? array() ),
				(string) $work['title'],
				(int) $work['year']
			);

			if ( null === $match ) {
				// Deliberately a failure rather than a guess: the wrong poster
				// on a card is worse than no poster, and harder to notice.
				return new WP_Error(
					'TMDB_NO_MATCH',
					__( 'TMDB üzerinde eşleşen dizi bulunamadı. Arayıp elle seçebilirsin.', 'animeh' ),
					array( 'status' => 404 )
				);
			}

			$tmdb_id = (int) ( $match['id'] ?? 0 );
		}

		$details = $client->tv( $tmdb_id );
		if ( $details instanceof WP_Error ) {
			return $details;
		}

		$base   = TmdbClient::image_base();
		$mapped = TmdbMapper::work( $details, $base );

		// Start from the row as it stands and change only what is empty (or
		// everything, when overwriting): save_work fills every column from
		// defaults, so a partial array would blank the rest.
		$row = $work;
		unset( $row['id'], $row['created_at'], $row['updated_at'] );
		$row['tmdb_id'] = $tmdb_id;

		$filled = array();

		foreach ( array( 'poster_url', 'banner_url' ) as $field ) {
			if ( '' !== $mapped[ $field ] && ( $overwrite || '' === (string) $row[ $field ] ) ) {
				$row[ $field ] = $mapped[ $field ];
				$filled[]      = $field;
			}
		}

		if ( $request->get_param( 'synopsis' ) && '' !== $mapped['synopsis'] &&
			( $overwrite || '' === trim( (string) $row['synopsis'] ) ) ) {
			$row['synopsis'] = $mapped['synopsis'];
			$filled[]        = 'synopsis';
		}

		$saved = $repo->save_work( $row, $work_id );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$episodes_filled = 0;

		if ( $request->get_param( 'episodes' ) ) {
			$episodes_filled = $this->fill_episode_stills( $repo, $client, $work_id, $tmdb_id, $base, $overwrite );
		}

		( new LogRepository() )->record(
			'info',
			'TMDB_ARTWORK',
			'TMDB görselleri',
			array(
				'work_id'  => $work_id,
				'tmdb_id'  => $tmdb_id,
				'fields'   => $filled,
				'episodes' => $episodes_filled,
			),
			get_current_user_id()
		);

		$updated = $repo->work( $work_id );

		return new WP_REST_Response(
			array(
				'work'            => null === $updated ? null : CatalogController::work_payload( $updated ),
				'tmdb_id'         => $tmdb_id,
				'filled'          => $filled,
				'episodes_filled' => $episodes_filled,
			)
		);
	}

	/**
	 * Copy episode stills, names and synopses onto the episodes already here.
	 *
	 * Only onto episodes that exist: TMDB is being asked for artwork, not for
	 * a list of what should be in the catalog. Seasons are walked in the order
	 * the catalog has them, and a season TMDB does not know about is skipped
	 * rather than failing the whole run.
	 *
	 * @param CatalogRepository $repo      Catalog.
	 * @param TmdbClient        $client    TMDB.
	 * @param int               $work_id   Work.
	 * @param int               $tmdb_id   TMDB show id.
	 * @param string            $base      Image base.
	 * @param bool              $overwrite Replace values that are already set.
	 */
	private function fill_episode_stills(
		CatalogRepository $repo,
		TmdbClient $client,
		int $work_id,
		int $tmdb_id,
		string $base,
		bool $overwrite
	): int {
		$episodes = $repo->episodes( $work_id, 0, true );
		if ( array() === $episodes ) {
			return 0;
		}

		$by_season = array();
		foreach ( $episodes as $episode ) {
			$by_season[ (int) $episode['season_number'] ][] = $episode;
		}

		$filled = 0;

		foreach ( $by_season as $season_number => $season_episodes ) {
			$season = $client->season( $tmdb_id, $season_number );
			if ( $season instanceof WP_Error ) {
				continue;
			}

			$remote = array();
			foreach ( (array) ( $season['episodes'] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$mapped = TmdbMapper::episode( $entry, $base );
				if ( $mapped['number'] > 0 ) {
					$remote[ $mapped['number'] ] = $mapped;
				}
			}

			foreach ( $season_episodes as $episode ) {
				$match = $remote[ (int) $episode['number'] ] ?? null;
				if ( null === $match ) {
					continue;
				}

				$row = $episode;
				unset( $row['id'], $row['work_id'], $row['created_at'], $row['updated_at'] );

				$changed = false;

				if ( '' !== $match['thumbnail_url'] && ( $overwrite || '' === (string) $row['thumbnail_url'] ) ) {
					$row['thumbnail_url'] = $match['thumbnail_url'];
					$changed              = true;
				}

				if ( '' !== $match['title'] && ( $overwrite || '' === trim( (string) $row['title'] ) ) ) {
					$row['title'] = $match['title'];
					$changed      = true;
				}

				if ( '' !== $match['synopsis'] && ( $overwrite || '' === trim( (string) $row['synopsis'] ) ) ) {
					$row['synopsis'] = $match['synopsis'];
					$changed         = true;
				}

				// The length is the one field never taken from TMDB over one
				// the catalog already has: this episode's own file decides how
				// long it is, and a nominal runtime overwriting a measured one
				// is exactly what made short uploads unresumable.
				if ( $match['duration_seconds'] > 0 && 0 === (int) $row['duration_seconds'] ) {
					$row['duration_seconds'] = $match['duration_seconds'];
					$changed                 = true;
				}

				if ( ! $changed ) {
					continue;
				}

				$result = $repo->save_episode( $work_id, $row, (int) $episode['id'] );
				if ( ! $result instanceof WP_Error ) {
					++$filled;
				}
			}
		}

		return $filled;
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
			// The sanction comes with the row so the list can draw the state
			// and the action together, rather than asking per user.
			$payload          = $this->user_with_ban( (int) $user->ID );
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
	 * The address clients should use, and whether sign-ups are open.
	 */
	public function client_config(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'api_base'          => AuthController::public_base(),
				'api_base_override' => (string) get_option( AuthController::PUBLIC_BASE_OPTION, '' ),
				'default_base'      => trailingslashit( rest_url( FontsController::NAMESPACE ) ),
				'registration_open' => AuthController::registration_is_open(),
			)
		);
	}

	/**
	 * Point every client at a different address, or open and close sign-ups.
	 *
	 * Setting the address here is the migration path: the app asks `/config`
	 * on its way past and follows the answer, so phones move on their own.
	 * It has to be set on the install that is still answering, though — an
	 * app cannot be told about a new address by a server that is already gone.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_client_config( WP_REST_Request $request ) {
		if ( null !== $request->get_param( 'registration_open' ) ) {
			update_option(
				AuthController::REGISTRATION_OPTION,
				$request->get_param( 'registration_open' ) ? 1 : 0,
				true
			);
		}

		$base = $request->get_param( 'api_base' );

		if ( null !== $base ) {
			$stored = AuthController::set_public_base( (string) $base );
			if ( $stored instanceof WP_Error ) {
				return $stored;
			}

			( new LogRepository() )->record(
				'warning',
				'CLIENT_BASE_CHANGED',
				'İstemci sunucu adresi değişti',
				array( 'api_base' => $stored ),
				get_current_user_id()
			);
		}

		return $this->client_config();
	}

	/**
	 * The report queue.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reports( WP_REST_Request $request ): WP_REST_Response {
		$status = (string) $request->get_param( 'status' );
		if ( ! in_array( $status, array( 'open', 'resolved', 'dismissed', '' ), true ) ) {
			$status = 'open';
		}

		$repo  = new ModerationRepository();
		$items = array();

		foreach ( $repo->reports( $status, (int) $request->get_param( 'limit' ) ) as $row ) {
			$items[] = self::report_payload( $row );
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'open'  => $repo->open_report_count(),
			)
		);
	}

	/**
	 * Act on a report: remove the review, or decide there is nothing to do.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_report( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$action = (string) $request->get_param( 'action' );
		$repo   = new ModerationRepository();
		$report = $repo->report_row( $id );

		if ( null === $report ) {
			return new WP_Error( 'NOT_FOUND', __( 'Şikâyet bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$actor = get_current_user_id();

		if ( 'delete' === $action ) {
			( new ReviewRepository() )->delete( (int) $report['review_id'] );
			$repo->resolve_for_review( (int) $report['review_id'], $actor );
		} elseif ( 'dismiss' === $action ) {
			$repo->resolve( $id, $actor, 'dismissed' );
		} else {
			$repo->resolve( $id, $actor, 'resolved' );
		}

		( new LogRepository() )->record(
			'info',
			'REVIEW_REPORT',
			'Şikâyet işlendi',
			array( 'report_id' => $id, 'action' => $action, 'review_id' => (int) $report['review_id'] ),
			$actor
		);

		return new WP_REST_Response( array( 'ok' => true, 'open' => $repo->open_report_count() ) );
	}

	/**
	 * Suspend or ban a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ban_user( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$actor   = get_current_user_id();

		if ( $user_id === $actor ) {
			return new WP_Error( 'FORBIDDEN', __( 'Kendini yasaklayamazsın.', 'animeh' ), array( 'status' => 400 ) );
		}

		$target = get_userdata( $user_id );
		if ( false === $target ) {
			return new WP_Error( 'NOT_FOUND', __( 'Kullanıcı bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		// A moderator cannot sanction an administrator or another moderator:
		// the tool exists for the audience, not for settling staff arguments.
		if ( ! Permissions::current_user_can_manage() &&
			( user_can( $target, Permissions::MODERATE ) || user_can( $target, 'manage_options' ) ) ) {
			return new WP_Error(
				'FORBIDDEN',
				__( 'Bu kullanıcıya işlem uygulayamazsın.', 'animeh' ),
				array( 'status' => 403 )
			);
		}

		$days    = (int) $request->get_param( 'days' );
		$expires = null;

		if ( $days > 0 ) {
			$expires = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		}

		$result = ( new ModerationRepository() )->ban(
			$user_id,
			$actor,
			(string) $request->get_param( 'reason' ),
			(string) $request->get_param( 'note' ),
			$expires
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		( new LogRepository() )->record(
			'warning',
			'USER_BANNED',
			null === $expires ? 'Kullanıcı yasaklandı' : 'Kullanıcı uzaklaştırıldı',
			array( 'user_id' => $user_id, 'days' => $days ),
			$actor
		);

		return new WP_REST_Response( array( 'ok' => true, 'user' => $this->user_with_ban( $user_id ) ), 201 );
	}

	/**
	 * Lift whatever is in force for a user.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lift_ban( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'id' );

		( new ModerationRepository() )->lift( $user_id, get_current_user_id() );

		( new LogRepository() )->record(
			'info',
			'USER_UNBANNED',
			'Yasak kaldırıldı',
			array( 'user_id' => $user_id ),
			get_current_user_id()
		);

		return new WP_REST_Response( array( 'ok' => true, 'user' => $this->user_with_ban( $user_id ) ) );
	}

	/**
	 * Everyone currently under a sanction.
	 */
	public function bans(): WP_REST_Response {
		$items = array();

		foreach ( ( new ModerationRepository() )->active_bans() as $row ) {
			$items[] = array(
				'id'         => (int) $row['id'],
				'user'       => AuthController::user_payload( (int) $row['user_id'] ),
				'reason'     => (string) $row['reason'],
				'note'       => (string) $row['note'],
				'expires_at' => null === $row['expires_at'] ? '' : (string) $row['expires_at'],
				'permanent'  => null === $row['expires_at'],
				'created_at' => (string) $row['created_at'],
			);
		}

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Who holds the moderator role.
	 */
	public function moderators(): WP_REST_Response {
		Permissions::ensure_moderator_role();

		$query = new WP_User_Query(
			array(
				'role'    => Permissions::MODERATOR_ROLE,
				'number'  => 100,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = AuthController::user_payload( (int) $user->ID );
		}

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Make an existing account a moderator, found by email.
	 *
	 * By email rather than by picking from a list on purpose: the address is
	 * what the person tells you, and searching a user list for the right
	 * "ayse" is where the wrong account gets promoted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_moderator( WP_REST_Request $request ) {
		$email = (string) $request->get_param( 'email' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'VALIDATION_ERROR', __( 'Geçerli bir e-posta yaz.', 'animeh' ), array( 'status' => 400 ) );
		}

		$user = get_user_by( 'email', $email );
		if ( false === $user ) {
			// Deliberately not an invitation flow: the role is attached to an
			// account, and there is no account to attach it to yet.
			return new WP_Error(
				'NOT_FOUND',
				__( 'Bu e-posta ile kayıtlı bir hesap yok. Önce kayıt olması gerekiyor.', 'animeh' ),
				array( 'status' => 404 )
			);
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return new WP_Error(
				'VALIDATION_ERROR',
				__( 'Bu hesap zaten yönetici.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		Permissions::ensure_moderator_role();
		$user->add_role( Permissions::MODERATOR_ROLE );

		( new LogRepository() )->record(
			'info',
			'MODERATOR_ADDED',
			'Moderatör eklendi',
			array( 'user_id' => (int) $user->ID ),
			get_current_user_id()
		);

		return new WP_REST_Response( array( 'user' => AuthController::user_payload( (int) $user->ID ) ), 201 );
	}

	/**
	 * Take the moderator role back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_moderator( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_userdata( $user_id );

		if ( false === $user ) {
			return new WP_Error( 'NOT_FOUND', __( 'Kullanıcı bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$user->remove_role( Permissions::MODERATOR_ROLE );

		( new LogRepository() )->record(
			'info',
			'MODERATOR_REMOVED',
			'Moderatör kaldırıldı',
			array( 'user_id' => $user_id ),
			get_current_user_id()
		);

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * A user payload with whatever sanction is in force attached.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	private function user_with_ban( int $user_id ): array {
		$payload = AuthController::user_payload( $user_id );
		$ban     = ( new ModerationRepository() )->active_ban( $user_id );

		$payload['ban'] = null === $ban ? null : array(
			'reason'     => (string) $ban['reason'],
			'expires_at' => null === $ban['expires_at'] ? '' : (string) $ban['expires_at'],
			'permanent'  => null === $ban['expires_at'],
			'created_at' => (string) $ban['created_at'],
		);

		return $payload;
	}

	/**
	 * The shape of one report in the queue.
	 *
	 * @param array<string, mixed> $row Joined report row.
	 * @return array<string, mixed>
	 */
	private static function report_payload( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'review_id'     => (int) $row['review_id'],
			'work_id'       => (int) $row['work_id'],
			'work_title'    => (string) ( $row['work_title'] ?? '' ),
			'reason'        => (string) $row['reason'],
			'note'          => (string) $row['note'],
			'status'        => (string) $row['status'],
			'created_at'    => (string) $row['created_at'],
			'reporter'      => AuthController::user_payload( (int) $row['reporter_id'] ),
			'review_author' => AuthController::user_payload( (int) ( $row['review_user_id'] ?? 0 ) ),
			'review_body'   => (string) ( $row['review_body'] ?? '' ),
			'review_score'  => (int) ( $row['review_score'] ?? 0 ),
			'review_spoiler' => (bool) ( $row['review_spoiler'] ?? false ),
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
