<?php
/**
 * Nonce memory in WordPress transients.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Transients live in the options table (or the object cache), so they work on any host.
 * The options table is swapped during an import, so a nonce used during the import request
 * itself is simply forgotten afterwards, which is harmless because the time window is short.
 */
class WpNonces implements Nonces {

	const MAX_FAILURES = 20;
	const WINDOW       = 600;

	/**
	 * Use a value once.
	 *
	 * @param string $nonce   Value.
	 * @param int    $seconds Lifetime.
	 * @return bool
	 */
	public function use_once( $nonce, $seconds ) {
		$name = 'estm_n_' . substr( hash( 'sha256', $nonce ), 0, 32 );
		if ( false !== get_transient( $name ) ) {
			return false;
		}
		set_transient( $name, 1, max( 60, (int) $seconds ) );
		return true;
	}

	/**
	 * Count a failure.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public function failure( $ip ) {
		$name  = 'estm_f_' . substr( hash( 'sha256', $ip ), 0, 32 );
		$count = (int) get_transient( $name ) + 1;
		set_transient( $name, $count, self::WINDOW );
		return $count >= self::MAX_FAILURES;
	}

	/**
	 * Blocked?
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public function blocked( $ip ) {
		return (int) get_transient( 'estm_f_' . substr( hash( 'sha256', $ip ), 0, 32 ) ) >= self::MAX_FAILURES;
	}
}
