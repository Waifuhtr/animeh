<?php
/**
 * AnimehTok's endpoints.
 *
 * A short video shelf on the same account as everything else, and on the same
 * bucket, under `animehtok/<kullanıcı>/`. Deliberately *not* on the same
 * counters: nothing here writes history, awards a point or reaches a
 * leaderboard, because time spent scrolling is not time spent watching an
 * episode and the app should not pretend otherwise.
 *
 * Uploading goes straight from the phone to storage. The plugin only signs the
 * parts and records the row afterwards — a forty megabyte video through PHP is
 * a memory limit waiting to be hit on somebody else's host.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\B2Client;
use Animeh\Storage\LogRepository;
use Animeh\Storage\Notifier;
use Animeh\Storage\ShortsRepository;
use Animeh\Storage\StorageSettings;
use Animeh\Support\Hashtag;
use Animeh\Support\StorageKey;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * The short-video endpoints.
 */
final class ShortsController {

	/**
	 * Longest video accepted, in seconds.
	 *
	 * Three minutes. Past that it is not a short, and the feed's whole promise
	 * — that the next one is one swipe away — stops being true.
	 */
	private const MAX_DURATION_SECONDS = 180;

	/**
	 * Largest video accepted, in bytes.
	 */
	private const MAX_VIDEO_BYTES = 300 * 1024 * 1024;

	/**
	 * Largest cover image accepted, in bytes.
	 */
	private const MAX_COVER_BYTES = 2 * 1024 * 1024;

	/**
	 * Longest caption, in characters.
	 */
	private const MAX_DESCRIPTION = 2200;

	/**
	 * Longest comment, in characters.
	 */
	private const MAX_COMMENT = 1000;

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$guard     = array( AuthController::class, 'require_login' );
		$manage    = array( Permissions::class, 'require_manage' );

		// The feed is readable signed out, like the catalogue: a wall in front
		// of the first video is a wall in front of the reason to sign up.
		register_rest_route(
			$namespace,
			'/shorts/feed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'feed' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'tab'      => array(
						'type'    => 'string',
						'default' => 'foryou',
						'enum'    => array( 'foryou', 'following' ),
					),
					'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 10, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/uploads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'begin_upload' ),
				'permission_callback' => $guard,
				'args'                => array(
					'filename'     => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'size'         => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'content_type' => array( 'type' => 'string', 'default' => 'video/mp4', 'sanitize_callback' => 'sanitize_text_field' ),
					'description'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/uploads/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_upload' ),
				'permission_callback' => $guard,
				'args'                => array(
					'key'         => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'upload_id'   => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'slug'        => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'parts'       => array( 'required' => true, 'type' => 'array' ),
					'description' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'duration_ms' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'width'       => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'height'      => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'size'        => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'sound_title' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'sound_id'    => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'adult'       => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/(?P<id>\d+)/cover',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_cover' ),
				'permission_callback' => $guard,
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $guard,
					'args'                => array(
						'id'          => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'description' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $guard,
					'args'                => array(
						'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/(?P<id>\d+)/like',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'like' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unlike' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/(?P<id>\d+)/save',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unsave' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
			)
		);

		// Signed out too: a view is a view, and the number under a video is
		// wrong if half the people watching are not counted.
		register_rest_route(
			$namespace,
			'/shorts/(?P<id>\d+)/view',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'view' ),
				'permission_callback' => '__return_true',
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/(?P<id>\d+)/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'comments' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id'       => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'parent'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
						'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_comment' ),
					'permission_callback' => $guard,
					'args'                => array(
						'id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'body'   => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
						'parent' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/comments/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_comment' ),
				'permission_callback' => $guard,
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/comments/(?P<id>\d+)/like',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'like_comment' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unlike_comment' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/tags/(?P<tag>[^/]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tag_page' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'tag'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 21, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'trending_tags' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/sounds/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sound_page' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'       => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 21, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/users/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'creator' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'       => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 21, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/users/(?P<id>\d+)/follow',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => $guard,
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/shorts/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/shorts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'mine' ),
				'permission_callback' => $guard,
				'args'                => array(
					'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 21, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/shorts/saved',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'saved' ),
				'permission_callback' => $guard,
				'args'                => array(
					'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 21, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/shorts/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => $guard,
			)
		);

