<?php
/**
 * Moving the library to another site, and getting it back when one is lost.
 *
 * Two situations, deliberately handled by two different mechanisms:
 *
 * **The old site still works.** The operator generates a code on the old site
 * and types it into the new one, which then pulls the data across directly.
 * The pull direction matters: the operator is sitting at the new site, the new
 * site may be behind NAT or not yet resolvable at its final address, and an old
 * site that has to reach the new one fails in both cases.
 *
 * **The old site is gone.** Nothing can be asked of it, so the new site
 * restores from the bucket, which was never on the lost host. The bucket
 * credentials are the only thing the operator has to carry — and they were
 * always going to need those anyway, since the videos are there too.
 *
 * Either way, the last step writes a pointer file into the bucket so the
 * installed app finds the new backend without being updated by hand.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Rest;

use Animeh\Storage\SnapshotStore;
use Animeh\Storage\StorageSettings;
use Animeh\Support\MigrationCode;
use Animeh\Support\Snapshot;
use Animeh\Support\UrlGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Snapshot, handoff and restore endpoints.
 */
final class MigrationController {

	/**
	 * Option holding the outstanding handoff code.
	 */
	private const HANDOFF_OPTION = 'animeh_migration_handoff';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$namespace = FontsController::NAMESPACE;
		$guard     = array( Permissions::class, 'require_manage' );

