<?php
/**
 * Adaptive batch size.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Learns how many rows fit in one batch. A batch should take about a second and stay under a
 * size in bytes, so wide tables (postmeta with large values) get small batches and narrow
 * tables get large ones. The size is saved in the job state, so a resumed job keeps what it learned.
 */
final class BatchSizer {

	/**
	 * Current row count.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Smallest batch.
	 *
	 * @var int
	 */
	private $min;

	/**
	 * Largest batch.
	 *
	 * @var int
	 */
	private $max;

	/**
	 * Target seconds per batch.
	 *
	 * @var float
	 */
	private $target_seconds;

	/**
	 * Target bytes per batch.
	 *
	 * @var int
	 */
	private $target_bytes;

	/**
	 * Hard ceiling set after the server killed a step at a larger size (0 for none).
	 *
	 * @var int
	 */
	private $cap = 0;

	/**
	 * Constructor.
	 *
	 * @param int   $max            Largest batch in rows.
	 * @param int   $initial        First batch in rows.
	 * @param float $target_seconds Wanted time per batch.
	 * @param int   $target_bytes   Wanted size per batch.
	 */
	public function __construct( $max = 20000, $initial = 200, $target_seconds = 1.0, $target_bytes = 4194304 ) {
		$this->max            = max( 1, (int) $max );
		$this->min            = 1;
		$this->size           = max( 1, min( $this->max, (int) $initial ) );
		$this->target_seconds = max( 0.05, (float) $target_seconds );
		$this->target_bytes   = max( 65536, (int) $target_bytes );
	}

	/**
	 * Restore from saved state.
	 *
	 * @param array $state Saved by export_state().
	 * @param int   $max   Largest batch allowed now.
	 * @return BatchSizer
	 */
	public static function from_state( array $state, $max = 20000 ) {
		$cap      = isset( $state['cap'] ) ? max( 1, (int) $state['cap'] ) : 0;
		$new      = new self( $cap ? min( (int) $max, $cap ) : $max, isset( $state['size'] ) ? (int) $state['size'] : 200 );
		$new->cap = $cap;
		return $new;
	}

	/**
	 * State to save in the job.
	 *
	 * @return array
	 */
	public function export_state() {
		return $this->cap ? array(
			'size' => $this->size,
			'cap'  => $this->cap,
		) : array( 'size' => $this->size );
	}

	/**
	 * Rows to read next.
	 *
	 * @return int
	 */
	public function size() {
		return $this->size;
	}

	/**
	 * Learn from a finished batch.
	 *
	 * @param int   $rows    Rows handled.
	 * @param float $seconds Time taken.
	 * @param int   $bytes   Approximate data size.
	 * @return void
	 */
	public function record( $rows, $seconds, $bytes ) {
		if ( $rows <= 0 ) {
			return;
		}
		$seconds   = max( 0.001, (float) $seconds );
		$by_time   = $rows / $seconds * $this->target_seconds;
		$avg_bytes = max( 1.0, $bytes / $rows );
		$by_bytes  = $this->target_bytes / $avg_bytes;
		$ideal     = min( $by_time, $by_bytes );

		// Move halfway toward the ideal, and never more than double at once.
		$next = (int) round( ( $this->size + $ideal ) / 2 );
		$next = min( $next, $this->size * 2 );
		if ( $by_bytes < $this->size ) {
			$next = min( $next, (int) floor( $by_bytes ) );
		}
		$this->size = max( $this->min, min( $this->max, $next ) );
	}

	/**
	 * Halve the batch (memory pressure or a failed batch).
	 *
	 * @return void
	 */
	public function shrink() {
		$this->size = max( $this->min, (int) floor( $this->size / 2 ) );
	}

	/**
	 * Change the upper limit (for example after the setting changed).
	 *
	 * @param int $max Largest batch.
	 * @return void
	 */
	public function limit( $max ) {
		$this->max  = max( 1, (int) $max );
		$this->size = min( $this->size, $this->max );
	}
}
