<?php
/**
 * Profiles, friends, watch-party rooms and push registration.
 *
 * The room endpoints are thin on purpose. Opening a room writes one row and
 * hands back a code; everything that happens inside it — the playhead, the
 * chat, who is present — goes through Firebase, because a WordPress install
 * answering a write per second per viewer is the wrong shape for the job. What
 * this owns is the part that has to be trusted: who may open a room, who may
 * join one, and who may be invited to it.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\CatalogRepository;
use Animeh\Storage\FirebaseClient;
use Animeh\Storage\LogRepository;
use Animeh\Storage\ReviewRepository;
use Animeh\Storage\SocialRepository;
use Animeh\Storage\UserDataRepository;
use Animeh\Support\GenreTally;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * The social surface.
 */
final class SocialController {

	/**
	 * User meta holding the work someone chose to show off.
	 */
	public const FAVORITE_META = 'animeh_favorite_work';

	/**
	 * Whether other people may look at this profile. On by default: the
	 * feature exists so friends can see what each other watch, and a default
	 * of off would mean nobody ever sees anything.
	 */
	public const PUBLIC_META = 'animeh_profile_public';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$signed_in = array( $this, 'require_login' );

		// Readable signed out, like the rest of the catalog: a profile link
		// shared outside the app should show something.
		register_rest_route(
			$namespace,
			'/users/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'profile' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/favorite-work',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_favorite_work' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					// Zero clears it, which is how someone stops showing one.
					'work_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/profile-visibility',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_visibility' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'public' => array( 'required' => true, 'type' => 'boolean' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/devices',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register_device' ),
					'permission_callback' => $signed_in,
					'args'                => array(
						'token'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'platform' => array( 'type' => 'string', 'default' => 'android', 'sanitize_callback' => 'sanitize_key' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'forget_device' ),
					'permission_callback' => $signed_in,
					'args'                => array(
						'token' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		/* ── Friends ─────────────────────────────────────────────────── */

		register_rest_route(
			$namespace,
			'/me/friends',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'friends' ),
				'permission_callback' => $signed_in,
			)
		);

		register_rest_route(
			$namespace,
			'/me/friends/requests',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'request_friend' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					// By address or by name: the address is what a person
					// tells you, the name is what you saw on a review.
					'email'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_email' ),
					'username' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'user_id'  => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/friends/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'accept_friend' ),
					'permission_callback' => $signed_in,
					'args'                => array(
						'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_friend' ),
					'permission_callback' => $signed_in,
					'args'                => array(
						'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		/* ── Rooms ───────────────────────────────────────────────────── */

		register_rest_route(
			$namespace,
			'/rooms',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_room' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'episode_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/rooms/(?P<code>[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'room' ),
					'permission_callback' => $signed_in,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'close_room' ),
					'permission_callback' => $signed_in,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/rooms/(?P<code>[A-Za-z0-9]+)/join',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'join_room' ),
				'permission_callback' => $signed_in,
			)
		);

		register_rest_route(
			$namespace,
			'/rooms/(?P<code>[A-Za-z0-9]+)/invite',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'invite' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'user_ids' => array( 'required' => true, 'type' => 'array' ),
				),
			)
		);
	}

	/**
	 * `permission_callback` for anything that needs an account.
	 *
	 * @return true|WP_Error
	 */
	public function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error( 'AUTH_ERROR', __( 'Giriş yapman gerekiyor.', 'animeh' ), array( 'status' => 401 ) );
	}

	/* ── Profiles ────────────────────────────────────────────────────── */

	/**
	 * Somebody's public profile.
	 *
	 * Never the email address, whatever else is on it. Everything here is
	 * something the person chose to do in public — what they watched, what
	 * they scored, what they wrote — except the watch history, which is why
	 * there is a switch to turn the profile off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function profile( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'NOT_FOUND', __( 'Kullanıcı bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$viewer  = get_current_user_id();
		$is_self = $viewer === $user_id;

		if ( ! $is_self && ! self::is_public( $user_id ) ) {
			return new WP_Error(
				'FORBIDDEN',
				__( 'Bu profil gizli.', 'animeh' ),
				array( 'status' => 403 )
			);
		}

		$data    = new UserDataRepository();
		$watched = $data->watched_works( $user_id );

		$recent = array();
		$genres = array();

		foreach ( $watched as $row ) {
			$list = json_decode( (string) $row['genres'], true );
			$genres[] = is_array( $list ) ? $list : array();

			if ( count( $recent ) < 12 ) {
				$recent[] = array(
					'id'         => (int) $row['id'],
					'slug'       => (string) $row['slug'],
					'title'      => (string) $row['title'],
					'poster_url' => (string) $row['poster_url'],
					'adult'      => (bool) $row['adult'],
				);
			}
		}

		$reviews = array();
		foreach ( ( new ReviewRepository() )->by_user( $user_id ) as $row ) {
			$reviews[] = array(
				'id'          => (int) $row['id'],
				'work_id'     => (int) $row['work_id'],
				'work_title'  => (string) $row['work_title'],
				'work_poster' => (string) $row['work_poster'],
				'score'       => (int) $row['score'],
				'body'        => (string) $row['body'],
				'spoiler'     => (bool) $row['spoiler'],
				'up_votes'    => (int) $row['up_votes'],
				'down_votes'  => (int) $row['down_votes'],
				'created_at'  => (string) $row['created_at'],
			);
		}

		$favorite = self::favorite_work( $user_id );
		$social   = new SocialRepository();

		return new WP_REST_Response(
			array(
				'id'            => $user_id,
				'username'      => $user->user_login,
				'display_name'  => $user->display_name,
				'avatar'        => AuthController::avatar_url( $user_id ),
				'registered'    => $user->user_registered,
				'is_self'       => $is_self,
				'is_public'     => self::is_public( $user_id ),
				'stats'         => $data->stats( $user_id ),
				'favorite_work' => $favorite,
				'top_genres'    => GenreTally::top( $genres ),
				'recent_works'  => $recent,
				'reviews'       => $reviews,
				// So the profile can offer the right button rather than a
				// menu of everything that might apply.
				'friendship'    => $is_self || $viewer <= 0
					? ''
					: (string) ( $social->friendship( $viewer, $user_id )['status'] ?? '' ),
			)
		);
	}

	/**
	 * Choose the work shown at the top of your profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_favorite_work( WP_REST_Request $request ) {
		$work_id = (int) $request->get_param( 'work_id' );
		$user_id = get_current_user_id();

		if ( 0 === $work_id ) {
			delete_user_meta( $user_id, self::FAVORITE_META );

			return new WP_REST_Response( array( 'favorite_work' => null ) );
		}

		$work = ( new CatalogRepository() )->work( $work_id );

		if ( null === $work || ! (int) $work['published'] ) {
			return new WP_Error( 'NOT_FOUND', __( 'Anime bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		update_user_meta( $user_id, self::FAVORITE_META, $work_id );

		return new WP_REST_Response( array( 'favorite_work' => self::favorite_work( $user_id ) ) );
	}

	/**
	 * Show or hide your profile from everyone else.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_visibility( WP_REST_Request $request ): WP_REST_Response {
		$public = (bool) $request->get_param( 'public' );

		update_user_meta( get_current_user_id(), self::PUBLIC_META, $public ? '1' : '0' );

		return new WP_REST_Response( array( 'public' => $public ) );
	}

	/**
	 * Whether a profile may be read by other people.
	 *
	 * @param int $user_id Whose.
	 */
	public static function is_public( int $user_id ): bool {
		$stored = get_user_meta( $user_id, self::PUBLIC_META, true );

		// Unset means on: the feature exists so friends can see what each
		// other watch, and defaulting to off would mean nobody sees anything
		// until they find a switch they do not know about.
		return '' === $stored || '1' === $stored;
	}

	/**
	 * The work someone chose to show, if it is still there.
	 *
	 * @param int $user_id Whose.
	 * @return array<string, mixed>|null
	 */
	public static function favorite_work( int $user_id ): ?array {
		$work_id = (int) get_user_meta( $user_id, self::FAVORITE_META, true );

		if ( $work_id <= 0 ) {
			return null;
		}

		$work = ( new CatalogRepository() )->work( $work_id );

		if ( null === $work || ! (int) $work['published'] ) {
			return null;
		}

		return array(
			'id'         => (int) $work['id'],
			'slug'       => (string) $work['slug'],
			'title'      => (string) $work['title'],
			'poster_url' => (string) $work['poster_url'],
			'banner_url' => (string) $work['banner_url'],
			'score'      => (float) $work['score'],
		);
	}

	/* ── Devices ─────────────────────────────────────────────────────── */

	/**
	 * Remember where to send this account's notifications.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function register_device( WP_REST_Request $request ): WP_REST_Response {
		( new SocialRepository() )->register_device(
			get_current_user_id(),
			(string) $request->get_param( 'token' ),
			(string) $request->get_param( 'platform' )
		);

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Stop sending to a device, on sign-out.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function forget_device( WP_REST_Request $request ): WP_REST_Response {
		( new SocialRepository() )->forget_device( (string) $request->get_param( 'token' ) );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/* ── Friends ─────────────────────────────────────────────────────── */

	/**
	 * Your friends, and both directions of what is outstanding.
	 */
	public function friends(): WP_REST_Response {
		$repo    = new SocialRepository();
		$user_id = get_current_user_id();

		return new WP_REST_Response(
			array(
				'friends'  => $this->people( $repo->friends( $user_id, 'accepted' ) ),
				// Waiting on you to answer.
				'incoming' => $this->people( $repo->friends( $user_id, 'pending' ) ),
				// Waiting on them.
				'outgoing' => $this->people( $repo->friends( $user_id, 'requested' ) ),
			)
		);
	}

	/**
	 * Ask to be someone's friend.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function request_friend( WP_REST_Request $request ) {
		$target = $this->resolve_user( $request );

		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$repo   = new SocialRepository();
		$result = $repo->request_friend( get_current_user_id(), $target );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$me = wp_get_current_user();

		$this->notify(
			array( $target ),
			__( 'Yeni arkadaşlık isteği', 'animeh' ),
			sprintf(
				/* translators: %s: the requester's display name. */
				__( '%s seni arkadaş olarak ekledi.', 'animeh' ),
				$me->display_name
			),
			array( 'type' => 'friend_request', 'user_id' => (string) $me->ID )
		);

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Accept a request waiting on you.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept_friend( WP_REST_Request $request ) {
		$friend_id = (int) $request->get_param( 'id' );
		$result    = ( new SocialRepository() )->accept_friend( get_current_user_id(), $friend_id );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$me = wp_get_current_user();

		$this->notify(
			array( $friend_id ),
			__( 'Arkadaşlık isteği kabul edildi', 'animeh' ),
			sprintf(
				/* translators: %s: display name of whoever accepted. */
				__( '%s isteğini kabul etti.', 'animeh' ),
				$me->display_name
			),
			array( 'type' => 'friend_accepted', 'user_id' => (string) $me->ID )
		);

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Decline a request, or drop a friend.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remove_friend( WP_REST_Request $request ): WP_REST_Response {
		( new SocialRepository() )->remove_friend( get_current_user_id(), (int) $request->get_param( 'id' ) );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/* ── Rooms ───────────────────────────────────────────────────────── */

	/**
	 * Open a room on an episode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_room( WP_REST_Request $request ) {
		if ( array() === FirebaseClient::client_config() ) {
			return new WP_Error(
				'FIREBASE_ERROR',
				__( 'Birlikte izleme bu sunucuda yapılandırılmamış.', 'animeh' ),
				array( 'status' => 503 )
			);
		}

		$episode_id = (int) $request->get_param( 'episode_id' );
		$episode    = ( new CatalogRepository() )->episode( $episode_id );

		if ( null === $episode ) {
			return new WP_Error( 'NOT_FOUND', __( 'Bölüm bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$room = ( new SocialRepository() )->create_room(
			get_current_user_id(),
			(int) $episode['work_id'],
			$episode_id
		);

		if ( $room instanceof WP_Error ) {
			return $room;
		}

		return new WP_REST_Response( $this->room_payload( $room ), 201 );
	}

	/**
	 * What is behind a room code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function room( WP_REST_Request $request ) {
		$room = ( new SocialRepository() )->room_by_code( (string) $request->get_param( 'code' ) );

		if ( null === $room ) {
			return new WP_Error(
				'NOT_FOUND',
				__( 'Bu oda kapanmış ya da hiç açılmamış.', 'animeh' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->room_payload( $room ) );
	}

	/**
	 * Enter a room, which is also what says it is still alive.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function join_room( WP_REST_Request $request ) {
		$repo = new SocialRepository();
		$room = $repo->room_by_code( (string) $request->get_param( 'code' ) );

		if ( null === $room ) {
			return new WP_Error(
				'NOT_FOUND',
				__( 'Bu oda kapanmış ya da hiç açılmamış.', 'animeh' ),
				array( 'status' => 404 )
			);
		}

		$repo->join_room( (int) $room['id'], get_current_user_id() );

		return new WP_REST_Response( $this->room_payload( $room ) );
	}

	/**
	 * Close a room. Only whoever opened it may.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function close_room( WP_REST_Request $request ) {
		$repo = new SocialRepository();
		$room = $repo->room_by_code( (string) $request->get_param( 'code' ) );

		if ( null === $room ) {
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		if ( (int) $room['host_id'] !== get_current_user_id() ) {
			return new WP_Error(
				'FORBIDDEN',
				__( 'Bu oda senin değil.', 'animeh' ),
				array( 'status' => 403 )
			);
		}

		$repo->destroy_room( (int) $room['id'] );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Invite friends into a room.
	 *
	 * Friends only, and checked here rather than trusted from the client:
	 * without it the endpoint is a way to push a notification to any account
	 * on the site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function invite( WP_REST_Request $request ) {
		$repo = new SocialRepository();
		$room = $repo->room_by_code( (string) $request->get_param( 'code' ) );

		if ( null === $room ) {
			return new WP_Error( 'NOT_FOUND', __( 'Oda bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$me      = wp_get_current_user();
		$invited = array();

		foreach ( (array) $request->get_param( 'user_ids' ) as $raw ) {
			$candidate = (int) $raw;

			if ( $candidate > 0 && $repo->are_friends( (int) $me->ID, $candidate ) ) {
				$invited[] = $candidate;
			}
		}

		if ( array() === $invited ) {
			return new WP_Error(
				'VALIDATION_ERROR',
				__( 'Sadece arkadaşlarını davet edebilirsin.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$work  = ( new CatalogRepository() )->work( (int) $room['work_id'] );
		$title = null === $work ? '' : (string) $work['title'];

		$this->notify(
			$invited,
			__( 'Birlikte izleme daveti', 'animeh' ),
			'' === $title
				? sprintf(
					/* translators: %s: the inviter's display name. */
					__( '%s seni bir odaya davet etti.', 'animeh' ),
					$me->display_name
				)
				: sprintf(
					/* translators: 1: inviter's display name, 2: anime title. */
					__( '%1$s seni "%2$s" izlemeye davet etti.', 'animeh' ),
					$me->display_name,
					$title
				),
			array(
				'type'       => 'room_invite',
				'room_code'  => (string) $room['code'],
				'work_id'    => (string) $room['work_id'],
				'episode_id' => (string) $room['episode_id'],
			)
		);

		return new WP_REST_Response( array( 'invited' => count( $invited ) ) );
	}

	/* ── Shared ──────────────────────────────────────────────────────── */

	/**
	 * The public shape of a room.
	 *
	 * @param array<string, mixed> $room Room row.
	 * @return array<string, mixed>
	 */
	private function room_payload( array $room ): array {
		$catalog = new CatalogRepository();
		$work    = $catalog->work( (int) $room['work_id'] );
		$episode = $catalog->episode( (int) $room['episode_id'] );

		return array(
			'code'       => (string) $room['code'],
			'host'       => AuthController::user_payload( (int) $room['host_id'] ),
			'work'       => null === $work ? null : CatalogController::work_payload( $work ),
			'episode'    => null === $episode ? null : CatalogController::episode_payload( $episode ),
			'created_at' => (string) $room['created_at'],
			'link'       => self::room_link( (string) $room['code'] ),
		);
	}

	/**
	 * The address an invite is shared as.
	 *
	 * Built from the site's own home URL rather than the API base: this is a
	 * link a person pastes into a chat, and it has to look like a web page
	 * because that is where it will be opened first.
	 *
	 * @param string $code Room code.
	 */
	public static function room_link( string $code ): string {
		return home_url( '/oda/' . rawurlencode( $code ) );
	}

	/**
	 * Turn friendship rows into people.
	 *
	 * @param array<int, array<string, mixed>> $rows Friend rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function people( array $rows ): array {
		$people = array();

		foreach ( $rows as $row ) {
			$payload = AuthController::user_payload( (int) $row['friend_id'] );

			if ( array() === $payload ) {
				continue;
			}

			// Never the address: a friend list is not a way to harvest them.
			unset( $payload['email'] );

			$payload['since'] = (string) $row['updated_at'];
			$people[]         = $payload;
		}

		return $people;
	}

	/**
	 * Find who a friend request is aimed at.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|WP_Error
	 */
	private function resolve_user( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'user_id' );

		if ( $user_id > 0 ) {
			return false === get_userdata( $user_id )
				? new WP_Error( 'NOT_FOUND', __( 'Kullanıcı bulunamadı.', 'animeh' ), array( 'status' => 404 ) )
				: $user_id;
		}

		$email = (string) $request->get_param( 'email' );
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );

			return $user instanceof WP_User
				? (int) $user->ID
				: new WP_Error(
					'NOT_FOUND',
					__( 'Bu e-posta ile kayıtlı bir hesap yok.', 'animeh' ),
					array( 'status' => 404 )
				);
		}

		$username = (string) $request->get_param( 'username' );
		if ( '' !== $username ) {
			$user = get_user_by( 'login', $username );

			return $user instanceof WP_User
				? (int) $user->ID
				: new WP_Error(
					'NOT_FOUND',
					__( 'Bu kullanıcı adı bulunamadı.', 'animeh' ),
					array( 'status' => 404 )
				);
		}

		return new WP_Error(
			'VALIDATION_ERROR',
			__( 'Kullanıcı adı ya da e-posta yaz.', 'animeh' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Push a notification, and carry on if it fails.
	 *
	 * A notification not arriving is a worse experience, not a failed request:
	 * the friend request was still made and the room is still open.
	 *
	 * @param int[]                 $user_ids Who to tell.
	 * @param string                $title    Notification title.
	 * @param string                $body     Notification body.
	 * @param array<string, string> $data     What the app reads on the tap.
	 */
	private function notify( array $user_ids, string $title, string $body, array $data ): void {
		if ( ! FirebaseClient::can_send() ) {
			return;
		}

		$tokens = ( new SocialRepository() )->tokens_for( $user_ids );

		if ( array() === $tokens ) {
			return;
		}

		$sent = ( new FirebaseClient() )->send( $tokens, $title, $body, $data );

		if ( 0 === $sent ) {
			( new LogRepository() )->error(
				'FCM_ERROR',
				'Bildirim gönderilemedi',
				array( 'type' => (string) ( $data['type'] ?? '' ), 'devices' => count( $tokens ) )
			);
		}
	}
}