		register_rest_route(
			$namespace,
			'/migration/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			$namespace,
			'/migration/snapshots',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_snapshots' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_snapshot' ),
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/migration/schedule',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_schedule' ),
				'permission_callback' => $guard,
				'args'                => array(
					'enabled' => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/migration/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_from_bucket' ),
				'permission_callback' => $guard,
				'args'                => array(
					'key'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'confirm' => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			)
		);

		// Old site: issue or withdraw the pairing code.
		register_rest_route(
			$namespace,
			'/migration/handoff',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'open_handoff' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'close_handoff' ),
					'permission_callback' => $guard,
				),
			)
		);

		// Old site: hand over the data. The only route in the plugin not
		// guarded by a capability, because the caller is a different site with
		// no account here. The pairing code stands in for the login, and it is
		// checked in the permission callback — not in the handler — so the data
		// is never assembled for a caller that failed the check.
		register_rest_route(
			$namespace,
			'/migration/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'check_handoff_code' ),
				'args'                => array(
					'code' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// New site: pull from the old one.
		register_rest_route(
			$namespace,
			'/migration/pull',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pull' ),
				'permission_callback' => $guard,
				'args'                => array(
					'source_url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'code'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/migration/pointer',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_pointer' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'claim_pointer' ),
					'permission_callback' => $guard,
				),
			)
		);
	}

	/**
	 * Everything the migration screen needs to draw itself.
	 */
	public function status(): WP_REST_Response {
		$settings = StorageSettings::load();
		$handoff  = $this->handoff_state();

		return new WP_REST_Response(
			array(
				'storage_configured' => $settings->is_configured(),
				'scheduled'          => SnapshotStore::is_scheduled(),
				'last_snapshot'      => SnapshotStore::status(),
				'site_url'           => home_url(),
				'api_base'           => rest_url( FontsController::NAMESPACE ),
				'handoff'            => $handoff,
				'keep'               => SnapshotStore::KEEP,
			)
		);
	}

	/**
	 * Snapshots in the bucket.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_snapshots() {
		$listing = SnapshotStore::listing();
		if ( $listing instanceof WP_Error ) {
			return $listing;
		}

		return new WP_REST_Response( array( 'snapshots' => $listing ) );
	}

	/**
	 * Take a snapshot now.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_snapshot() {
		$result = SnapshotStore::run();
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Turn the nightly snapshot on or off.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_schedule( WP_REST_Request $request ): WP_REST_Response {
		$enabled = (bool) $request->get_param( 'enabled' );
		SnapshotStore::set_schedule( $enabled );

		return new WP_REST_Response( array( 'scheduled' => SnapshotStore::is_scheduled() ) );
	}

	/**
	 * Replace this site's library with a snapshot from the bucket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_from_bucket( WP_REST_Request $request ) {
		if ( ! $request->get_param( 'confirm' ) ) {
			return new WP_Error(
				'animeh_confirm_required',
				__( 'Geri yükleme mevcut verinin üzerine yazar; onay gerekiyor.', 'animeh' ),
				array( 'status' => 400 )
			);
		}

		$envelope = SnapshotStore::fetch( (string) $request->get_param( 'key' ) );
		if ( $envelope instanceof WP_Error ) {
			return $envelope;
		}

		return $this->apply( $envelope );
	}

	/**
	 * Issue a pairing code for a planned move.
	 */
	public function open_handoff(): WP_REST_Response {
		$code   = MigrationCode::generate();
		$secret = $this->handoff_secret();

		update_option(
			self::HANDOFF_OPTION,
			array(
				'hash'      => MigrationCode::hash( $code, $secret ),
				'issued_at' => time(),
				'issued_by' => get_current_user_id(),
				'used_at'   => 0,
			),
			false
		);

		return new WP_REST_Response(
			array(
				// The only time the code is ever returned. It is stored as a
				// hash, so it cannot be shown again.
				'code'      => $code,
				'expires_in' => MigrationCode::TTL_SECONDS,
				'api_base'  => rest_url( FontsController::NAMESPACE ),
			),
			201
		);
	}

	/**
	 * Withdraw an outstanding code.
	 */
	public function close_handoff(): WP_REST_Response {
		delete_option( self::HANDOFF_OPTION );

		return new WP_REST_Response( array( 'handoff' => $this->handoff_state() ) );
	}

	/**
	 * Permission callback for the export route: a valid, unused code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_handoff_code( WP_REST_Request $request ) {
		$stored = get_option( self::HANDOFF_OPTION, array() );
		$denied = new WP_Error(
			'animeh_handoff_denied',
			__( 'Kod geçersiz ya da süresi dolmuş.', 'animeh' ),
			array( 'status' => 403 )
		);

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return $denied;
		}

		// Single use: a code that has already moved the library must not move
		// it again for whoever else has seen it.
		if ( ! empty( $stored['used_at'] ) ) {
			return $denied;
		}

		$valid = MigrationCode::verify(
			(string) $request->get_param( 'code' ),
			(string) $stored['hash'],
			$this->handoff_secret(),
			(int) ( $stored['issued_at'] ?? 0 )
		);

		return $valid ? true : $denied;
	}

	/**
	 * Hand the library to the new site.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function export() {
		$stored = get_option( self::HANDOFF_OPTION, array() );
		if ( is_array( $stored ) ) {
			$stored['used_at'] = time();
			update_option( self::HANDOFF_OPTION, $stored, false );
		}

		// Storage credentials are not in here — see Snapshot::EXCLUDED_OPTIONS.
		// The operator enters them on the new site, which is also the moment
		// they get to rotate a key that has been sitting on a host they are
		// leaving.
		return new WP_REST_Response( SnapshotStore::capture() );
	}

	/**
	 * Pull the library from the old site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pull( WP_REST_Request $request ) {
		$source = trim( (string) $request->get_param( 'source_url' ) );
		$code   = (string) $request->get_param( 'code' );

		// The same SSRF rules as the media proxy: an administrator naming a
		// host is still not a reason to let WordPress probe the private
		// network it sits in.
		$check = UrlGuard::check( $source );
		if ( ! $check->allowed() ) {
			return new WP_Error(
				'animeh_source_rejected',
				$this->url_reason( (string) $check->reason ),
				array( 'status' => 400 )
			);
		}

		$endpoint = $this->export_endpoint( $source );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => array( 'content-type' => 'application/json' ),
				'body'        => wp_json_encode( array( 'code' => $code ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'animeh_source_unreachable',
				sprintf(
					/* translators: %s: error message from the HTTP request. */
					__( 'Eski siteye ulaşılamadı: %s', 'animeh' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$decoded = json_decode( $body, true );
			$message = is_array( $decoded ) && isset( $decoded['message'] )
				? (string) $decoded['message']
				: __( 'Eski site aktarımı reddetti.', 'animeh' );

			return new WP_Error(
				'animeh_handoff_refused',
				$message,
				array( 'status' => 403 === $status ? 403 : 502 )
			);
		}

		$envelope = json_decode( $body, true );
		if ( ! is_array( $envelope ) ) {
			return new WP_Error(
				'animeh_handoff_unreadable',
				__( 'Eski siteden gelen yanıt okunamadı.', 'animeh' ),
				array( 'status' => 502 )
			);
		}

		return $this->apply( $envelope );
	}

	/**
	 * What the bucket currently says the backend is.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function read_pointer() {
		$pointer = SnapshotStore::read_pointer();
		if ( $pointer instanceof WP_Error ) {
			return $pointer;
		}

		$mine = isset( $pointer['site_url'] ) && untrailingslashit( (string) $pointer['site_url'] ) === untrailingslashit( home_url() );

		return new WP_REST_Response(
			array(
				'pointer' => $pointer,
				'is_self' => $mine,
			)
		);
	}

	/**
	 * Declare this site the backend the app should use.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function claim_pointer() {
		$settings = StorageSettings::load();
		if ( ! $settings->is_configured() ) {
			return new WP_Error(
				'animeh_storage_unconfigured',
				__( 'Önce bucket bilgilerini gir.', 'animeh' ),
				array( 'status' => 409 )
			);
		}

		$written = SnapshotStore::write_pointer( $settings );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		return new WP_REST_Response( array( 'claimed' => true, 'site_url' => home_url() ) );
	}

	/**
	 * Validate, restore, and take over as the app's backend.
	 *
	 * @param array<string, mixed> $envelope Envelope from a bucket snapshot or a handoff.
	 * @return WP_REST_Response|WP_Error
	 */
	private function apply( array $envelope ) {
		$summary = Snapshot::summarise( $envelope );
		if ( ! $summary['valid'] ) {
			return new WP_Error(
				'animeh_snapshot_invalid',
				$this->snapshot_reason( $summary['problems'] ),
				array(
					'status'   => 422,
					'problems' => $summary['problems'],
				)
			);
		}

		$written = SnapshotStore::restore( $envelope );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		// Only now, with the data actually in place, does this site claim the
		// pointer. Claiming first would send the app to a site that cannot yet
		// answer for the library.
		$settings = StorageSettings::load();
		$pointer  = $settings->is_configured() ? SnapshotStore::write_pointer( $settings ) : null;

		return new WP_REST_Response(
			array(
				'restored'        => $written,
				'origin'          => $summary['origin'],
				'created_at'      => $summary['created_at'],
				'pointer_updated' => true === $pointer,
			)
		);
	}

	/**
	 * The outstanding code's state, without revealing the code.
	 *
	 * @return array<string, mixed>
	 */
	private function handoff_state(): array {
		$stored = get_option( self::HANDOFF_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return array( 'open' => false );
		}

		$issued    = (int) ( $stored['issued_at'] ?? 0 );
		$remaining = MigrationCode::remaining( $issued );
		$used      = ! empty( $stored['used_at'] );

		return array(
			'open'      => $remaining > 0 && ! $used,
			'used'      => $used,
			'issued_at' => $issued,
			'expires_in' => $remaining,
		);
	}

	/**
	 * Key material for hashing codes.
	 */
	private function handoff_secret(): string {
		$material = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'NONCE_SALT' ) ? (string) NONCE_SALT : '' );

		return '' === $material ? 'animeh-handoff-fallback' : $material;
	}

	/**
	 * Turn a site address into its export endpoint.
	 *
	 * Accepts what an operator would actually paste — the site's home page, its
	 * admin URL, or the REST base — rather than demanding an exact path.
	 *
	 * @param string $source Address as entered.
	 */
	private function export_endpoint( string $source ): string {
		$trimmed = untrailingslashit( trim( $source ) );

		if ( str_ends_with( $trimmed, '/migration/export' ) ) {
			return $trimmed;
		}

		if ( str_ends_with( $trimmed, '/' . FontsController::NAMESPACE ) ) {
			return $trimmed . '/migration/export';
		}

		// Strip the parts of an address someone copies out of the browser bar
		// while sitting in wp-admin.
		$trimmed = (string) preg_replace( '#/wp-admin(/.*)?$#', '', $trimmed );

		return $trimmed . '/wp-json/' . FontsController::NAMESPACE . '/migration/export';
	}

	/**
	 * Readable text for a rejected address.
	 *
	 * @param string $reason Machine reason from UrlGuard.
	 */
	private function url_reason( string $reason ): string {
		$map = array(
			'malformed_url'      => __( 'Adres okunamadı.', 'animeh' ),
			'unsupported_scheme' => __( 'Yalnızca http ve https adresleri kullanılabilir.', 'animeh' ),
			'credentials_in_url' => __( 'Adres kullanıcı adı veya parola içeremez.', 'animeh' ),
			'unresolvable_host'  => __( 'Adres çözümlenemedi.', 'animeh' ),
			'private_address'    => __( 'Özel ağ adresleri kullanılamaz.', 'animeh' ),
			'host_not_allowed'   => __( 'Bu alan adı izin listesinde değil.', 'animeh' ),
		);

		return $map[ $reason ] ?? __( 'Adres reddedildi.', 'animeh' );
	}

	/**
	 * Readable text for an unusable snapshot.
	 *
	 * @param string[] $problems Machine reasons from Snapshot.
	 */
	private function snapshot_reason( array $problems ): string {
		if ( in_array( 'format_too_new', $problems, true ) ) {
			return __( 'Bu yedek daha yeni bir eklenti sürümüyle alınmış; önce eklentiyi güncelle.', 'animeh' );
		}
		if ( in_array( 'checksum_mismatch', $problems, true ) ) {
			return __( 'Yedek bozulmuş görünüyor: içerik özeti tutmuyor.', 'animeh' );
		}

		return __( 'Yedek geçerli değil.', 'animeh' );
	}
}
