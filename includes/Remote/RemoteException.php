<?php
/**
 * Error from the remote connection, with a message the site owner can act on.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable code, a plain-language message and the HTTP status.
 */
class RemoteException extends \RuntimeException {

	/**
	 * Machine readable code, for example estm_bad_key.
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * HTTP status for the response.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Extra values for the response (for example server_time).
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Can the same request be tried again?
	 *
	 * @var bool
	 */
	public $retryable;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Code.
	 * @param string $message    Message.
	 * @param int    $status     HTTP status.
	 * @param array  $data       Extra data.
	 * @param bool   $retryable  Temporary problem.
	 */
	public function __construct( $error_code, $message, $status = 400, array $data = array(), $retryable = false ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->status     = $status;
		$this->data       = $data;
		$this->retryable  = $retryable;
	}
}
