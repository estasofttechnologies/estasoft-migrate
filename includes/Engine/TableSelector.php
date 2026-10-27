<?php
/**
 * Chooses which tables a job works on.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\Database;
use Estasoft\Migrate\Db\Identifier;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Site tables only: names that start with the table prefix, minus the plugin's own tables.
 */
final class TableSelector {

	/**
	 * Tables of this site.
	 *
	 * @param Database $db     Database.
	 * @param array    $params tables (list, optional), exclude_tables (list, optional).
	 * @return array<int,array{name:string, logical:string, rows_est:int, bytes:int}>
	 */
	public static function select( Database $db, array $params = array() ) {
		$prefix   = $db->prefix();
		$statuses = $db->table_statuses( $prefix );

		$wanted  = isset( $params['tables'] ) && is_array( $params['tables'] ) && $params['tables'] ? array_flip( $params['tables'] ) : null;
		$exclude = array_flip( (array) ( $params['exclude_tables'] ?? array() ) );
		$own     = $prefix . 'estasoft_';

		$list = array();
		foreach ( $statuses as $name => $info ) {
			if ( 0 === strpos( $name, $own ) || ! Identifier::is_safe( $name ) ) {
				continue;
			}
			if ( null !== $wanted && ! isset( $wanted[ $name ] ) ) {
				continue;
			}
			if ( isset( $exclude[ $name ] ) ) {
				continue;
			}
			$list[] = array(
				'name'     => $name,
				'logical'  => substr( $name, strlen( $prefix ) ),
				'rows_est' => (int) $info['rows'],
				'bytes'    => (int) $info['data_bytes'] + (int) $info['index_bytes'],
			);
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the tables a job works on.
			 *
			 * @param array $list   Tables (name, logical, rows_est, bytes).
			 * @param array $params Job parameters.
			 */
			$list = apply_filters( 'estasoft_migrate_tables', $list, $params );
		}
		return array_values( $list );
	}
}
