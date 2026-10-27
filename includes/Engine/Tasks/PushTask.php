<?php
/**
 * Send this site to another site.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine\Tasks;

use Estasoft\Migrate\Engine\Baseline;
use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\TaskException;
use Estasoft\Migrate\Package\Crypto;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Remote\Client;
use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Settings;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Push = export a package here, upload it in resumable parts, let the other site import it.
 *
 * Phases: connect, export, upload, import, finish.
 *
 * Parameters: remote_url, key_enc (see RemoteTask::seal_params), the export parameters (tables,
 * exclude_tables, replace, sanitize, label), and for the other site: snapshot (default true),
 * safety, rewrite. keep_package keeps the local copy of the package.
 */
class PushTask extends RemoteTask {

	const PHASES = array( 'connect', 'export', 'upload', 'import', 'finish', 'done' );

	/**
	 * Check the other site and plan the work.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function prepare( array $params, array &$state, JobContext $ctx ) {
		$client = $this->client( $params, $ctx );
		$hello  = $this->connect( $client, $params, $ctx );

		$state['phase']  = 'export';
		$state['remote'] = array(
			'url'      => (string) ( $hello['site']['url'] ?? $client->base() ),
			'max_part' => (int) ( $hello['max_part'] ?? self::PART ),
			'free'     => (int) ( $hello['free_bytes'] ?? 0 ),
		);

		// A one-time password protects the package on the way and in the other site's storage.
		$state['_pass'] = '';
		if ( Crypto::available() ) {
			$state['_pass'] = Secrets::seal( Settings::base64url( random_bytes( 24 ) ), $ctx->secret );
		}

		$export = $this->export_params( $params, $state );
		$sub    = array();
		( new ExportTask() )->prepare( $export, $sub, $ctx );
		$state['export']     = $sub;
		$state['package']    = $sub['package'];
		$state['upload']     = array(
			'remote_id' => '',
			'offset'    => 0,
			'size'      => 0,
			'restarts'  => 0,
			'resyncs'   => 0,
		);
		$state['import']     = array(
			'job_id'  => 0,
			'status'  => '',
			'percent' => 0,
			'message' => '',
		);
		$state['started_at'] = time();
		$ctx->checkpoint();
	}

	/**
	 * Parameters of the nested export.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	private function export_params( array $params, array $state ) {
		$keys            = array( 'tables', 'exclude_tables', 'replace', 'sanitize', 'compress' );
		$export          = array_intersect_key( $params, array_flip( $keys ) );
		$export['kind']  = ! empty( $params['sanitize'] ) ? 'sanitize' : 'migration';
		$export['label'] = (string) ( $params['label'] ?? 'push' );
		$export['peer']  = self::peer_id( (string) $params['remote_url'] );
		if ( ! empty( $state['_pass'] ) ) {
			$export['passphrase_enc'] = $state['_pass'];
		}
		if ( ! empty( $state['package'] ) ) {
			$export['package_id'] = $state['package'];
		}
		return $export;
	}

	/**
	 * Work through the phases.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return bool
	 */
	public function step( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		while ( 'done' !== $state['phase'] ) {
			if ( $budget->expired() || $ctx->should_stop() ) {
				return false;
			}
			switch ( $state['phase'] ) {
				case 'export':
					$this->export( $params, $state, $budget, $ctx );
					break;
				case 'upload':
					$this->upload( $params, $state, $budget, $ctx );
					break;
				case 'import':
					$this->remote_import( $params, $state, $budget, $ctx );
					break;
				case 'finish':
					$this->finish( $params, $state, $ctx );
					break;
			}
		}
		return true;
	}

