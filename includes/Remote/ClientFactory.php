<?php
/**
 * Builds the client that talks to another site.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

use Estasoft\Migrate\Engine\JobContext;

defined( 'ABSPATH' ) || exit;

/**
 * Tests (and other code) can replace the transport by setting the "remote_client" service.
 */
final class ClientFactory {

	/**
	 * A client for the site at $url.
	 *
	 * @param JobContext $ctx Services.
	 * @param string     $url Address of the other site.
	 * @param string     $key Its connection key.
	 * @return Client
	 */
	public static function make( JobContext $ctx, $url, $key ) {
		$factory = $ctx->service( 'remote_client' );
		if ( is_callable( $factory ) ) {
			return call_user_func( $factory, $url, $key );
		}
		return new Client( new WpTransport( (bool) $ctx->service( 'allow_private_hosts' ) ), $url, $key );
	}
}
