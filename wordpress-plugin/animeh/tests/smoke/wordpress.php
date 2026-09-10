<?php
/**
 * Just enough WordPress to run the plugin's read paths.
 *
 * Written after a live 500 that every check here had passed: `php -l` parses
 * and the unit tests exercise `Support/`, but neither of them ever *ran* an
 * endpoint. The bug was a `use` statement that an edit had silently failed to
 * add, so a class reference resolved into the wrong namespace and fatalled —
 * on the first request that had a row to format, and never on an empty one.
 *
 * So this stubs the forty-odd WordPress functions the read path touches and a
 * `$wpdb` that hands back fixture rows, then calls the endpoints. It proves
 * the code runs; it proves nothing about SQL, and is not meant to.
 */
declare( strict_types = 1 );

// --- WordPress constants ------------------------------------------------
define( 'ABSPATH', sys_get_temp_dir() . '/animeh-smoke-wp/' );

// The installer does what WordPress asks and requires wp-admin's upgrade.php
// before calling dbDelta(). There is no wp-admin here and dbDelta is defined
// below, so an empty file is enough to let that require succeed.
if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
	mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n" );
}
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'AUTH_KEY', 'x' );
define( 'SECURE_AUTH_SALT', 'y' );
define( 'ANIMEH_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

// --- The handful of WordPress functions the read path calls -------------
function __( $t, $d = '' ) { return $t; }
function _e( $t, $d = '' ) { echo $t; }
function esc_html__( $t, $d = '' ) { return $t; }
function esc_url_raw( $u ) { return $u; }
function esc_attr( $v ) { return $v; }
function esc_html( $v ) { return $v; }
function esc_js( $v ) { return $v; }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; }
function sanitize_key( $v ) { return strtolower( (string) $v ); }
function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $v ) ); }
function sanitize_file_name( $v ) { return (string) $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }
function get_user_meta( $u, $k, $s = false ) { return ''; }
function update_user_meta( $u, $k, $v ) { return true; }
function delete_user_meta( $u, $k ) { return true; }
function delete_metadata( ...$a ) { return true; }
function wp_generate_password( $l = 12, ...$rest ) { return str_repeat( 'a', $l ); }
function wp_upload_dir() { return array( 'basedir' => '/tmp/uploads', 'baseurl' => 'https://site/uploads' ); }
function wp_get_upload_dir() { return wp_upload_dir(); }
function wp_mkdir_p( $d ) { return true; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( (array) $args ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://site' . $p; }
function rest_url( $p = '' ) { return 'https://site/wp-json/' . $p; }
function get_bloginfo( $k ) { return 'site'; }
function wp_remote_get( $url, $args = array() ) { return animeh_http( (string) $url, $args ); }
function wp_remote_post( $url, $args = array() ) { return animeh_http( (string) $url, $args ); }
function wp_remote_head( $url, $args = array() ) { return animeh_http( (string) $url, $args ); }
function wp_remote_request( $url, $args = array() ) { return animeh_http( (string) $url, $args ); }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_header( $r, $k ) { return is_array( $r ) ? (string) ( $r['headers'][ strtolower( (string) $k ) ] ?? '' ) : ''; }
function wp_remote_retrieve_headers( $r ) { return is_array( $r ) ? (array) ( $r['headers'] ?? array() ) : array(); }
/**
 * dbDelta, recorded rather than run.
 *
 * The real one is in wp-admin and needs a database. What matters here is what
 * it is *handed*: it splits its input on `;` and reads the field list with one
 * regex, so a statement can be well-formed SQL and still be half-invisible to
 * it. The checks read these back.
 */
function dbDelta( $queries = '', $execute = true ) {
	$GLOBALS['__delta'][] = (string) $queries;
	return array();
}

/**
 * The columns dbDelta would actually see in a statement.
 *
 * A faithful copy of its parsing, and only its parsing: split on `;`, take the
 * first fragment, grab everything between the outermost parentheses, then read
 * the first token of each line.
 *
 * @param string $statement CREATE TABLE statement.
 * @return array<int, string>
 */
function animeh_delta_columns( string $statement ): array {
	$first = explode( ';', $statement )[0];

	if ( 1 !== preg_match( '|\((.*)\)|ms', $first, $body ) ) {
		return array();
	}

	$columns = array();

	foreach ( explode( "\n", $body[1] ) as $line ) {
		$line = trim( $line, " \t\n\r\0\x0B," );

		preg_match( '|^([^ ]*)|', $line, $field );
		$name = strtolower( trim( $field[1], '`' ) );

		if ( in_array( $name, array( '', 'primary', 'index', 'fulltext', 'unique', 'key', 'spatial' ), true ) ) {
			continue;
		}

		$columns[] = $name;
	}

	return $columns;
}

function is_wp_error( $t ) { return $t instanceof WP_Error; }

/**
 * Outbound HTTP, answered from a fixture table.
 *
 * The importer and both metadata sources spend their whole lives inside
 * `wp_remote_get`, so with it stubbed to a flat failure the only line of
 * theirs that ever ran was the one that gives up. A test registers the
 * replies it wants by URL substring and the rest of the path runs for real.
 *
 * @param string $needle Substring of the URL this answers.
 * @param int    $code   HTTP status.
 * @param mixed  $body   String body, or anything JSON-encodable.
 */
function animeh_http_reply( string $needle, int $code, $body ): void {
	$GLOBALS['__http'][ $needle ] = array(
		'response' => array( 'code' => $code ),
		'headers'  => array(),
		'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
	);
}

/** Forget every registered reply, and the log of what was asked for. */
function animeh_http_reset(): void {
	$GLOBALS['__http']     = array();
	$GLOBALS['__http_log'] = array();
$GLOBALS['__delta'] = array();
}

/** Every URL asked for since the last reset. */
function animeh_http_log(): array {
	return $GLOBALS['__http_log'] ?? array();
}

/**
 * Answer one request.
 *
 * An unregistered URL is a transport error rather than a 404: that is what a
 * host with no route to it actually produces, and it keeps a test from
 * passing on a request it never meant to allow.
 *
 * @param string $url  Address.
 * @param mixed  $args Request arguments.
 * @return array<string, mixed>|WP_Error
 */
function animeh_http( string $url, $args = array() ) {
	$GLOBALS['__http_log'][] = $url;

	foreach ( $GLOBALS['__http'] as $needle => $reply ) {
		if ( str_contains( $url, (string) $needle ) ) {
			return $reply;
		}
	}

	return new WP_Error( 'http_request_failed', 'harness: fixture yok — ' . $url );
}
function current_user_can( $c ) { return false; }
function user_can( $u, $c ) { return false; }
function get_userdata( $id ) { return false; }
function get_role( $r ) { return null; }
function add_role( ...$a ) { return null; }
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function do_action( ...$a ) { return null; }
function apply_filters( $t, $v, ...$rest ) { return $v; }
function register_rest_route( ...$a ) { $GLOBALS['__routes'][] = $a[1] ?? ''; return true; }
function wp_count_posts( $t ) { return (object) array( 'publish' => 0 ); }
function get_post_meta( ...$a ) { return ''; }
function get_the_terms( ...$a ) { return false; }
function get_permalink( ...$a ) { return ''; }
function get_the_post_thumbnail_url( ...$a ) { return ''; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_kses_post( $s ) { return $s; }
function checked( ...$a ) { return ''; }
function selected( ...$a ) { return ''; }
function wp_nonce_field( ...$a ) { return ''; }
function check_admin_referer( ...$a ) { return true; }
function add_options_page( ...$a ) { return ''; }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = array() ) {}
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public array $headers = array();
	public function __construct( public $data = null, public int $status = 200 ) {}
	public function get_data() { return $this->data; }
	public function header( $k, $v, $replace = true ) { $this->headers[ $k ] = $v; }
	public function set_status( $s ) { $this->status = (int) $s; }
}
class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_file_params() { return array(); }
	public function get_header( $k ) { return ''; }
}
class WP_REST_Server {
	const READABLE = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE = 'PUT';
	const DELETABLE = 'DELETE';
}
class WP_User { public $ID = 0; public $roles = array(); }
class WP_Post {}
class WP_Query { public $posts = array(); public $max_num_pages = 0; public $found_posts = 0;
	public function __construct( $a = array() ) {} }

