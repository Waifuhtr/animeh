<?php
/**
 * Admin screens.
 *
 * The markup is deliberately thin: the panel is driven by the same widgets the
 * player's own harness uses, so the two cannot drift apart. PHP renders the
 * containers and the JavaScript fills them.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Admin;

use Animeh\Rest\Permissions;

/**
 * Registers and renders the plugin's admin pages.
 */
final class MenuPage {

	/**
	 * Slug of the player test screen.
	 */
	public const TEST_SLUG = 'animeh-player-test';

	/**
	 * Slug of the font library screen.
	 */
	public const FONTS_SLUG = 'animeh-fonts';

	/**
	 * Slug of the storage screen.
	 */
	public const STORAGE_SLUG = 'animeh-storage';

	/**
	 * Slug of the backup and migration screen.
	 */
	public const MIGRATION_SLUG = 'animeh-migration';

	/**
	 * Metadata keys and the client's server address.
	 */
	public const INTEGRATIONS_SLUG = 'animeh-integrations';

	/**
	 * Add the menu.
	 */
	public static function register(): void {
		$capability = Permissions::current_user_can_manage() ? Permissions::CAPABILITY : 'manage_options';

		add_menu_page(
			__( 'Animeh', 'animeh' ),
			__( 'Animeh', 'animeh' ),
			$capability,
			self::TEST_SLUG,
			array( self::class, 'render_test_page' ),
			'dashicons-video-alt3',
			58
		);

		add_submenu_page(
			self::TEST_SLUG,
			__( 'Player Test', 'animeh' ),
			__( 'Player Test', 'animeh' ),
			$capability,
			self::TEST_SLUG,
			array( self::class, 'render_test_page' )
		);

		add_submenu_page(
			self::TEST_SLUG,
			__( 'Fontlar', 'animeh' ),
			__( 'Fontlar', 'animeh' ),
			$capability,
			self::FONTS_SLUG,
			array( self::class, 'render_fonts_page' )
		);

		add_submenu_page(
			self::TEST_SLUG,
			__( 'Depolama', 'animeh' ),
			__( 'Depolama', 'animeh' ),
			$capability,
			self::STORAGE_SLUG,
			array( self::class, 'render_storage_page' )
		);

		add_submenu_page(
			self::TEST_SLUG,
			__( 'Entegrasyonlar', 'animeh' ),
			__( 'Entegrasyonlar', 'animeh' ),
			$capability,
			self::INTEGRATIONS_SLUG,
			array( IntegrationsPage::class, 'render' )
		);

		add_submenu_page(
			self::TEST_SLUG,
			__( 'Yedek ve Taşıma', 'animeh' ),
			__( 'Yedek ve Taşıma', 'animeh' ),
			$capability,
			self::MIGRATION_SLUG,
			array( self::class, 'render_migration_page' )
		);
	}

	/**
	 * Whether a screen belongs to this plugin.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function owns_screen( string $hook_suffix ): bool {
		return str_contains( $hook_suffix, self::TEST_SLUG )
			|| str_contains( $hook_suffix, self::FONTS_SLUG )
			|| str_contains( $hook_suffix, self::STORAGE_SLUG )
			|| str_contains( $hook_suffix, self::MIGRATION_SLUG )
			|| str_contains( $hook_suffix, self::INTEGRATIONS_SLUG );
	}

	/**
	 * The player test screen.
	 */
	public static function render_test_page(): void {
		if ( ! Permissions::current_user_can_manage() ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkin yok.', 'animeh' ) );
		}
		?>
		<div class="wrap animeh-admin">
			<h1><?php esc_html_e( 'Player Test', 'animeh' ); ?></h1>
			<p class="animeh-admin__lede">
				<?php esc_html_e( 'Gerçek bir kaynağı oynatıcıyla test et: HLS veya MKV, ASS altyazı, font çözümleme ve zayıf bağlantı davranışı.', 'animeh' ); ?>
			</p>

			<div id="animeh-test-root" class="animeh-admin__root">
				<noscript>
					<p><?php esc_html_e( 'Bu ekran JavaScript gerektirir.', 'animeh' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * The storage screen.
	 */
	public static function render_storage_page(): void {
		if ( ! Permissions::current_user_can_manage() ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkin yok.', 'animeh' ) );
		}
		?>
		<div class="wrap animeh-admin">
			<h1><?php esc_html_e( 'Depolama', 'animeh' ); ?></h1>
			<p class="animeh-admin__lede">
				<?php esc_html_e( 'Backblaze B2 bucket ayarları. Uygulama anahtarı şifrelenerek saklanır ve hiçbir zaman tarayıcıya gönderilmez; videolar WordPress üzerinden geçmeden doğrudan bucket\'a yüklenir.', 'animeh' ); ?>
			</p>

			<div id="animeh-storage-root" class="animeh-admin__root">
				<noscript>
					<p><?php esc_html_e( 'Bu ekran JavaScript gerektirir.', 'animeh' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * The backup and migration screen.
	 */
	public static function render_migration_page(): void {
		if ( ! Permissions::current_user_can_manage() ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkin yok.', 'animeh' ) );
		}
		?>
		<div class="wrap animeh-admin">
			<h1><?php esc_html_e( 'Yedek ve Taşıma', 'animeh' ); ?></h1>
			<p class="animeh-admin__lede">
				<?php esc_html_e( 'Kütüphane verisi bucket içine yedeklenir. Site kaybedilirse eklentiyi yeni bir WordPress\'e kurup aynı bucket bilgileriyle geri yüklersin; site hâlâ ayaktaysa tek kullanımlık kodla doğrudan aktarabilirsin.', 'animeh' ); ?>
			</p>

			<div id="animeh-migration-root" class="animeh-admin__root">
				<noscript>
					<p><?php esc_html_e( 'Bu ekran JavaScript gerektirir.', 'animeh' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * The font library screen.
	 */
	public static function render_fonts_page(): void {
		if ( ! Permissions::current_user_can_manage() ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkin yok.', 'animeh' ) );
		}
		?>
		<div class="wrap animeh-admin">
			<h1><?php esc_html_e( 'Fontlar', 'animeh' ); ?></h1>
			<p class="animeh-admin__lede">
				<?php esc_html_e( 'Altyazıların ihtiyaç duyduğu fontlar. Aile adı dosya adından değil, fontun kendi içinden okunur.', 'animeh' ); ?>
			</p>

			<div id="animeh-fonts-root" class="animeh-admin__root">
				<noscript>
					<p><?php esc_html_e( 'Bu ekran JavaScript gerektirir.', 'animeh' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
	}
}
