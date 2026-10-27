<?php
/**
 * MySQL / MariaDB driver on top of the WordPress connection.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Db;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Uses the connection WordPress already opened. Bulk reads and writes go straight to mysqli,
 * which skips wpdb's per-query bookkeeping (SAVEQUERIES, ASCII checks) that slows down and
 * bloats memory during large transfers. Without mysqli the driver falls back to wpdb.
 */
class WpdbDatabase extends AbstractDatabase {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Cached max_allowed_packet.
	 *
	 * @var int|null
	 */
	private $packet = null;

	/**
	 * Cached server details.
	 *
	 * @var array|null
	 */
	private $info = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $wpdb Database object, defaults to the global.
	 */
	public function __construct( $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Has mysqli's exception mode been switched off?
	 *
	 * @var bool
	 */
	private static $quiet = false;

	/**
	 * The raw mysqli link, if there is one.
	 *
	 * @return \mysqli|null
	 */
	private function link() {
		$dbh = $this->wpdb->dbh;
		return $dbh instanceof \mysqli ? $dbh : null;
	}

	/**
	 * Run a statement.
	 *
	 * @param string $sql SQL.
	 * @return int
	 * @throws DatabaseException On failure.
	 */
	protected function run( $sql ) {
		$link = $this->link();
		if ( $link ) {
			$ok = $this->try_query( $link, $sql );
			if ( false === $ok ) {
				$link = $this->reconnect();
				$ok   = $link ? $this->try_query( $link, $sql ) : false;
			}
			if ( false === $ok ) {
				throw $this->error_from( $link ? $link : $this->link() );
			}
			return (int) $link->affected_rows;
		}

		$this->wpdb->suppress_errors( true );
		$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The engine builds this statement from quoted identifiers and escaped values (Identifier, quote_for()).
		$this->wpdb->suppress_errors( false );
		if ( false === $result ) {
			throw new DatabaseException( (string) $this->wpdb->last_error );
		}
		return (int) $result;
	}

	/**
	 * Run a SELECT.
	 *
	 * @param string $sql SQL.
	 * @return array[]
	 * @throws DatabaseException On failure.
	 */
	protected function rows( $sql ) {
		$link = $this->link();
		if ( $link ) {
			$result = $this->try_query( $link, $sql );
			if ( false === $result ) {
				$link   = $this->reconnect();
				$result = $link ? $this->try_query( $link, $sql ) : false;
			}
			if ( false === $result ) {
				throw $this->error_from( $link ? $link : $this->link() );
			}
			if ( true === $result ) {
				return array();
			}
			$rows = $result->fetch_all( MYSQLI_ASSOC );
			$result->free();
			return $rows;
		}

		$this->wpdb->suppress_errors( true );
		$rows = $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Same as query(): quoted identifiers and escaped values only.
		$this->wpdb->suppress_errors( false );
		if ( null === $rows && '' !== (string) $this->wpdb->last_error ) {
			throw new DatabaseException( (string) $this->wpdb->last_error );
		}
		$this->wpdb->flush();
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a query without throwing PHP exceptions from mysqli.
	 *
	 * @param \mysqli $link mysqli link.
	 * @param string  $sql  SQL.
	 * @return \mysqli_result|bool
	 */
	private function try_query( \mysqli $link, $sql ) {
		if ( ! self::$quiet ) {
			// WordPress itself switches mysqli's exceptions off when it connects; this makes sure of it once, because every result is checked here.
			mysqli_report( MYSQLI_REPORT_OFF ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report
			self::$quiet = true;
		}
		return @$link->query( $sql ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Re-open a lost connection.
	 *
	 * @return \mysqli|null
	 */
	private function reconnect() {
		if ( $this->wpdb->check_connection( false ) ) {
			return $this->link();
		}
		return null;
	}

	/**
	 * Build an exception from the connection's last error.
	 *
	 * @param \mysqli|null $link Link.
	 * @return DatabaseException
	 */
	private function error_from( $link ) {
		if ( $link instanceof \mysqli ) {
			return new DatabaseException( $link->error, $link->errno );
		}
		return new DatabaseException( 'The database connection was lost.', 2006 );
	}

	/**
	 * Escape text for a single-quoted literal.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function escape( $text ) {
		$link = $this->link();
		if ( $link ) {
			return $link->real_escape_string( $text );
		}
		return $this->wpdb->remove_placeholder_escape( esc_sql( $text ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int
	 */
	public function insert_id() {
		$link = $this->link();
		return $link ? (int) $link->insert_id : (int) $this->wpdb->insert_id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function prefix() {
		return $this->wpdb->prefix;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string[]
	 */
	public function list_tables() {
		$rows   = $this->rows( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
		$result = array();
		foreach ( $rows as $row ) {
			$result[] = (string) reset( $row );
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public function table_exists( $table ) {
		$count = $this->get_var(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			array( $table )
		);
		return (int) $count > 0;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $prefix Name prefix.
	 * @return array
	 */
	public function table_statuses( $prefix = '' ) {
		$rows   = $this->rows(
			'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, ENGINE AS engine, TABLE_COLLATION AS collation
			FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME'
		);
		$result = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			if ( '' !== $prefix && 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$result[ $name ] = array(
				'rows'        => (int) $row['row_count'],
				'data_bytes'  => (int) $row['data_bytes'],
				'index_bytes' => (int) $row['index_bytes'],
				'engine'      => (string) $row['engine'],
				'collation'   => (string) $row['collation'],
			);
		}
		return $result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	public function primary_key( $table ) {
		$rows = $this->rows( 'SHOW KEYS FROM ' . Identifier::quote( $table ) . " WHERE Key_name = 'PRIMARY'" );
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
			}
		);
		return array_map(
			static function ( $row ) {
				return (string) $row['Column_name'];
			},
			$rows
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	public function columns( $table ) {
		if ( isset( $this->columns_cache[ $table ] ) ) {
			return $this->columns_cache[ $table ];
		}
		$result = array();
		foreach ( $this->rows( 'SHOW FULL COLUMNS FROM ' . Identifier::quote( $table ) ) as $row ) {
			$type                    = (string) $row['Type'];
			$result[ $row['Field'] ] = array(
				'type'    => $type,
				'binary'  => self::is_binary_type( $type ),
				'numeric' => self::is_numeric_type( $type ),
			);
		}
		$this->columns_cache[ $table ] = $result;
		return $result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	public function show_create( $table ) {
		$rows = $this->rows( 'SHOW CREATE TABLE ' . Identifier::quote( $table ) );
		if ( ! $rows ) {
			throw new DatabaseException( 'Table not found: ' . $table );
		}
		$row = $rows[0];
		return (string) ( $row['Create Table'] ?? '' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string,string> $pairs Old name => new name.
	 * @return void
	 */
	public function rename_tables( array $pairs ) {
		if ( ! $pairs ) {
			return;
		}
		$parts = array();
		foreach ( $pairs as $from => $to ) {
			$parts[] = Identifier::quote( $from ) . ' TO ' . Identifier::quote( $to );
		}
		$this->run( 'RENAME TABLE ' . implode( ', ', $parts ) );
		$this->forget();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int
	 */
	public function max_allowed_packet() {
		if ( null === $this->packet ) {
			$value        = $this->get_var( 'SELECT @@max_allowed_packet' );
			$this->packet = $value ? (int) $value : 4 * 1024 * 1024;
		}
		return $this->packet;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array
	 */
	public function server_info() {
		if ( null === $this->info ) {
			$version = (string) $this->get_var( 'SELECT VERSION()' );
			$charset = (string) $this->get_var( 'SELECT @@character_set_database' );
			$collate = (string) $this->get_var(
				'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				array( $this->prefix() . 'posts' )
			);
			if ( '' === $collate ) {
				$collate = (string) $this->get_var( 'SELECT @@collation_database' );
			}
			$this->info = array(
				'type'      => false !== stripos( $version, 'mariadb' ) ? 'mariadb' : 'mysql',
				'version'   => preg_replace( '/^(\d+(?:\.\d+){1,2}).*$/', '$1', $version ),
				'charset'   => $charset,
				'collation' => $collate,
			);
		}
		return $this->info;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $collation Collation name.
	 * @return bool
	 */
	public function has_collation( $collation ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $collation ) ) {
			return false;
		}
		return (int) $this->get_var( 'SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = %s', array( $collation ) ) > 0;
	}
}
