<?php
/**
 * An error the screens can show as it is.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-language message, a stable code and an HTTP status.
 */
class ApiException extends \RuntimeException {

	/**
	 * Machine readable code.
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Extra values for the response.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Code, for example estm_not_found.
	 * @param string $message    Message for the person.
	 * @param int    $status     HTTP status.
	 * @param array  $data       Extra values.
	 */
	public function __construct( $error_code, $message, $status = 400, array $data = array() ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->status     = (int) $status;
		$this->data       = $data;
	}
}
