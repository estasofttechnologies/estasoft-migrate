<?php
/**
 * Package failure.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Package;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Raised when a package cannot be written, read or verified.
 */
class PackageException extends \RuntimeException {

	const BAD_PASSPHRASE = 'bad_passphrase';
	const TRUNCATED      = 'truncated';
	const CORRUPT        = 'corrupt';
	const UNSUPPORTED    = 'unsupported';
	const NEEDS_KEY      = 'needs_key';
	const IO             = 'io';

	/**
	 * Short machine-readable reason.
	 *
	 * @var string
	 */
	public $reason = '';

	/**
	 * Constructor.
	 *
	 * @param string $message Plain-language message.
	 * @param string $reason  One of the class constants.
	 */
	public function __construct( $message, $reason = self::CORRUPT ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}
}
