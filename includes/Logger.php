<?php
/**
 * Plain-language job log stored in the database.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads log lines. Logging never throws: a broken log must not stop a migration.
 */
class Logger {

	const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/**
	 * Add a line.
	 *
	 * @param string $level   debug, info, warning or error.
	 * @param string $message Text shown in the log.
	 * @param int    $job_id  Job the line belongs to, 0 for none.
	 * @param array  $context Extra details, stored as JSON.
	 * @return void
	 */
	public function log( $level, $message, $job_id = 0, array $context = array() ) {
		global $wpdb;
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}
		try {
			$json = empty( $context ) ? null : wp_json_encode( $context, JSON_PARTIAL_OUTPUT_ON_ERROR );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::table( 'logs' ),
				array(
					'job_id'      => (int) $job_id,
					'level'       => $level,
					'message'     => (string) $message,
					'context'     => $json,
					'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$this->trim_queries();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $e );
		}
	}

	/**
	 * Shortcut for debug lines.
	 *
	 * @param string $message Text.
	 * @param int    $job_id  Job id.
	 * @param array  $context Details.
	 * @return void
	 */
	public function debug( $message, $job_id = 0, array $context = array() ) {
		$this->log( 'debug', $message, $job_id, $context );
	}

	/**
	 * Shortcut for info lines.
	 *
	 * @param string $message Text.
	 * @param int    $job_id  Job id.
	 * @param array  $context Details.
	 * @return void
	 */
	public function info( $message, $job_id = 0, array $context = array() ) {
		$this->log( 'info', $message, $job_id, $context );
	}

	/**
	 * Shortcut for warnings.
	 *
	 * @param string $message Text.
	 * @param int    $job_id  Job id.
	 * @param array  $context Details.
	 * @return void
	 */
	public function warning( $message, $job_id = 0, array $context = array() ) {
		$this->log( 'warning', $message, $job_id, $context );
	}

	/**
	 * Shortcut for errors.
	 *
	 * @param string $message Text.
	 * @param int    $job_id  Job id.
	 * @param array  $context Details.
	 * @return void
	 */
	public function error( $message, $job_id = 0, array $context = array() ) {
		$this->log( 'error', $message, $job_id, $context );
	}

	/**
	 * Read log lines.
	 *
	 * @param array $args job_id (int|null), level (string, minimum level), levels (list, exactly these levels), search (string), after_id (int), limit (int), order (asc|desc).
	 * @return array[] Rows with id, job_id, level, message, context (array), created_gmt.
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$args = array_merge(
			array(
				'job_id'   => null,
				'level'    => '',
				'search'   => '',
				'after_id' => 0,
				'limit'    => 200,
				'order'    => 'desc',
			),
			$args
		);

		$where  = array( '1=1' );
		$params = array();
		if ( null !== $args['job_id'] && '' !== $args['job_id'] ) {
			$where[]  = 'job_id = %d';
			$params[] = (int) $args['job_id'];
		}
		if ( '' !== $args['level'] && in_array( $args['level'], self::LEVELS, true ) ) {
			$allowed = array_slice( self::LEVELS, (int) array_search( $args['level'], self::LEVELS, true ) );
			$marks   = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
			$where[] = "level IN ({$marks})";
			$params  = array_merge( $params, $allowed );
		}
		if ( ! empty( $args['levels'] ) ) {
			$exact = array_values( array_intersect( self::LEVELS, array_map( 'strval', (array) $args['levels'] ) ) );
			if ( $exact ) {
				$marks   = implode( ',', array_fill( 0, count( $exact ), '%s' ) );
				$where[] = "level IN ({$marks})";
				$params  = array_merge( $params, $exact );
			}
		}
		if ( '' !== (string) $args['search'] ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( (int) $args['after_id'] > 0 ) {
			$where[]  = 'id > %d';
			$params[] = (int) $args['after_id'];
		}

		$order  = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$limit  = max( 1, min( 1000, (int) $args['limit'] ) );
		$table  = Schema::table( 'logs' );
		$sql    = "SELECT id, job_id, level, message, context, created_gmt FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY id {$order} LIMIT {$limit}";
		$rows   = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the plugin's own table; every value in $where goes through prepare().
		$result = array();
		foreach ( (array) $rows as $row ) {
			$row['id']      = (int) $row['id'];
			$row['job_id']  = (int) $row['job_id'];
			$row['context'] = empty( $row['context'] ) ? array() : (array) json_decode( $row['context'], true );
			$result[]       = $row;
		}
		return $result;
	}

	/**
	 * Delete every log line.
	 *
	 * @return void
	 */
	public function clear() {
		global $wpdb;
		$table = Schema::table( 'logs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the plugin's own table.
	}

	/**
	 * Delete all lines of a job, or everything older than a number of days.
	 *
	 * @param int|null $job_id     Job id, or null.
	 * @param int      $older_days Delete lines older than this many days (0 = ignore).
	 * @return void
	 */
	public function purge( $job_id = null, $older_days = 0 ) {
		global $wpdb;
		$table = Schema::table( 'logs' );
		if ( null !== $job_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE job_id = %d", (int) $job_id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the plugin's own table.
		}
		if ( $older_days > 0 ) {
			$cut = gmdate( 'Y-m-d H:i:s', time() - $older_days * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s", $cut ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the plugin's own table.
		}
	}

	/**
	 * Keep wpdb from remembering every query when SAVEQUERIES is on (long jobs would run out of memory).
	 *
	 * @return void
	 */
	private function trim_queries() {
		global $wpdb;
		if ( ! empty( $wpdb->queries ) && count( $wpdb->queries ) > 50 ) {
			$wpdb->queries = array();
		}
	}
}
