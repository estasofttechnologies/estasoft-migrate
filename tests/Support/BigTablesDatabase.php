<?php
/**
 * A test database that reports large tables.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Support;

/**
 * Pretends each table holds a lot of data, so size checks can be tested.
 */
class BigTablesDatabase extends PdoDatabase {

	/**
	 * Fake size of every table in bytes.
	 *
	 * @var int
	 */
	private $fake;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Prefix.
	 * @param string $dsn    DSN.
	 * @param int    $fake   Fake table size in bytes.
	 */
	public function __construct( $prefix, $dsn, $fake ) {
		parent::__construct( $prefix, $dsn );
		$this->fake = (int) $fake;
	}

	public function table_statuses( $prefix = '' ) {
		$out = parent::table_statuses( $prefix );
		foreach ( $out as $name => $info ) {
			$out[ $name ]['data_bytes'] = $this->fake;
			$out[ $name ]['rows']       = 1000000;
		}
		return $out;
	}
}
