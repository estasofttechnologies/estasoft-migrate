<?php
/**
 * Remembers one-time values so a captured request cannot be sent again.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

interface Nonces {

	/**
	 * Use a value. Returns false when it was used before.
	 *
	 * @param string $nonce   Value.
	 * @param int    $seconds How long to remember it.
	 * @return bool
	 */
	public function use_once( $nonce, $seconds );

	/**
	 * Count a failed attempt from an address and tell whether the address is now blocked.
	 *
	 * @param string $ip Address.
	 * @return bool True when blocked.
	 */
	public function failure( $ip );

	/**
	 * Is the address blocked?
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public function blocked( $ip );
}