	/**
	 * Build the package.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	private function export( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$task   = new ExportTask();
		$export = $this->export_params( $params, $state );
		if ( $task->step( $export, $state['export'], $budget, $ctx ) ) {
			$path = $ctx->storage->package_path( $state['package'] );
			clearstatcache( true, $path );
			$state['upload']['size'] = (int) filesize( $path );
			$state['phase']          = 'upload';
			$ctx->log(
				'info',
				sprintf(
					/* translators: 1: row count, 2: package size */
					__( 'Package ready: %1$s rows, %2$s. Sending it now.', 'estasoft-migrate' ),
					Util::number( $state['export']['rows'] ),
					Util::format_bytes( $state['upload']['size'] )
				)
			);
		}
		$ctx->checkpoint();
	}

	/**
	 * Send the package in parts. A part that was already received is skipped, so a stopped upload continues where it was.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException On failure.
	 */
	private function upload( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$client    = $this->client( $params, $ctx );
		$untouched = __( 'The other site was not changed. Your site was not changed either.', 'estasoft-migrate' );
		$up        = &$state['upload'];
		$path      = $ctx->storage->package_path( $state['package'] );
		$part      = max( 65536, min( (int) ( $params['part_size'] ?? self::PART ), (int) $state['remote']['max_part'] ) );

		if ( '' === $up['remote_id'] ) {
			$meta            = (array) $ctx->storage->read_meta( $state['package'] );
			$init            = $this->call(
				$client,
				'put_init',
				array(
					'size' => $up['size'],
					'meta' => array(
						'kind'       => (string) ( $meta['kind'] ?? 'migration' ),
						'label'      => (string) ( $params['label'] ?? 'push' ),
						'protection' => (string) ( $meta['protection'] ?? '' ),
						'encrypted'  => ! empty( $meta['encrypted'] ),
						'sanitized'  => $meta['sanitized'] ?? null,
						'source_url' => (string) ( $meta['source_url'] ?? '' ),
						'tables'     => (int) ( $meta['tables'] ?? 0 ),
					),
				),
				$untouched
			);
			$up['remote_id'] = (string) $init['package_id'];
			$up['offset']    = 0;
			$part            = max( 65536, min( $part, (int) ( $init['max_part'] ?? $part ) ) );
			$ctx->checkpoint();
		}

		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new TaskException( __( 'The package file could not be read.', 'estasoft-migrate' ), array( 'untouched' => $untouched ) );
		}
		try {
			while ( $up['offset'] < $up['size'] ) {
				if ( $budget->exhausted() || $ctx->should_stop() ) {
					return;
				}
				fseek( $fh, $up['offset'] );
				$raw = (string) fread( $fh, $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( '' === $raw ) {
					throw new TaskException( __( 'The package file ended earlier than expected.', 'estasoft-migrate' ), array( 'untouched' => $untouched ), true );
				}
				try {
					$res          = $client->call(
						'put_part',
						array(
							'package_id' => $up['remote_id'],
							'offset'     => $up['offset'],
							'data'       => base64_encode( $raw ),
							'sha256'     => hash( 'sha256', $raw ),
						),
						90
					);
					$up['offset'] = (int) $res['received'];
				} catch ( RemoteException $e ) {
					if ( 'estm_offset' === $e->error_code && isset( $e->data['received'] ) && $up['resyncs'] < 5 ) {
						// The other site has a different idea of how much arrived: carry on from its number.
						++$up['resyncs'];
						$up['offset'] = (int) $e->data['received'];
					} elseif ( 'estm_no_package' === $e->error_code && $up['restarts'] < 2 ) {
						// The other site lost the file: start sending again from the beginning.
						++$up['restarts'];
						$up['remote_id'] = '';
						$ctx->checkpoint();
						return;
					} else {
						throw $this->wrap( $e, $untouched );
					}
				}
				$ctx->checkpoint();
			}
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$this->call(
			$client,
			'put_done',
			array(
				'package_id' => $up['remote_id'],
				'size'       => $up['size'],
			),
			$untouched
		);
		$state['phase'] = 'import';
		$ctx->log( 'info', 'The other site has the whole package. Asking it to import.' );
		$ctx->checkpoint();
	}

	/**
	 * Let the other site import, and follow its progress.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the other site fails.
	 */
	private function remote_import( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$client    = $this->client( $params, $ctx );
		$untouched = __( 'Your site was not changed. The other site keeps a rollback snapshot of what it had before.', 'estasoft-migrate' );
		$imp       = &$state['import'];

		if ( ! $imp['job_id'] ) {
			$payload = array(
				'package_id' => $state['upload']['remote_id'],
				'snapshot'   => ! isset( $params['snapshot'] ) || $params['snapshot'],
				'rewrite'    => ! isset( $params['rewrite'] ) || $params['rewrite'],
			);
			if ( ! empty( $state['_pass'] ) ) {
				$payload['passphrase_sealed'] = Secrets::seal( Secrets::open( $state['_pass'], $ctx->secret ), $client->key() );
			}
			if ( isset( $params['safety'] ) ) {
				$payload['safety'] = (array) $params['safety'];
			}
			$res           = $this->call( $client, 'import_start', $payload, $untouched );
			$imp['job_id'] = (int) $res['job_id'];
			$ctx->log( 'info', 'The other site started importing.', array( 'remote_job' => $imp['job_id'] ) );
			$ctx->checkpoint();
		}

		while ( true ) {
			if ( $budget->exhausted() || $ctx->should_stop() ) {
				return;
			}
			$report         = $this->tick( $client, $imp['job_id'], $budget, $untouched );
			$job            = (array) $report['job'];
			$imp['status']  = (string) $job['status'];
			$imp['percent'] = (float) $job['progress'];
			$imp['message'] = (string) $job['message'];
			$ctx->checkpoint();

			if ( 'completed' === $job['status'] ) {
				$state['phase'] = 'finish';
				return;
			}
			if ( in_array( $job['status'], array( 'failed', 'cancelled' ), true ) ) {
				// A retry starts a new import of the package that is already on the other site.
				$imp['job_id']  = 0;
				$imp['status']  = '';
				$imp['percent'] = 0;
				$ctx->checkpoint();
				throw $this->remote_job_failed( $report, $untouched );
			}
		}
	}

	/**
	 * Clean up and remember what the other site now has.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	private function finish( array $params, array &$state, JobContext $ctx ) {
		$client = $this->client( $params, $ctx );
		$this->quietly( $client, 'drop_package', array( 'package_id' => $state['upload']['remote_id'] ) );

		// The other site now holds exactly what was exported, so these checksums are the baseline for later updates.
		$peer = self::peer_id( (string) $params['remote_url'] );
		Baseline::promote( $ctx->db, $ctx->baselines_table(), 'tmp:' . ( $ctx->job ? $ctx->job->id : 0 ), $peer );

		if ( empty( $params['keep_package'] ) ) {
			$ctx->storage->delete( $state['package'] );
		} else {
			$ctx->storage->update_meta( $state['package'], array( 'status' => 'complete' ) );
		}
		$state['phase'] = 'done';
		$ctx->log(
			'info',
			sprintf(
				/* translators: 1: row count, 2: address */
				__( 'Pushed %1$s rows to %2$s.', 'estasoft-migrate' ),
				Util::number( $state['export']['rows'] ),
				$state['remote']['url']
			)
		);
		$ctx->checkpoint();
	}

	/**
	 * Undo what a cancelled or failed job left behind.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function cleanup( array $params, array &$state, JobContext $ctx ) {
		try {
			$client = $this->client( $params, $ctx );
			if ( ! empty( $state['import']['job_id'] ) && ! in_array( $state['import']['status'] ?? '', array( 'completed', 'failed' ), true ) ) {
				$this->quietly( $client, 'job_cancel', array( 'job_id' => (int) $state['import']['job_id'] ) );
			}
			if ( ! empty( $state['upload']['remote_id'] ) && 'done' !== $state['phase'] ) {
				$this->quietly( $client, 'drop_package', array( 'package_id' => $state['upload']['remote_id'] ) );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $e );
		}
		if ( ! empty( $state['package'] ) && 'done' !== $state['phase'] ) {
			$ctx->storage->delete( $state['package'] );
		}
		Baseline::discard( $ctx->db, $ctx->baselines_table(), 'tmp:' . ( $ctx->job ? $ctx->job->id : 0 ) );
	}

	/**
	 * Progress.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return float
	 */
	public function progress( array $params, array $state ) {
		switch ( $state['phase'] ?? '' ) {
			case 'export':
				return 0.4 * ( new ExportTask() )->progress( array(), (array) $state['export'] );
			case 'upload':
				$size = max( 1, (int) $state['upload']['size'] );
				return 40 + 20 * min( 1, $state['upload']['offset'] / $size );
			case 'import':
				return 60 + 0.39 * (float) $state['import']['percent'];
			case 'finish':
				return 99;
			case 'done':
				return 100;
		}
		return 0;
	}

	/**
	 * What is happening.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return string
	 */
	public function describe( array $params, array $state ) {
		$url = (string) ( $state['remote']['url'] ?? '' );
		switch ( $state['phase'] ?? '' ) {
			case 'export':
				return ( new ExportTask() )->describe( array(), (array) $state['export'] );
			case 'upload':
				/* translators: 1: sent, 2: total, 3: address */
				return sprintf( __( 'Sending the package to %3$s: %1$s of %2$s.', 'estasoft-migrate' ), Util::format_bytes( $state['upload']['offset'] ), Util::format_bytes( $state['upload']['size'] ), $url );
			case 'import':
				$msg = (string) ( $state['import']['message'] ?? '' );
				/* translators: 1: address, 2: what the other site is doing */
				return sprintf( __( '%1$s is importing. %2$s', 'estasoft-migrate' ), $url, $msg );
			case 'finish':
				return __( 'Finishing up.', 'estasoft-migrate' );
			case 'done':
				/* translators: %s: address */
				return sprintf( __( 'Pushed to %s.', 'estasoft-migrate' ), $url );
		}
		return '';
	}

	/**
	 * Per-table progress while exporting.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	public function tables( array $params, array $state ) {
		return isset( $state['export'] ) ? ( new ExportTask() )->tables( array(), (array) $state['export'] ) : array();
	}

	/**
	 * Steps.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	public function steps( array $params, array $state ) {
		$order = array(
			'export' => __( 'Build the package', 'estasoft-migrate' ),
			'upload' => __( 'Send it to the other site', 'estasoft-migrate' ),
			'import' => __( 'The other site imports it', 'estasoft-migrate' ),
			'finish' => __( 'Finish up', 'estasoft-migrate' ),
		);
		$phase = (string) ( $state['phase'] ?? 'export' );
		$ids   = array_keys( $order );
		$pos   = array_search( $phase, $ids, true );
		$out   = array();
		foreach ( $ids as $i => $id ) {
			$out[] = array(
				'id'     => $id,
				'label'  => $order[ $id ],
				'status' => 'done' === $phase ? 'done' : ( false === $pos ? 'pending' : ( $i < $pos ? 'done' : ( $i === $pos ? 'current' : 'pending' ) ) ),
			);
		}
		return $out;
	}
}
