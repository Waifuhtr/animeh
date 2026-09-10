<?php
/**
 * Plugin Name:       Animeh Manga Köprüsü
 * Plugin URI:        https://github.com/Waifuhtr/animeh
 * Description:       Manga Core ile kurulmuş bir siteyi Animeh uygulamasına açar. Mangaları, bölümleri ve sayfa görsellerinin tam adreslerini anahtar korumalı bir REST ucundan verir; Animeh eklentisi buradan okuyup kendi katalogunu doldurur ve görselleri kendi kovasına kopyalar.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Animeh
 * Text Domain:       animeh-bridge
 *
 * Bu eklenti MANGA SİTESİNE kurulur, Animeh sitesine değil.
 *
 * Tek yönlüdür ve hiçbir şey yazmaz: yalnızca okur ve JSON döndürür. Sitenin
 * kendi çalışmasına dokunmaz — ne bir CPT kaydeder, ne bir hook değiştirir,
 * ne de var olan veriyi düzenler.
 *
 * @package AnimehBridge
 */

declare( strict_types = 1 );

namespace Animeh\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION       = '1.0.0';
const NAMESPACE_URI = 'animeh-bridge/v1';
const KEY_OPTION    = 'animeh_bridge_key';
const HEADER        = 'X-Animeh-Bridge-Key';

/**
 * The key this site answers to.
 *
 * Not called `key()`: PHP already has one of those, and a namespaced function
 * with a built-in's name is resolved differently depending on where it is
 * called from, which is exactly the kind of thing nobody wants to think about
 * while reading an authorisation check.
 *
 * Made once, on the first look, and never shown anywhere but the settings
 * screen. Without one the endpoints refuse everything — a site that has just
 * installed this is not accidentally open while nobody is looking.
 */
function bridge_key(): string {
	$key = (string) get_option( KEY_OPTION, '' );

	if ( '' === $key ) {
		$key = wp_generate_password( 48, false, false );
		update_option( KEY_OPTION, $key, false );
	}

	return $key;
}

/**
 * Whether the caller presented the key.
 *
 * Compared with `hash_equals`, which takes the same time whether the first
 * character is wrong or the last one is. A plain `===` on a secret leaks its
 * length and its prefix to anyone patient enough to measure.
 */
function authorised( \WP_REST_Request $request ): bool {
	$given = (string) $request->get_header( HEADER );

	if ( '' === $given ) {
		$given = (string) $request->get_param( 'key' );
	}

	return '' !== $given && hash_equals( key(), $given );
}

/**
 * `permission_callback` for every route here.
 *
 * @return true|\WP_Error
 */
function guard( \WP_REST_Request $request ) {
	if ( authorised( $request ) ) {
		return true;
	}

	return new \WP_Error(
		'animeh_bridge_forbidden',
		__( 'Köprü anahtarı geçersiz.', 'animeh-bridge' ),
		array( 'status' => 401 )
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			NAMESPACE_URI,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\ping',
				'permission_callback' => __NAMESPACE__ . '\\guard',
			)
		);

		register_rest_route(
			NAMESPACE_URI,
			'/manga',
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\manga_list',
				'permission_callback' => __NAMESPACE__ . '\\guard',
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
					'modified_after' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			NAMESPACE_URI,
			'/manga/(?P<id>\d+)/chapters',
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\chapter_list',
				'permission_callback' => __NAMESPACE__ . '\\guard',
				'args'                => array(
					'id'       => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}
);

/**
 * A handshake, so the other end can say "connected" before it starts.
 */
function ping(): \WP_REST_Response {
	return new \WP_REST_Response(
		array(
			'ok'       => true,
			'version'  => VERSION,
			'site'     => get_bloginfo( 'name' ),
			'home'     => home_url(),
			'manga'    => (int) wp_count_posts( 'manga' )->publish,
			'chapters' => (int) wp_count_posts( 'chapter' )->publish,
			'storage'  => array(
				'b2_bucket'      => (string) get_option( 'b2_bucket_name', '' ),
				'b2_download'    => (string) get_option( 'b2_download_url', '' ),
				'b2_cdn'         => (string) get_option( 'b2_cdn_url', '' ),
				'b2_s3_endpoint' => (string) get_option( 'b2_s3_endpoint', '' ),
				'bunny'          => (string) get_option( 'bunny_pull_zone', '' ),
			),
		)
	);
}

