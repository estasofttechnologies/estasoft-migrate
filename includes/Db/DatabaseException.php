<?php
/**
 * Database failure.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Db;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Raised when a query fails. Knows whether trying again later can help.
 */
class DatabaseException extends \RuntimeException {

	/**
	 * Does the message look like a temporary problem (lost connection, deadlock, lock wait)?
	 *
	 * @return bool
	 */
	public function is_transient() {
		$code = (int) $this->getCode();
		if ( in_array( $code, array( 2006, 2013, 2002, 2003, 1205, 1213, 1040 ), true ) ) {
			return true;
		}
		return 1 === preg_match( '/gone away|lost connection|deadlock|lock wait timeout|too many connections|connection refused/i', $this->getMessage() );
	}
}
