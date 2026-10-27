<?php
/**
 * Shared SQL building for database drivers.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Db;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Builds the SELECT, INSERT and UPDATE statements; drivers only run SQL and describe tables.
 */
abstract class AbstractDatabase implements Database {

	/**
	 * Cached column details per table.
	 *
	 * @var array
	 */
	protected $columns_cache = array();

	/**
	 * Run a statement and return the number of affected rows.
	 *
	 * @param string $sql SQL.
	 * @return int
	 * @throws DatabaseException On failure.
	 */
	abstract protected function run( $sql );

	/**
	 * Run a SELECT and return rows with string-or-null cells.
	 *
	 * @param string $sql SQL.
	 * @return array[]
	 * @throws DatabaseException On failure.
	 */
	abstract protected function rows( $sql );

	/**
	 * Escape text for use inside single quotes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	abstract protected function escape( $text );

	/**
	 * Column type tells whether the data is binary.
	 *
	 * @param string $type Column type as reported by the server.
	 * @return bool
	 */
	protected static function is_binary_type( $type ) {
		return 1 === preg_match( '/^(tiny|medium|long)?blob|^(var)?binary|^bit\b/i', trim( $type ) );
	}

	/**
	 * Column type tells whether the data is a number.
	 *
	 * @param string $type Column type as reported by the server.
	 * @return bool
	 */
	protected static function is_numeric_type( $type ) {
		return 1 === preg_match( '/^(tiny|small|medium|big)?int|^decimal|^numeric|^float|^double|^real|^integer/i', trim( $type ) );
	}

