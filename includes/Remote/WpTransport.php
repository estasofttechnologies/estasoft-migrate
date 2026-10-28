<?php
/**
 * HTTP transport on top of the WordPress HTTP API.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Redirects are not followed: a POST that is redirected loses its body, so the person is told the
 * final address instead.
 */
class WpTransport implements Transport {

	/**
	 * Allow this site to connect to local and private network addresses.
	 *
	 * @var bool
	 */
	private $allow_private;

	/**
	 * Constructor.
	 *
	 * @param bool $allow_private Connect to localhost and private network addresses too (a development site on the same machine or network).
	 */
	public function __construct( $allow_private = false ) {
		$this->allow_private = (bool) $allow_private;
	}

	/**
	 * POST a body.
	 *
	 * @param string $url     Address.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param int    $timeout Seconds.
	 * @return array
	 * @throws RemoteException When the site cannot be reached.
	 */
	public function post( $url, array $headers, $body, $timeout ) {
		$args = array(
			'timeout'     => max( 5, (int) $timeout ),
			'redirection' => 0,
			'headers'     => array_merge( array( 'Content-Type' => 'application/json; charset=utf-8' ), $headers ),
			'body'        => $body,
			'sslverify'   => (bool) apply_filters( 'estasoft_migrate_remote_sslverify', true, $url ),
			'user-agent'  => 'EstasoftMigrate/' . ( defined( 'ESTASOFT_MIGRATE_VERSION' ) ? ESTASOFT_MIGRATE_VERSION : '0' ) . '; ' . home_url( '/' ),
		);

		// WordPress's "safe" request refuses localhost, private network addresses and unusual ports, so a connection key typed
		// with a mistaken address cannot be used to make this server call something inside its own network.
		$allow = (bool) apply_filters( 'estasoft_migrate_allow_private_hosts', $this->allow_private, $url );
		if ( ! $allow && false === wp_http_validate_url( $url ) ) {
			throw new RemoteException(
				'estm_private_host',
				sprintf(
					/* translators: %s: address of the other site */
					__( 'WordPress does not connect to %s because it is a local or private network address, or uses an unusual port. If this is a site of your own on this machine or network, turn on "Allow local and private addresses" in Settings and try again.', 'estasoft-migrate' ),
					$url
				),
				0
			);
		}

		$response = $allow ? wp_remote_post( $url, $args ) : wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$code    = 'estm_unreachable';
			$text    = sprintf(
				/* translators: %s: technical error message */
				__( 'The other site could not be reached: %s', 'estasoft-migrate' ),
				$message
			);
			if ( false !== stripos( $message, 'ssl' ) || false !== stripos( $message, 'certificate' ) ) {
				$code = 'estm_ssl';
				$text = sprintf(
					/* translators: %s: technical error message */
					__( 'A secure connection to the other site could not be made (%s). Check that its SSL certificate is valid.', 'estasoft-migrate' ),
					$message
				);
			} elseif ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) {
				$code = 'estm_timeout';
				$text = __( 'The other site did not answer in time.', 'estasoft-migrate' );
			}
			throw new RemoteException( $code, $text, 0, array(), true );
		}

		$headers_out = array();
		foreach ( (array) wp_remote_retrieve_headers( $response )->getAll() as $name => $value ) {
			$headers_out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers_out,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