		// Moderation: a moderator can take any video down, and the row and the
		// stored objects go together.
		register_rest_route(
			$namespace,
			'/admin/shorts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_index' ),
				'permission_callback' => $manage,
				'args'                => array(
					'offset'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 30, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/shorts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'admin_delete' ),
				'permission_callback' => $manage,
				'args'                => $this->id_arg(),
			)
		);
	}

	/* ── Feed and one video ──────────────────────────────────────────── */

	/**
	 * A page of the feed.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function feed( WP_REST_Request $request ): WP_REST_Response {
		$repo     = new ShortsRepository();
		$user_id  = get_current_user_id();
		$per_page = (int) $request->get_param( 'per_page' );
		$offset   = (int) $request->get_param( 'offset' );

		$rows = 'following' === (string) $request->get_param( 'tab' )
			? $repo->following_feed( $user_id, $per_page, $offset )
			: $repo->for_you( $user_id, $per_page, $offset );

		return new WP_REST_Response(
			array(
				'items' => $this->payloads( $rows, $repo, $user_id ),
				'tab'   => (string) $request->get_param( 'tab' ),
			)
		);
	}

	/**
	 * One video.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short || ( empty( $short['published'] ) && ! $this->may_edit( $short ) ) ) {
			return $this->not_found();
		}

		$items = $this->payloads( array( $short ), $repo, get_current_user_id() );

		return new WP_REST_Response( $items[0] );
	}

	/**
	 * Change a caption.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short ) {
			return $this->not_found();
		}

		if ( ! $this->may_edit( $short ) ) {
			return $this->forbidden();
		}

		if ( null !== $request->get_param( 'description' ) ) {
			$repo->set_description(
				(int) $short['id'],
				$this->clamp( (string) $request->get_param( 'description' ), self::MAX_DESCRIPTION )
			);
		}

		$fresh = $repo->find( (int) $short['id'] );
		$items = $this->payloads( array( $fresh ?? $short ), $repo, get_current_user_id() );

		return new WP_REST_Response( $items[0] );
	}

	/**
	 * Delete a video, with its stored objects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short ) {
			return $this->not_found();
		}

		if ( ! $this->may_edit( $short ) ) {
			return $this->forbidden();
		}

		$this->remove( $short, $repo );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/* ── Uploading ───────────────────────────────────────────────────── */

	/**
	 * Sign the parts of a direct upload.
	 *
	 * The slug is decided here rather than by the phone: it is the file name
	 * in the bucket and the address of the video, and neither is the client's
	 * to choose.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function begin_upload( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Depolama ayarlanmamış.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$size = (int) $request->get_param( 'size' );
		if ( $size <= 0 ) {
			return new WP_Error( 'animeh_bad_size', __( 'Dosya boyutu geçersiz.', 'animeh' ), array( 'status' => 400 ) );
		}

		if ( $size > self::MAX_VIDEO_BYTES ) {
			return new WP_Error(
				'animeh_video_too_large',
				sprintf(
					/* translators: %d: megabytes */
					__( 'Video en fazla %d MB olabilir.', 'animeh' ),
					(int) ( self::MAX_VIDEO_BYTES / 1024 / 1024 )
				),
				array( 'status' => 413 )
			);
		}

		$user_id = get_current_user_id();
		$repo    = new ShortsRepository();

		$slug = $repo->unique_slug( (string) $request->get_param( 'description' ), $user_id );
		$key  = StorageKey::tok_video(
			$user_id,
			$this->creator_name( $user_id ),
			$slug,
			(string) pathinfo( (string) $request->get_param( 'filename' ), PATHINFO_EXTENSION )
		);

		$result = ( new B2Client( $settings ) )->create_multipart_upload(
			$key,
			$size,
			(string) $request->get_param( 'content_type' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['slug'] = $slug;

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Finish the upload and record the row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_upload( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Depolama ayarlanmamış.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$user_id = get_current_user_id();
		$key     = (string) $request->get_param( 'key' );

		// The key has to be inside this account's own folder. Without this
		// check a signed-in user could finish an upload into anybody's prefix
		// simply by sending a different key back.
		$prefix = StorageKey::tok_prefix( $user_id, $this->creator_name( $user_id ) ) . '/';
		if ( 0 !== strpos( $key, $prefix ) ) {
			return $this->forbidden();
		}

		$duration = (int) $request->get_param( 'duration_ms' );
		if ( $duration > self::MAX_DURATION_SECONDS * 1000 ) {
			return new WP_Error(
				'animeh_video_too_long',
				sprintf(
					/* translators: %d: seconds */
					__( 'Video en fazla %d saniye olabilir.', 'animeh' ),
					self::MAX_DURATION_SECONDS
				),
				array( 'status' => 422 )
			);
		}

		$parts = $this->normalise_parts( (array) $request->get_param( 'parts' ) );
		if ( array() === $parts ) {
			return new WP_Error( 'animeh_no_parts', __( 'Yükleme parçaları eksik.', 'animeh' ), array( 'status' => 400 ) );
		}

		$client   = new B2Client( $settings );
		$finished = $client->complete_multipart_upload( $key, (string) $request->get_param( 'upload_id' ), $parts );

		if ( is_wp_error( $finished ) ) {
			return $finished;
		}

		$repo        = new ShortsRepository();
		$description = $this->clamp( (string) $request->get_param( 'description' ), self::MAX_DESCRIPTION );

		// A slug that got taken between signing and finishing — two uploads in
		// the same second — would fail the unique key, so it is checked again
		// rather than trusted from the first call.
		$slug = (string) $request->get_param( 'slug' );
		if ( '' === $slug || $repo->slug_taken( $slug ) ) {
			$slug = $repo->unique_slug( $description, $user_id );
		}

		$sound_id = (int) $request->get_param( 'sound_id' );
		if ( $sound_id > 0 && null === $repo->sound( $sound_id ) ) {
			$sound_id = 0;
		}

		if ( 0 === $sound_id ) {
			// Every video brings a sound with it, so the sound page has
			// something to show from the first upload rather than only once
			// somebody reuses one.
			$title = $this->clamp( (string) $request->get_param( 'sound_title' ), 191 );

			if ( '' === $title ) {
				$title = __( 'Orijinal ses', 'animeh' );
			}

			$sound_id = $repo->create_sound( $title, $this->creator_name( $user_id ), $user_id );
		}

		$id = $repo->create(
			array(
				'user_id'     => $user_id,
				'slug'        => $slug,
				'description' => $description,
				'storage_key' => $key,
				'sound_id'    => $sound_id,
				'duration_ms' => $duration,
				'width'       => (int) $request->get_param( 'width' ),
				'height'      => (int) $request->get_param( 'height' ),
				'size_bytes'  => (int) $request->get_param( 'size' ),
				'mime'        => 'video/mp4',
				'published'   => true,
				'adult'       => (bool) $request->get_param( 'adult' ),
			)
		);

		if ( is_wp_error( $id ) ) {
			// The bytes are already in the bucket and now nothing points at
			// them, so they go rather than sit there being billed for.
			$client->delete_object( $key );

			return $id;
		}

		if ( $sound_id > 0 ) {
			$repo->attach_sound( $sound_id, $id );
		}

		$short = $repo->find( $id );
		$items = $this->payloads( array( $short ), $repo, $user_id );

		return new WP_REST_Response( $items[0], 201 );
	}

	/**
	 * Store a cover frame for a video.
	 *
	 * Small enough to come through the server as a raw body, unlike the video.
	 * The phone grabs a frame and sends it; there is nothing to decode here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_cover( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Depolama ayarlanmamış.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short ) {
			return $this->not_found();
		}

		if ( ! $this->may_edit( $short ) ) {
			return $this->forbidden();
		}

		$body = $request->get_body();
		$size = strlen( $body );

		if ( 0 === $size ) {
			return new WP_Error( 'animeh_empty_image', __( 'Görsel boş.', 'animeh' ), array( 'status' => 400 ) );
		}

		if ( $size > self::MAX_COVER_BYTES ) {
			return new WP_Error(
				'animeh_image_too_large',
				__( 'Kapak en fazla 2 MB olabilir.', 'animeh' ),
				array( 'status' => 413 )
			);
		}

		// The bytes decide, not the header: a script renamed to .jpg must not
		// be stored as an image.
		if ( 0 !== strncmp( $body, "\xFF\xD8\xFF", 3 ) ) {
			return new WP_Error(
				'animeh_bad_image',
				__( 'Kapak JPEG olmalı.', 'animeh' ),
				array( 'status' => 415 )
			);
		}

		$key = StorageKey::tok_cover(
			(int) $short['user_id'],
			$this->creator_name( (int) $short['user_id'] ),
			(string) $short['slug']
		);

		$stored = ( new B2Client( $settings ) )->put_object( $key, $body, 'image/jpeg' );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\Animeh\Storage\ShortsSchema::shorts(),
			array( 'thumb_key' => $key ),
			array( 'id' => (int) $short['id'] )
		);

		$fresh = $repo->find( (int) $short['id'] );
		$items = $this->payloads( array( $fresh ?? $short ), $repo, get_current_user_id() );

		return new WP_REST_Response( $items[0] );
	}

	/* ── Reactions ───────────────────────────────────────────────────── */

	/**
	 * Like a video.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function like( WP_REST_Request $request ) {
		return $this->react( $request, 'like' );
	}

	/**
	 * Remove a like.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlike( WP_REST_Request $request ) {
		return $this->react( $request, 'unlike' );
	}

	/**
	 * Save a video.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		return $this->react( $request, 'save' );
	}

	/**
	 * Unsave a video.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unsave( WP_REST_Request $request ) {
		return $this->react( $request, 'unsave' );
	}

	/**
	 * Count a view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function view( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short ) {
			return $this->not_found();
		}

		$repo->record_view( (int) $short['id'], get_current_user_id() );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/* ── Comments ────────────────────────────────────────────────────── */

	/**
	 * A page of comments, or of replies under one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function comments( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short ) {
			return $this->not_found();
		}

		$rows = $repo->comments(
			(int) $short['id'],
			(int) $request->get_param( 'parent' ),
			(int) $request->get_param( 'per_page' ),
			(int) $request->get_param( 'offset' )
		);

		return new WP_REST_Response(
			array(
				'items' => $this->comment_payloads( $rows, $repo, get_current_user_id() ),
				'total' => (int) $short['comment_count'],
			)
		);
	}

	/**
	 * Post a comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_comment( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short || empty( $short['published'] ) ) {
			return $this->not_found();
		}

		$body = trim( $this->clamp( (string) $request->get_param( 'body' ), self::MAX_COMMENT ) );
		if ( '' === $body ) {
			return new WP_Error( 'animeh_empty_comment', __( 'Yorum boş olamaz.', 'animeh' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();

		$id = $repo->add_comment( (int) $short['id'], $user_id, $body, (int) $request->get_param( 'parent' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// The creator hears about it, unless the creator is the one typing.
		if ( (int) $short['user_id'] !== $user_id ) {
			$this->notify(
				array( (int) $short['user_id'] ),
				__( 'Yeni yorum', 'animeh' ),
				sprintf(
					/* translators: %s: display name */
					__( '%s videona yorum yaptı.', 'animeh' ),
					$this->creator_name( $user_id )
				),
				array(
					'type'     => 'short_comment',
					'short_id' => (string) $short['id'],
				)
			);
		}

		$comment = $repo->comment( $id );
		$items   = $this->comment_payloads( array( $comment ), $repo, $user_id );

		return new WP_REST_Response( $items[0], 201 );
	}

	/**
	 * Delete a comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_comment( WP_REST_Request $request ) {
		$repo    = new ShortsRepository();
		$comment = $repo->comment( (int) $request->get_param( 'id' ) );

		if ( null === $comment ) {
			return $this->not_found();
		}

		$short   = $repo->find( (int) $comment['short_id'] );
		$user_id = get_current_user_id();

		// Its author, the video's creator, or a moderator. The middle one is
		// the point: a creator has to be able to clear their own comments.
		$allowed = (int) $comment['user_id'] === $user_id
			|| ( null !== $short && (int) $short['user_id'] === $user_id )
			|| Permissions::current_user_can_manage();

		if ( ! $allowed ) {
			return $this->forbidden();
		}

		$repo->delete_comment( (int) $comment['id'] );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Like a comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function like_comment( WP_REST_Request $request ) {
		return $this->react_comment( $request, true );
	}

	/**
	 * Remove the like.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlike_comment( WP_REST_Request $request ) {
		return $this->react_comment( $request, false );
	}

	/* ── Pages ───────────────────────────────────────────────────────── */

	/**
	 * Everything under one hashtag.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function tag_page( WP_REST_Request $request ): WP_REST_Response {
		$repo = new ShortsRepository();
		$tag  = (string) $request->get_param( 'tag' );

		$summary = $repo->tag_summary( $tag );
		$rows    = $repo->by_tag( $tag, (int) $request->get_param( 'per_page' ), (int) $request->get_param( 'offset' ) );

		return new WP_REST_Response(
			array(
				'tag'   => $summary['tag'],
				'key'   => $summary['key'],
				'count' => $summary['count'],
				'items' => $this->payloads( $rows, $repo, get_current_user_id() ),
			)
		);
	}

	/**
	 * The most used tags.
	 */
	public function trending_tags(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => ( new ShortsRepository() )->trending_tags() ) );
	}

	/**
	 * Everything using one sound.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sound_page( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$sound = $repo->sound( (int) $request->get_param( 'id' ) );

		if ( null === $sound ) {
			return $this->not_found();
		}

		$rows = $repo->by_sound(
			(int) $sound['id'],
			(int) $request->get_param( 'per_page' ),
			(int) $request->get_param( 'offset' )
		);

		return new WP_REST_Response(
			array(
				'sound' => $this->sound_payload( $sound, $repo ),
				'items' => $this->payloads( $rows, $repo, get_current_user_id() ),
			)
		);
	}

	/**
	 * One creator's page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function creator( WP_REST_Request $request ) {
		$target = (int) $request->get_param( 'id' );
		$user   = get_userdata( $target );

		if ( ! $user instanceof WP_User ) {
			return $this->not_found();
		}

		$repo    = new ShortsRepository();
		$viewer  = get_current_user_id();
		$rows    = $repo->by_user( $target, (int) $request->get_param( 'per_page' ), (int) $request->get_param( 'offset' ) );

		return new WP_REST_Response(
			array(
				'creator'   => $this->creator_payload( $target ),
				'stats'     => $repo->stats( $target ),
				'following' => $repo->follows( $viewer, $target ),
				'is_self'   => $viewer === $target,
				'items'     => $this->payloads( $rows, $repo, $viewer ),
			)
		);
	}

	/**
	 * Follow somebody.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow( WP_REST_Request $request ) {
		$target = (int) $request->get_param( 'id' );
		$viewer = get_current_user_id();

		if ( $target === $viewer ) {
			return new WP_Error(
				'animeh_follow_self',
				__( 'Kendini takip edemezsin.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		if ( ! get_userdata( $target ) instanceof WP_User ) {
			return $this->not_found();
		}

		$repo    = new ShortsRepository();
		$changed = $repo->follow( $viewer, $target );

		if ( $changed ) {
			$this->notify(
				array( $target ),
				__( 'Yeni takipçi', 'animeh' ),
				sprintf(
					/* translators: %s: display name */
					__( '%s seni takip etmeye başladı.', 'animeh' ),
					$this->creator_name( $viewer )
				),
				array(
					'type'    => 'short_follow',
					'user_id' => (string) $viewer,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'following' => true,
				'stats'     => $repo->stats( $target ),
			)
		);
	}

	/**
	 * Stop following.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function unfollow( WP_REST_Request $request ): WP_REST_Response {
		$repo   = new ShortsRepository();
		$target = (int) $request->get_param( 'id' );

		$repo->unfollow( get_current_user_id(), $target );

		return new WP_REST_Response(
			array(
				'following' => false,
				'stats'     => $repo->stats( $target ),
			)
		);
	}

	/**
	 * Videos, creators, tags and sounds matching a phrase.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$repo  = new ShortsRepository();
		$query = (string) $request->get_param( 'q' );

		$creators = array();
		foreach ( $this->search_users( $query ) as $user_id ) {
			$creators[] = array(
				'creator' => $this->creator_payload( $user_id ),
				'stats'   => $repo->stats( $user_id ),
			);
		}

		$sounds = array();
		foreach ( $repo->search_sounds( $query ) as $sound ) {
			$sounds[] = $this->sound_payload( $sound, $repo );
		}

		return new WP_REST_Response(
			array(
				'videos'   => $this->payloads( $repo->search( $query ), $repo, get_current_user_id() ),
				'tags'     => $repo->search_tags( $query ),
				'sounds'   => $sounds,
				'creators' => $creators,
			)
		);
	}

	/* ── Mine ────────────────────────────────────────────────────────── */

	/**
	 * The signed-in account's own videos, published or not.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function mine( WP_REST_Request $request ): WP_REST_Response {
		$repo    = new ShortsRepository();
		$user_id = get_current_user_id();

		$rows = $repo->by_user(
			$user_id,
			(int) $request->get_param( 'per_page' ),
			(int) $request->get_param( 'offset' ),
			true
		);

		return new WP_REST_Response( array( 'items' => $this->payloads( $rows, $repo, $user_id ) ) );
	}

	/**
	 * The signed-in account's saved videos.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function saved( WP_REST_Request $request ): WP_REST_Response {
		$repo    = new ShortsRepository();
		$user_id = get_current_user_id();

		$rows = $repo->saved_by( $user_id, (int) $request->get_param( 'per_page' ), (int) $request->get_param( 'offset' ) );

		return new WP_REST_Response( array( 'items' => $this->payloads( $rows, $repo, $user_id ) ) );
	}

	/**
	 * The AnimehTok stat block.
	 */
	public function stats(): WP_REST_Response {
		$user_id = get_current_user_id();

		return new WP_REST_Response(
			array(
				'stats'   => ( new ShortsRepository() )->stats( $user_id ),
				'creator' => $this->creator_payload( $user_id ),
			)
		);
	}

	/* ── Moderation ──────────────────────────────────────────────────── */

	/**
	 * Everything, newest first, for a moderator.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function admin_index( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$repo   = new ShortsRepository();
		$table  = \Animeh\Storage\ShortsSchema::shorts();
		$limit  = max( 1, min( (int) $request->get_param( 'per_page' ), ShortsRepository::MAX_PER_PAGE ) );
		$offset = (int) $request->get_param( 'offset' );

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return new WP_REST_Response(
			array(
				'items' => $this->payloads( is_array( $rows ) ? $rows : array(), $repo, get_current_user_id() ),
				'total' => $total,
			)
		);
	}

	/**
	 * Take a video down.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete( WP_REST_Request $request ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short ) {
			return $this->not_found();
		}

		$this->remove( $short, $repo );

		( new LogRepository() )->error(
			'MODERATION',
			'AnimehTok videosu kaldırıldı',
			array(
				'short_id' => (int) $short['id'],
				'user_id'  => (int) $short['user_id'],
				'by'       => get_current_user_id(),
			)
		);

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/* ── Payloads ────────────────────────────────────────────────────── */

	/**
	 * Rows as the app wants them, with the viewer's relation to each.
	 *
	 * @param array<int, array<string, mixed>|null> $rows    Short rows.
	 * @param ShortsRepository                      $repo    Repository.
	 * @param int                                   $user_id Viewer.
	 * @return array<int, array<string, mixed>>
	 */
	private function payloads( array $rows, ShortsRepository $repo, int $user_id ): array {
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		if ( array() === $rows ) {
			return array();
		}

		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );

		// One query for the whole page rather than one per video: a feed page
		// is twenty rows and forty round trips is the difference between a
		// feed that opens and one that does not.
		$relations = $repo->relations( $ids, $user_id );

		$settings = StorageSettings::load();
		$client   = $settings->is_configured() ? new B2Client( $settings ) : null;

		$out = array();
		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$creator  = (int) $row['user_id'];
			$sound_id = (int) $row['sound_id'];
			$sound    = $sound_id > 0 ? $repo->sound( $sound_id ) : null;

			$out[] = array(
				'id'            => $id,
				'slug'          => (string) $row['slug'],
				'description'   => (string) $row['description'],
				'tags'          => $repo->tags_of( $id ),
				'video_url'     => $this->url_for( (string) $row['storage_key'], $settings, $client ),
				'cover_url'     => $this->url_for( (string) $row['thumb_key'], $settings, $client ),
				'duration_ms'   => (int) $row['duration_ms'],
				'width'         => (int) $row['width'],
				'height'        => (int) $row['height'],
				'adult'         => (bool) (int) $row['adult'],
				'published'     => (bool) (int) $row['published'],
				'view_count'    => (int) $row['view_count'],
				'like_count'    => (int) $row['like_count'],
				'comment_count' => (int) $row['comment_count'],
				'save_count'    => (int) $row['save_count'],
				'liked'         => isset( $relations['liked'][ $id ] ),
				'saved'         => isset( $relations['saved'][ $id ] ),
				'created_at'    => (string) $row['created_at'],
				'creator'       => $this->creator_payload( $creator ),
				'following'     => $repo->follows( $user_id, $creator ),
				'is_mine'       => $user_id > 0 && $user_id === $creator,
				'sound'         => null === $sound ? null : $this->sound_payload( $sound, $repo ),
			);
		}

		return $out;
	}

	/**
	 * Comments as the app wants them.
	 *
	 * @param array<int, array<string, mixed>|null> $rows    Comment rows.
	 * @param ShortsRepository                      $repo    Repository.
	 * @param int                                   $user_id Viewer.
	 * @return array<int, array<string, mixed>>
	 */
	private function comment_payloads( array $rows, ShortsRepository $repo, int $user_id ): array {
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		if ( array() === $rows ) {
			return array();
		}

		$ids   = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$liked = $repo->liked_comments( $ids, $user_id );

		$out = array();
		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$author = (int) $row['user_id'];

			$out[] = array(
				'id'          => $id,
				'short_id'    => (int) $row['short_id'],
				'parent_id'   => (int) $row['parent_id'],
				'body'        => (string) $row['body'],
				'like_count'  => (int) $row['like_count'],
				'reply_count' => (int) $row['reply_count'],
				'liked'       => isset( $liked[ $id ] ),
				'is_mine'     => $user_id > 0 && $user_id === $author,
				'created_at'  => (string) $row['created_at'],
				'author'      => $this->creator_payload( $author ),
			);
		}

		return $out;
	}

	/**
	 * A sound, with where it is played from.
	 *
	 * @param array<string, mixed> $sound Sound row.
	 * @param ShortsRepository     $repo  Repository.
	 * @return array<string, mixed>
	 */
	private function sound_payload( array $sound, ShortsRepository $repo ): array {
		$origin = (int) $sound['origin_short_id'];
		$short  = $origin > 0 ? $repo->find( $origin ) : null;

		$settings = StorageSettings::load();
		$client   = $settings->is_configured() ? new B2Client( $settings ) : null;

		return array(
			'id'        => (int) $sound['id'],
			'title'     => (string) $sound['title'],
			'author'    => (string) $sound['author'],
			'use_count' => (int) $sound['use_count'],
			// A sound has no file of its own — it is the audio of the video it
			// came from — so the page plays that video's cover and audio.
			'origin_id' => $origin,
			'cover_url' => null === $short ? '' : $this->url_for( (string) $short['thumb_key'], $settings, $client ),
		);
	}

	/**
	 * Who made something.
	 *
	 * @param int $user_id Account.
	 * @return array<string, mixed>
	 */
	private function creator_payload( int $user_id ): array {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return array(
				'id'           => 0,
				'username'     => '',
				'display_name' => __( 'Silinmiş hesap', 'animeh' ),
				'avatar'       => '',
			);
		}

		return array(
			'id'           => $user_id,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'avatar'       => AuthController::avatar_url( $user_id ),
		);
	}

	/* ── Internals ───────────────────────────────────────────────────── */

	/**
	 * Like/unlike/save/unsave, which differ only in which method they call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $action  Repository method.
	 * @return WP_REST_Response|WP_Error
	 */
	private function react( WP_REST_Request $request, string $action ) {
		$repo  = new ShortsRepository();
		$short = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $short ) {
			return $this->not_found();
		}

		$user_id = get_current_user_id();
		$changed = $repo->{$action}( (int) $short['id'], $user_id );

		if ( $changed && 'like' === $action && (int) $short['user_id'] !== $user_id ) {
			$this->notify(
				array( (int) $short['user_id'] ),
				__( 'Yeni beğeni', 'animeh' ),
				sprintf(
					/* translators: %s: display name */
					__( '%s videonu beğendi.', 'animeh' ),
					$this->creator_name( $user_id )
				),
				array(
					'type'     => 'short_like',
					'short_id' => (string) $short['id'],
				)
			);
		}

		$fresh = $repo->find( (int) $short['id'] );
		$items = $this->payloads( array( $fresh ?? $short ), $repo, $user_id );

		return new WP_REST_Response( $items[0] );
	}

	/**
	 * Like or unlike a comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $on      Whether to like.
	 * @return WP_REST_Response|WP_Error
	 */
	private function react_comment( WP_REST_Request $request, bool $on ) {
		$repo    = new ShortsRepository();
		$comment = $repo->comment( (int) $request->get_param( 'id' ) );

		if ( null === $comment ) {
			return $this->not_found();
		}

		$user_id = get_current_user_id();

		if ( $on ) {
			$repo->like_comment( (int) $comment['id'], $user_id );
		} else {
			$repo->unlike_comment( (int) $comment['id'], $user_id );
		}

		$fresh = $repo->comment( (int) $comment['id'] );
		$items = $this->comment_payloads( array( $fresh ?? $comment ), $repo, $user_id );

		return new WP_REST_Response( $items[0] );
	}

	/**
	 * Delete a video's row and the objects behind it.
	 *
	 * @param array<string, mixed> $short Short row.
	 * @param ShortsRepository     $repo  Repository.
	 */
	private function remove( array $short, ShortsRepository $repo ): void {
		$settings = StorageSettings::load();

		if ( $settings->is_configured() ) {
			$client = new B2Client( $settings );

			foreach ( array( (string) $short['storage_key'], (string) $short['thumb_key'] ) as $key ) {
				if ( '' !== $key ) {
					$client->delete_object( $key );
				}
			}
		}

		$repo->delete( (int) $short['id'] );
	}

	/**
	 * Whether the signed-in account may change this video.
	 *
	 * @param array<string, mixed> $short Short row.
	 */
	private function may_edit( array $short ): bool {
		return (int) $short['user_id'] === get_current_user_id() || Permissions::current_user_can_manage();
	}

	/**
	 * An address for a stored object.
	 *
	 * @param string          $key      Object key.
	 * @param StorageSettings $settings Storage settings.
	 * @param B2Client|null   $client   Client, when storage is configured.
	 */
	private function url_for( string $key, StorageSettings $settings, ?B2Client $client ): string {
		if ( '' === $key || ! $settings->is_configured() ) {
			return '';
		}

		if ( $settings->public_bucket ) {
			$friendly = $settings->friendly_url( $key );

			return '' !== $friendly ? $friendly : $settings->s3_url( $key );
		}

		return null === $client ? '' : $client->presign_get( $key );
	}

	/**
	 * What to call a creator.
	 *
	 * @param int $user_id Account.
	 */
	private function creator_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return 'animeh';
		}

		return '' !== $user->display_name ? $user->display_name : $user->user_login;
	}

	/**
	 * Accounts whose name matches a phrase.
	 *
	 * @param string $query What was typed.
	 * @return array<int, int>
	 */
	private function search_users( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		$found = get_users(
			array(
				'search'         => '*' . $query . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'display_name' ),
				'number'         => 10,
				'fields'         => 'ID',
			)
		);

		return array_map( 'intval', (array) $found );
	}

	/**
	 * Upload parts, in the shape the client expects.
	 *
	 * @param array<int, mixed> $parts What was sent.
	 * @return array<int, array{part_number: int, etag: string}>
	 */
	private function normalise_parts( array $parts ): array {
		$out = array();

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$number = (int) ( $part['part_number'] ?? $part['partNumber'] ?? 0 );
			$etag   = trim( (string) ( $part['etag'] ?? $part['eTag'] ?? '' ) );

			if ( $number <= 0 || '' === $etag ) {
				continue;
			}

			$out[] = array(
				'part_number' => $number,
				'etag'        => $etag,
			);
		}

		return $out;
	}

	/**
	 * Cut a string to a length in characters.
	 *
	 * @param string $value What was sent.
	 * @param int    $limit Characters.
	 */
	private function clamp( string $value, int $limit ): string {
		return mb_substr( $value, 0, $limit, 'UTF-8' );
	}

	/**
	 * Send a push, if the site has push configured.
	 *
	 * @param array<int, int>      $user_ids Recipients.
	 * @param string               $title    Notification title.
	 * @param string               $body     Notification body.
	 * @param array<string, string> $data    Payload.
	 */
	private function notify( array $user_ids, string $title, string $body, array $data ): int {
		return ( new Notifier() )->to_users( $user_ids, $title, $body, $data );
	}

	/**
	 * The `id` path argument, which most of these routes share.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function id_arg(): array {
		return array(
			'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
		);
	}

	/**
	 * 404.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'animeh_short_not_found', __( 'Video bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
	}

	/**
	 * 403.
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'animeh_short_forbidden', __( 'Bu video senin değil.', 'animeh' ), array( 'status' => 403 ) );
	}
}
