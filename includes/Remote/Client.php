<?php
/**
 * Talks to another site that runs this plugin.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Builds signed requests, checks the signed answers and turns every failure into a message a
 * site owner can understand.
 */
class Client {

	/**
	 * Transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Address of the other site.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Connection key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Pause between tries, in microseconds (zero in the tests).
	 *
	 * @var int
	 */
	public $retry_pause = 800000;

	/**
	 * Constructor.
	 *
	 * @param Transport $transport Transport.
	 * @param string    $base      Address of the other site, for example https://example.com.
	 * @param string    $key       Connection key from the other site's Settings screen.
	 */
	public function __construct( Transport $transport, $base, $key ) {
		$this->transport = $transport;
		$this->base      = rtrim( trim( (string) $base ), '/' );
		$this->key       = trim( (string) $key );
	}

	/**
	 * Address of the other site.
	 *
	 * @return string
	 */
	public function base() {
		return $this->base;
	}

	/**
	 * The key, for sealing values sent to the other site.
	 *
	 * @return string
	 */
	public function key() {
		return $this->key;
	}

	/**
	 * Address of an action.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	public function url( $action ) {
		return $this->base . '/?estm_remote=' . rawurlencode( $action );
	}

	/**
	 * Call an action and return its data.
	 *
	 * @param string $action  Action name.
	 * @param array  $payload Values.
	 * @param int    $timeout Seconds to wait for one try.
	 * @param int    $tries   How many times to try on temporary errors.
	 * @return array
	 * @throws RemoteException On any failure.
	 */
	public function call( $action, array $payload = array(), $timeout = 60, $tries = 3 ) {
		if ( '' === $this->base || ! preg_match( '#^https?://#i', $this->base ) ) {
			throw new RemoteException( 'estm_address', __( 'Enter the full address of the other site, starting with https://', 'estasoft-migrate' ) );
		}
		if ( '' === $this->key ) {
			throw new RemoteException( 'estm_bad_key', __( 'Enter the connection key from the other site.', 'estasoft-migrate' ) );
		}

		$body  = (string) wp_json_encode( (object) $payload, JSON_UNESCAPED_SLASHES );
		$last  = null;
		$tries = max( 1, (int) $tries );
		for ( $try = 1; $try <= $tries; $try++ ) {
			try {
				return $this->once( $action, $body, $timeout );
			} catch ( RemoteException $e ) {
				$last = $e;
				if ( ! $e->retryable || $try >= $tries ) {
					throw $e;
				}
				if ( $this->retry_pause > 0 ) {
					usleep( $this->retry_pause * $try );
				}
			}
		}
		throw $last;
	}

	/**
	 * One try. Every try gets a new one-time value, so a lost answer can safely be asked for again.
	 *
	 * @param string $action  Action.
	 * @param string $body    JSON body.
	 * @param int    $timeout Seconds.
	 * @return array
	 * @throws RemoteException On failure.
	 */
	private function once( $action, $body, $timeout ) {
		$headers = Signer::request_headers( $this->key, $action, $body );
		$reply   = $this->transport->post( $this->url( $action ), $headers, $body, $timeout );
		$status  = (int) $reply['status'];
		$text    = (string) $reply['body'];
		$data    = json_decode( $text, true );

		if ( ! is_array( $data ) || ! array_key_exists( 'ok', $data ) ) {
			throw $this->not_ours( $status, $reply );
		}

		if ( empty( $data['ok'] ) ) {
			$sig    = (string) ( $reply['headers']['x-estm-resp-sig'] ?? '' );
			$signed = Signer::verify_response( $this->key, $headers['X-Estm-Nonce'], $text, $sig );
			$code   = (string) ( $data['code'] ?? 'estm_error' );
			throw new RemoteException(
				$code,
				$this->message_for( $code, (array) $data ),
				$status,
				(array) ( $data['data'] ?? array() ),
				$signed && ! empty( $data['retry'] )
			);
		}

		$sig = (string) ( $reply['headers']['x-estm-resp-sig'] ?? '' );
		if ( ! Signer::verify_response( $this->key, $headers['X-Estm-Nonce'], $text, $sig ) ) {
			throw new RemoteException(
				'estm_bad_response',
				__( 'The answer was not signed with your connection key. It may not come from the site you meant, or something between the two sites changed it.', 'estasoft-migrate' ),
				$status
			);
		}
		unset( $data['ok'] );
		return $data;
	}

	/**
	 * Message for an error code sent by the other site.
	 *
	 * @param string $code Code.
	 * @param array  $data Whole answer.
	 * @return string
	 */
	private function message_for( $code, array $data ) {
		switch ( $code ) {
			case 'estm_off':
				return __( 'The other site is not accepting migrations. It may never have been switched on, or it switched itself off after a while without a migration. On that site open Estasoft Migration → Settings and turn on “Accept incoming migrations”.', 'estasoft-migrate' );
			case 'estm_bad_key':
				return __( 'The other site did not accept the connection key. Copy the key again from its Settings screen.', 'estasoft-migrate' );
			case 'estm_bad_sig':
				return __( 'The other site could not confirm the request. Check the connection key, and that nothing between the two sites changes the data.', 'estasoft-migrate' );
			case 'estm_clock':
				$skew = isset( $data['data']['server_time'] ) ? (int) round( abs( time() - (int) $data['data']['server_time'] ) / 60 ) : 0;
				return sprintf(
					/* translators: %d: number of minutes */
					_n(
						'The clocks of the two sites differ by about %d minute. Correct the time on one of them and try again.',
						'The clocks of the two sites differ by about %d minutes. Correct the time on one of them and try again.',
						max( 1, $skew ),
						'estasoft-migrate'
					),
					max( 1, $skew )
				);
		}
		return (string) ( $data['message'] ?? __( 'The other site reported an error.', 'estasoft-migrate' ) );
	}

	/**
	 * The answer was not from this plugin.
	 *
	 * @param int   $status HTTP status.
	 * @param array $reply  Transport reply.
	 * @return RemoteException
	 */
	private function not_ours( $status, array $reply ) {
		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			$to = (string) ( $reply['headers']['location'] ?? '' );
			return new RemoteException(
				'estm_redirect',
				'' !== $to
					? sprintf(
						/* translators: %s: address the other site redirects to */
						__( 'The address redirects to %s. Use that address instead (check http or https, and with or without www).', 'estasoft-migrate' ),
						preg_replace( '#[?&]estm_remote=.*$#', '', $to )
					)
					: __( 'The address redirects somewhere else. Use the final address of the other site (check http or https, and with or without www).', 'estasoft-migrate' ),
				$status
			);
		}
		if ( 429 === $status || $status >= 500 ) {
			return new RemoteException( 'estm_server_error', __( 'The other site had a temporary problem. Trying again in a moment usually works.', 'estasoft-migrate' ), $status, array(), true );
		}
		if ( 401 === $status || 403 === $status ) {
			return new RemoteException( 'estm_blocked', __( 'The other site or its firewall refused the request. Ask the host to allow requests to the site address from this server, or allow them in the firewall plugin.', 'estasoft-migrate' ), $status );
		}
		return new RemoteException(
			'estm_not_found',
			__( 'Estasoft Migration did not answer at this address. Check the address, and make sure the plugin is installed and active on the other site.', 'estasoft-migrate' ),
			$status
		);
	}
}
