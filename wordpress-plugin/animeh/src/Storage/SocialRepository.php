<?php
/**
 * Friends, watch-party rooms, and the devices a notification is sent to.
 *
 * The division of labour with Firebase is the thing to understand here. This
 * table holds what has to outlive a moment — who is whose friend, who opened a
 * room, what is being watched in it — and Firebase holds what changes several
 * times a second: the playhead, the chat, who is currently present. A shared
 * hosting WordPress asked to answer a write per second per viewer would fall
 * over, and a room that stopped existing when the last person closed the app
 * is not worth a row in a durable database anyway.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use WP_Error;

/**
 * The social graph and the rooms hanging off it.
 */
final class SocialRepository {

	/**
	 * How long a room survives without anyone reporting themselves in it.
	 *
	 * The app closes a room when the last person leaves, but an app that was
	 * killed cannot close anything — so a sweep is what actually guarantees
	 * the promise that a room does not outlive the people in it.
	 */
	public const ROOM_IDLE_SECONDS = 3600;

	/**
	 * Length of the code an invite link carries.
	 *
	 * Random rather than the row id: a link with a guessable code is a
	 * stranger in your living room. Ten characters of the URL-safe alphabet is
	 * about 60 bits, which is not worth guessing at.
	 */
	private const CODE_LENGTH = 10;

	/* ── Friends ─────────────────────────────────────────────────────── */

	/**
	 * Ask to be someone's friend.
	 *
	 * Two rows: theirs is `pending` and yours is `requested`, so both sides can
	 * list what is waiting on them without a UNION.
	 *
	 * @param int $user_id   Who is asking.
	 * @param int $friend_id Who is being asked.
	 * @return true|WP_Error
	 */
	public function request_friend( int $user_id, int $friend_id ) {
		if ( $user_id === $friend_id ) {
			return new WP_Error( 'VALIDATION_ERROR', __( 'Kendini ekleyemezsin.', 'animeh' ), array( 'status' => 400 ) );
		}

		$existing = $this->friendship( $user_id, $friend_id );

		if ( null !== $existing && 'accepted' === $existing['status'] ) {
			return new WP_Error( 'VALIDATION_ERROR', __( 'Zaten arkadaşsınız.', 'animeh' ), array( 'status' => 400 ) );
		}

		// They already asked you: asking back is accepting, which is what
		// anyone tapping "add" on someone who invited them means.
		$incoming = $this->friendship( $friend_id, $user_id );
		if ( null !== $incoming && 'requested' === $incoming['status'] ) {
			return $this->accept_friend( $user_id, $friend_id );
		}

		$this->upsert_edge( $user_id, $friend_id, 'requested' );
		$this->upsert_edge( $friend_id, $user_id, 'pending' );

		return true;
	}

	/**
	 * Accept a request that is waiting on you.
	 *
	 * @param int $user_id   Who is accepting.
	 * @param int $friend_id Who asked.
	 * @return true|WP_Error
	 */
	public function accept_friend( int $user_id, int $friend_id ) {
		$row = $this->friendship( $user_id, $friend_id );

		if ( null === $row || 'pending' !== $row['status'] ) {
			return new WP_Error( 'NOT_FOUND', __( 'Bekleyen bir istek yok.', 'animeh' ), array( 'status' => 404 ) );
		}

		$this->upsert_edge( $user_id, $friend_id, 'accepted' );
		$this->upsert_edge( $friend_id, $user_id, 'accepted' );

		return true;
	}