/**
 * A page of manga, with every meta field the app can use.
 */
function manga_list( \WP_REST_Request $request ): \WP_REST_Response {
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );

	$args = array(
		'post_type'      => 'manga',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		// Oldest first, so a sync that runs in pages sees a stable order even
		// while new manga are being added at the other end.
		'orderby'        => 'ID',
		'order'          => 'ASC',
	);

	$after = (string) $request->get_param( 'modified_after' );
	if ( '' !== $after ) {
		$args['date_query'] = array(
			array(
				'column' => 'post_modified_gmt',
				'after'  => $after,
			),
		);
	}

	$query = new \WP_Query( $args );
	$items = array();

	foreach ( $query->posts as $post ) {
		$items[] = manga_payload( $post );
	}

	return new \WP_REST_Response(
		array(
			'items' => $items,
			'page'  => $page,
			'pages' => (int) $query->max_num_pages,
			'total' => (int) $query->found_posts,
		)
	);
}

/**
 * One manga, flattened.
 *
 * @param \WP_Post $post Manga.
 * @return array<string, mixed>
 */
function manga_payload( \WP_Post $post ): array {
	$characters = get_post_meta( $post->ID, 'manga_characters', true );

	return array(
		'id'            => $post->ID,
		'slug'          => $post->post_name,
		'title'         => $post->post_title,
		'synopsis'      => wp_strip_all_tags( $post->post_content ),
		'permalink'     => get_permalink( $post ),
		'cover'         => (string) get_the_post_thumbnail_url( $post, 'full' ),
		'modified_gmt'  => $post->post_modified_gmt,
		'created_gmt'   => $post->post_date_gmt,
		'meta'          => array(
			'alternative_titles' => (string) get_post_meta( $post->ID, 'manga_alternative_titles', true ),
			'year'               => (int) get_post_meta( $post->ID, 'manga_year', true ),
			'score'              => (float) get_post_meta( $post->ID, 'manga_score', true ),
			'mal_id'             => (int) get_post_meta( $post->ID, 'manga_jikan_id', true ),
			'author'             => (string) get_post_meta( $post->ID, 'manga_author', true ),
			'nsfw'               => '1' === (string) get_post_meta( $post->ID, 'manga_is_nsfw', true ),
		),
		'characters'    => is_array( $characters ) ? array_values( $characters ) : array(),
		'taxonomies'    => array(
			'genre'     => term_names( $post->ID, 'genre' ),
			'status'    => term_names( $post->ID, 'manga_status' ),
			'artist'    => term_names( $post->ID, 'manga_artist' ),
			'parody'    => term_names( $post->ID, 'manga_parody' ),
			'group'     => term_names( $post->ID, 'manga_group' ),
			'language'  => term_names( $post->ID, 'manga_language' ),
			'category'  => term_names( $post->ID, 'manga_category' ),
			'tag'       => term_names( $post->ID, 'manga_tag' ),
			'character' => term_names( $post->ID, 'manga_character_tax' ),
		),
		'chapter_count' => chapter_count( $post->ID ),
	);
}

/**
 * A page of one manga's chapters, each with its resolved image addresses.
 */
function chapter_list( \WP_REST_Request $request ): \WP_REST_Response {
	$manga_id = (int) $request->get_param( 'id' );
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );

	$query = new \WP_Query(
		array(
			'post_type'      => 'chapter',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value_num',
			'meta_key'       => 'chapter_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => 'chapter_manga_id',
					'value' => $manga_id,
				),
			),
		)
	);

	$items = array();
	foreach ( $query->posts as $post ) {
		$items[] = chapter_payload( $post );
	}

	return new \WP_REST_Response(
		array(
			'items' => $items,
			'page'  => $page,
			'pages' => (int) $query->max_num_pages,
			'total' => (int) $query->found_posts,
		)
	);
}

