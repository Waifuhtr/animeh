<?php
/**
 * Standings, points, frames and the profile colour.
 *
 * One controller because they are one feature: watching earns points, points
 * buy frames, frames and colours are what other people see, and the standings
 * are why anyone bothers. Splitting them across four files would only mean
 * four copies of the same guard.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\FrameRepository;
use Animeh\Storage\LeaderboardRepository;
use Animeh\Storage\LogRepository;
use Animeh\Storage\PointsRepository;
use Animeh\Support\Points;
use Animeh\Support\ProfileTheme;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The rewards endpoints.
 */
final class RewardsController {

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$signed_in = static fn(): bool => is_user_logged_in();
		$manage    = array( Permissions::class, 'require_manage' );

		// Readable without an account, like the catalogue it sits beside:
		// the standings are the advertisement for the whole thing, and the
		// payload is a display name, a picture and a number — all of which
		// are already on a public profile.
		register_rest_route(
			$namespace,
			'/leaderboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'leaderboard' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'metric' => array(
						'type'              => 'string',
						'default'           => LeaderboardRepository::METRIC_EPISODES,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool => LeaderboardRepository::valid( (string) $value ),
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/points',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'wallet' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'limit' => array( 'type' => 'integer', 'default' => 30, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/frames',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'shop' ),
				'permission_callback' => $signed_in,
			)
		);

		register_rest_route(
			$namespace,
			'/frames/(?P<id>\d+)/buy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'buy' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/frame',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'equip' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'frame_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/profile-theme',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_theme' ),
				'permission_callback' => $signed_in,
				'args'                => array(
					'theme' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool => ProfileTheme::valid( (string) $value ),
					),
				),
			)
		);

		// The shop's stock. Administrators only, moderators included nowhere:
		// what a frame costs is an economy decision, and so is minting the
		// currency below.
		register_rest_route(
			$namespace,
			'/admin/frames',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_frames' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_frame' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/frames/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_frame' ),
					'permission_callback' => $manage,
					'args'                => array(
						'id'         => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'name'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'price'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'rarity'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'sort_order' => array( 'type' => 'integer' ),
						'published'  => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_frame' ),
					'permission_callback' => $manage,
					'args'                => array(
						'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/users/(?P<id>\d+)/points',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'grant_points' ),
				'permission_callback' => $manage,
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'amount' => array( 'type' => 'integer', 'required' => true ),
					'note'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * One board, plus where the person asking stands on it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function leaderboard( WP_REST_Request $request ): WP_REST_Response {
		$metric = (string) $request->get_param( 'metric' );
		$limit  = (int) $request->get_param( 'limit' );
		$viewer = get_current_user_id();

		$board = LeaderboardRepository::board( $metric, $limit > 0 ? $limit : 25 );
		$rows  = array();

		foreach ( $board as $entry ) {
			$user = AuthController::user_payload( (int) $entry['user_id'] );
			if ( array() === $user ) {
				// Deleted between the board being computed and being read.
				continue;
			}

			$rows[] = array(
				'rank'         => (int) $entry['rank'],
				'value'        => (int) $entry['value'],
				'user_id'      => (int) $entry['user_id'],
				'display_name' => (string) $user['display_name'],
				'username'     => (string) $user['username'],
				'avatar'       => (string) $user['avatar'],
				'frame'        => $user['frame'],
				'theme'        => (string) $user['theme'],
				'is_me'        => $viewer === (int) $entry['user_id'],
			);
		}

		return new WP_REST_Response(
			array(
				'metric'  => $metric,
				'entries' => $rows,
				// Their own standing, whether or not they made the list. A
				// board somebody is not on is a board they stop opening.
				'me'      => $viewer > 0 ? LeaderboardRepository::standing( $metric, $viewer ) : null,
			)
		);
	}

	/**
	 * A viewer's balance and where it came from.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function wallet( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$limit   = (int) $request->get_param( 'limit' );

		$entries = array();
		foreach ( PointsRepository::history( $user_id, $limit > 0 ? $limit : 30 ) as $row ) {
			$entries[] = array(
				'id'         => (int) $row['id'],
				'delta'      => (int) $row['delta'],
				'reason'     => (string) $row['reason'],
				'label'      => '' !== (string) $row['note']
					? (string) $row['note']
					: Points::label( (string) $row['reason'] ),
				'created_at' => (string) $row['created_at'],
			);
		}

		return new WP_REST_Response(
			array(
				'balance'     => PointsRepository::balance( $user_id ),
				'earned'      => PointsRepository::earned( $user_id ),
				'per_episode' => Points::PER_EPISODE,
				'entries'     => $entries,
				// The three standings ride along here rather than on `/me`.
				// They are six queries, and `/me` is asked on every launch;
				// this endpoint is asked when somebody opens their profile,
				// which is exactly when a rank is worth counting.
				'ranks'       => array(
					'works'    => (int) LeaderboardRepository::standing( LeaderboardRepository::METRIC_WORKS, $user_id )['rank'],
					'seconds'  => (int) LeaderboardRepository::standing( LeaderboardRepository::METRIC_SECONDS, $user_id )['rank'],
					'episodes' => (int) LeaderboardRepository::standing( LeaderboardRepository::METRIC_EPISODES, $user_id )['rank'],
				),
			)
		);
	}

	/**
	 * The shop: what exists, what is owned, what is being worn.
	 *
	 * @return WP_REST_Response
	 */
	public function shop(): WP_REST_Response {
		$user_id = get_current_user_id();
		$owned   = FrameRepository::owned_ids( $user_id );
		$wearing = (int) get_user_meta( $user_id, FrameRepository::EQUIPPED_META, true );

		$frames = array();
		foreach ( FrameRepository::all( true ) as $frame ) {
			$payload             = FrameRepository::payload( $frame );
			$payload['owned']    = in_array( (int) $frame['id'], $owned, true );
			$payload['equipped'] = (int) $frame['id'] === $wearing;
			$frames[]            = $payload;
		}

		// An owned frame that has since been unpublished still belongs to the
		// person who bought it, so it is listed for them even though it is
		// not for sale any more.
		$listed = array_map( static fn( array $f ): int => (int) $f['id'], $frames );
		foreach ( $owned as $id ) {
			if ( in_array( $id, $listed, true ) ) {
				continue;
			}
			$frame = FrameRepository::cached( $id );
			if ( null === $frame ) {
				continue;
			}
			$payload             = FrameRepository::payload( $frame );
			$payload['owned']    = true;
			$payload['equipped'] = $id === $wearing;
			$payload['retired']  = true;
			$frames[]            = $payload;
		}

		return new WP_REST_Response(
			array(
				'balance'  => PointsRepository::balance( $user_id ),
				'equipped' => $wearing,
				'frames'   => $frames,
			)
		);
	}

	/**
	 * Buy a frame.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function buy( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$frame_id = (int) $request->get_param( 'id' );
		$frame    = FrameRepository::find( $frame_id );

		if ( null === $frame || ! (bool) $frame['published'] ) {
			return new WP_Error( 'animeh_frame_missing', __( 'Çerçeve bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		if ( FrameRepository::owns( $user_id, $frame_id ) ) {
			// Already theirs. Not an error — a second tap on a slow connection
			// looks exactly like this — so it succeeds and changes nothing.
			return new WP_REST_Response(
				array(
					'owned'   => true,
					'balance' => PointsRepository::balance( $user_id ),
				)
			);
		}

		$price   = (int) $frame['price'];
		$outcome = PointsRepository::spend(
			$user_id,
			$price,
			Points::REASON_FRAME,
			Points::frame_key( $frame_id ),
			(string) $frame['name']
		);

		if ( 'insufficient' === $outcome ) {
			return new WP_Error(
				'animeh_points_short',
				__( 'Bu çerçeve için yeterli puanın yok.', 'animeh' ),
				array( 'status' => 402 )
			);
		}

		if ( '' !== $outcome ) {
			return new WP_Error(
				'animeh_frame_purchase',
				__( 'Satın alma tamamlanamadı.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		FrameRepository::grant( $user_id, $frame_id );

		return new WP_REST_Response(
			array(
				'owned'   => true,
				'balance' => PointsRepository::balance( $user_id ),
			)
		);
	}

	/**
	 * Wear a frame, or take it off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function equip( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$frame_id = (int) $request->get_param( 'frame_id' );

		if ( ! FrameRepository::equip( $user_id, $frame_id ) ) {
			return new WP_Error(
				'animeh_frame_not_owned',
				__( 'Bu çerçeve sende yok.', 'animeh' ),
				array( 'status' => 403 )
			);
		}

		return new WP_REST_Response(
			array(
				'equipped' => $frame_id,
				'frame'    => FrameRepository::payload_for_user( $user_id ),
			)
		);
	}

	/**
	 * Choose a profile colour.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function set_theme( WP_REST_Request $request ): WP_REST_Response {
		$theme = ProfileTheme::normalise( $request->get_param( 'theme' ) );

		update_user_meta( get_current_user_id(), AuthController::THEME_META, $theme );

		return new WP_REST_Response( array( 'theme' => $theme ) );
	}

	/**
	 * Every frame, published or not, for the panel.
	 *
	 * @return WP_REST_Response
	 */
	public function admin_frames(): WP_REST_Response {
		$frames = array();

		foreach ( FrameRepository::all( false ) as $frame ) {
			$payload               = FrameRepository::payload( $frame );
			$payload['published']  = (bool) $frame['published'];
			$payload['sort_order'] = (int) $frame['sort_order'];
			$payload['width']      = (int) $frame['width'];
			$payload['size_bytes'] = (int) $frame['size_bytes'];
			$payload['format']     = (string) $frame['format'];
			$frames[]              = $payload;
		}

		return new WP_REST_Response( array( 'frames' => $frames ) );
	}

	/**
	 * Add a frame to the shop.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_frame( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		// One part named `file`, or many named `file[]`. Forty frames uploaded
		// one at a time is forty round trips and forty dialogs; PHP already
		// gives us the whole set in one request, so the only thing missing was
		// a handler that looked for it.
		$uploads = self::normalise_uploads( $files['file'] ?? null );

		if ( array() === $uploads ) {
			return new WP_Error( 'animeh_frame_no_file', __( 'Dosya gelmedi.', 'animeh' ), array( 'status' => 400 ) );
		}

		// A name given with a batch would be the same name on every frame, so
		// it is only used when there is exactly one file. The rest are named
		// from their own filenames, which is what a batch upload wants anyway.
		$single = 1 === count( $uploads );

		$added  = array();
		$failed = array();

		foreach ( $uploads as $index => $file ) {
			$stored = FrameRepository::store(
				(string) $file['tmp_name'],
				(string) ( $file['name'] ?? 'frame' ),
				array(
					'name'       => $single ? (string) $request->get_param( 'name' ) : '',
					'price'      => (int) $request->get_param( 'price' ),
					'rarity'     => (string) $request->get_param( 'rarity' ),
					// Keeps the order they were picked in, so a numbered set
					// of frames lands in the shop in that order.
					'sort_order' => (int) $request->get_param( 'sort_order' ) + $index,
					'published'  => null === $request->get_param( 'published' ) ? true : (bool) $request->get_param( 'published' ),
				)
			);

			if ( $stored instanceof WP_Error ) {
				// One bad file does not sink the batch: the other thirty-nine
				// are fine, and the reply says exactly which one failed and
				// why so it can be fixed and sent again.
				$failed[] = array(
					'filename' => (string) ( $file['name'] ?? '' ),
					'message'  => $stored->get_error_message(),
				);
				continue;
			}

			$added[] = FrameRepository::payload( $stored );
		}

		if ( array() === $added ) {
			return new WP_Error(
				'animeh_frame_all_failed',
				$failed[0]['message'] ?? __( 'Çerçeve eklenemedi.', 'animeh' ),
				array( 'status' => 422, 'failed' => $failed )
			);
		}

		( new LogRepository() )->record(
			'info',
			'frame_added',
			sprintf( '%d çerçeve eklendi', count( $added ) )
		);

		return new WP_REST_Response(
			array(
				// Kept for the single-file callers that read `frame`.
				'frame'  => $added[0],
				'frames' => $added,
				'failed' => $failed,
			),
			201
		);
	}

	/**
	 * PHP's two shapes for an upload field, as one list.
	 *
	 * A single part arrives as `['name' => 'a.webp', 'tmp_name' => …]`; a
	 * repeated one as `['name' => ['a.webp', 'b.webp'], 'tmp_name' => […]]`.
	 * Everything downstream wants the first shape, one at a time.
	 *
	 * @param mixed $field The `$_FILES` entry.
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
				'name'     => $field['name'][ $index ] ?? 'frame',
				'tmp_name' => $field['tmp_name'][ $index ],
				'error'    => UPLOAD_ERR_OK,
			);
		}

		return $uploads;
	}

	/**
	 * Change a frame's price, name, order or visibility.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_frame( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		$fields = array();
		foreach ( array( 'name', 'price', 'rarity', 'sort_order', 'published' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$fields[ $key ] = $value;
			}
		}

		if ( ! FrameRepository::update( $id, $fields ) ) {
			return new WP_Error( 'animeh_frame_missing', __( 'Çerçeve bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$frame = FrameRepository::find( $id );

		return new WP_REST_Response( array( 'frame' => FrameRepository::payload( $frame ) ) );
	}

	/**
	 * Remove a frame.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_frame( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! FrameRepository::delete( $id ) ) {
			return new WP_Error( 'animeh_frame_missing', __( 'Çerçeve bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		( new LogRepository() )->record( 'info', 'frame_deleted', 'Çerçeve silindi: #' . $id );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Send points to somebody.
	 *
	 * A negative amount takes them back, which is the only way to undo a
	 * mistake without editing the database by hand. The ledger keeps both
	 * rows: a correction is history too.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function grant_points( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$amount  = Points::clamp_grant( (int) $request->get_param( 'amount' ) );
		$note    = (string) $request->get_param( 'note' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'animeh_user_missing', __( 'Kullanıcı bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		if ( 0 === $amount ) {
			return new WP_Error( 'animeh_points_zero', __( 'Sıfır puan gönderilemez.', 'animeh' ), array( 'status' => 400 ) );
		}

		PointsRepository::record(
			$user_id,
			$amount,
			Points::REASON_GRANT,
			null,
			$note,
			get_current_user_id()
		);

		( new LogRepository() )->record(
			'info',
			'points_granted',
			sprintf( '%d puan gönderildi', $amount ),
			array( 'note' => $note ),
			$user_id
		);

		return new WP_REST_Response(
			array(
				'balance' => PointsRepository::balance( $user_id ),
				'granted' => $amount,
			)
		);
	}
}
