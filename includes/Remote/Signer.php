<?php
/**
 * Signs and verifies requests and responses between two sites.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 over the action, the time, a one-time value and the SHA-256 of the body.
 *
 * The connection key itself is never sent. A request can be used only once (the nonce is
 * remembered by the receiver) and only within a short time window, and the answer is signed too,
 * so a client knows it is talking to the site that owns the key.
 */
final class Signer {

	const VERSION = 1;

	/**
	 * Allowed difference between the two clocks, in seconds.
	 */
	const MAX_SKEW = 300;

	/**
	 * Headers added to a request.
	 *
	 * @param string $key    Connection key.
	 * @param string $action Action name.
	 * @param string $body   Request body.
	 * @param int    $time   Unix time (defaults to now).
	 * @param string $nonce  One-time value (defaults to random).
	 * @return array<string,string>
	 */
	public static function request_headers( $key, $action, $body, $time = 0, $nonce = '' ) {
		$time  = $time ? (int) $time : time();
		$nonce = '' !== $nonce ? $nonce : bin2hex( random_bytes( 16 ) );
		return array(
			'X-Estm-Version' => (string) self::VERSION,
			'X-Estm-Key'     => \Estasoft\Migrate\Settings::key_id( $key ),
			'X-Estm-Time'    => (string) $time,
			'X-Estm-Nonce'   => $nonce,
			'X-Estm-Sig'     => self::request_signature( $key, $action, $time, $nonce, $body ),
		);
	}

	/**
	 * Signature of a request.
	 *
	 * @param string $key    Connection key.
	 * @param string $action Action.
	 * @param int    $time   Time.
	 * @param string $nonce  Nonce.
	 * @param string $body   Body.
	 * @return string
	 */
	public static function request_signature( $key, $action, $time, $nonce, $body ) {
		$data = "ESTM1\n" . $action . "\n" . (int) $time . "\n" . $nonce . "\n" . hash( 'sha256', (string) $body );
		return hash_hmac( 'sha256', $data, $key );
	}

	/**
	 * Check a request signature.
	 *
	 * @param string $key       Connection key to try.
	 * @param string $action    Action.
	 * @param string $time      Time header.
	 * @param string $nonce     Nonce header.
	 * @param string $body      Body.
	 * @param string $signature Signature header.
	 * @return bool
	 */
	public static function verify_request( $key, $action, $time, $nonce, $body, $signature ) {
		if ( '' === $signature || ! ctype_digit( (string) $time ) ) {
			return false;
		}
		return hash_equals( self::request_signature( $key, $action, (int) $time, $nonce, $body ), (string) $signature );
	}

	/**
	 * Signature of a response.
	 *
	 * @param string $key   Connection key.
	 * @param string $nonce Nonce of the request being answered.
	 * @param string $body  Response body.
	 * @return string
	 */
	public static function response_signature( $key, $nonce, $body ) {
		return hash_hmac( 'sha256', "ESTM1R\n" . $nonce . "\n" . hash( 'sha256', (string) $body ), $key );
	}

	/**
	 * Check a response signature.
	 *
	 * @param string $key       Connection key.
	 * @param string $nonce     Nonce of the request.
	 * @param string $body      Response body.
	 * @param string $signature Signature header.
	 * @return bool
	 */
	public static function verify_response( $key, $nonce, $body, $signature ) {
		return '' !== $signature && hash_equals( self::response_signature( $key, $nonce, $body ), (string) $signature );
	}
}
