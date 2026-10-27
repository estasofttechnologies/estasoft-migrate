<?php
/**
 * Sends one HTTP request. A small interface so the tests can talk to a second site in the same process.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || exit;

interface Transport {

	/**
	 * POST a body.
	 *
	 * @param string               $url     Address.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body    Body.
	 * @param int                  $timeout Seconds.
	 * @return array{status:int, headers:array<string,string>, body:string} Header names in lower case.
	 * @throws RemoteException When the other site cannot be reached at all.
	 */
	public function post( $url, array $headers, $body, $timeout );
}
