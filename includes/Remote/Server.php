<?php
/**
 * Answers requests from another site that holds this site's connection key.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Engine\JobConflicts;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\JobRunner;
use Estasoft\Migrate\Engine\SiteInfo;
use Estasoft\Migrate\Package\Crypto;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Settings;
use Estasoft\Migrate\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Pure PHP: gets the request pieces, returns the response pieces. The WordPress glue
 * (reading php://input, printing the answer) lives in Endpoint.
 *
 * Whoever holds the connection key can replace this site's database, so every request goes through
 * the same checks, in this order: receiving is switched on, the caller is not blocked, https if
 * required, the address is allowed, the key is known, the clock is close, the signature matches
 * the exact body and the one-time value was not used before.
 */
class Server {

	const MAX_PART = 4194304;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Job runner.
	 *
	 * @var JobRunner
	 */
	private $runner;

	/**
	 * Services.
	 *
	 * @var JobContext
	 */
	private $ctx;

	/**
	 * Nonce memory.
	 *
	 * @var Nonces
	 */
	private $nonces;

	/**
	 * Clock override for tests.
	 *
	 * @var callable|null
	 */
	public $clock = null;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings.
	 * @param JobRunner  $runner   Runner.
	 * @param JobContext $ctx      Context.
	 * @param Nonces     $nonces   Nonce memory.
	 */
	public function __construct( Settings $settings, JobRunner $runner, JobContext $ctx, Nonces $nonces ) {
		$this->settings = $settings;
		$this->runner   = $runner;
		$this->ctx      = $ctx;
		$this->nonces   = $nonces;
	}

