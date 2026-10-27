<?php
/**
 * A unit of long-running work.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Plain data object. The state array holds everything needed to continue after a restart.
 */
class Job {

	const QUEUED    = 'queued';
	const RUNNING   = 'running';
	const PAUSED    = 'paused';
	const FAILED    = 'failed';
	const COMPLETED = 'completed';
	const CANCELLED = 'cancelled';

	/**
	 * Id.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Task type: export, import, search_replace, push or pull.
	 *
	 * @var string
	 */
	public $type = '';

	/**
	 * Status.
	 *
	 * @var string
	 */
	public $status = self::QUEUED;

	/**
	 * Title shown in lists.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * What the job was asked to do (never changes).
	 *
	 * @var array
	 */
	public $params = array();

	/**
	 * Where the job is (changes after every batch).
	 *
	 * @var array
	 */
	public $state = array();

	/**
	 * Percent, 0 to 100.
	 *
	 * @var float
	 */
	public $progress = 0.0;

	/**
	 * Latest plain-language message.
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Runner that last worked on the job.
	 *
	 * @var string
	 */
	public $runner = '';

	/**
	 * Lock expiry (unix time).
	 *
	 * @var int
	 */
	public $locked_until = 0;

	/**
	 * Timestamps (UTC, MySQL format).
	 *
	 * @var string|null
	 */
	public $created_gmt;

	/**
	 * Last change (UTC, Y-m-d H:i:s).
	 *
	 * @var string|null
	 */
	public $updated_gmt;

	/**
	 * First run (UTC, Y-m-d H:i:s).
	 *
	 * @var string|null
	 */
	public $started_gmt;

	/**
	 * End (UTC, Y-m-d H:i:s).
	 *
	 * @var string|null
	 */
	public $finished_gmt;

	/**
	 * Is the job over?
	 *
	 * @return bool
	 */
	public function is_finished() {
		return in_array( $this->status, array( self::COMPLETED, self::FAILED, self::CANCELLED ), true );
	}

	/**
	 * Can a runner work on it now?
	 *
	 * @return bool
	 */
	public function is_runnable() {
		return in_array( $this->status, array( self::QUEUED, self::RUNNING ), true );
	}

	/**
	 * Array form for the REST API.
	 *
	 * @param bool $with_state Include the full state.
	 * @return array
	 */
	public function to_array( $with_state = false ) {
		$out = array(
			'id'       => $this->id,
			'type'     => $this->type,
			'kind'     => (string) ( $this->params['kind'] ?? '' ),
			'status'   => $this->status,
			'title'    => $this->title,
			'progress' => round( (float) $this->progress, 1 ),
			'message'  => $this->message,
			'created'  => $this->created_gmt,
			'updated'  => $this->updated_gmt,
			'started'  => $this->started_gmt,
			'finished' => $this->finished_gmt,
		);
		if ( $with_state ) {
			$out['state'] = $this->public_state();
		}
		return $out;
	}

	/**
	 * State without secrets or private fields (keys starting with an underscore).
	 *
	 * @return array
	 */
	public function public_state() {
		return self::strip_private( $this->state );
	}

	/**
	 * Remove keys that start with an underscore, at every depth.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function strip_private( array $data ) {
		$out = array();
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && '_' === substr( $k, 0, 1 ) ) {
				continue;
			}
			$out[ $k ] = is_array( $v ) ? self::strip_private( $v ) : $v;
		}
		return $out;
	}
}
