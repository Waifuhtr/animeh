<?php
/**
 * Sending a push notification to people, rather than to devices.
 *
 * `FirebaseClient` speaks in registration tokens, which is the wrong unit for
 * everything that wants to notify somebody: an account can have three phones,
 * or none, and the caller should not have to know. This turns a list of user
 * ids into a send, and — just as importantly — says out loud when nothing was
 * sent.
 *
 * That last part is why this exists as a class rather than a helper on one
 * controller. Both of the ordinary reasons a notification never arrives (no
 * service account configured, and a recipient with no registered device) used
 * to be silent returns, which made "no notification arrived" impossible to
 * tell apart from "it was sent and the phone dropped it". Every caller gets
 * the same logging, because every caller has the same question.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

/**
 * Push notifications, addressed to accounts.
 */
final class Notifier {

	/**
	 * Notify a set of users, and carry on if it fails.
	 *
	 * A notification not arriving is a worse experience, not a failed request:
	 * the episode is still published and the room is still open.
	 *
	 * @param int[]                 $user_ids Who to tell.
	 * @param string                $title    Notification title.
	 * @param string                $body     Notification body.
	 * @param array<string, string> $data     What the app reads on the tap.
	 * @return int How many devices accepted it.
	 */
	public function to_users( array $user_ids, string $title, string $body, array $data = array() ): int {
		$recipients = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		if ( array() === $recipients ) {
			return 0;
		}

		$type = (string) ( $data['type'] ?? '' );
		$log  = new LogRepository();

		if ( ! FirebaseClient::can_send() ) {
			$log->error(
				'FCM_ERROR',
				'Bildirim gönderilemedi: Firebase servis hesabı ayarlı değil',
				array( 'type' => $type )
			);

			return 0;
		}

		$tokens = ( new SocialRepository() )->tokens_for( $recipients );

		if ( array() === $tokens ) {
			$log->error(
				'FCM_ERROR',
				'Bildirim gönderilemedi: alıcının kayıtlı cihazı yok',
				array( 'type' => $type, 'users' => count( $recipients ) )
			);

			return 0;
		}

		$sent = ( new FirebaseClient() )->send( $tokens, $title, $body, $data );

		if ( 0 === $sent ) {
			$log->error(
				'FCM_ERROR',
				'Bildirim gönderilemedi',
				array( 'type' => $type, 'devices' => count( $tokens ) )
			);
		}

		return $sent;
	}
}
