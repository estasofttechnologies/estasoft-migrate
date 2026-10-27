<?php
/**
 * A task failure with a message fit for the person running the job.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * The message says what happened, the details say what was not changed and what to do.
 */
class TaskException extends \RuntimeException {

	/**
	 * Extra explanation: untouched (what was not changed) and advice (what to do).
	 *
	 * @var array
	 */
	public $details = array();

	/**
	 * Can trying again help?
	 *
	 * @var bool
	 */
	public $retryable = false;

	/**
	 * Constructor.
	 *
	 * @param string          $message   What happened, in plain language.
	 * @param array           $details   untouched, advice, table, chunk.
	 * @param bool            $retryable Whether a retry may succeed.
	 * @param \Throwable|null $previous  Cause.
	 */
	public function __construct( $message, array $details = array(), $retryable = false, $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->details   = $details;
		$this->retryable = $retryable;
	}
}
