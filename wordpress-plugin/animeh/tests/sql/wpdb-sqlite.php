<?php
/**
 * A `$wpdb` backed by a real SQL engine.
 *
 * The smoke runner hands back fixture rows whatever the query says, and it
 * says so in its own header: it proves the code runs and nothing about the
 * SQL. That gap hid a live bug — a tag page whose header said "2 video" over
 * an empty grid, because the count query and the listing query disagreed
 * about what a tag page contains. No check here could see it, because no
 * check here had ever executed either one.
 *
 * So this runs them. SQLite rather than MySQL, because MySQL is not reachable
 * from where this is written and the queries under test use nothing outside
 * plain SQL — no ON DUPLICATE KEY, no date arithmetic, no MySQL functions.
 * What it proves is that the joins, the predicates and the paging mean what
 * they are meant to mean; what it cannot prove is anything MySQL-specific,
 * and nothing here should grow to depend on such a thing.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

/**
 * The subset of `$wpdb` the plugin's storage layer uses, over PDO.
 */
class SqliteWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';

	/** Every statement that reached the engine, for query-count checks. */
	public array $queries = array();

	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * WordPress's placeholder substitution, close enough to the real one.
	 *
	 * `%s` quoted, `%d` cast to int, `%f` to float — the three the storage
	 * layer uses. A count mismatch throws rather than producing broken SQL,
	 * which is the failure the smoke runner's version was written to catch
	 * and is worth keeping here too.
	 *
	 * @param string $sql  Statement with placeholders.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$sql = (string) $sql;

		$wanted = preg_match_all( '/%[dsfF]/', $sql );
		if ( $wanted !== count( $args ) ) {
			throw new RuntimeException(
				"prepare(): {$wanted} placeholders but " . count( $args ) . ' arguments'
			);
		}

		$index = 0;

		return (string) preg_replace_callback(
			'/%[dsfF]/',
			function ( array $match ) use ( &$index, $args ) {
				$value = $args[ $index++ ];

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}

				if ( '%f' === strtolower( $match[0] ) ) {
					return (string) (float) $value;
				}

				return $this->quote( (string) $value );
			},
			$sql
		);
	}

	public function get_results( $sql, $out = null ): array {
		$rows = $this->rows( (string) $sql );

		return $rows;
	}

	public function get_row( $sql, $out = null ) {
		return $this->rows( (string) $sql )[0] ?? null;
	}

	public function get_var( $sql = null ) {
		$row = $this->rows( (string) $sql )[0] ?? null;

		return null === $row ? null : reset( $row );
	}

	public function get_col( $sql = null ): array {
		$sql = (string) $sql;

		// `reconcile()` asks MySQL what columns a table has; SQLite answers
		// the same question under another name.
		if ( 1 === preg_match( '/^\s*SHOW COLUMNS FROM\s+(\S+)/i', $sql, $table ) ) {
			$columns = $this->rows( 'PRAGMA table_info(' . $table[1] . ')' );

			return array_map( static fn( array $row ): string => (string) $row['name'], $columns );
		}

		return array_map( static fn( array $row ) => reset( $row ), $this->rows( $sql ) );
	}

	public function query( $sql ) {
		$sql = (string) $sql;
		$this->queries[] = $sql;

		try {
			return $this->pdo->exec( $this->translate( $sql ) );
		} catch ( PDOException $error ) {
			$this->last_error = $error->getMessage();

			return false;
		}
	}

	public function insert( $table, $data, $format = null ) {
		$columns = array_keys( $data );
		$values  = array_map( array( $this, 'literal' ), array_values( $data ) );

		$sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' .
			implode( ', ', $values ) . ')';

		$this->queries[] = $sql;

		try {
			$this->pdo->exec( $sql );
		} catch ( PDOException $error ) {
			$this->last_error = $error->getMessage();

			return false;
		}

		$this->last_error = '';
		$this->insert_id  = (int) $this->pdo->lastInsertId();

		return 1;
	}

	public function update( $table, $data, $where, ...$rest ) {
		$sets = array();
		foreach ( $data as $column => $value ) {
			$sets[] = $column . ' = ' . $this->literal( $value );
		}

		$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . $this->conditions( $where );

		$this->queries[] = $sql;

		return $this->pdo->exec( $sql );
	}

	public function delete( $table, $where, $format = null ) {
		$sql = 'DELETE FROM ' . $table . ' WHERE ' . $this->conditions( (array) $where );

		$this->queries[] = $sql;

		return $this->pdo->exec( $sql );
	}

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/** Run whatever the plugin's installer collected from dbDelta(). */
	public function create( string $statement ): void {
		$this->pdo->exec( $this->ddl( $statement ) );
	}

	/* ── Internals ───────────────────────────────────────────────────── */

	private function rows( string $sql ): array {
		$this->queries[] = $sql;

		$statement = $this->pdo->query( $this->translate( $sql ) );

		return $statement->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * The few spellings MySQL and SQLite disagree about in these queries.
	 *
	 * Deliberately tiny. A translation layer that grows is a translation
	 * layer that starts testing itself instead of the plugin.
	 */
	private function translate( string $sql ): string {
		return str_replace( '`', '"', $sql );
	}

	private function quote( string $value ): string {
		return $this->pdo->quote( $value );
	}

	private function literal( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return $this->quote( (string) $value );
	}

	private function conditions( array $where ): string {
		$parts = array();

		foreach ( $where as $column => $value ) {
			$parts[] = $column . ' = ' . $this->literal( $value );
		}

		return array() === $parts ? '1 = 1' : implode( ' AND ', $parts );
	}

	/**
	 * A MySQL CREATE TABLE as SQLite will take it.
	 *
	 * The table under test is the plugin's own statement, unedited — that is
	 * the point, since a column nobody created is exactly the kind of thing
	 * this is meant to catch. Only the dialect is adjusted: the auto-increment
	 * spelling, the unsigned/size decorations SQLite has no use for, and the
	 * inline KEY lines, which become real indexes so uniqueness still bites.
	 */
	private function ddl( string $statement ): string {
		$statement = trim( $statement );
		$statement = (string) preg_replace( '/\)\s*[^()]*;?\s*$/', ')', $statement );

		if ( 1 !== preg_match( '/CREATE TABLE\s+(\S+)\s*\((.*)\)\s*$/ms', $statement, $parts ) ) {
			throw new RuntimeException( 'anlaşılmayan CREATE TABLE:' . "\n" . $statement );
		}

		$table   = $parts[1];
		$columns = array();
		$indexes = array();

		foreach ( explode( "\n", $parts[2] ) as $line ) {
			$line = trim( $line, " \t\r\n," );
			if ( '' === $line ) {
				continue;
			}

			if ( 1 === preg_match( '/^PRIMARY KEY\s+\(?\s*\(?(.+?)\)?\s*\)?$/i', $line, $primary ) ) {
				// Handled on the column itself below; a second declaration
				// would collide with INTEGER PRIMARY KEY.
				continue;
			}

			if ( 1 === preg_match( '/^(UNIQUE\s+)?KEY\s+(\w+)\s*\((.+)\)$/i', $line, $key ) ) {
				$indexes[] = 'CREATE ' . ( '' !== trim( $key[1] ) ? 'UNIQUE ' : '' ) .
					'INDEX ' . $table . '_' . $key[2] . ' ON ' . $table . ' (' . $key[3] . ')';
				continue;
			}

			if ( str_contains( strtoupper( $line ), 'AUTO_INCREMENT' ) ) {
				$name      = strtok( $line, ' ' );
				$columns[] = $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
				continue;
			}

			$columns[] = preg_replace(
				array( '/\b(big|small|tiny|medium)?int\(\d+\)/i', '/\bunsigned\b/i' ),
				array( 'INTEGER', '' ),
				$line
			);
		}

		return 'CREATE TABLE ' . $table . " (\n  " . implode( ",\n  ", $columns ) . "\n);\n" .
			implode( ";\n", $indexes ) . ( array() === $indexes ? '' : ';' );
	}
}