/**
 * One chapter, with every page's absolute URL.
 *
 * This is the whole reason the bridge exists. The reader theme works out where
 * a chapter's images live at render time, from three settings and two
 * compatibility flags; doing that on the other end would mean copying that
 * logic and keeping the copy correct forever. Doing it here means the app is
 * handed addresses that already work.
 *
 * @param \WP_Post $post Chapter.
 * @return array<string, mixed>
 */
function chapter_payload( \WP_Post $post ): array {
	$path     = trim( (string) get_post_meta( $post->ID, 'chapter_path', true ), '/' );
	$provider = storage_provider( $post->ID );
	$files    = get_post_meta( $post->ID, 'chapter_image_list', true );
	$files    = is_array( $files ) ? array_values( $files ) : array();

	return array(
		'id'           => $post->ID,
		'manga_id'     => (int) get_post_meta( $post->ID, 'chapter_manga_id', true ),
		'number'       => (string) get_post_meta( $post->ID, 'chapter_number', true ),
		'title'        => $post->post_title,
		'permalink'    => get_permalink( $post ),
		'created_gmt'  => $post->post_date_gmt,
		'modified_gmt' => $post->post_modified_gmt,
		'storage'      => $provider,
		'path'         => $path,
		'pages'        => page_urls( $provider, $path, $files ),
	);
}

/**
 * Where a chapter's images are, honouring the old flags.
 *
 * `is_b2_hosted` and `is_bunny_hosted` predate `storage_provider` and still
 * win where they are set, exactly as the reader does it.
 *
 * @param int $chapter_id Chapter.
 * @return string
 */
function storage_provider( int $chapter_id ): string {
	$provider = (string) get_post_meta( $chapter_id, 'storage_provider', true );

	if ( '1' === (string) get_post_meta( $chapter_id, 'is_b2_hosted', true ) ) {
		return 'b2';
	}

	if ( '1' === (string) get_post_meta( $chapter_id, 'is_bunny_hosted', true ) && 'b2' !== $provider ) {
		return 'bunny';
	}

	return '' !== $provider ? $provider : 'local';
}

/**
 * Every page of a chapter, as an address and its alternate.
 *
 * B2 answers the same object at a friendly URL and an S3 one, and the friendly
 * one has a habit of failing by itself. Both are sent so the reader can move
 * to the other rather than showing a hole.
 *
 * @param string   $provider Storage.
 * @param string   $path     Chapter directory, relative to the storage root.
 * @param string[] $files    Image filenames, in order.
 * @return array<int, array<string, mixed>>
 */
function page_urls( string $provider, string $path, array $files ): array {
	if ( '' === $path ) {
		return array();
	}

	if ( array() === $files && 'local' === $provider ) {
		$files = local_files( $path );
	}

	natcasesort( $files );
	$files = array_values( $files );

	$base      = '';
	$alternate = '';

	if ( 'b2' === $provider ) {
		$base = rtrim( (string) get_option( 'b2_cdn_url', '' ), '/' );

		if ( '' === $base ) {
			$download = rtrim( (string) get_option( 'b2_download_url', '' ), '/' );
			$bucket   = (string) get_option( 'b2_bucket_name', '' );
			if ( '' !== $download && '' !== $bucket ) {
				$base = $download . '/file/' . $bucket;
			}
		}

		$endpoint = trim( (string) get_option( 'b2_s3_endpoint', '' ) );
		$bucket   = (string) get_option( 'b2_bucket_name', '' );
		if ( '' !== $endpoint && '' !== $bucket ) {
			$alternate = 'https://' . $bucket . '.' . preg_replace( '#^https?://#i', '', $endpoint );
		}
	} elseif ( 'bunny' === $provider ) {
		$base = rtrim( (string) get_option( 'bunny_pull_zone', '' ), '/' );
	}

	if ( '' === $base ) {
		$uploads = wp_get_upload_dir();
		$base    = rtrim( $uploads['baseurl'], '/' );
	}

	$pages = array();
	foreach ( $files as $index => $file ) {
		$file = trim( (string) $file );
		if ( '' === $file ) {
			continue;
		}

		$relative = $path . '/' . rawurlencode( $file );

		$pages[] = array(
			'position' => $index + 1,
			'file'     => $file,
			'url'      => $base . '/' . $relative,
			// Empty unless the S3 endpoint is configured, in which case it is
			// the same object addressed the other way.
			'fallback' => '' !== $alternate ? $alternate . '/' . $relative : '',
		);
	}

	return $pages;
}

