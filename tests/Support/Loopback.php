<?php
/**
 * Test doubles that let one test site talk to another in the same process.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Support;

use Estasoft\Migrate\Remote\Client;
use Estasoft\Migrate\Remote\Nonces;
use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Remote\Server;
use Estasoft\Migrate\Remote\Transport;
use Estasoft\Migrate\Settings;

/**
 * Settings kept in an array instead of the options table.
 */
class ArraySettings extends Settings {

	/**
	 * Stored values.
	 *
	 * @var array
	 */
	public $values = array();

	/**
	 * Constructor.
	 *
	 * @param array $values Starting values.
	 */
	public function __construct( array $values = array() ) {
		$this->values = $values;
	}

	public function all() {
		return array_merge( self::defaults(), $this->values );
	}

	public function update( array $input ) {
		$this->values = array_merge( $this->values, self::sanitize( $input, $this->all() ) );
		return $this->all();
	}

	public function update_raw( array $values ) {
		$this->values = array_merge( $this->values, $values );
	}
}

/**
 * Nonce memory in an array.
 */
class MemoryNonces implements Nonces {

	/**
	 * Used values.
	 *
	 * @var array
	 */
	public $used = array();

	/**
	 * Failures per address.
	 *
	 * @var array
	 */
	public $failures = array();

	public function use_once( $nonce, $seconds ) {
		if ( isset( $this->used[ $nonce ] ) ) {
			return false;
		}
		$this->used[ $nonce ] = true;
		return true;
	}

	public function failure( $ip ) {
		$this->failures[ $ip ] = ( $this->failures[ $ip ] ?? 0 ) + 1;
		return $this->failures[ $ip ] >= 20;
	}

	public function blocked( $ip ) {
		return ( $this->failures[ $ip ] ?? 0 ) >= 20;
	}
}

/**
 * Hands requests straight to a Server, as the web would, and records what was asked.
 */
class LoopbackTransport implements Transport {

	/**
	 * Receiving site.
	 *
	 * @var Server
	 */
	public $server;

	/**
	 * Pretend https.
	 *
	 * @var bool
	 */
	public $https = true;

	/**
	 * Address of the sender as the receiver sees it.
	 *
	 * @var string
	 */
	public $ip = '203.0.113.50';

	/**
	 * Actions asked, in order.
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Optional hook(action, reply) => reply|null|throws. Lets a test lose or change answers.
	 *
	 * @var callable|null
	 */
	public $after = null;

	/**
	 * Optional hook(action, headers, body) => null|reply to answer instead of the server.
	 *
	 * @var callable|null
	 */
	public $before = null;

	/**
	 * Constructor.
	 *
	 * @param Server $server Server.
	 */
	public function __construct( Server $server ) {
		$this->server = $server;
	}

	public function post( $url, array $headers, $body, $timeout ) {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$action = (string) ( $query['estm_remote'] ?? '' );
		$this->log[] = $action;

		$lower = array();
		foreach ( $headers as $k => $v ) {
			$lower[ strtolower( $k ) ] = $v;
		}
		if ( $this->before ) {
			$early = call_user_func( $this->before, $action, $lower, $body );
			if ( null !== $early ) {
				return $early;
			}
		}
		$out   = $this->server->handle( $action, $body, $lower, $this->ip, $this->https );
		$reply = array(
			'status'  => $out['status'],
			'headers' => array_change_key_case( $out['headers'], CASE_LOWER ),
			'body'    => $out['body'],
		);
		if ( $this->after ) {
			$changed = call_user_func( $this->after, $action, $reply );
			if ( null !== $changed ) {
				$reply = $changed;
			}
		}
		return $reply;
	}
}
