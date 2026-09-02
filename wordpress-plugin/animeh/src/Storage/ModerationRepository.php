<?php
/**
 * Suspensions, bans, and reports raised against a review.
 *
 * A sanction is a row rather than a flag on the user, so lifting one leaves
 * the record of it behind: "this is their third suspension" is only answerable
 * with the history kept, and it is the question that decides the fourth.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use WP_Error;

/**
 * Reads and writes the moderation tables.
 */
final class ModerationRepository {

	/**
	 * Reasons a review can be reported for.
	 *
	 * A closed list plus `other`: free text on every report is unreadable at
	 * volume, and the fixed reasons are what make "six people reported this
	 * for spam" countable.
	 *
	 * @var string[]
	 */
	public const REASONS = array( 'spam', 'spoiler', 'abuse', 'offtopic', 'other' );

	/**
	 * Longest a report note may be.
	 */
	public const MAX_NOTE = 500;

	/**
	 * Ban or suspend a user.
	 *
	 * @param int         $user_id    Who.
	 * @param int         $by         Which moderator.
	 * @param string      $reason     Short reason, shown to the user.
	 * @param string      $note       Internal note.
	 * @param string|null $expires_at UTC datetime, or null for permanent.
	 * @return int|WP_Error New ban id.
	 */
	public function ban( int $user_id, int $by, string $reason, string $note = '', ?string $expires_at = null ) {
		global $wpdb;

		// Lifting whatever is already in force first keeps "the active ban" a
		// single row, so the app never has to reason about which of two applies.
		$this->lift( $user_id, $by );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::bans(),
			array(
				'user_id'    => $user_id,
				'reason'     => mb_substr( $reason, 0, 255 ),
				'note'       => mb_substr( $note, 0, 1000 ),
				'expires_at' => $expires_at,
				'lifted_at'  => null,
				'created_by' => $by,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		if ( false === $inserted ) {
			return new WP_Error( 'animeh_ban_failed', __( 'Yasaklama kaydedilemedi.', 'animeh' ), array( 'status' => 500 ) );
		}

		// Every issued token is revoked with the ban, or the app keeps working
		// until one expires — which is the whole sanction, missed.
		( new TokenRepository() )->revoke_all( $user_id );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Lift whatever is in force for a user. Safe to call when nothing is.
	 *
	 * @param int $user_id Who.
	 * @param int $by      Which moderator.
	 */
	public function lift( int $user_id, int $by = 0 ): int {
		global $wpdb;

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . CatalogSchema::bans() . ' SET lifted_at = %s WHERE user_id = %d AND lifted_at IS NULL',
				current_time( 'mysql', true ),
				$user_id
			)
		);

		unset( $by );

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * The sanction in force for a user, if there is one.
	 *
	 * An expired suspension is not in force and is not reported as one; the
	 * row stays for the history rather than being deleted on expiry, so this
	 * has to compare against the clock rather than trust `lifted_at` alone.
	 *
	 * @param int $user_id Who.
	 * @return array<string, mixed>|null
	 */
	public function active_ban( int $user_id ): ?array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . CatalogSchema::bans() . '
				 WHERE user_id = %d AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > %s)
				 ORDER BY id DESC LIMIT 1',
				$user_id,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every sanction ever imposed on a user, newest first.
	 *
	 * @param int $user_id Who.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . CatalogSchema::bans() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 50',
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Users currently under sanction.
	 *
	 * @param int $limit How many.
	 * @return array<int, array<string, mixed>>
	 */
	public function active_bans( int $limit = 100 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . CatalogSchema::bans() . '
				 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > %s)
				 ORDER BY id DESC LIMIT %d',
				current_time( 'mysql', true ),
				max( 1, min( $limit, 500 ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Raise a report against a review.
	 *
	 * One per reporter per review, enforced by the unique key: reporting the
	 * same thing twice is a mistake, not two reports, and counting it as two
	 * would make the queue's ordering wrong.
	 *
	 * @param int    $review_id Review.
	 * @param int    $work_id   Work it belongs to, for the admin listing.
	 * @param int    $reporter  Who reported it.
	 * @param string $reason    One of REASONS.
	 * @param string $note      Free text, only meaningful for `other`.
	 * @return int|WP_Error Report id.
	 */
	public function report( int $review_id, int $work_id, int $reporter, string $reason, string $note = '' ) {
		global $wpdb;

		if ( ! in_array( $reason, self::REASONS, true ) ) {
			$reason = 'other';
		}

		$note = mb_substr( trim( $note ), 0, self::MAX_NOTE );

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id FROM ' . CatalogSchema::review_reports() . ' WHERE review_id = %d AND reporter_id = %d',
				$review_id,
				$reporter
			),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			// Re-reporting updates the reason rather than failing: someone who
			// picked the wrong one and tried again meant the second.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				CatalogSchema::review_reports(),
				array(
					'reason'     => $reason,
					'note'       => $note,
					'status'     => 'open',
					'created_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $existing['id'] )
			);

			return (int) $existing['id'];
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::review_reports(),
			array(
				'review_id'   => $review_id,
				'work_id'     => $work_id,
				'reporter_id' => $reporter,
				'reason'      => $reason,
				'note'        => $note,
				'status'      => 'open',
				'created_at'  => current_time( 'mysql', true ),
			)
		);

		if ( false === $inserted ) {
			return new WP_Error( 'animeh_report_failed', __( 'Şikâyet kaydedilemedi.', 'animeh' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * The report queue, joined to the review it is about.
	 *
	 * @param string $status `open`, `resolved`, or an empty string for both.
	 * @param int    $limit  How many.
	 * @return array<int, array<string, mixed>>
	 */
	public function reports( string $status = 'open', int $limit = 50 ): array {
		global $wpdb;

		$reports = CatalogSchema::review_reports();
		$reviews = CatalogSchema::reviews();
		$works   = CatalogSchema::works();

		$where = '';
		$args  = array();

		if ( '' !== $status ) {
			$where  = 'WHERE r.status = %s';
			$args[] = $status;
		}

		$args[] = max( 1, min( $limit, 200 ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT r.*, v.body AS review_body, v.score AS review_score, v.spoiler AS review_spoiler,
					v.user_id AS review_user_id, w.title AS work_title, w.slug AS work_slug
				 FROM {$reports} r
				 LEFT JOIN {$reviews} v ON v.id = r.review_id
				 LEFT JOIN {$works} w ON w.id = r.work_id
				 {$where}
				 ORDER BY r.created_at DESC
				 LIMIT %d",
				...$args
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many reports are waiting.
	 */
	public function open_report_count(): int {
		global $wpdb;

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM " . CatalogSchema::review_reports() . " WHERE status = 'open'"
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * One report by id.
	 *
	 * @param int $id Report.
	 * @return array<string, mixed>|null
	 */
	public function report_row( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . CatalogSchema::review_reports() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Mark a report dealt with.
	 *
	 * @param int    $id     Report.
	 * @param int    $by     Which moderator.
	 * @param string $status `resolved` or `dismissed`.
	 */
	public function resolve( int $id, int $by, string $status = 'resolved' ): bool {
		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			CatalogSchema::review_reports(),
			array(
				'status'     => in_array( $status, array( 'resolved', 'dismissed' ), true ) ? $status : 'resolved',
				'handled_by' => $by,
				'handled_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		return false !== $updated;
	}

	/**
	 * Close every open report about one review, used when the review goes.
	 *
	 * @param int $review_id Review.
	 * @param int $by        Which moderator.
	 */
	public function resolve_for_review( int $review_id, int $by ): int {
		global $wpdb;

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . CatalogSchema::review_reports() . "
				 SET status = 'resolved', handled_by = %d, handled_at = %s
				 WHERE review_id = %d AND status = 'open'",
				$by,
				current_time( 'mysql', true ),
				$review_id
			)
		);

		return is_numeric( $updated ) ? (int) $updated : 0;
	}
}
