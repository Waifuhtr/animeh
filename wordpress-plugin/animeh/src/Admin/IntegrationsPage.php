<?php
/**
 * The Entegrasyonlar screen: metadata keys and the client's address.
 *
 * Server-rendered rather than another JavaScript root, unlike the other admin
 * screens. What lives here is four text fields and a save button, and the
 * value of the panel elsewhere — live results, uploads, progress — buys
 * nothing for a form. It also means these settings stay reachable if the
 * bundled panel assets are ever missing from a deployment, which is exactly
 * the situation in which someone needs to check an API key.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Admin;

use Animeh\Rest\AuthController;
use Animeh\Rest\Permissions;
use Animeh\Storage\FirebaseClient;
use Animeh\Storage\TenraiClient;
use Animeh\Storage\TmdbClient;
use WP_Error;

/**
 * Renders and saves the integration settings.
 */
final class IntegrationsPage {

	/**
	 * Nonce action for the form.
	 */
	private const NONCE = 'animeh_integrations';

	/**
	 * Handle the POST, then draw the screen.
	 */
	public static function render(): void {
		if ( ! Permissions::current_user_can_manage() ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkin yok.', 'animeh' ) );
		}

		$notices  = self::maybe_save();
		$tenrai   = TenraiClient::public_settings();
		$tmdb     = TmdbClient::public_settings();
		$firebase = FirebaseClient::public_settings();
		$base    = (string) get_option( AuthController::PUBLIC_BASE_OPTION, '' );
		$default = trailingslashit( rest_url( \Animeh\Rest\FontsController::NAMESPACE ) );
		?>
		<div class="wrap animeh-admin">
			<h1><?php esc_html_e( 'Entegrasyonlar', 'animeh' ); ?></h1>
			<p class="animeh-admin__lede">
				<?php esc_html_e( 'Metadata sağlayıcılarının anahtarları ve uygulamanın bağlandığı sunucu adresi. Anahtarlar şifrelenerek saklanır ve hiçbir zaman uygulamaya gönderilmez; TMDB ve Tenrai isteklerini her zaman bu sunucu yapar.', 'animeh' ); ?>
			</p>

			<?php foreach ( $notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2><?php esc_html_e( 'TMDB', 'animeh' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Afişler, arka plan görselleri, bölüm kareleri ve Türkçe özetler için. Anahtarı themoviedb.org hesabının Ayarlar → API bölümünden alırsın; hem v3 API anahtarı hem v4 okuma jetonu çalışır.', 'animeh' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="animeh-tmdb-key"><?php esc_html_e( 'API anahtarı', 'animeh' ); ?></label></th>
						<td>
							<input type="password" id="animeh-tmdb-key" name="tmdb_key" class="regular-text"
								autocomplete="off" placeholder="<?php echo esc_attr( $tmdb['has_key'] ? (string) $tmdb['key_masked'] : '' ); ?>">
							<p class="description">
								<?php echo $tmdb['has_key']
									? esc_html__( 'Kayıtlı. Değiştirmek için yenisini yaz, dokunmamak için boş bırak.', 'animeh' )
									: esc_html__( 'Henüz girilmedi.', 'animeh' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="animeh-tmdb-language"><?php esc_html_e( 'Dil', 'animeh' ); ?></label></th>
						<td>
							<input type="text" id="animeh-tmdb-language" name="tmdb_language" class="regular-text"
								value="<?php echo esc_attr( (string) $tmdb['language'] ); ?>">
							<p class="description"><?php esc_html_e( 'Özetlerin ve bölüm adlarının hangi dilde isteneceği. Örnek: tr-TR', 'animeh' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Durum', 'animeh' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tmdb_enabled" value="1" <?php checked( (bool) $tmdb['enabled'] ); ?>>
								<?php esc_html_e( 'TMDB entegrasyonu açık', 'animeh' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Tenrai', 'animeh' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Anime meta verisi: sezonlar, bölüm numaraları, dolgu bölümler.', 'animeh' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="animeh-tenrai-base"><?php esc_html_e( 'API adresi', 'animeh' ); ?></label></th>
						<td>
							<input type="url" id="animeh-tenrai-base" name="tenrai_base" class="regular-text"
								value="<?php echo esc_attr( (string) $tenrai['base'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="animeh-tenrai-key"><?php esc_html_e( 'API anahtarı', 'animeh' ); ?></label></th>
						<td>
							<input type="password" id="animeh-tenrai-key" name="tenrai_key" class="regular-text"
								autocomplete="off" placeholder="<?php echo esc_attr( $tenrai['has_key'] ? (string) $tenrai['key_masked'] : '' ); ?>">
							<p class="description">
								<?php echo $tenrai['has_key']
									? esc_html__( 'Kayıtlı. Değiştirmek için yenisini yaz, dokunmamak için boş bırak.', 'animeh' )
									: esc_html__( 'Gerekmiyorsa boş bırakabilirsin.', 'animeh' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Durum', 'animeh' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tenrai_enabled" value="1" <?php checked( (bool) $tenrai['enabled'] ); ?>>
								<?php esc_html_e( 'Tenrai entegrasyonu açık', 'animeh' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Firebase (birlikte izleme ve bildirimler)', 'animeh' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Birlikte izleme odalarının anlık verisi ve bildirimler Firebase üzerinden geçer. Alttaki beş alanı Firebase konsolundaki Android uygulamanın ayarlarından alırsın; servis hesabı JSON dosyası ise Proje ayarları → Servis hesapları bölümünden indirilir ve yalnızca bildirim göndermek için kullanılır, hiçbir zaman uygulamaya gönderilmez.', 'animeh' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="animeh-fb-database"><?php esc_html_e( 'Realtime Database adresi', 'animeh' ); ?></label></th>
						<td>
							<input type="url" id="animeh-fb-database" name="firebase_database_url" class="large-text"
								value="<?php echo esc_attr( (string) $firebase['database_url'] ); ?>"
								placeholder="https://proje-adi-default-rtdb.europe-west1.firebasedatabase.app">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="animeh-fb-project"><?php esc_html_e( 'Proje kimliği', 'animeh' ); ?></label></th>
						<td>
							<input type="text" id="animeh-fb-project" name="firebase_project_id" class="regular-text"
								value="<?php echo esc_attr( (string) $firebase['project_id'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="animeh-fb-api"><?php esc_html_e( 'Web API anahtarı', 'animeh' ); ?></label></th>
						<td>
							<input type="text" id="animeh-fb-api" name="firebase_api_key" class="regular-text"
								value="<?php echo esc_attr( (string) $firebase['api_key'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="animeh-fb-app"><?php esc_html_e( 'Uygulama kimliği', 'animeh' ); ?></label></th>
						<td>
							<input type="text" id="animeh-fb-app" name="firebase_app_id" class="regular-text"
								value="<?php echo esc_attr( (string) $firebase['app_id'] ); ?>"
								placeholder="1:123456789:android:abc123">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="animeh-fb-sender"><?php esc_html_e( 'Gönderen kimliği', 'animeh' ); ?></label></th>
						<td>
							<input type="text" id="animeh-fb-sender" name="firebase_sender_id" class="regular-text"
								value="<?php echo esc_attr( (string) $firebase['sender_id'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="animeh-fb-account"><?php esc_html_e( 'Servis hesabı JSON', 'animeh' ); ?></label></th>
						<td>
							<textarea id="animeh-fb-account" name="firebase_service_account" class="large-text code" rows="4"
								placeholder="<?php echo esc_attr( $firebase['has_account'] ? (string) $firebase['account_email'] : '{ &quot;type&quot;: &quot;service_account&quot;, … }' ); ?>"></textarea>
							<p class="description">
								<?php echo $firebase['has_account']
									? esc_html__( 'Kayıtlı. Değiştirmek için yeni dosyanın tamamını yapıştır, dokunmamak için boş bırak.', 'animeh' )
									: esc_html__( 'Bildirim göndermek için gerekli. Boş bırakırsan odalar çalışır ama davet bildirimi gitmez.', 'animeh' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Durum', 'animeh' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="firebase_enabled" value="1" <?php checked( (bool) $firebase['enabled'] ); ?>>
								<?php esc_html_e( 'Birlikte izleme açık', 'animeh' ); ?>
							</label>
							<p class="description">
								<?php echo $firebase['ready']
									? esc_html__( 'Bildirimler gönderilebilir durumda.', 'animeh' )
									: esc_html__( 'Bildirimler için servis hesabı gerekiyor.', 'animeh' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Uygulama sunucusu', 'animeh' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Uygulamanın bağlanacağı adres. Boş bırakırsan bu sitenin kendi adresi kullanılır. Siteyi taşırken buraya yeni adresi yazarsan, uygulamalar bir sonraki açılışta kendiliğinden oraya geçer — bu yüzden yeni adresi eski site hâlâ ayaktayken yazman gerekir.', 'animeh' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="animeh-api-base"><?php esc_html_e( 'Sunucu adresi', 'animeh' ); ?></label></th>
						<td>
							<input type="url" id="animeh-api-base" name="api_base" class="large-text"
								value="<?php echo esc_attr( $base ); ?>" placeholder="<?php echo esc_attr( $default ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: the site's own REST address. */
									esc_html__( 'Şu an kullanılan: %s', 'animeh' ),
									'<code>' . esc_html( AuthController::public_base() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Kayıt', 'animeh' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="registration_open" value="1"
									<?php checked( (bool) get_option( AuthController::REGISTRATION_OPTION, true ) ); ?>>
								<?php esc_html_e( 'Uygulamadan yeni hesap açılabilsin', 'animeh' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Kaydet', 'animeh' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Apply a submitted form.
	 *
	 * @return array<int, array{type: string, text: string}> Notices to draw.
	 */
	private static function maybe_save(): array {
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return array();
		}

		if ( ! check_admin_referer( self::NONCE ) ) {
			// check_admin_referer already dies on failure; this is here so a
			// reader does not have to know that to see the path is guarded.
			return array();
		}

		$notices = array();

		TmdbClient::save_settings(
			array(
				// Empty keeps whatever is stored, so the masked placeholder in
				// the field is not a way to wipe a working key by saving.
				'key'      => isset( $_POST['tmdb_key'] ) ? trim( (string) wp_unslash( $_POST['tmdb_key'] ) ) : '',
				'language' => isset( $_POST['tmdb_language'] ) ? sanitize_text_field( wp_unslash( $_POST['tmdb_language'] ) ) : '',
				'enabled'  => isset( $_POST['tmdb_enabled'] ),
			)
		);

		TenraiClient::save_settings(
			array(
				'base'    => isset( $_POST['tenrai_base'] ) ? esc_url_raw( wp_unslash( $_POST['tenrai_base'] ) ) : '',
				'key'     => isset( $_POST['tenrai_key'] ) ? trim( (string) wp_unslash( $_POST['tenrai_key'] ) ) : '',
				'enabled' => isset( $_POST['tenrai_enabled'] ),
			)
		);

		$firebase = FirebaseClient::save_settings(
			array(
				'database_url'    => isset( $_POST['firebase_database_url'] ) ? esc_url_raw( wp_unslash( $_POST['firebase_database_url'] ) ) : '',
				'project_id'      => isset( $_POST['firebase_project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['firebase_project_id'] ) ) : '',
				'api_key'         => isset( $_POST['firebase_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['firebase_api_key'] ) ) : '',
				'app_id'          => isset( $_POST['firebase_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['firebase_app_id'] ) ) : '',
				'sender_id'       => isset( $_POST['firebase_sender_id'] ) ? sanitize_text_field( wp_unslash( $_POST['firebase_sender_id'] ) ) : '',
				// Deliberately not sanitised: it is a JSON document with a PEM
				// in it, and every sanitiser WordPress has would corrupt one.
				// It is validated by being parsed, and stored encrypted.
				'service_account' => isset( $_POST['firebase_service_account'] ) ? trim( (string) wp_unslash( $_POST['firebase_service_account'] ) ) : '',
				'enabled'         => isset( $_POST['firebase_enabled'] ),
			)
		);

		if ( $firebase instanceof WP_Error ) {
			$notices[] = array( 'type' => 'error', 'text' => $firebase->get_error_message() );
		}

		update_option( AuthController::REGISTRATION_OPTION, isset( $_POST['registration_open'] ) ? 1 : 0, true );

		$base   = isset( $_POST['api_base'] ) ? trim( (string) wp_unslash( $_POST['api_base'] ) ) : '';
		$stored = AuthController::set_public_base( $base );

		if ( $stored instanceof WP_Error ) {
			$notices[] = array( 'type' => 'error', 'text' => $stored->get_error_message() );
		} else {
			$notices[] = array( 'type' => 'success', 'text' => __( 'Ayarlar kaydedildi.', 'animeh' ) );
		}

		return $notices;
	}
}
