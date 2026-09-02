<?php
/**
 * What readers contribute: reviews, the votes on them, and their own picture.
 *
 * Also serves the display-name map, which is read by everyone and written only
 * by an admin, so it lives beside the rest of the public surface rather than
 * behind the admin namespace.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\B2Client;
use Animeh\Storage\CatalogRepository;
use Animeh\Storage\ModerationRepository;
use Animeh\Storage\ReviewRepository;
use Animeh\Storage\StorageSettings;
use Animeh\Storage\TermRepository;
use Animeh\Support\StorageKey;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reviews, votes, vocabulary and avatars.
 */
final class CommunityController {

	/**
	 * A profile picture larger than this is refused rather than resized: the
	 * phone already downscales what it picks, and a server-side resize needs an
	 * image library that cannot be relied on to be installed.
	 */
	private const MAX_AVATAR_BYTES = 3 * 1024 * 1024;

	/**
	 * Image types accepted for a profile picture.
	 */
	private const AVATAR_TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$guard     = array( AuthController::class, 'require_login' );
		$admin     = array( Permissions::class, 'require_manage' );

		register_rest_route(
			$namespace,
			'/terms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'terms' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$namespace,
			'/admin/terms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_terms' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_term' ),
					'permission_callback' => $admin,
					'args'                => array(
						'kind'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'source'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'display' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/works/(?P<work_id>\d+)/reviews',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'reviews' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'work_id'  => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
						'sort'     => array( 'type' => 'string', 'default' => 'useful', 'sanitize_callback' => 'sanitize_key' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_review' ),
					'permission_callback' => $guard,
					'args'                => array(
						'work_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'score'   => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
						'body'    => array( 'type' => 'string', 'default' => '' ),
						'spoiler' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/reviews/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_review' ),
				'permission_callback' => $guard,
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/reviews/(?P<id>\d+)/vote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'vote' ),
				'permission_callback' => $guard,
				'args'                => array(
					'id'   => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'vote' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/reviews/(?P<id>\d+)/report',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'report_review' ),
				'permission_callback' => $guard,
				'args'                => array(
					'id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'reason' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'note'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/me/avatar',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_avatar' ),
				'permission_callback' => $guard,
			)
		);
	}

	/**
	 * The display-name map, for a client that renders stored values.
	 */
	public function terms(): WP_REST_Response {
		return new WP_REST_Response( array( 'terms' => ( new TermRepository() )->map() ) );
	}

	/**
	 * The editor's view: every value in use, and what it is shown as.
	 */
	public function admin_terms(): WP_REST_Response {
		$repo      = new TermRepository();
		$overrides = $repo->map();
		$items     = array();

		foreach ( $repo->in_use() as $kind => $values ) {
			foreach ( $values as $value ) {
				$items[] = array(
					'kind'    => $kind,
					'source'  => $value,
					'display' => $overrides[ $kind ][ TermRepository::key( $value ) ] ?? '',
				);
			}
		}

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Set or clear what a value is shown as.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_term( WP_REST_Request $request ) {
		$ok = ( new TermRepository() )->set(
			(string) $request->get_param( 'kind' ),
			(string) $request->get_param( 'source' ),
			(string) $request->get_param( 'display' )
		);

		if ( ! $ok ) {
			return new WP_Error(
				'animeh_bad_term',
				__( 'Geçersiz terim türü ya da boş kaynak değeri.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * A work's reviews, with this reader's own vote on each.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reviews( WP_REST_Request $request ): WP_REST_Response {
		$repo    = new ReviewRepository();
		$work_id = (int) $request->get_param( 'work_id' );
		$user_id = get_current_user_id();

		$page = $repo->for_work(
			$work_id,
			(int) $request->get_param( 'page' ),
			min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			(string) $request->get_param( 'sort' )
		);

		$ids   = array_map( static fn( array $row ): int => (int) $row['id'], $page['items'] );
		$votes = $repo->votes_by( $ids, $user_id );

		$items = array();
		foreach ( $page['items'] as $row ) {
			$items[] = self::review_payload( $row, $votes[ (int) $row['id'] ] ?? 0, $user_id );
		}

		$rating = $repo->rating( $work_id );

		return new WP_REST_Response(
			array(
				'items'  => $items,
				'total'  => $page['total'],
				'rating' => $rating['average'],
				'rating_count' => $rating['count'],
				'mine'   => $user_id > 0
					? ( ( $row = $repo->mine( $work_id, $user_id ) ) ? self::review_payload( $row, $votes[ (int) $row['id'] ] ?? 0, $user_id ) : null )
					: null,
			)
		);
	}

	/**
	 * Post or replace this reader's review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_review( WP_REST_Request $request ) {
		$work_id = (int) $request->get_param( 'work_id' );

		if ( null === ( new CatalogRepository() )->work( $work_id ) ) {
			return new WP_Error( 'NOT_FOUND', __( 'Yapıt bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$score = (int) $request->get_param( 'score' );
		if ( $score < ReviewRepository::MIN_SCORE || $score > ReviewRepository::MAX_SCORE ) {
			return new WP_Error(
				'animeh_bad_score',
				__( 'Puan 1 ile 10 arasında olmalı.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$repo    = new ReviewRepository();
		$user_id = get_current_user_id();

		$id = $repo->save(
			$work_id,
			$user_id,
			$score,
			// The prose is stored as the reader wrote it minus anything that
			// would execute; it is rendered as text on the phone.
			wp_kses_post( (string) $request->get_param( 'body' ) ),
			(bool) $request->get_param( 'spoiler' )
		);

		$saved = $repo->by_id( $id );

		return new WP_REST_Response(
			array( 'review' => null === $saved ? null : self::review_payload( $saved, 0, $user_id ) ),
			201
		);
	}

	/**
	 * Withdraw a review: the author's own, or any of them for an admin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_review( WP_REST_Request $request ) {
		$repo   = new ReviewRepository();
		$id     = (int) $request->get_param( 'id' );
		$review = $repo->by_id( $id );

		if ( null === $review ) {
			return new WP_Error( 'NOT_FOUND', __( 'Eleştiri bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();
		$owns    = (int) $review['user_id'] === $user_id;

		// Moderators too: removing a reported review is the whole point of
		// the report queue, and it is the one catalog-adjacent write they have.
		if ( ! $owns && ! Permissions::current_user_can_moderate() ) {
			return new WP_Error( 'FORBIDDEN', __( 'Bu eleştiri sizin değil.', 'animeh' ), array( 'status' => 403 ) );
		}

		$repo->delete( $id );

		// The reports about it have been acted on by definition; leaving them
		// open would keep an entry in the queue pointing at nothing.
		( new ModerationRepository() )->resolve_for_review( $id, $user_id );

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Report a review to the moderators.
	 *
	 * The reason comes from a fixed list so the queue can be counted and
	 * sorted; `other` carries the reporter's own words, which is the case the
	 * fixed list cannot cover.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report_review( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$review = ( new ReviewRepository() )->by_id( $id );

		if ( null === $review ) {
			return new WP_Error( 'NOT_FOUND', __( 'Eleştiri bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();

		if ( (int) $review['user_id'] === $user_id ) {
			return new WP_Error(
				'FORBIDDEN',
				__( 'Kendi eleştirini şikâyet edemezsin.', 'animeh' ),
				array( 'status' => 403 )
			);
		}

		$reason = (string) $request->get_param( 'reason' );
		$note   = (string) $request->get_param( 'note' );

		if ( 'other' === $reason && '' === trim( $note ) ) {
			return new WP_Error(
				'VALIDATION_ERROR',
				__( 'Diğer seçeneğini seçtiysen nedenini yazman gerekiyor.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new ModerationRepository() )->report(
			$id,
			(int) $review['work_id'],
			$user_id,
			$reason,
			$note
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response( array( 'ok' => true, 'id' => $result ), 201 );
	}

	/**
	 * Agree or disagree with a review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function vote( WP_REST_Request $request ) {
		$repo = new ReviewRepository();
		$id   = (int) $request->get_param( 'id' );

		$review = $repo->by_id( $id );
		if ( null === $review ) {
			return new WP_Error( 'NOT_FOUND', __( 'Eleştiri bulunamadı.', 'animeh' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();

		if ( (int) $review['user_id'] === $user_id ) {
			return new WP_Error(
				'animeh_own_review',
				__( 'Kendi eleştirinize oy veremezsiniz.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$repo->vote( $id, $user_id, (int) $request->get_param( 'vote' ) );

		$updated = $repo->by_id( $id );
		$votes   = $repo->votes_by( array( $id ), $user_id );

		return new WP_REST_Response(
			array( 'review' => null === $updated ? null : self::review_payload( $updated, $votes[ $id ] ?? 0, $user_id ) )
		);
	}

	/**
	 * Store a profile picture in the bucket and point the account at it.
	 *
	 * Sent as a raw body rather than multipart: there is one file, and the
	 * phone already has the bytes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_avatar( WP_REST_Request $request ) {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Depolama ayarlanmamış.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$body = $request->get_body();
		$size = strlen( $body );

		if ( 0 === $size ) {
			return new WP_Error( 'animeh_empty_image', __( 'Görsel boş.', 'animeh' ), array( 'status' => 400 ) );
		}

		if ( $size > self::MAX_AVATAR_BYTES ) {
			return new WP_Error(
				'animeh_image_too_large',
				__( 'Profil fotoğrafı en fazla 3 MB olabilir.', 'animeh' ),
				array( 'status' => 413 )
			);
		}

		// The declared type is not trusted: the bytes themselves decide, so a
		// script renamed to .jpg cannot be stored as an image.
		$type = self::sniff_image( $body );
		if ( null === $type ) {
			return new WP_Error(
				'animeh_bad_image',
				__( 'Yalnızca JPEG, PNG ve WebP kabul ediliyor.', 'animeh' ),
				array( 'status' => 415 )
			);
		}

		$user_id = get_current_user_id();
		$key     = StorageKey::profile_image( $user_id, 'avatar.' . $type['extension'] );

		$stored = ( new B2Client( $settings ) )->put_object( $key, $body, $type['mime'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$previous = (string) get_user_meta( $user_id, AuthController::AVATAR_META, true );
		update_user_meta( $user_id, AuthController::AVATAR_META, $key );

		// The old picture is no longer reachable from anywhere, and storage is
		// billed for as long as it sits there.
		if ( '' !== $previous && $previous !== $key ) {
			( new B2Client( $settings ) )->delete_object( $previous );
		}

		return new WP_REST_Response(
			array(
				'avatar' => AuthController::avatar_url( $user_id ),
				'user'   => AuthController::user_payload( $user_id ),
			),
			201
		);
	}

	/**
	 * Identify an image from its leading bytes.
	 *
	 * @param string $body File contents.
	 * @return array{mime: string, extension: string}|null
	 */
	private static function sniff_image( string $body ): ?array {
		if ( 0 === strncmp( $body, "\xFF\xD8\xFF", 3 ) ) {
			return array( 'mime' => 'image/jpeg', 'extension' => 'jpg' );
		}

		if ( 0 === strncmp( $body, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return array( 'mime' => 'image/png', 'extension' => 'png' );
		}

		if ( 0 === strncmp( $body, 'RIFF', 4 ) && 'WEBP' === substr( $body, 8, 4 ) ) {
			return array( 'mime' => 'image/webp', 'extension' => 'webp' );
		}

		return null;
	}

	/**
	 * One review as the app reads it.
	 *
	 * The author's name and picture are joined in here rather than left to the
	 * client, which has no way to look up a stranger's account.
	 *
	 * @param array<string, mixed> $row     Stored row.
	 * @param int                  $my_vote -1, 0 or 1.
	 * @param int                  $user_id Reader, 0 when signed out.
	 * @return array<string, mixed>
	 */
	private static function review_payload( array $row, int $my_vote, int $user_id ): array {
		$author = (int) $row['user_id'];
		$user   = get_userdata( $author );

		return array(
			'id'           => (int) $row['id'],
			'work_id'      => (int) $row['work_id'],
			'user_id'      => $author,
			'author'       => $user ? $user->display_name : __( 'Silinmiş kullanıcı', 'animeh' ),
			'author_avatar' => $user ? AuthController::avatar_url( $author ) : '',
			'score'        => (int) $row['score'],
			'body'         => (string) $row['body'],
			'spoiler'      => (bool) $row['spoiler'],
			'up_votes'     => (int) $row['up_votes'],
			'down_votes'   => (int) $row['down_votes'],
			'my_vote'      => $my_vote,
			'mine'         => $user_id > 0 && $author === $user_id,
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}
}
