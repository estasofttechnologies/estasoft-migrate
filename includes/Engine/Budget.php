<?php
/**
 * Time and memory limits for one slice of work.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a task when to stop and hand control back, so no request runs into a server limit.
 */
final class Budget {

	/**
	 * Unix time (float) at which the slice should end.
	 *
	 * @var float
	 */
	private $deadline;

	/**
	 * Memory ceiling in bytes, 0 for none.
	 *
	 * @var int
	 */
	private $memory_limit;

	/**
	 * Remaining work units, or null for a time-based budget.
	 *
	 * @var int|null
	 */
	private $units = null;

	/**
	 * Does a slice always get to do one unit of work, even when time or memory is already short?
	 *
	 * @var bool
	 */
	private $guarantee_unit = false;

	/**
	 * Has that one guaranteed unit been handed out?
	 *
	 * @var bool
	 */
	private $unit_given = false;

	/**
	 * Constructor.
	 *
	 * @param float $seconds      Seconds available from now.
	 * @param int   $memory_limit Bytes of memory the slice may use, 0 for no limit.
	 */
	public function __construct( $seconds, $memory_limit = 0 ) {
		$this->deadline     = microtime( true ) + max( 0.0, (float) $seconds );
		$this->memory_limit = max( 0, (int) $memory_limit );
	}

	/**
	 * A budget whose slice always does at least one unit of work. Without that, a slice that starts
	 * when memory is already high (or time is short) would stop at once, every time, and the job would never move.
	 *
	 * @param float $seconds      Seconds available from now.
	 * @param int   $memory_limit Bytes of memory the slice may use, 0 for no limit.
	 * @return Budget
	 */
	public static function guaranteed( $seconds, $memory_limit = 0 ) {
		$budget                 = new self( $seconds, $memory_limit );
		$budget->guarantee_unit = true;
		return $budget;
	}

	/**
	 * Budget for a web request: about 80 percent of the remaining execution time (capped), and 75 percent of the memory limit.
	 *
	 * @param int   $cap_seconds Longest slice wanted, so progress updates regularly.
	 * @param float $fraction    Share of the server time limit to use.
	 * @return Budget
	 */
	public static function for_request( $cap_seconds = 15, $fraction = 0.8 ) {
		$max_exec = (int) ini_get( 'max_execution_time' );
		$seconds  = (float) $cap_seconds;
		if ( $max_exec > 0 ) {
			$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
			$left    = $max_exec * $fraction - ( microtime( true ) - $started );
			$seconds = min( $seconds, max( 2.0, $left ) );
		}
		$limit = Util::bytes_from_ini( ini_get( 'memory_limit' ) );
		return self::guaranteed( $seconds, $limit > 0 ? (int) ( $limit * 0.75 ) : 0 );
	}

	/**
	 * Budget that allows a fixed number of work units (batches) and no more. Used by the tests to stop
	 * a job at an exact point and prove that it resumes correctly.
	 *
	 * @param int $units Number of work units.
	 * @return Budget
	 */
	public static function units( $units ) {
		$budget        = new self( 86400 * 7, 0 );
		$budget->units = max( 0, (int) $units );
		return $budget;
	}

	/**
	 * Budget without a time limit (command line).
	 *
	 * @return Budget
	 */
	public static function unlimited() {
		$limit = Util::bytes_from_ini( ini_get( 'memory_limit' ) );
		return self::guaranteed( 86400 * 7, $limit > 0 ? (int) ( $limit * 0.75 ) : 0 );
	}

	/**
	 * Seconds left.
	 *
	 * @return float
	 */
	public function time_left() {
		return max( 0.0, $this->deadline - microtime( true ) );
	}

	/**
	 * Is memory use close to the ceiling?
	 *
	 * @return bool
	 */
	public function memory_pressure() {
		return $this->memory_limit > 0 && memory_get_usage( true ) > $this->memory_limit;
	}

	/**
	 * Should the task stop now?
	 *
	 * @param float $reserve Seconds to keep in reserve for saving state.
	 * @return bool
	 */
	public function exhausted( $reserve = 0.3 ) {
		if ( null !== $this->units ) {
			if ( $this->units <= 0 ) {
				return true;
			}
			--$this->units;
			return false;
		}
		if ( $this->guarantee_unit && ! $this->unit_given ) {
			$this->unit_given = true;
			return false;
		}
		return $this->time_left() <= $reserve || $this->memory_pressure();
	}

	/**
	 * Like exhausted(), but never uses up a work unit. For checks between phases.
	 *
	 * @param float $reserve Seconds to keep in reserve for saving state.
	 * @return bool
	 */
	public function expired( $reserve = 0.3 ) {
		if ( null !== $this->units ) {
			return $this->units <= 0;
		}
		return $this->time_left() <= $reserve || $this->memory_pressure();
	}

	/**
	 * Memory ceiling in bytes (0 if none).
	 *
	 * @return int
	 */
	public function memory_limit() {
		return $this->memory_limit;
	}
}
