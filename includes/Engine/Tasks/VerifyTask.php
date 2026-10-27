<?php
/**
 * Check that a package is complete and undamaged.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine\Tasks;

use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\Task;
use Estasoft\Migrate\Engine\TaskException;
use Estasoft\Migrate\Package\PackageException;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Storage;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Reads every block and compares its checksum. No password is needed and nothing is changed.
 *
 * Parameters: package_id.
 */
class VerifyTask extends Task {

	/**
	 * Open the package.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the package is missing.
	 */
	public function prepare( array $params, array &$state, JobContext $ctx ) {
		$id = (string) ( $params['package_id'] ?? '' );
		if ( ! Storage::is_valid_id( $id ) || ! is_file( $ctx->storage->package_path( $id ) ) ) {
			throw new TaskException( __( 'The package file could not be found.', 'estasoft-migrate' ) );
		}
		$path = $ctx->storage->package_path( $id );
		try {
			$header = PackageReader::read_header( $path );
		} catch ( PackageException $e ) {
			$this->record( $ctx, $id, false, $e->getMessage() );
			throw new TaskException( $e->getMessage(), array( 'advice' => __( 'This is not a usable package file.', 'estasoft-migrate' ) ) );
		}
		clearstatcache( true, $path );
		$state['package']   = $id;
		$state['size']      = (int) filesize( $path );
		$state['offset']    = 0;
		$state['seq']       = 0;
		$state['blocks']    = 0;
		$state['complete']  = false;
		$state['encrypted'] = $header['encrypted'];
		$state['done']      = false;
	}

	/**
	 * Check blocks until the time is up.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return bool
	 * @throws TaskException When a block is damaged.
	 */
	public function step( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$path = $ctx->storage->package_path( $state['package'] );
		try {
			$reader = PackageReader::open_for_check( $path );
			if ( $state['offset'] > 0 ) {
				$reader->seek( $state['offset'] );
			}
			while ( ! $budget->exhausted() && ! $ctx->should_stop() ) {
				$f = $reader->next( false );
				if ( null === $f ) {
					if ( ! $state['complete'] ) {
						throw new PackageException( 'The package is cut short: its closing block is missing.', PackageException::TRUNCATED );
					}
					$state['done'] = true;
					break;
				}
				if ( $f['seq'] !== $state['seq'] ) {
					throw new PackageException( sprintf( 'Block %1$d is missing or out of order (found %2$d).', $state['seq'], $f['seq'] ), PackageException::CORRUPT );
				}
				++$state['seq'];
				++$state['blocks'];
				$state['complete'] = 'E' === $f['type'];
				$state['offset']   = $f['end'];
			}
			$reader->close();
		} catch ( PackageException $e ) {
			$this->record( $ctx, $state['package'], false, $e->getMessage() );
			throw new TaskException(
				$e->getMessage(),
				array( 'advice' => __( 'Upload or download the package again.', 'estasoft-migrate' ) )
			);
		}
		$ctx->checkpoint();
		return (bool) $state['done'];
	}

	/**
	 * Store the result next to the package.
	 *
	 * @param JobContext $ctx     Services.
	 * @param string     $id      Package id.
	 * @param bool       $ok      Result.
	 * @param string     $message Details.
	 * @return void
	 */
	private function record( JobContext $ctx, $id, $ok, $message ) {
		if ( Storage::is_valid_id( $id ) && $ctx->storage->read_meta( $id ) ) {
			$ctx->storage->update_meta(
				$id,
				array(
					'verified_gmt' => Util::now(),
					'verified_ok'  => $ok,
					'verified_msg' => $message,
				)
			);
		}
	}

	/**
	 * Remember the good result.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function complete( array $params, array &$state, JobContext $ctx ) {
		$this->record( $ctx, $state['package'], true, '' );
		$ctx->log( 'info', sprintf( 'Package %1$s is intact: %2$s blocks checked.', $state['package'], Util::number( $state['blocks'] ) ) );
	}

	/**
	 * Progress.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return float
	 */
	public function progress( array $params, array $state ) {
		if ( ! empty( $state['done'] ) ) {
			return 100.0;
		}
		return min( 99.0, $state['offset'] / max( 1, $state['size'] ) * 100 );
	}

	/**
	 * Description.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return string
	 */
	public function describe( array $params, array $state ) {
		return ! empty( $state['done'] )
			? __( 'The package is complete and undamaged.', 'estasoft-migrate' )
			: __( 'Checking the package.', 'estasoft-migrate' );
	}
}
