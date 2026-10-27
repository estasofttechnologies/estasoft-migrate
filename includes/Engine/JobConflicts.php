<?php
/**
 * Which jobs may run at the same time.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * An import, a pull or a real search and replace changes this site's tables. Anything that reads the
 * tables (an export, a push, a preview) must not run while that happens, and two writers must not
 * run together. Checking a package is harmless and may always run.
 */
final class JobConflicts {

	/**
	 * Does a job of this kind change this site's tables?
	 *
	 * @param string $type   Job type.
	 * @param array  $params Job parameters.
	 * @return bool
	 */
	public static function writes( $type, array $params = array() ) {
		if ( 'search_replace' === $type ) {
			return isset( $params['dry_run'] ) ? ! $params['dry_run'] : false;
		}
		return in_array( $type, array( 'import', 'pull' ), true );
	}

	/**
	 * Does a job of this kind read this site's tables?
	 *
	 * @param string $type   Job type.
	 * @param array  $params Job parameters.
	 * @return bool
	 */
	public static function reads( $type, array $params = array() ) {
		if ( 'search_replace' === $type ) {
			return ! self::writes( $type, $params );
		}
		return in_array( $type, array( 'export', 'push' ), true );
	}

	/**
	 * The unfinished job that stops a new one from starting, if any.
	 *
	 * @param Job[]  $active Unfinished jobs.
	 * @param string $type   Type of the job to start.
	 * @param array  $params Parameters of the job to start.
	 * @return Job|null
	 */
	public static function find( array $active, $type, array $params = array() ) {
		$new_writes = self::writes( $type, $params );
		$new_reads  = self::reads( $type, $params );
		if ( ! $new_writes && ! $new_reads ) {
			return null;
		}
		foreach ( $active as $job ) {
			$writes = self::writes( $job->type, $job->params );
			$reads  = self::reads( $job->type, $job->params );
			if ( $writes || ( $new_writes && $reads ) ) {
				return $job;
			}
		}
		return null;
	}
}
