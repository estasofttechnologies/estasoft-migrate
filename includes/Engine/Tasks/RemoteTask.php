<?php
/**
 * Shared parts of push and pull.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine\Tasks;

use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\Task;
use Estasoft\Migrate\Engine\TaskException;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Remote\Client;
use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Remote\WpTransport;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * A job that works together with the plugin on another site.
 *
 * The connection key is stored with the job only in sealed form. Whatever is sent to the other site
 * is signed, and whatever comes back is checked before it is used.
 */
abstract class RemoteTask extends Task {

	/**
	 * Part size used for transfers when the other site allows it.
	 */
	const PART = 1048576;

	/**
	 * Seal a connection key for storing with the job parameters.
	 *
	 * @param array  $params Job parameters containing remote_key.
	 * @param string $secret Site secret.
	 * @return array Parameters with remote_key replaced by key_enc.
	 */
	public static function seal_params( array $params, $secret ) {
		if ( isset( $params['remote_key'] ) ) {
			$params['key_enc'] = Secrets::seal( trim( (string) $params['remote_key'] ), $secret );
			unset( $params['remote_key'] );
		}
		if ( isset( $params['remote_url'] ) ) {
			$params['remote_url'] = rtrim( trim( (string) $params['remote_url'] ), '/' );
		}
		return $params;
	}

	/**
	 * A client for the other site.
	 *
	 * @param array      $params Job parameters.
	 * @param JobContext $ctx    Services.
	 * @return Client
	 * @throws TaskException When no address or key is stored.
	 */
	protected function client( array $params, JobContext $ctx ) {
		$url = (string) ( $params['remote_url'] ?? '' );
		$key = ! empty( $params['key_enc'] ) ? Secrets::open( (string) $params['key_enc'], $ctx->secret ) : '';
		if ( '' === $url || '' === $key ) {
			throw new TaskException(
				__( 'The address or the connection key of the other site is missing.', 'estasoft-migrate' ),
				array(
					'untouched' => __( 'Nothing was changed on either site.', 'estasoft-migrate' ),
					'advice'    => __( 'Start the migration again and enter both.', 'estasoft-migrate' ),
				)
			);
		}
		$factory = $ctx->service( 'remote_client' );
		if ( is_callable( $factory ) ) {
			return call_user_func( $factory, $url, $key );
		}
		return new Client( new WpTransport(), $url, $key );
	}

	/**
	 * Call the other site and turn failures into job errors.
	 *
	 * @param Client $client    Client.
	 * @param string $action    Action.
	 * @param array  $payload   Values.
	 * @param string $untouched What was not changed, shown if this fails for good.
	 * @param int    $timeout   Seconds.
	 * @return array
	 * @throws TaskException On failure.
	 */
	protected function call( Client $client, $action, array $payload, $untouched, $timeout = 60 ) {
		try {
			return $client->call( $action, $payload, $timeout );
		} catch ( RemoteException $e ) {
			throw $this->wrap( $e, $untouched );
		}
	}

	/**
	 * Map a remote error to a job error.
	 *
	 * @param RemoteException $e         Error.
	 * @param string          $untouched What was not changed.
	 * @return TaskException
	 */
	protected function wrap( RemoteException $e, $untouched ) {
		$advice = '';
		switch ( $e->error_code ) {
			case 'estm_off':
			case 'estm_bad_key':
			case 'estm_bad_sig':
			case 'estm_https':
			case 'estm_ip':
				$advice = __( 'Check the connection details, then start again.', 'estasoft-migrate' );
				break;
			case 'estm_no_space':
				$advice = __( 'Free some disk space on that site, then retry.', 'estasoft-migrate' );
				break;
			default:
				$advice = $e->retryable ? __( 'Retry in a few minutes.', 'estasoft-migrate' ) : __( 'Check the Logs screen on both sites for details.', 'estasoft-migrate' );
		}
		return new TaskException(
			$e->getMessage(),
			array(
				'untouched' => $untouched,
				'advice'    => $advice,
				'code'      => $e->error_code,
			),
			$e->retryable
		);
	}