	/**
	 * Current time.
	 *
	 * @return int
	 */
	private function now() {
		return $this->clock ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Handle one request.
	 *
	 * @param string               $action  Action name.
	 * @param string               $body    Raw body.
	 * @param array<string,string> $headers Request headers, names in lower case.
	 * @param string               $ip      Caller address.
	 * @param bool                 $https   Was the request made over https.
	 * @return array{status:int, body:string, headers:array<string,string>}
	 */
	public function handle( $action, $body, array $headers, $ip, $https ) {
		$matched = null;
		$nonce   = (string) ( $headers['x-estm-nonce'] ?? '' );
		try {
			$matched = $this->authenticate( $action, $body, $headers, $ip, $https );
			if ( in_array( $action, array( 'put_init', 'import_start', 'export_start', 'drop_package' ), true ) ) {
				// A trace of who started something on this site: the caller's address and which of the keys was used.
				$this->ctx->logger->info(
					sprintf(
						'Incoming request %1$s from %2$s with key %3$s.',
						$action,
						$ip,
						Settings::key_id( $matched )
					)
				);
			}
			$payload = '' === $body ? array() : json_decode( $body, true );
			if ( ! is_array( $payload ) ) {
				throw new RemoteException( 'estm_invalid', __( 'The request could not be read.', 'estasoft-migrate' ), 400 );
			}
			$data = $this->dispatch( $action, $payload, $matched );
			return $this->reply( 200, array_merge( array( 'ok' => true ), $data ), $matched, $nonce );
		} catch ( RemoteException $e ) {
			if ( in_array( $e->error_code, array( 'estm_bad_key', 'estm_bad_sig', 'estm_ip', 'estm_replay' ), true ) ) {
				$this->ctx->logger->warning( sprintf( 'Incoming request %1$s from %2$s was refused (%3$s).', $action, $ip, $e->error_code ) );
			}
			$out = array(
				'ok'      => false,
				'code'    => $e->error_code,
				'message' => $e->getMessage(),
			);
			if ( $e->data ) {
				$out['data'] = $e->data;
			}
			if ( $e->retryable ) {
				$out['retry'] = true;
			}
			return $this->reply( $e->status ? $e->status : 400, $out, $matched, $nonce );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->error( 'Remote request failed: ' . $e->getMessage(), 0, array( 'action' => $action ) );
			return $this->reply(
				500,
				array(
					'ok'      => false,
					'code'    => 'estm_failed',
					'message' => __( 'The other site could not complete the request.', 'estasoft-migrate' ),
					'retry'   => false,
				),
				$matched,
				$nonce
			);
		}
	}

	/**
	 * Build the response; it is signed whenever the caller proved they know the key.
	 *
	 * @param int         $status HTTP status.
	 * @param array       $data   Values.
	 * @param string|null $key    Key that authenticated the request.
	 * @param string      $nonce  Request nonce.
	 * @return array
	 */
	private function reply( $status, array $data, $key, $nonce ) {
		$body    = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
		if ( null !== $key && '' !== $nonce ) {
			$headers['X-Estm-Resp-Sig'] = Signer::response_signature( $key, $nonce, $body );
		}
		return array(
			'status'  => $status,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Run the checks. Returns the connection key that matched.
	 *
	 * @param string $action  Action.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @param string $ip      Address.
	 * @param bool   $https   Over https.
	 * @return string
	 * @throws RemoteException When any check fails.
	 */
	private function authenticate( $action, $body, array $headers, $ip, $https ) {
		if ( ! $this->settings->incoming_open() ) {
			throw new RemoteException( 'estm_off', __( 'This site is not accepting migrations. Switch on "Accept incoming migrations" in its Settings.', 'estasoft-migrate' ), 403 );
		}
		if ( $this->nonces->blocked( $ip ) ) {
			throw new RemoteException( 'estm_rate', __( 'Too many failed attempts. Try again in a few minutes.', 'estasoft-migrate' ), 429 );
		}
		if ( $this->settings->get( 'require_https', true ) && ! $https ) {
			throw new RemoteException( 'estm_https', __( 'This site only accepts migrations over a secure (https) connection.', 'estasoft-migrate' ), 403 );
		}
		if ( ! IpMatcher::matches( $ip, (string) $this->settings->get( 'ip_allowlist', '' ) ) ) {
			throw new RemoteException(
				'estm_ip',
				sprintf(
					/* translators: %s: IP address */
					__( 'This site does not accept migrations from the address %s. Add it to the allowed addresses in Settings, or clear that list.', 'estasoft-migrate' ),
					$ip
				),
				403
			);
		}
		if ( (int) ( $headers['x-estm-version'] ?? 0 ) !== Signer::VERSION ) {
			throw new RemoteException( 'estm_protocol', __( 'The two sites run versions of Estasoft Migration that cannot talk to each other. Update the plugin on both.', 'estasoft-migrate' ), 400 );
		}

		$key_id = (string) ( $headers['x-estm-key'] ?? '' );
		$found  = null;
		foreach ( $this->settings->valid_keys() as $key ) {
			if ( hash_equals( Settings::key_id( $key ), $key_id ) ) {
				$found = $key;
				break;
			}
		}
		if ( null === $found ) {
			$this->nonces->failure( $ip );
			throw new RemoteException( 'estm_bad_key', __( 'The connection key was not accepted.', 'estasoft-migrate' ), 401 );
		}

		$time = (string) ( $headers['x-estm-time'] ?? '' );
		if ( ! ctype_digit( $time ) || abs( $this->now() - (int) $time ) > Signer::MAX_SKEW ) {
			throw new RemoteException( 'estm_clock', __( 'The clocks of the two sites differ too much.', 'estasoft-migrate' ), 401, array( 'server_time' => $this->now() ) );
		}

		$nonce = (string) ( $headers['x-estm-nonce'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{16,64}$/', $nonce ) || ! Signer::verify_request( $found, $action, $time, $nonce, $body, (string) ( $headers['x-estm-sig'] ?? '' ) ) ) {
			$this->nonces->failure( $ip );
			throw new RemoteException( 'estm_bad_sig', __( 'The request signature did not match.', 'estasoft-migrate' ), 401 );
		}
		if ( ! $this->nonces->use_once( $nonce, Signer::MAX_SKEW * 2 ) ) {
			throw new RemoteException( 'estm_replay', __( 'This request was already received.', 'estasoft-migrate' ), 401 );
		}
		$this->settings->keep_incoming_open();
		return $found;
	}

	/**
	 * Route an action.
	 *
	 * @param string $action  Action.
	 * @param array  $payload Values.
	 * @param string $key     Connection key.
	 * @return array
	 * @throws RemoteException For unknown actions or bad values.
	 */
	private function dispatch( $action, array $payload, $key ) {
		switch ( $action ) {
			case 'hello':
				return $this->hello();
			case 'put_init':
				return $this->put_init( $payload );
			case 'put_part':
				return $this->put_part( $payload );
			case 'put_done':
				return $this->put_done( $payload );
			case 'import_start':
				return $this->import_start( $payload, $key );
			case 'export_start':
				return $this->export_start( $payload, $key );
			case 'job_tick':
				return $this->job_tick( $payload );
			case 'job_cancel':
				return $this->job_cancel( $payload );
			case 'get_part':
				return $this->get_part( $payload );
			case 'drop_package':
				return $this->drop_package( $payload );
		}
		throw new RemoteException( 'estm_unknown_action', __( 'Unknown request.', 'estasoft-migrate' ), 404 );
	}

	/**
	 * Largest part this site accepts in one request.
	 *
	 * @return int
	 */
	private function max_part() {
		$post = \Estasoft\Migrate\Util::bytes_from_ini( ini_get( 'post_max_size' ) );
		$mem  = \Estasoft\Migrate\Util::bytes_from_ini( ini_get( 'memory_limit' ) );
		$cap  = self::MAX_PART;
		if ( $post > 0 ) {
			$cap = min( $cap, (int) ( $post * 0.4 ) );
		}
		if ( $mem > 0 ) {
			$cap = min( $cap, (int) ( $mem * 0.1 ) );
		}
		return max( 65536, $cap );
	}

	/**
	 * Facts about this site and what it can do.
	 *
	 * @return array
	 */
	private function hello() {
		$stats = array(
			'tables'  => 0,
			'rows'    => 0,
			'bytes'   => 0,
			'largest' => null,
		);
		foreach ( \Estasoft\Migrate\Engine\TableSelector::select( $this->ctx->db ) as $t ) {
			++$stats['tables'];
			$stats['rows']  += (int) $t['rows_est'];
			$stats['bytes'] += (int) $t['bytes'];
			if ( null === $stats['largest'] || (int) $t['rows_est'] > $stats['largest']['rows_est'] ) {
				$stats['largest'] = array(
					'name'     => (string) $t['name'],
					'rows_est' => (int) $t['rows_est'],
				);
			}
		}
		return array(
			'stats'      => $stats,
			'protocol'   => Signer::VERSION,
			'version'    => defined( 'ESTASOFT_MIGRATE_VERSION' ) ? ESTASOFT_MIGRATE_VERSION : '',
			'time'       => $this->now(),
			'site'       => $this->ctx->site_info(),
			'max_part'   => $this->max_part(),
			'free_bytes' => (int) $this->ctx->storage->free_space(),
			'writable'   => (bool) $this->ctx->storage->is_writable(),
			'encryption' => Crypto::available(),
			'max_exec'   => (int) ini_get( 'max_execution_time' ),
			'memory'     => \Estasoft\Migrate\Util::bytes_from_ini( ini_get( 'memory_limit' ) ),
			'cron_off'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'php'        => PHP_VERSION,
		);
	}

	/**
	 * Start receiving a package.
	 *
	 * @param array $p Values.
	 * @return array
	 * @throws RemoteException On bad values or no space.
	 */
	private function put_init( array $p ) {
		$size = (int) ( $p['size'] ?? 0 );
		if ( $size < 1 ) {
			throw new RemoteException( 'estm_invalid', __( 'The package is empty.', 'estasoft-migrate' ), 400 );
		}
		if ( $size > Storage::max_package_bytes() ) {
			throw new RemoteException( 'estm_invalid', __( 'The package is larger than the other site accepts.', 'estasoft-migrate' ), 413 );
		}
		if ( ! $this->ctx->storage->is_writable() ) {
			throw new RemoteException( 'estm_no_space', __( 'The other site cannot write to its storage folder.', 'estasoft-migrate' ), 507 );
		}
		$free = $this->ctx->storage->free_space();
		if ( $free > 0 && $free < $size * 1.1 ) {
			throw new RemoteException(
				'estm_no_space',
				sprintf(
					/* translators: 1: needed space, 2: free space */
					__( 'The other site does not have enough disk space: %1$s needed, %2$s free.', 'estasoft-migrate' ),
					\Estasoft\Migrate\Util::format_bytes( $size ),
					\Estasoft\Migrate\Util::format_bytes( $free )
				),
				507
			);
		}
		$meta = (array) ( $p['meta'] ?? array() );
		$id   = Storage::new_id( 'received-' . (string) ( $meta['label'] ?? 'push' ) );
		$path = $this->ctx->storage->package_path( $id );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, '' ) ) {
			throw new RemoteException( 'estm_no_space', __( 'The other site could not create the package file.', 'estasoft-migrate' ), 507 );
		}
		$this->ctx->storage->write_meta(
			$id,
			array(
				'type'        => 'received',
				'kind'        => (string) ( $meta['kind'] ?? 'migration' ),
				'status'      => 'receiving',
				'label'       => (string) ( $meta['label'] ?? '' ),
				'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'expected'    => $size,
				'protection'  => (string) ( $meta['protection'] ?? '' ),
				'encrypted'   => ! empty( $meta['encrypted'] ),
				'sanitized'   => $meta['sanitized'] ?? null,
				'source_url'  => (string) ( $meta['source_url'] ?? '' ),
				'tables'      => (int) ( $meta['tables'] ?? 0 ),
			)
		);
		return array(
			'package_id' => $id,
			'received'   => 0,
			'max_part'   => $this->max_part(),
		);
	}

	/**
	 * Package id from a request, only if it is one we are receiving.
	 *
	 * @param array  $p      Values.
	 * @param string $status Required status, empty for any.
	 * @return string
	 * @throws RemoteException When unknown.
	 */
	private function package_id( array $p, $status = '' ) {
		$id = (string) ( $p['package_id'] ?? '' );
		if ( ! Storage::is_valid_id( $id ) || ! is_file( $this->ctx->storage->package_path( $id ) ) ) {
			throw new RemoteException( 'estm_no_package', __( 'The package was not found on the other site.', 'estasoft-migrate' ), 404 );
		}
		if ( '' !== $status ) {
			$meta = $this->ctx->storage->read_meta( $id );
			if ( ! is_array( $meta ) || ( $meta['status'] ?? '' ) !== $status ) {
				throw new RemoteException( 'estm_no_package', __( 'The package is not ready for this step.', 'estasoft-migrate' ), 409 );
			}
		}
		return $id;
	}

	/**
	 * Append a part. Sending the same part twice is harmless.
	 *
	 * @param array $p Values.
	 * @return array
	 * @throws RemoteException On bad data or a gap.
	 */
	private function put_part( array $p ) {
		$id     = $this->package_id( $p, 'receiving' );
		$offset = (int) ( $p['offset'] ?? -1 );
		$raw    = base64_decode( (string) ( $p['data'] ?? '' ), true );
		if ( false === $raw || '' === $raw || $offset < 0 ) {
			throw new RemoteException( 'estm_invalid', __( 'The part could not be read.', 'estasoft-migrate' ), 400 );
		}
		if ( strlen( $raw ) > $this->max_part() + 1024 ) {
			throw new RemoteException( 'estm_invalid', __( 'The part is too large for the other site.', 'estasoft-migrate' ), 413 );
		}
		if ( ! hash_equals( hash( 'sha256', $raw ), (string) ( $p['sha256'] ?? '' ) ) ) {
			throw new RemoteException( 'estm_bad_part', __( 'A part was damaged on the way.', 'estasoft-migrate' ), 422, array(), true );
		}

		// A part may not run past the size announced when the transfer started.
		$expected = (int) ( ( (array) $this->ctx->storage->read_meta( $id ) )['expected'] ?? 0 );
		if ( $expected > 0 && $offset + strlen( $raw ) > $expected ) {
			throw new RemoteException( 'estm_invalid', __( 'The part is larger than the package announced.', 'estasoft-migrate' ), 413 );
		}

		$path = $this->ctx->storage->package_path( $id );
		clearstatcache( true, $path );
		$have = (int) filesize( $path );
		if ( $offset > $have ) {
			throw new RemoteException( 'estm_offset', __( 'A part is missing.', 'estasoft-migrate' ), 409, array( 'received' => $have ) );
		}
		if ( $offset + strlen( $raw ) <= $have ) {
			// Already stored (the answer to an earlier try was lost). Make sure it is the same data.
			$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fseek( $fh, $offset );
			$same = fread( $fh, strlen( $raw ) ) === $raw; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( ! $same ) {
				throw new RemoteException( 'estm_offset', __( 'A part does not match what was already received.', 'estasoft-migrate' ), 409, array( 'received' => $have ) );
			}
			return array( 'received' => $have );
		}

		$fh = fopen( $path, 'cb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new RemoteException( 'estm_no_space', __( 'The other site could not write the package.', 'estasoft-migrate' ), 507 );
		}
		fseek( $fh, $offset );
		$written = fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( strlen( $raw ) !== $written ) {
			throw new RemoteException( 'estm_no_space', __( 'The other site ran out of disk space while receiving the package.', 'estasoft-migrate' ), 507 );
		}
		clearstatcache( true, $path );
		return array( 'received' => (int) filesize( $path ) );
	}

	/**
	 * Finish receiving.
	 *
	 * @param array $p Values.
	 * @return array
	 * @throws RemoteException When the size is wrong.
	 */
	private function put_done( array $p ) {
		$id   = $this->package_id( $p, 'receiving' );
		$path = $this->ctx->storage->package_path( $id );
		clearstatcache( true, $path );
		$size = (int) filesize( $path );
		if ( (int) ( $p['size'] ?? -1 ) !== $size ) {
			throw new RemoteException( 'estm_offset', __( 'The package arrived incomplete.', 'estasoft-migrate' ), 409, array( 'received' => $size ) );
		}
		$this->ctx->storage->update_meta(
			$id,
			array(
				'status' => 'complete',
				'size'   => $size,
			)
		);
		return array(
			'package_id' => $id,
			'size'       => $size,
		);
	}

	/**
	 * Create a job on behalf of the caller.
	 *
	 * @param string $type   Job type.
	 * @param string $title  Title.
	 * @param array  $params Parameters.
	 * @return Job
	 */
	private function create_job( $type, $title, array $params ) {
		$params['_remote'] = true;
		$busy              = JobConflicts::find( $this->runner_store()->active(), $type, $params );
		if ( $busy ) {
			throw new RemoteException(
				'estm_busy',
				sprintf(
					/* translators: %s: title of the job that is running */
					__( 'Another job is still in progress on this site: %s.', 'estasoft-migrate' ),
					$busy->title
				),
				409
			);
		}
		return $this->runner->create( $type, $title, $params );
	}

	/**
	 * Start importing a received package.
	 *
	 * @param array  $p   Values.
	 * @param string $key Connection key.
	 * @return array
	 * @throws RemoteException When the package or password is wrong.
	 */
	private function import_start( array $p, $key ) {
		$id     = $this->package_id( $p, 'complete' );
		$params = array(
			'package_id' => $id,
			'snapshot'   => ! isset( $p['snapshot'] ) || $p['snapshot'],
			'rewrite'    => ! isset( $p['rewrite'] ) || $p['rewrite'],
			'kind'       => 'import',
		);
		if ( ! empty( $p['passphrase_sealed'] ) ) {
			$plain = Secrets::open( (string) $p['passphrase_sealed'], $key );
			if ( '' === $plain ) {
				throw new RemoteException( 'estm_invalid', __( 'The package password could not be read.', 'estasoft-migrate' ), 400 );
			}
			// Kept encrypted with this site's own secret while the job runs.
			$params['passphrase_enc'] = Secrets::seal( $plain, $this->ctx->secret );
		}
		if ( isset( $p['safety'] ) && is_array( $p['safety'] ) ) {
			$params['safety'] = array_intersect_key( array_map( 'boolval', $p['safety'] ), array_flip( Profiles::SAFETY ) );
		}
		if ( ! empty( $p['replace']['pairs'] ) && is_array( $p['replace']['pairs'] ) ) {
			$params['replace'] = array( 'pairs' => $this->clean_pairs( $p['replace']['pairs'] ) );
		}
		$job = $this->create_job( 'import', __( 'Import received package', 'estasoft-migrate' ), $params );
		return array( 'job_id' => $job->id );
	}

	/**
	 * Start an export that the caller will download.
	 *
	 * @param array  $p   Values.
	 * @param string $key Connection key.
	 * @return array
	 */
	private function export_start( array $p, $key ) {
		$params = array(
			'kind'  => 'migration',
			'label' => 'pull',
		);
		foreach ( array( 'tables', 'exclude_tables' ) as $list ) {
			if ( ! empty( $p[ $list ] ) && is_array( $p[ $list ] ) ) {
				$params[ $list ] = array_values( array_filter( array_map( 'strval', $p[ $list ] ), array( '\\Estasoft\\Migrate\\Db\\Identifier', 'is_safe' ) ) );
			}
		}
		if ( ! empty( $p['replace']['pairs'] ) && is_array( $p['replace']['pairs'] ) ) {
			$params['replace'] = array(
				'pairs'          => $this->clean_pairs( $p['replace']['pairs'] ),
				'regex'          => ! empty( $p['replace']['regex'] ),
				'case_sensitive' => ! isset( $p['replace']['case_sensitive'] ) || $p['replace']['case_sensitive'],
			);
		}
		if ( ! empty( $p['sanitize'] ) && is_array( $p['sanitize'] ) ) {
			$s                  = $p['sanitize'];
			$params['sanitize'] = Profiles::build_config( (string) ( $s['profile'] ?? 'staging' ), (array) ( $s['overrides'] ?? array() ) );
			$params['kind']     = 'sanitize';
		}

		$sealed = '';
		if ( Crypto::available() ) {
			$pass                     = Settings::base64url( random_bytes( 24 ) );
			$params['passphrase_enc'] = Secrets::seal( $pass, $this->ctx->secret );
			$sealed                   = Secrets::seal( $pass, $key );
		}
		$job = $this->create_job( 'export', __( 'Export for another site', 'estasoft-migrate' ), $params );
		return array(
			'job_id'            => $job->id,
			'passphrase_sealed' => $sealed,
		);
	}

	/**
	 * Replacement pairs from a request, as plain text only.
	 *
	 * @param array $pairs Raw pairs.
	 * @return array
	 */
	private function clean_pairs( array $pairs ) {
		$out = array();
		foreach ( array_slice( $pairs, 0, 200 ) as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0] ) && '' !== (string) $pair[0] ) {
				$out[] = array( (string) $pair[0], (string) ( $pair[1] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * Find a job that was started by a remote caller.
	 *
	 * @param array $p Values.
	 * @return Job
	 * @throws RemoteException When it does not exist or belongs to someone else.
	 */
	private function remote_job( array $p ) {
		$job = $this->runner_store()->find( (int) ( $p['job_id'] ?? 0 ) );
		if ( ! $job || empty( $job->params['_remote'] ) ) {
			throw new RemoteException( 'estm_no_job', __( 'The job was not found on the other site.', 'estasoft-migrate' ), 404 );
		}
		return $job;
	}

	/**
	 * Job store.
	 *
	 * @return \Estasoft\Migrate\Engine\JobStore
	 */
	private function runner_store() {
		return $this->ctx->store;
	}

	/**
	 * Work on a job for a while and report.
	 *
	 * @param array $p Values.
	 * @return array
	 */
	private function job_tick( array $p ) {
		$job     = $this->remote_job( $p );
		$seconds = (int) $this->settings->get( 'slice_seconds', 15 );
		if ( isset( $p['budget'] ) ) {
			$seconds = max( 2, min( $seconds, (int) $p['budget'] ) );
		}
		$result           = $this->runner->run_slice( $job->id, Budget::for_request( $seconds ), 'remote' );
		$report           = $this->job_report( $this->runner_store()->find( $job->id ) );
		$report['locked'] = ! empty( $result['locked'] );
		return $report;
	}

	/**
	 * Cancel a job.
	 *
	 * @param array $p Values.
	 * @return array
	 */
	private function job_cancel( array $p ) {
		$job = $this->remote_job( $p );
		$this->runner->cancel( $job->id );
		return $this->job_report( $this->runner_store()->find( $job->id ) );
	}

	/**
	 * What the caller needs to know about a job.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	private function job_report( Job $job ) {
		$out = array(
			'job' => array(
				'id'       => $job->id,
				'type'     => $job->type,
				'status'   => $job->status,
				'progress' => round( (float) $job->progress, 1 ),
				'message'  => (string) $job->message,
			),
		);
		if ( 'export' === $job->type && Job::COMPLETED === $job->status && ! empty( $job->state['package'] ) ) {
			$id   = (string) $job->state['package'];
			$path = $this->ctx->storage->package_path( $id );
			clearstatcache( true, $path );
			$out['package'] = array(
				'id'   => $id,
				'size' => is_file( $path ) ? (int) filesize( $path ) : 0,
				'meta' => $this->ctx->storage->read_meta( $id ),
			);
		}
		if ( in_array( $job->status, array( Job::FAILED, Job::CANCELLED ), true ) ) {
			$out['job']['error'] = (string) $job->message;
		}
		return $out;
	}

	/**
	 * Hand out a piece of a package that this site exported for the caller.
	 *
	 * @param array $p Values.
	 * @return array
	 * @throws RemoteException When the package is not one that may be downloaded.
	 */
	private function get_part( array $p ) {
		$id   = $this->package_id( $p );
		$meta = $this->ctx->storage->read_meta( $id );
		if ( ! is_array( $meta ) || ! in_array( (string) ( $meta['kind'] ?? '' ), array( 'migration', 'sanitize' ), true ) || 'export' !== ( $meta['type'] ?? '' ) ) {
			// Only packages made for a remote pull can be downloaded, never a snapshot or a local backup.
			throw new RemoteException( 'estm_no_package', __( 'This package is not available for download.', 'estasoft-migrate' ), 403 );
		}
		$offset = max( 0, (int) ( $p['offset'] ?? 0 ) );
		$length = max( 1, min( $this->max_part(), (int) ( $p['length'] ?? $this->max_part() ) ) );
		$path   = $this->ctx->storage->package_path( $id );
		clearstatcache( true, $path );
		$size = (int) filesize( $path );
		$fh   = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $fh, $offset );
		$raw = $offset < $size ? (string) fread( $fh, $length ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'data'   => base64_encode( $raw ),
			'sha256' => hash( 'sha256', $raw ),
			'size'   => $size,
			'eof'    => $offset + strlen( $raw ) >= $size,
		);
	}

	/**
	 * Delete a temporary package that the caller is done with.
	 *
	 * @param array $p Values.
	 * @return array
	 */
	private function drop_package( array $p ) {
		$id   = $this->package_id( $p );
		$meta = $this->ctx->storage->read_meta( $id );
		// Only transfer packages (received pushes and exports made for a pull) can be removed this way.
		if ( is_array( $meta ) && ( 'received' === ( $meta['type'] ?? '' ) || in_array( (string) ( $meta['kind'] ?? '' ), array( 'migration', 'sanitize' ), true ) ) ) {
			$this->ctx->storage->delete( $id );
			return array( 'deleted' => true );
		}
		return array( 'deleted' => false );
	}
}
