<?php
/**
 * Bring another site here.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine\Tasks;

use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\TaskException;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Storage;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Pull = the other site exports a package, this site downloads it in resumable parts and imports it.
 *
 * Phases: remote export, download, import, finish.
 *
 * Parameters: remote_url, key_enc, tables, exclude_tables, replace, sanitize ({profile, overrides}, applied
 * on the other site before anything leaves it), snapshot (default true), safety, rewrite, keep_package.
 */
class PullTask extends RemoteTask {

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

		$site = (array) ( $hello['site'] ?? array() );
		if ( ! $ctx->storage->is_writable() ) {
			throw new TaskException(
				__( 'The storage folder is not writable, so the package cannot be downloaded.', 'estasoft-migrate' ),
				array(
					'untouched' => __( 'Nothing was changed.', 'estasoft-migrate' ),
					'advice'    => __( 'Make wp-content/uploads writable by the web server, then try again.', 'estasoft-migrate' ),
				)
			);
		}

		$state['phase']    = 'export';
		$state['remote']   = array(
			'url'      => (string) ( $site['url'] ?? $client->base() ),
			'max_part' => (int) ( $hello['max_part'] ?? self::PART ),
		);
		$state['export']   = array(
			'job_id'  => 0,
			'status'  => '',
			'percent' => 0,
			'message' => '',
			'size'    => 0,
			'id'      => '',
		);
		$state['download'] = array(
			'package' => Storage::new_id( 'pulled-' . ( $params['label'] ?? 'site' ) ),
			'offset'  => 0,
			'size'    => 0,
			'resyncs' => 0,
		);
		$state['_pass']    = '';
		$state['import']   = array();
		$ctx->checkpoint();
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
					$this->remote_export( $params, $state, $budget, $ctx );
					break;
				case 'download':
					$this->download( $params, $state, $budget, $ctx );
					break;
				case 'import':
					$this->import( $params, $state, $budget, $ctx );
					break;
				case 'finish':
					$this->finish( $params, $state, $ctx );
					break;
			}
		}
		return true;
	}

	/**
	 * Parameters of the nested import.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	private function import_params( array $params, array $state ) {
		$out = array(
			'package_id' => $state['download']['package'],
			'snapshot'   => ! isset( $params['snapshot'] ) || $params['snapshot'],
			'rewrite'    => ! isset( $params['rewrite'] ) || $params['rewrite'],
			'kind'       => 'import',
		);
		if ( ! empty( $state['_pass'] ) ) {
			$out['passphrase_enc'] = $state['_pass'];
		}
		foreach ( array( 'safety', 'collation', 'started_by' ) as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$out[ $key ] = $params[ $key ];
			}
		}
		return $out;
	}

	/**
	 * The other site builds the package.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the other site fails.
	 */
	private function remote_export( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$client    = $this->client( $params, $ctx );
		$untouched = __( 'Your site was not changed. Nothing was changed on the other site either.', 'estasoft-migrate' );
		$exp       = &$state['export'];

		if ( ! $exp['job_id'] ) {
			$payload       = array_intersect_key( $params, array_flip( array( 'tables', 'exclude_tables', 'replace', 'sanitize' ) ) );
			$res           = $this->call( $client, 'export_start', $payload, $untouched );
			$exp['job_id'] = (int) $res['job_id'];
			if ( ! empty( $res['passphrase_sealed'] ) ) {
				$plain = Secrets::open( (string) $res['passphrase_sealed'], $client->key() );
				if ( '' === $plain ) {
					throw new TaskException( __( 'The package password sent by the other site could not be read.', 'estasoft-migrate' ), array( 'untouched' => $untouched ) );
				}
				$state['_pass'] = Secrets::seal( $plain, $ctx->secret );
			}
			$ctx->log( 'info', 'The other site started building the package.', array( 'remote_job' => $exp['job_id'] ) );
			$ctx->checkpoint();
		}

		while ( true ) {
			if ( $budget->exhausted() || $ctx->should_stop() ) {
				return;
			}
			$report         = $this->tick( $client, $exp['job_id'], $budget, $untouched );
			$job            = (array) $report['job'];
			$exp['status']  = (string) $job['status'];
			$exp['percent'] = (float) $job['progress'];
			$exp['message'] = (string) $job['message'];

			if ( 'completed' === $job['status'] ) {
				if ( empty( $report['package']['id'] ) || empty( $report['package']['size'] ) ) {
					throw new TaskException( __( 'The other site finished but did not report a package.', 'estasoft-migrate' ), array( 'untouched' => $untouched ) );
				}
				$exp['id']                 = (string) $report['package']['id'];
				$exp['size']               = (int) $report['package']['size'];
				$state['download']['size'] = $exp['size'];
				$meta                      = (array) ( $report['package']['meta'] ?? array() );
				$ctx->storage->write_meta(
					$state['download']['package'],
					array(
						'type'        => 'received',
						'kind'        => (string) ( $meta['kind'] ?? 'migration' ),
						'status'      => 'receiving',
						'label'       => (string) ( $params['label'] ?? 'pull' ),
						'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
						'expected'    => $exp['size'],
						'protection'  => (string) ( $meta['protection'] ?? '' ),
						'encrypted'   => ! empty( $meta['encrypted'] ),
						'sanitized'   => $meta['sanitized'] ?? null,
						'source_url'  => (string) ( $meta['source_url'] ?? $state['remote']['url'] ),
						'tables'      => (int) ( $meta['tables'] ?? 0 ),
					)
				);
				$state['phase'] = 'download';
				$ctx->log( 'info', sprintf( 'The other site finished the package (%s). Downloading.', Util::format_bytes( $exp['size'] ) ) );
				$ctx->checkpoint();
				return;
			}
			if ( in_array( $job['status'], array( 'failed', 'cancelled' ), true ) ) {
				// A retry asks the other site to start a new export.
				$exp['job_id'] = 0;
				$exp['status'] = '';
				$ctx->checkpoint();
				throw $this->remote_job_failed( $report, $untouched );
			}
			$ctx->checkpoint();
		}
	}

	/**
	 * Download in parts. Every part is checked on arrival; the next try starts at the saved position.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException On failure.
	 */
	private function download( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$client    = $this->client( $params, $ctx );
		$untouched = __( 'Your site was not changed.', 'estasoft-migrate' );
		$dl        = &$state['download'];
		$path      = $ctx->storage->package_path( $dl['package'] );
		$part      = max( 65536, min( (int) ( $params['part_size'] ?? self::PART ), (int) $state['remote']['max_part'] ) );

		$fh = fopen( $path, is_file( $path ) ? 'c+b' : 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new TaskException( __( 'The package file could not be created.', 'estasoft-migrate' ), array( 'untouched' => $untouched ) );
		}
		try {
			while ( $dl['offset'] < $dl['size'] ) {
				if ( $budget->exhausted() || $ctx->should_stop() ) {
					return;
				}
				try {
					$res = $client->call(
						'get_part',
						array(
							'package_id' => $state['export']['id'],
							'offset'     => $dl['offset'],
							'length'     => $part,
						),
						90
					);
				} catch ( RemoteException $e ) {
					throw $this->wrap( $e, $untouched );
				}
				$raw = base64_decode( (string) $res['data'], true );
				if ( false === $raw || ! hash_equals( hash( 'sha256', $raw ), (string) $res['sha256'] ) ) {
					throw new TaskException( __( 'A part of the package was damaged on the way.', 'estasoft-migrate' ), array( 'untouched' => $untouched ), true );
				}
				if ( '' === $raw ) {
					throw new TaskException( __( 'The other site sent an empty part.', 'estasoft-migrate' ), array( 'untouched' => $untouched ), true );
				}
				if ( (int) $res['size'] !== (int) $dl['size'] ) {
					throw new TaskException( __( 'The package on the other site changed while it was being downloaded.', 'estasoft-migrate' ), array( 'untouched' => $untouched ) );
				}
				fseek( $fh, $dl['offset'] );
				if ( strlen( $raw ) !== fwrite( $fh, $raw ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					throw new TaskException( __( 'This site ran out of disk space while downloading the package.', 'estasoft-migrate' ), array( 'untouched' => $untouched ) );
				}
				fflush( $fh );
				$dl['offset'] += strlen( $raw );
				$ctx->checkpoint();
			}
			ftruncate( $fh, (int) $dl['size'] );
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$ctx->storage->update_meta(
			$dl['package'],
			array(
				'status' => 'complete',
				'size'   => (int) $dl['size'],
			)
		);
		$this->quietly( $client, 'drop_package', array( 'package_id' => $state['export']['id'] ) );
		$state['export']['id'] = '';

		// Import the downloaded package with the normal import.
		$import_params = $this->import_params( $params, $state );
		$sub           = array();
		( new ImportTask() )->prepare( $import_params, $sub, $ctx );
		$state['import'] = $sub;
		$state['phase']  = 'import';
		$ctx->log( 'info', 'Download finished and checked. Importing.' );
		$ctx->checkpoint();
	}

	/**
	 * Import the downloaded package.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	private function import( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$task = new ImportTask();
		if ( $task->step( $this->import_params( $params, $state ), $state['import'], $budget, $ctx ) ) {
			$task->complete( $this->import_params( $params, $state ), $state['import'], $ctx );
			$state['phase'] = 'finish';
		}
		$ctx->checkpoint();
	}

	/**
	 * Remove the downloaded copy.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	private function finish( array $params, array &$state, JobContext $ctx ) {
		if ( empty( $params['keep_package'] ) ) {
			$ctx->storage->delete( $state['download']['package'] );
		}
		$state['phase'] = 'done';
		$ctx->log(
			'info',
			sprintf(
				/* translators: %s: address */
				__( 'Pulled %s into this site.', 'estasoft-migrate' ),
				$state['remote']['url']
			)
		);
		$ctx->checkpoint();
	}

	/**
	 * Undo what a cancelled job left behind.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function cleanup( array $params, array &$state, JobContext $ctx ) {
		try {
			$client = $this->client( $params, $ctx );
			if ( ! empty( $state['export']['job_id'] ) && ! in_array( $state['export']['status'] ?? '', array( 'completed', 'failed' ), true ) ) {
				$this->quietly( $client, 'job_cancel', array( 'job_id' => (int) $state['export']['job_id'] ) );
			}
			if ( ! empty( $state['export']['id'] ) ) {
				$this->quietly( $client, 'drop_package', array( 'package_id' => $state['export']['id'] ) );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $e );
		}
		if ( ! empty( $state['import'] ) && 'done' !== ( $state['phase'] ?? '' ) ) {
			( new ImportTask() )->cleanup( $this->import_params( $params, $state ), $state['import'], $ctx );
		}
		if ( ! empty( $state['download']['package'] ) && 'done' !== ( $state['phase'] ?? '' ) ) {
			$ctx->storage->delete( $state['download']['package'] );
		}
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
				return 0.35 * (float) $state['export']['percent'];
			case 'download':
				$size = max( 1, (int) $state['download']['size'] );
				return 35 + 20 * min( 1, $state['download']['offset'] / $size );
			case 'import':
				return 55 + 0.44 * ( new ImportTask() )->progress( $this->import_params( $params, $state ), (array) $state['import'] );
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
				/* translators: 1: address, 2: what the other site is doing */
				return sprintf( __( '%1$s is building the package. %2$s', 'estasoft-migrate' ), $url, (string) ( $state['export']['message'] ?? '' ) );
			case 'download':
				/* translators: 1: received, 2: total */
				return sprintf( __( 'Downloading the package: %1$s of %2$s.', 'estasoft-migrate' ), Util::format_bytes( $state['download']['offset'] ), Util::format_bytes( $state['download']['size'] ) );
			case 'import':
				return ( new ImportTask() )->describe( $this->import_params( $params, $state ), (array) $state['import'] );
			case 'finish':
				return __( 'Finishing up.', 'estasoft-migrate' );
			case 'done':
				/* translators: %s: address */
				return sprintf( __( 'Pulled %s.', 'estasoft-migrate' ), $url );
		}
		return '';
	}

	/**
	 * Per-table progress while importing.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	public function tables( array $params, array $state ) {
		return ! empty( $state['import'] ) ? ( new ImportTask() )->tables( $this->import_params( $params, $state ), (array) $state['import'] ) : array();
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
			'export'   => __( 'The other site builds the package', 'estasoft-migrate' ),
			'download' => __( 'Download it', 'estasoft-migrate' ),
			'import'   => __( 'Import it here', 'estasoft-migrate' ),
			'finish'   => __( 'Finish up', 'estasoft-migrate' ),
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