	/**
	 * Say hello and check that the other site can take part.
	 *
	 * @param Client     $client Client.
	 * @param array      $params Job parameters.
	 * @param JobContext $ctx    Services.
	 * @return array Facts about the other site.
	 * @throws TaskException When it cannot.
	 */
	protected function connect( Client $client, array $params, JobContext $ctx ) {
		$untouched = __( 'Nothing was changed on either site.', 'estasoft-migrate' );
		$hello     = $this->call( $client, 'hello', array(), $untouched, 30 );

		$site = (array) ( $hello['site'] ?? array() );
		if ( ! empty( $site['multisite'] ) ) {
			throw new TaskException(
				__( 'The other site is a WordPress multisite network, which this version cannot migrate.', 'estasoft-migrate' ),
				array( 'untouched' => $untouched )
			);
		}
		if ( empty( $hello['writable'] ) ) {
			throw new TaskException(
				__( 'The other site cannot write to its storage folder.', 'estasoft-migrate' ),
				array(
					'untouched' => $untouched,
					'advice'    => __( 'Make wp-content/uploads writable on that site.', 'estasoft-migrate' ),
				)
			);
		}
		$mine        = defined( 'ESTASOFT_MIGRATE_VERSION' ) ? ESTASOFT_MIGRATE_VERSION : '';
		$their_major = ! empty( $hello['version'] ) ? explode( '.', (string) $hello['version'] )[0] : '';
		$my_major    = explode( '.', $mine )[0];
		if ( '' !== $mine && '' !== $their_major && $my_major !== $their_major ) {
			throw new TaskException(
				sprintf(
					/* translators: 1: local version, 2: other version */
					__( 'The two sites run different major versions of Estasoft Migration (%1$s and %2$s). Update the plugin on both.', 'estasoft-migrate' ),
					$mine,
					$hello['version']
				),
				array( 'untouched' => $untouched )
			);
		}
		$ctx->log(
			'info',
			sprintf(
				/* translators: %s: address */
				__( 'Connected to %s.', 'estasoft-migrate' ),
				(string) ( $site['url'] ?? $client->base() )
			)
		);
		return $hello;
	}

	/**
	 * Run the other site's job for a while.
	 *
	 * @param Client $client    Client.
	 * @param int    $job_id    Remote job.
	 * @param Budget $budget    Time left here.
	 * @param string $untouched What stays unchanged on failure.
	 * @return array The remote report: job (status, progress, message) and, for exports, package.
	 * @throws TaskException On failure.
	 */
	protected function tick( Client $client, $job_id, Budget $budget, $untouched ) {
		$allow = max( 2, (int) floor( $budget->time_left() - 3 ) );
		$allow = min( $allow, 30 );
		$out   = $this->call(
			$client,
			'job_tick',
			array(
				'job_id' => (int) $job_id,
				'budget' => $allow,
			),
			$untouched,
			$allow + 30
		);
		if ( ! empty( $out['locked'] ) ) {
			usleep( 700000 );
		}
		return $out;
	}

	/**
	 * Remote job failed: say so with the other site's message.
	 *
	 * @param array  $report    Report.
	 * @param string $untouched What stays unchanged.
	 * @return TaskException
	 */
	protected function remote_job_failed( array $report, $untouched ) {
		$message = (string) ( $report['job']['message'] ?? '' );
		return new TaskException(
			sprintf(
				/* translators: %s: message from the other site */
				__( 'The other site stopped the job: %s', 'estasoft-migrate' ),
				'' !== $message ? $message : __( 'no reason was given.', 'estasoft-migrate' )
			),
			array(
				'untouched' => $untouched,
				'advice'    => __( 'Open the Logs screen on the other site for details, fix the cause and start again.', 'estasoft-migrate' ),
			)
		);
	}

	/**
	 * Best-effort call used while cleaning up: never throws.
	 *
	 * @param Client $client  Client.
	 * @param string $action  Action.
	 * @param array  $payload Values.
	 * @return void
	 */
	protected function quietly( Client $client, $action, array $payload ) {
		try {
			$client->call( $action, $payload, 20, 1 );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $e );
		}
	}

	/**
	 * Name under which delta-sync baselines for the other site are stored.
	 *
	 * @param string $url Address of the other site.
	 * @return string
	 */
	public static function peer_id( $url ) {
		return 'remote:' . substr( hash( 'sha256', strtolower( rtrim( (string) $url, '/' ) ) ), 0, 16 );
	}
}