/**
 * A `$wpdb` that answers every read with nothing.
 *
 * Enough to prove the code runs: a fatal is a fatal whether the table had
 * rows in it or not, and an empty catalogue is exactly the state a fresh
 * install is in.
 */
class FakeWpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';
	public array $queries = array();

	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		// Close enough for a smoke test: the point is that prepare() is
		// reached with the right number of arguments, which is where a
		// mismatched placeholder count would blow up in production.
		$placeholders = preg_match_all( '/%[dsfF]/', (string) $sql );
		if ( $placeholders !== count( $args ) ) {
			throw new RuntimeException(
				"prepare(): {$placeholders} placeholders but " . count( $args ) . " arguments\n" .
				substr( preg_replace( '/\s+/', ' ', (string) $sql ), 0, 240 )
			);
		}
		$this->queries[] = $sql;
		return (string) $sql;
	}
	/** Rows to hand back, so the payload builders actually run. */
	public array $rows = array();

	public function get_results( $sql, $out = null ) {
		$this->queries[] = $sql;
		return $this->rows_for( (string) $sql );
	}
	public function get_row( $sql, $out = null ) {
		$this->queries[] = $sql;
		$rows = $this->rows_for( (string) $sql );
		return $rows[0] ?? null;
	}

	/** Whichever fixture the query is asking for. */
	private function rows_for( string $sql ): array {
		if ( str_contains( $sql, 'FROM wp_animeh_works' ) || str_contains( $sql, 'wp_animeh_works w' ) ) {
			// A works query unless an episode join says otherwise.
			if ( ! str_contains( $sql, 'wp_animeh_episodes' ) ) {
				return $this->rows['works'] ?? array();
			}
		}
		if ( str_contains( $sql, 'wp_animeh_episodes' ) ) {
			return $this->rows['episodes'] ?? array();
		}
		if ( str_contains( $sql, 'wp_animeh_history' ) ) {
			return $this->rows['history'] ?? array();
		}
		return array();
	}
	public function get_var( $sql = null ) { $this->queries[] = $sql; return null; }
	public function get_col( $sql = null ) { $this->queries[] = $sql; return array(); }
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
	/** Writes are recorded and handed an id: the importer branches on it. */
	public array $writes = array();

	/** Set to a message to make every write fail, as a real one can. */
	public string $fail_insert = '';

	public function insert( $table, $data, $format = null ) {
		if ( '' !== $this->fail_insert ) {
			$this->last_error = $this->fail_insert;
			return false;
		}

		$this->last_error = '';
		$this->writes[]   = array( 'insert', $table, $data );
		$this->insert_id  = ++$this->next_id;
		return 1;
	}
	private int $next_id = 100;
	public function update( $table, $data, $where, ...$rest ) {
		$this->writes[] = array( 'update', $table, $data );
		return 1;
	}
	public function delete( ...$a ) { return 1; }
	public function suppress_errors( $s = true ) { return false; }
	public function esc_like( $t ) { return $t; }
}

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['__options'] = array();
$GLOBALS['__routes'] = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__http'] = array();
$GLOBALS['__http_log'] = array();

// --- Autoload the plugin ------------------------------------------------
$root = dirname( __DIR__, 2 ) . '/src/';
spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		$prefix = 'Animeh\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$path = $root . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
