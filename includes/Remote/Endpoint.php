<?php
/**
 * WordPress glue for requests from another site.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

use Estasoft\Migrate\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for POST /?estm_remote=action. It does not use the REST API, because many sites
 * switch the REST API off for visitors who are not logged in.
 */
class Endpoint {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Requests are authenticated by their signature.
		if ( isset( $_GET['estm_remote'] ) ) {
			// Answers can contain translated text, and WordPress 6.7+ only allows translations from after_setup_theme on.
			// That is still before init, so no post types, widgets or front-end code have run yet.
			if ( did_action( 'after_setup_theme' ) ) {
				$this->serve();
			} else {
				add_action( 'after_setup_theme', array( $this, 'serve' ), 0 );
			}
		}
	}

	/**
	 * Answer and stop.
	 *
	 * @return void
	 */
	public function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		$given  = wp_unslash( $_GET['estm_remote'] );
		$action = is_scalar( $given ) ? preg_replace( '/[^a-z_]/', '', strtolower( (string) $given ) ) : '';

		// Other plugins sometimes print notices; they must not end up inside the signed answer.
		ob_start();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			$this->send(
				405,
				array( 'Content-Type' => 'application/json' ),
				(string) wp_json_encode(
					array(
						'ok'      => false,
						'code'    => 'estm_method',
						'message' => 'POST only.',
					)
				)
			);
		}

		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$body = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$headers = array();
		foreach ( $_SERVER as $name => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 0 === strpos( $name, 'HTTP_X_ESTM_' ) ) {
				$headers[ strtolower( str_replace( '_', '-', substr( $name, 5 ) ) ) ] = (string) $value;
			}
		}

		/**
		 * The address of the other site as seen by this one. Change it when the site is behind a proxy
		 * that passes the real address in a header you trust.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'estasoft_migrate_remote_ip', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$server = new Server( $this->plugin->settings(), $this->plugin->runner(), $this->plugin->context(), new WpNonces() );
		$reply  = $server->handle( $action, $body, $headers, $ip, is_ssl() );
		$this->send( $reply['status'], $reply['headers'], $reply['body'] );
	}

	/**
	 * Print the answer.
	 *
	 * @param int    $status  HTTP status.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @return void
	 */
	private function send( $status, array $headers, $body ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		status_header( $status );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Signed JSON.
		exit;
	}
}