/**
 * The image files sitting in a local chapter directory.
 *
 * Only reached when a chapter has no stored list, which is how the very first
 * chapters were saved.
 *
 * @param string $path Relative directory.
 * @return string[]
 */
function local_files( string $path ): array {
	$uploads = wp_get_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . $path;

	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$found = array();
	foreach ( (array) scandir( $dir ) as $file ) {
		if ( is_string( $file ) && 1 === preg_match( '/\.(jpe?g|png|webp|gif|avif)$/i', $file ) ) {
			$found[] = $file;
		}
	}

	return $found;
}

/**
 * How many chapters a manga has.
 *
 * @param int $manga_id Manga.
 * @return int
 */
function chapter_count( int $manga_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = 'chapter_manga_id' AND pm.meta_value = %d
			   AND p.post_type = 'chapter' AND p.post_status = 'publish'",
			$manga_id
		)
	);
}

/**
 * Term names for one taxonomy, or an empty list.
 *
 * @param int    $post_id  Post.
 * @param string $taxonomy Taxonomy.
 * @return string[]
 */
function term_names( int $post_id, string $taxonomy ): array {
	$terms = get_the_terms( $post_id, $taxonomy );

	if ( ! is_array( $terms ) ) {
		return array();
	}

	return array_values( array_map( static fn( $term ): string => (string) $term->name, $terms ) );
}

/* ── Settings screen ─────────────────────────────────────────────────── */

add_action(
	'admin_menu',
	static function (): void {
		add_options_page(
			__( 'Animeh Köprüsü', 'animeh-bridge' ),
			__( 'Animeh Köprüsü', 'animeh-bridge' ),
			'manage_options',
			'animeh-bridge',
			__NAMESPACE__ . '\\settings_page'
		);
	}
);

/**
 * One screen: the address and the key, ready to copy.
 */
function settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['animeh_bridge_regenerate'] ) && check_admin_referer( 'animeh_bridge' ) ) {
		delete_option( KEY_OPTION );
		echo '<div class="updated"><p>' . esc_html__( 'Yeni anahtar üretildi. Animeh yönetim panelinde de güncelle.', 'animeh-bridge' ) . '</p></div>';
	}

	$base = rest_url( NAMESPACE_URI );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Animeh Köprüsü', 'animeh-bridge' ); ?></h1>

		<p><?php esc_html_e( 'Bu iki değeri Animeh uygulamasındaki Yönetim Paneli → Manga → Köprü ekranına yapıştır. Uygulama buradan mangaları, bölümleri ve sayfa görsellerini okuyup kendi kovasına kopyalar.', 'animeh-bridge' ); ?></p>

		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Köprü adresi', 'animeh-bridge' ); ?></th>
				<td><input type="text" readonly value="<?php echo esc_attr( $base ); ?>" style="width:100%;max-width:640px" onclick="this.select()"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Anahtar', 'animeh-bridge' ); ?></th>
				<td>
					<input type="text" readonly value="<?php echo esc_attr( bridge_key() ); ?>" style="width:100%;max-width:640px" onclick="this.select()">
					<p class="description"><?php esc_html_e( 'Bu anahtarı bilen herkes mangaların ve bölümlerin listesini okuyabilir. Hiçbir şey yazamaz, silemez.', 'animeh-bridge' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'İçerik', 'animeh-bridge' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: 1: manga count, 2: chapter count */
						esc_html__( '%1$d manga, %2$d bölüm yayında.', 'animeh-bridge' ),
						(int) wp_count_posts( 'manga' )->publish,
						(int) wp_count_posts( 'chapter' )->publish
					);
					?>
				</td>
			</tr>
		</table>

		<form method="post">
			<?php wp_nonce_field( 'animeh_bridge' ); ?>
			<p>
				<button type="submit" name="animeh_bridge_regenerate" value="1" class="button">
					<?php esc_html_e( 'Anahtarı yenile', 'animeh-bridge' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'Eski anahtar anında geçersiz olur.', 'animeh-bridge' ); ?></span>
			</p>
		</form>
	</div>
	<?php
}