	/**
	 * Decline a request, or remove a friend. Both directions go.
	 *
	 * @param int $user_id   Who is removing.
	 * @param int $friend_id The other side.
	 */
	public function remove_friend( int $user_id, int $friend_id ): bool {
		global $wpdb;

		$table = CatalogSchema::friends();

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE (user_id = %d AND friend_id = %d) OR (user_id = %d AND friend_id = %d)",
				$user_id,
				$friend_id,
				$friend_id,
				$user_id
			)
		);

		return false !== $deleted;
	}

	/**
	 * One side of a friendship, if it is there.
	 *
	 * @param int $user_id   Row owner.
	 * @param int $friend_id The other side.
	 * @return array<string, mixed>|null
	 */
	public function friendship( int $user_id, int $friend_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . CatalogSchema::friends() . ' WHERE user_id = %d AND friend_id = %d',
				$user_id,
				$friend_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether two people may invite each other.
	 *
	 * @param int $user_id   One side.
	 * @param int $friend_id The other.
	 */
	public function are_friends( int $user_id, int $friend_id ): bool {
		$row = $this->friendship( $user_id, $friend_id );

		return null !== $row && 'accepted' === $row['status'];
	}

	/**
	 * Everyone in one state, newest first.
	 *
	 * @param int    $user_id Whose list.
	 * @param string $status  accepted, pending (waiting on them) or requested.
	 * @return array<int, array<string, mixed>>
	 */
	public function friends( int $user_id, string $status = 'accepted' ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . CatalogSchema::friends() . '
				 WHERE user_id = %d AND status = %s
				 ORDER BY updated_at DESC LIMIT 500',
				$user_id,
				$status
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Write one direction of a friendship.
	 *
	 * @param int    $user_id   Row owner.
	 * @param int    $friend_id The other side.
	 * @param string $status    New status.
	 */
	private function upsert_edge( int $user_id, int $friend_id, string $status ): void {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = CatalogSchema::friends();

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, friend_id, status, created_at, updated_at)
				 VALUES (%d, %d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)",
				$user_id,
				$friend_id,
				$status,
				$now,
				$now
			)
		);
	}

	/* ── Rooms ───────────────────────────────────────────────────────── */

	/**
	 * Open a room.
	 *
	 * @param int $host_id    Who opened it.
	 * @param int $work_id    What is being watched.
	 * @param int $episode_id Which episode it started on.
	 * @return array<string, mixed>|WP_Error The room row.
	 */
	public function create_room( int $host_id, int $work_id, int $episode_id ) {
		global $wpdb;

		// One open room per person. Someone who opens a second has abandoned
		// the first, and two live rooms with the same host is a puzzle for
		// whoever is holding the older link.
		$this->close_rooms_hosted_by( $host_id );

		$now  = current_time( 'mysql', true );
		$code = $this->unique_code();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::rooms(),
			array(
				'code'         => $code,
				'host_id'      => $host_id,
				'work_id'      => $work_id,
				'episode_id'   => $episode_id,
				'created_at'   => $now,
				'last_seen_at' => $now,
				'closed_at'    => null,
			)
		);

		if ( false === $inserted ) {
			return new WP_Error( 'animeh_room_failed', __( 'Oda açılamadı.', 'animeh' ), array( 'status' => 500 ) );
		}

		$room_id = (int) $wpdb->insert_id;
		$this->join_room( $room_id, $host_id );

		$room = $this->room_by_id( $room_id );

		return null === $room
			? new WP_Error( 'animeh_room_failed', __( 'Oda açılamadı.', 'animeh' ), array( 'status' => 500 ) )
			: $room;
	}

	/**
	 * A room by the code its link carries.
	 *
	 * @param string $code Room code.
	 * @return array<string, mixed>|null
	 */
	public function room_by_code( string $code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . CatalogSchema::rooms() . ' WHERE code = %s AND closed_at IS NULL',
				$code
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A room by id, closed or not.
	 *
	 * @param int $id Room id.
	 * @return array<string, mixed>|null
	 */
	public function room_by_id( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . CatalogSchema::rooms() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Record that someone is in a room, and that the room is still alive.
	 *
	 * @param int $room_id Room.
	 * @param int $user_id Who.
	 */
	public function join_room( int $room_id, int $user_id ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO ' . CatalogSchema::room_members() . ' (room_id, user_id, joined_at)
				 VALUES (%d, %d, %s)
				 ON DUPLICATE KEY UPDATE joined_at = VALUES(joined_at)',
				$room_id,
				$user_id,
				$now
			)
		);

		$this->touch_room( $room_id );
	}

	/**
	 * Push a room's idle clock back.
	 *
	 * @param int $room_id Room.
	 */
	public function touch_room( int $room_id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::rooms(),
			array( 'last_seen_at' => current_time( 'mysql', true ) ),
			array( 'id' => $room_id )
		);
	}

	/**
	 * Everyone who has been in a room.
	 *
	 * @param int $room_id Room.
	 * @return int[] User ids.
	 */
	public function room_member_ids( int $room_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT user_id FROM ' . CatalogSchema::room_members() . ' WHERE room_id = %d',
				$room_id
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Close a room and forget everything about it.
	 *
	 * Deleted rather than kept: a room is worthless the moment the people in
	 * it have gone, and the promise made to whoever opened it was that it
	 * would not leave anything behind.
	 *
	 * @param int $room_id Room.
	 */
	public function destroy_room( int $room_id ): void {
		global $wpdb;

		$wpdb->delete( CatalogSchema::room_members(), array( 'room_id' => $room_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( CatalogSchema::rooms(), array( 'id' => $room_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Close whatever this person still has open.
	 *
	 * @param int $host_id Host.
	 * @return string[] The codes that were closed, so Firebase can be cleaned.
	 */
	public function close_rooms_hosted_by( int $host_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id, code FROM ' . CatalogSchema::rooms() . ' WHERE host_id = %d AND closed_at IS NULL',
				$host_id
			),
			ARRAY_A
		);

		$codes = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$codes[] = (string) $row['code'];
			$this->destroy_room( (int) $row['id'] );
		}

		return $codes;
	}

	/**
	 * Delete rooms nobody has reported themselves in for an hour.
	 *
	 * The app closes a room when the last person leaves; this is what covers
	 * the app that was killed, ran out of battery or lost its connection and
	 * never got to say so.
	 *
	 * @return string[] The codes that were swept.
	 */
	public function sweep_idle_rooms(): array {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::ROOM_IDLE_SECONDS );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id, code FROM ' . CatalogSchema::rooms() . ' WHERE last_seen_at < %s LIMIT 200',
				$cutoff
			),
			ARRAY_A
		);

		$codes = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$codes[] = (string) $row['code'];
			$this->destroy_room( (int) $row['id'] );
		}

		return $codes;
	}

	/**
	 * The rooms these people currently have open.
	 *
	 * What the "Odalar" list is built from. Restricted to friends by the
	 * caller rather than here — a listing of every open room on the site
	 * would be a list of strangers to walk in on, and the room code is the
	 * only thing standing between a link and a living room.
	 *
	 * Rooms past their idle window are left out rather than swept here: a read
	 * that deletes rows is a surprise, and the sweep runs on its own schedule.
	 *
	 * @param int[] $host_ids Whose rooms to list.
	 * @param int   $limit    Most rooms to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function open_rooms( array $host_ids, int $limit = 50 ): array {
		global $wpdb;

		$hosts = array_values( array_unique( array_filter( array_map( 'intval', $host_ids ) ) ) );

		if ( array() === $hosts ) {
			return array();
		}

		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - self::ROOM_IDLE_SECONDS );
		$placeholders = implode( ', ', array_fill( 0, count( $hosts ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . CatalogSchema::rooms() .
				" WHERE closed_at IS NULL AND host_id IN ({$placeholders}) AND last_seen_at >= %s" .
				' ORDER BY last_seen_at DESC LIMIT %d',
				array_merge( $hosts, array( $cutoff, max( 1, min( 100, $limit ) ) ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many people have been in a room.
	 *
	 * The durable count, not the live one — Firebase holds who is present this
	 * second. It is what a listing needs: "3 kişi" on a card that is about to
	 * be tapped, not a presence indicator.
	 *
	 * @param int $room_id Room.
	 */
	public function room_member_count( int $room_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CatalogSchema::room_members() . ' WHERE room_id = %d',
				$room_id
			)
		);
	}

	/**
	 * A code no open room is already using.
	 */
	private function unique_code(): string {
		$alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$code = '';

			for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}

			if ( null === $this->room_by_code( $code ) ) {
				return $code;
			}
		}

		// Eight collisions on a 32^10 space means something is very wrong with
		// the random source; a longer code is the safe way out rather than a
		// loop that might not end.
		return substr( bin2hex( random_bytes( 16 ) ), 0, 20 );
	}

	/* ── Devices ─────────────────────────────────────────────────────── */

	/**
	 * Remember where to send this person's notifications.
	 *
	 * The token is the unique key rather than the user: a device handed on to
	 * someone else re-registers the same token under the new account, and the
	 * old owner should stop receiving that phone's notifications immediately.
	 *
	 * @param int    $user_id  Whose.
	 * @param string $token    FCM registration token.
	 * @param string $platform Which kind of install.
	 */
	public function register_device( int $user_id, string $token, string $platform = 'android' ): bool {
		global $wpdb;

		$token = trim( $token );
		if ( '' === $token ) {
			return false;
		}

		$now   = current_time( 'mysql', true );
		$table = CatalogSchema::devices();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, token, platform, created_at, last_seen_at)
				 VALUES (%d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = VALUES(last_seen_at)",
				$user_id,
				$token,
				$platform,
				$now,
				$now
			)
		);

		return false !== $result;
	}

	/**
	 * Forget a token, on sign-out or when FCM says it is dead.
	 *
	 * @param string $token Registration token.
	 */
	public function forget_device( string $token ): bool {
		global $wpdb;

		return false !== $wpdb->delete( CatalogSchema::devices(), array( 'token' => $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Every token belonging to these people.
	 *
	 * @param int[] $user_ids Users.
	 * @return string[]
	 */
	public function tokens_for( array $user_ids ): array {
		global $wpdb;

		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		if ( array() === $user_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$tokens = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT token FROM ' . CatalogSchema::devices() . " WHERE user_id IN ({$placeholders}) LIMIT 500",
				$user_ids
			)
		);

		return is_array( $tokens ) ? array_values( array_unique( array_map( 'strval', $tokens ) ) ) : array();
	}
}
