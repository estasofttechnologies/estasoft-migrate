<?php
/**
 * Finds the user accounts that stay unchanged.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

use Estasoft\Migrate\Db\Database;
use Estasoft\Migrate\Db\Identifier;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the "keep unchanged" list (user logins, emails or ids) and, optionally, all administrators.
 */
final class KeepResolver {

	/**
	 * Resolve user ids.
	 *
	 * @param Database $db     Source database.
	 * @param string   $prefix Source table prefix.
	 * @param array    $config From Profiles::build_config().
	 * @return int[]
	 */
	public static function resolve( Database $db, $prefix, array $config ) {
		$ids   = array();
		$users = $prefix . 'users';
		$meta  = $prefix . 'usermeta';

		if ( ! empty( $config['keep_admins'] ) && $db->table_exists( $meta ) ) {
			$rows = $db->get_results(
				'SELECT user_id FROM ' . Identifier::quote( $meta ) . ' WHERE meta_key = %s AND meta_value LIKE %s',
				array( $prefix . 'capabilities', '%"administrator"%' )
			);
			foreach ( $rows as $row ) {
				$ids[ (int) $row['user_id'] ] = true;
			}
		}

		if ( ! empty( $config['keep_users'] ) && $db->table_exists( $users ) ) {
			foreach ( (array) $config['keep_users'] as $who ) {
				$who  = (string) $who;
				$rows = $db->get_results(
					'SELECT ID FROM ' . Identifier::quote( $users ) . ' WHERE user_login = %s OR user_email = %s OR ID = %d',
					array( $who, $who, ctype_digit( $who ) ? (int) $who : 0 )
				);
				foreach ( $rows as $row ) {
					$ids[ (int) $row['ID'] ] = true;
				}
			}
		}
		return array_keys( $ids );
	}
}
