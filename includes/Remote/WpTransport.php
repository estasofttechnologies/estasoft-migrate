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

		$response = wp_remote_post( $url, $args );
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