	/**
	 * SQL keyword for an insert mode.
	 *
	 * @param string $mode replace, ignore or insert.
	 * @return string
	 */
	protected function insert_verb( $mode ) {
		switch ( $mode ) {
			case 'ignore':
				return 'INSERT IGNORE INTO';
			case 'insert':
				return 'INSERT INTO';
			default:
				return 'REPLACE INTO';
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function quote( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return "'" . $this->escape( (string) $value ) . "'";
	}

	/**
	 * SQL literal for a value going into a particular column (binary columns use hex literals).
	 *
	 * @param array $column Column details.
	 * @param mixed $value  Value.
	 * @return string
	 */
	protected function quote_for( array $column, $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( ! empty( $column['binary'] ) && is_string( $value ) ) {
			return '' === $value ? "''" : "X'" . bin2hex( $value ) . "'";
		}
		return $this->quote( $value );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string        $table       Table name.
	 * @param string[]      $key_columns Primary key columns.
	 * @param string[]|null $after       Last key values.
	 * @param int           $limit       Max rows.
	 * @param int           $offset      Offset when no key.
	 * @return array[]
	 */
	public function fetch_batch( $table, array $key_columns, $after, $limit, $offset = 0 ) {
		$limit = max( 1, (int) $limit );
		$sql   = 'SELECT * FROM ' . Identifier::quote( $table );

		if ( $key_columns ) {
			$cols = Identifier::quote_list( $key_columns );
			if ( null !== $after ) {
				$values = array();
				foreach ( array_values( $after ) as $i => $v ) {
					$values[] = $this->quote( $v );
				}
				$sql .= count( $key_columns ) > 1
					? " WHERE ({$cols}) > (" . implode( ',', $values ) . ')'
					: " WHERE {$cols} > " . $values[0];
			}
			$sql .= " ORDER BY {$cols} LIMIT {$limit}";
		} else {
			$sql .= " LIMIT {$limit} OFFSET " . max( 0, (int) $offset );
		}

		return $this->rows( $sql );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $table   Table name.
	 * @param string[] $columns Columns.
	 * @param array[]  $rows    Rows.
	 * @param string   $mode    Mode.
	 * @return int
	 */
	public function insert_rows( $table, array $columns, array $rows, $mode = 'replace' ) {
		if ( ! $rows ) {
			return 0;
		}
		$details = $this->columns( $table );
		$specs   = array();
		foreach ( $columns as $name ) {
			$specs[] = $details[ $name ] ?? array(
				'type'    => '',
				'binary'  => false,
				'numeric' => false,
			);
		}
		$head  = $this->insert_verb( $mode ) . ' ' . Identifier::quote( $table ) . ' (' . Identifier::quote_list( $columns ) . ') VALUES ';
		$limit = max( 256 * 1024, min( 16 * 1024 * 1024, (int) ( $this->max_allowed_packet() * 0.8 ) ) );
		$count = count( $columns );

		$written = 0;
		$buffer  = array();
		$size    = strlen( $head );

		foreach ( $rows as $row ) {
			$row = array_values( $row );
			if ( count( $row ) !== $count ) {
				throw new DatabaseException( sprintf( 'Row has %1$d values but %2$d columns were expected for table %3$s.', count( $row ), $count, $table ) );
			}
			$parts = array();
			foreach ( $row as $i => $value ) {
				$parts[] = $this->quote_for( $specs[ $i ], $value );
			}
			$tuple = '(' . implode( ',', $parts ) . ')';
			$len   = strlen( $tuple ) + 1;

			if ( $buffer && $size + $len > $limit ) {
				$written += $this->run( $head . implode( ',', $buffer ) );
				$buffer   = array();
				$size     = strlen( $head );
			}
			$buffer[] = $tuple;
			$size    += $len;
		}
		if ( $buffer ) {
			$written += $this->run( $head . implode( ',', $buffer ) );
		}
		return $written;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table Table name.
	 * @param array  $where Key => value.
	 * @param array  $set   Column => value.
	 * @return int
	 */
	public function update_row( $table, array $where, array $set ) {
		if ( ! $where || ! $set ) {
			return 0;
		}
		$details = $this->columns( $table );
		$assign  = array();
		foreach ( $set as $col => $value ) {
			$spec     = $details[ $col ] ?? array( 'binary' => false );
			$assign[] = Identifier::quote( $col ) . ' = ' . $this->quote_for( $spec, $value );
		}
		$cond = array();
		foreach ( $where as $col => $value ) {
			$spec   = $details[ $col ] ?? array( 'binary' => false );
			$cond[] = Identifier::quote( $col ) . ' = ' . $this->quote_for( $spec, $value );
		}
		return $this->run( 'UPDATE ' . Identifier::quote( $table ) . ' SET ' . implode( ', ', $assign ) . ' WHERE ' . implode( ' AND ', $cond ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return void
	 */
	public function begin() {
		$this->run( 'START TRANSACTION' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return void
	 */
	public function commit() {
		$this->run( 'COMMIT' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return void
	 */
	public function rollback() {
		$this->run( 'ROLLBACK' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $sql SQL.
	 * @return int
	 */
	public function query( $sql ) {
		return $this->run( $sql );
	}

	/**
	 * Replace %s, %d and %% in a query with quoted values.
	 *
	 * @param string $sql    SQL with placeholders.
	 * @param array  $params Values.
	 * @return string
	 */
	protected function interpolate( $sql, array $params ) {
		$params = array_values( $params );
		$i      = 0;
		$out    = preg_replace_callback(
			'/%(%|s|d)/',
			function ( $m ) use ( &$i, $params ) {
				if ( '%' === $m[1] ) {
					return '%';
				}
				$value = $params[ $i++ ] ?? null;
				if ( 'd' === $m[1] ) {
					return (string) (int) $value;
				}
				return $this->quote( null === $value ? null : (string) $value );
			},
			$sql
		);
		return (string) $out;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Values.
	 * @return array[]
	 */
	public function get_results( $sql, array $params = array() ) {
		return $this->rows( $this->interpolate( $sql, $params ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Values.
	 * @return string|null
	 */
	public function get_var( $sql, array $params = array() ) {
		$rows = $this->get_results( $sql, $params );
		if ( ! $rows ) {
			return null;
		}
		$first = reset( $rows );
		$value = reset( $first );
		return null === $value ? null : (string) $value;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	public function count_rows( $table ) {
		return (int) $this->get_var( 'SELECT COUNT(*) FROM ' . Identifier::quote( $table ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table Table name.
	 * @return void
	 */
	public function drop_table( $table ) {
		$this->run( 'DROP TABLE IF EXISTS ' . Identifier::quote( $table ) );
		$this->forget( $table );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string|null $table Table name.
	 * @return void
	 */
	public function forget( $table = null ) {
		if ( null === $table ) {
			$this->columns_cache = array();
		} else {
			unset( $this->columns_cache[ $table ] );
		}
	}
}
