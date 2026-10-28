<?php
/**
 * Nonce memory in WordPress transients.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Used request numbers are kept as rows of the options table, written with one INSERT IGNORE. The table has a unique
 * index on the name, so of two requests that carry the same number at the same moment exactly one is let in, with or
 * without an object cache. The names start with the prefix of the plugin's own options, which an import keeps and a
 * package never carries, so a number used during an import is still remembered afterwards.
 *
 * Failure counters are transients: losing or miscounting one only changes how soon an address is slowed down.
 */
class WpNonces implements Nonces {

	const MAX_FAILURES = 20;
	const WINDOW       = 600;

	/**
	 * Start of the option names.
	 */
	const PREFIX = 'estasoft_migrate_n_';

	/**
	 * Use a value once.
	 *
	 * @param string $nonce   Value.
	 * @param int    $seconds Lifetime.
	 * @return bool False when the value was used before, or when it could not be written (a request that cannot be remembered is refused).
	 */
	public function use_once( $nonce, $seconds ) {
		global $wpdb;

		$now = time();
		if ( 1 === wp_rand( 1, 20 ) ) {
			$this->purge( $now );
		}
		$name  = self::PREFIX . substr( hash( 'sha256', $nonce ), 0, 32 );
		$until = $now + max( 60, (int) $seconds );
		$added = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic insert is the point; nothing is read back through the cache.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name.
				$name,
				(string) $until
			)
		);
		return 1 === (int) $added;
	}

	/**
	 * Forget the numbers that can no longer be replayed.
	 *
	 * @param int $now Current time.
	 * @return void
	 */
	public function purge( $now ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Housekeeping of this plugin's own rows.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name.
				$wpdb->esc_like( self::PREFIX ) . '%',
				(int) $now
			)
		);
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
