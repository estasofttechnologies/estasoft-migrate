<?php
/**
 * SQLite driver used by the unit tests, so the engine can run without a MySQL server.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Support;

use Estasoft\Migrate\Db\AbstractDatabase;
use Estasoft\Migrate\Db\DatabaseException;
use Estasoft\Migrate\Db\Identifier;

/**
 * Minimal Database implementation on PDO + SQLite.
 */
class PdoDatabase extends AbstractDatabase {

	/**
	 * Connection.
	 *
	 * @var \PDO
	 */
	public $pdo;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Table prefix.
	 * @param string $dsn    PDO DSN, in-memory by default.
	 */
	public function __construct( $prefix = 'wp_', $dsn = 'sqlite::memory:' ) {
		$this->prefix = $prefix;
		$this->pdo    = new \PDO( $dsn );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
	}

	protected function insert_verb( $mode ) {
		switch ( $mode ) {
			case 'ignore':
				return 'INSERT OR IGNORE INTO';
			case 'insert':
				return 'INSERT INTO';
			default:
				return 'REPLACE INTO';
		}
	}

	protected function run( $sql ) {
		try {
			return (int) $this->pdo->exec( $sql );
		} catch ( \PDOException $e ) {
			throw new DatabaseException( $e->getMessage() );
		}
	}

	protected function rows( $sql ) {
		try {
			$stmt = $this->pdo->query( $sql );
			$out  = array();
			foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
				foreach ( $row as $k => $v ) {
					$row[ $k ] = null === $v ? null : (string) $v;
				}
				$out[] = $row;
			}
			return $out;
		} catch ( \PDOException $e ) {
			throw new DatabaseException( $e->getMessage() );
		}
	}

	protected function escape( $text ) {
		return str_replace( "'", "''", $text );
	}

	public function begin() {
		$this->pdo->exec( 'BEGIN' );
	}

	public function commit() {
		$this->pdo->exec( 'COMMIT' );
	}

	public function rollback() {
		$this->pdo->exec( 'ROLLBACK' );
	}

	public function insert_id() {
		return (int) $this->pdo->lastInsertId();
	}

	public function prefix() {
		return $this->prefix;
	}

	public function list_tables() {
		$out = array();
		foreach ( $this->rows( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name" ) as $r ) {
			$out[] = $r['name'];
		}
		return $out;
	}

	public function table_exists( $table ) {
		return in_array( $table, $this->list_tables(), true );
	}

	public function table_statuses( $prefix = '' ) {
		$out = array();
		foreach ( $this->list_tables() as $t ) {
			if ( '' !== $prefix && 0 !== strpos( $t, $prefix ) ) {
				continue;
			}
			$out[ $t ] = array(
				'rows'        => $this->count_rows( $t ),
				'data_bytes'  => 0,
				'index_bytes' => 0,
				'engine'      => 'sqlite',
				'collation'   => '',
			);
		}
		return $out;
	}

	public function primary_key( $table ) {
		$cols = array();
		foreach ( $this->rows( 'PRAGMA table_info(' . Identifier::quote( $table ) . ')' ) as $r ) {
			if ( (int) $r['pk'] > 0 ) {
				$cols[ (int) $r['pk'] ] = $r['name'];
			}
		}
		ksort( $cols );
		return array_values( $cols );
	}

	public function columns( $table ) {
		if ( isset( $this->columns_cache[ $table ] ) ) {
			return $this->columns_cache[ $table ];
		}
		$out = array();
		foreach ( $this->rows( 'PRAGMA table_info(' . Identifier::quote( $table ) . ')' ) as $r ) {
			$out[ $r['name'] ] = array(
				'type'    => $r['type'],
				'binary'  => self::is_binary_type( $r['type'] ),
				'numeric' => self::is_numeric_type( $r['type'] ),
			);
		}
		$this->columns_cache[ $table ] = $out;
		return $out;
	}

	public function show_create( $table ) {
		$sql = $this->get_var( "SELECT sql FROM sqlite_master WHERE type='table' AND name = %s", array( $table ) );
		if ( null === $sql ) {
			throw new DatabaseException( 'Table not found: ' . $table );
		}
		return $sql;
	}

	public function rename_tables( array $pairs ) {
		$this->pdo->beginTransaction();
		try {
			foreach ( $pairs as $from => $to ) {
				$this->pdo->exec( 'ALTER TABLE ' . Identifier::quote( $from ) . ' RENAME TO ' . Identifier::quote( $to ) );
			}
			$this->pdo->commit();
		} catch ( \PDOException $e ) {
			$this->pdo->rollBack();
			throw new DatabaseException( $e->getMessage() );
		}
		$this->forget();
	}

	public function max_allowed_packet() {
		return 4 * 1024 * 1024;
	}

	public function server_info() {
		return array(
			'type'      => 'sqlite',
			'version'   => (string) $this->get_var( 'select sqlite_version()' ),
			'charset'   => 'utf8mb4',
			'collation' => 'utf8mb4_unicode_ci',
		);
	}

	public function has_collation( $collation ) {
		return true;
	}
}
