<?php
/**
 * Exports the database to a package file.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine\Tasks;

use Estasoft\Migrate\Engine\Baseline;
use Estasoft\Migrate\Engine\BatchSizer;
use Estasoft\Migrate\Engine\Cursor;
use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\DdlTranslator;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\RowPipeline;
use Estasoft\Migrate\Engine\Task;
use Estasoft\Migrate\Engine\TableSelector;
use Estasoft\Migrate\Engine\TaskException;
use Estasoft\Migrate\Package\PackageException;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Package\PackageWriter;
use Estasoft\Migrate\Package\RowCodec;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Replace\Replacer;
use Estasoft\Migrate\Sanitizer\KeepResolver;
use Estasoft\Migrate\Sanitizer\PasswordHasher;
use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Sanitizer\RowSanitizer;
use Estasoft\Migrate\Storage;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Reads every table in small keyset-paginated batches and writes them to a package.
 *
 * Parameters
 * ----------
 * tables / exclude_tables  Which tables (default: all tables of the site).
 * kind                     export, migration, sanitize or snapshot.
 * label                    Short name used in the file name.
 * replace                  { pairs: [[from, to]], regex, case_sensitive } applied while exporting.
 * sanitize                 Sanitizer configuration (see Profiles::build_config) or absent.
 * passphrase_enc           Package password, sealed with the site secret (or passphrase in tests).
 * protect_with_site_key    Encrypt with a key derived from the site's connection key (snapshots).
 * compress                 Compress blocks (default true).
 * peer                     Name used to store delta-sync baselines.
 *
 * Progress is saved after every batch (the byte offset of the package and the last key read), so
 * the task can stop at any moment and continue exactly where it was.
 */
class ExportTask extends Task {

	/**
	 * Create the package, the manifest and the table list.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the export cannot start.
	 */
	public function prepare( array $params, array &$state, JobContext $ctx ) {
		$db     = $ctx->db;
		$prefix = $db->prefix();
		$kind   = (string) ( $params['kind'] ?? 'export' );

		if ( ! $ctx->storage->is_writable() ) {
			throw new TaskException(
				__( 'The storage folder is not writable, so the package cannot be created.', 'estasoft-migrate' ),
				array(
					'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ),
					'advice'    => __( 'Make wp-content/uploads writable by the web server, then try again.', 'estasoft-migrate' ),
				)
			);
		}

		$tables = TableSelector::select( $db, $params );
		if ( ! $tables ) {
			throw new TaskException(
				__( 'There are no tables to export.', 'estasoft-migrate' ),
				array( 'advice' => __( 'Choose at least one table.', 'estasoft-migrate' ) )
			);
		}

		$id   = ! empty( $params['package_id'] ) ? (string) $params['package_id'] : Storage::new_id( (string) ( $params['label'] ?? $kind ) );
		$path = $ctx->storage->package_path( $id );

		// Sanitizer setup happens once, so every slice (and every retry) masks values the same way.
		$state['_sanitizer'] = null;
		if ( ! empty( $params['sanitize'] ) ) {
			$config              = (array) $params['sanitize'];
			$seed                = (string) ( $config['seed'] ?? '' );
			$state['_sanitizer'] = array(
				'config'  => $config,
				'salt'    => '' !== $seed ? $seed : bin2hex( random_bytes( 16 ) ),
				'keep'    => KeepResolver::resolve( $db, $prefix, $config ),
				'pw_hash' => PasswordHasher::hash( (string) ( $config['password'] ?? '' ) ),
			);
		}

		$site     = $ctx->site_info();
		$protect  = $this->key_options( $params, $ctx );
		$pairs    = $this->pairs( $params );
		$writer   = PackageWriter::create( $path, array_merge( array( 'compress' => $params['compress'] ?? true ), $protect ) );
		$manifest = array(
			'format'     => 1,
			'created'    => Util::now(),
			'kind'       => $kind,
			'source'     => $site,
			'sanitized'  => $state['_sanitizer'] ? (string) ( $state['_sanitizer']['config']['profile'] ?? 'custom' ) : null,
			'safety'     => $state['_sanitizer'] ? (array) ( $state['_sanitizer']['config']['safety'] ?? array() ) : array(),
			'rewrite'    => $pairs,
			'protection' => $protect ? ( isset( $protect['passphrase'] ) ? 'password' : 'site-key' ) : 'none',
			'tables'     => array_map(
				static function ( $t ) {
					return array(
						'name'     => $t['name'],
						'rows_est' => $t['rows_est'],
					);
				},
				$tables
			),
		);
		$writer->frame( 'M', $manifest );
		$writer->flush();

		$sizer = new BatchSizer( $ctx->max_batch );

		$state['phase']   = 'tables';
		$state['package'] = $id;
		$state['file']    = array(
			'offset' => $writer->offset(),
			'seq'    => $writer->seq(),
		);
		$state['tables']  = array_map(
			static function ( $t ) {
				return array(
					'name'     => $t['name'],
					'logical'  => $t['logical'],
					'rows_est' => $t['rows_est'],
					'read'     => 0,
					'rows'     => 0,
					'done'     => false,
					'started'  => false,
				);
			},
			$tables
		);
		$state['index']   = 0;
		$state['cursor']  = null;
		$state['skip']    = 0;
		$state['batch']   = $sizer->export_state();
		$state['rows']    = 0;
		$state['bytes']   = 0;
		$state['notes']   = array();
		$state['masked']  = array();
		$writer->close();

		$ctx->storage->write_meta(
			$id,
			array(
				'type'        => 'snapshot' === $kind ? 'snapshot' : 'export',
				'kind'        => $kind,
				'status'      => 'writing',
				'label'       => (string) ( $params['label'] ?? '' ),
				'created_gmt' => $manifest['created'],
				'job_id'      => $ctx->job ? $ctx->job->id : 0,
				'protection'  => $manifest['protection'],
				'encrypted'   => 'none' !== $manifest['protection'],
				'sanitized'   => $manifest['sanitized'],
				'source_url'  => $site['url'] ?? '',
				'tables'      => count( $tables ),
			)
		);
		$ctx->log(
			'info',
			sprintf(
				/* translators: 1: number of tables, 2: package file id */
				_n( 'Exporting %1$d table into package %2$s.', 'Exporting %1$d tables into package %2$s.', count( $tables ), 'estasoft-migrate' ),
				count( $tables ),
				$id
			)
		);
	}

	/**
	 * Encryption options from the job parameters.
	 *
	 * @param array      $params Parameters.
	 * @param JobContext $ctx    Services.
	 * @return array passphrase or secret, or an empty array for an unprotected package.
	 */
	protected function key_options( array $params, JobContext $ctx ) {
		$pass = isset( $params['passphrase'] ) ? (string) $params['passphrase'] : '';
		if ( '' === $pass && ! empty( $params['passphrase_enc'] ) ) {
			$pass = Secrets::open( (string) $params['passphrase_enc'], $ctx->secret );
		}
		if ( '' !== $pass ) {
			return array( 'passphrase' => $pass );
		}
		if ( ! empty( $params['protect_with_site_key'] ) ) {
			if ( '' === $ctx->secret ) {
				throw $this->protection_lost();
			}
			return array( 'secret' => $ctx->secret );
		}
		if ( ! empty( $params['passphrase_enc'] ) ) {
			// A password was chosen but cannot be read back (the site's security keys changed): never fall back to an open file.
			throw $this->protection_lost();
		}
		return array();
	}

	/**
	 * The failure for a package that was meant to be protected but whose key is no longer available.
	 *
	 * @return TaskException
	 */
	private function protection_lost() {
		return new TaskException(
			__( 'The package was meant to be protected, but its password can no longer be read, so nothing was written.', 'estasoft-migrate' ),
			array(
				'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ),
				'advice'    => __( 'This happens when the security keys in wp-config.php change while a job is waiting. Start the export again.', 'estasoft-migrate' ),
			),
			false
		);
	}

	/**
	 * Replacement pairs from the job parameters.
	 *
	 * @param array $params Parameters.
	 * @return array
	 */
	protected function pairs( array $params ) {
		return array_values( (array) ( $params['replace']['pairs'] ?? array() ) );
	}

	/**
	 * Build the row pipeline.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx Services.
	 * @return RowPipeline
	 */
	private function pipeline( array $params, array $state, JobContext $ctx ) {
		$replacer = null;
		$pairs    = $this->pairs( $params );
		if ( $pairs ) {
			$replacer = new Replacer(
				$pairs,
				array(
					'regex'          => ! empty( $params['replace']['regex'] ),
					'case_sensitive' => ! isset( $params['replace']['case_sensitive'] ) || $params['replace']['case_sensitive'],
				)
			);
		}
		$sanitizer = null;
		if ( ! empty( $state['_sanitizer'] ) ) {
			$s         = $state['_sanitizer'];
			$sanitizer = new RowSanitizer( (array) $s['config'], $s['salt'], $s['pw_hash'], (array) $s['keep'] );
		}
		return new RowPipeline( $replacer, $sanitizer, '', '', (array) ( $params['replace']['skip_columns'] ?? array() ) );
	}

	/**
	 * Export until the budget is used up.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return bool
	 * @throws TaskException When a table cannot be exported.
	 */
	public function step( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		if ( 'done' === $state['phase'] ) {
			return true;
		}
		$db     = $ctx->db;
		$prefix = $db->prefix();
		$sizer  = BatchSizer::from_state( (array) $state['batch'], $ctx->max_batch );
		$path   = $ctx->storage->package_path( $state['package'] );
		$writer = PackageWriter::resume( $path, $state['file']['offset'], $state['file']['seq'], $this->key_options( $params, $ctx ) );
		$pipe   = $this->pipeline( $params, $state, $ctx );
		$peer   = 'tmp:' . ( $ctx->job ? $ctx->job->id : 0 );
		$track  = ! in_array( (string) ( $params['kind'] ?? 'export' ), array( 'snapshot', 'sanitize' ), true ) && empty( $params['sanitize'] );

		$seen_counts = array();

		try {
			while ( 'tables' === $state['phase'] ) {
				if ( $budget->exhausted() || $ctx->should_stop() ) {
					break;
				}
				$i = (int) $state['index'];
				if ( $i >= count( $state['tables'] ) ) {
					$state['phase'] = 'finish';
					break;
				}
				$entry = &$state['tables'][ $i ];
				$name  = $entry['name'];
				$pk    = $db->primary_key( $name );
				$cols  = $db->columns( $name );
				$pipe->begin_table( $entry['logical'], $cols, $pk );

				if ( ! $entry['started'] ) {
					$ddl = $db->show_create( $name );
					$writer->frame(
						'T',
						array(
							'name'     => $name,
							'logical'  => $entry['logical'],
							'ddl'      => $ddl,
							'columns'  => array_keys( $cols ),
							'pk'       => $pk,
							'rows_est' => $entry['rows_est'],
						)
					);
					if ( ! $pk && $entry['rows_est'] > 0 ) {
						$ctx->log( 'warning', sprintf( 'Table %s has no primary key, so it is read with a slower method and rows changed during the export may be missed.', $name ) );
					}
					$entry['started']  = true;
					$state['baseline'] = Baseline::start( $pk );
					$this->checkpoint_file( $state, $writer, $ctx );
				}

				$t0 = microtime( true );
				if ( $pipe->empties_table() ) {
					$rows = array();
				} else {
					$rows = $this->fetch( $ctx, $name, $pk, $state, $sizer );
				}

				if ( ! $rows ) {
					$writer->frame(
						'U',
						array(
							'name' => $name,
							'rows' => $entry['rows'],
							'read' => $entry['read'],
						)
					);
					if ( $track && isset( $state['baseline'] ) ) {
						Baseline::save( $db, $ctx->baselines_table(), $peer, $name, Baseline::result( $state['baseline'] ), $ctx->job ? $ctx->job->id : 0 );
					}
					$entry['done']   = true;
					$state['index']  = $i + 1;
					$state['cursor'] = null;
					$state['skip']   = 0;
					unset( $state['baseline'] );
					$this->checkpoint_file( $state, $writer, $ctx );
					$ctx->log( 'info', sprintf( '%1$s: %2$s rows exported.', $name, Util::number( $entry['rows'] ) ) );
					unset( $entry );
					continue;
				}

				$out = array();
				foreach ( $rows as $row ) {
					if ( $track ) {
						Baseline::add( $state['baseline'], $row );
					}
					$new = $pipe->row( $row );
					if ( null !== $new ) {
						$out[] = $new;
					}
				}

				$json = RowCodec::encode( $name, array_keys( $cols ), $out );
				$writer->raw_frame( 'R', $json );

				// Move the cursor only after the block is safely written.
				$last = end( $rows );
				if ( $pk ) {
					$state['cursor'] = Cursor::pack( $last, $pk );
				} else {
					$state['skip'] = (int) $state['skip'] + count( $rows );
				}
				$entry['read']  += count( $rows );
				$entry['rows']  += count( $out );
				$state['rows']  += count( $out );
				$state['bytes'] += strlen( $json );
				$sizer->record( count( $rows ), microtime( true ) - $t0, strlen( $json ) );
				if ( $budget->memory_pressure() ) {
					$sizer->shrink();
				}
				$state['batch'] = $sizer->export_state();
				$counts         = $pipe->sanitizer_counts();
				foreach ( $counts as $rule => $n ) {
					$state['masked'][ $rule ] = ( $state['masked'][ $rule ] ?? 0 ) + $n - ( $seen_counts[ $rule ] ?? 0 );
				}
				$seen_counts = $counts;
				$this->checkpoint_file( $state, $writer, $ctx );
				unset( $entry, $rows, $out, $json );
			}

			if ( 'finish' === $state['phase'] ) {
				$this->finish( $params, $state, $writer, $ctx );
			}
		} finally {
			$writer->close();
		}

		return 'done' === $state['phase'];
	}

	/**
	 * Read the next batch of a table.
	 *
	 * @param JobContext $ctx   Services.
	 * @param string     $name  Table.
	 * @param string[]   $pk    Key columns.
	 * @param array      $state State.
	 * @param BatchSizer $sizer Sizer.
	 * @return array[]
	 */
	private function fetch( JobContext $ctx, $name, array $pk, array $state, BatchSizer $sizer ) {
		return $ctx->db->fetch_batch( $name, $pk, Cursor::unpack( $state['cursor'] ), $sizer->size(), (int) $state['skip'] );
	}

	/**
	 * Flush the package and remember where the last complete block ends, then save the job.
	 *
	 * @param array         $state  State.
	 * @param PackageWriter $writer Writer.
	 * @param JobContext    $ctx    Services.
	 * @return void
	 */
	private function checkpoint_file( array &$state, PackageWriter $writer, JobContext $ctx ) {
		$writer->flush();
		$state['file'] = array(
			'offset' => $writer->offset(),
			'seq'    => $writer->seq(),
		);
		$ctx->checkpoint();
	}

	/**
	 * Write the closing block and the package details.
	 *
	 * @param array         $params Parameters.
	 * @param array         $state  State.
	 * @param PackageWriter $writer Writer.
	 * @param JobContext    $ctx    Services.
	 * @return void
	 */
	private function finish( array $params, array &$state, PackageWriter $writer, JobContext $ctx ) {
		$tables = array();
		foreach ( $state['tables'] as $t ) {
			$tables[] = array(
				'name' => $t['name'],
				'rows' => $t['rows'],
			);
		}
		$writer->frame(
			'E',
			array(
				'tables'     => $tables,
				'total_rows' => $state['rows'],
				'blocks'     => $writer->seq() + 1,
			)
		);
		$this->checkpoint_file( $state, $writer, $ctx );
		$writer->close();

		$path = $ctx->storage->package_path( $state['package'] );
		clearstatcache( true, $path );
		$state['sha256'] = hash_file( 'sha256', $path );
		$state['size']   = (int) filesize( $path );
		$state['phase']  = 'done';

		$ctx->storage->update_meta(
			$state['package'],
			array(
				'status'     => 'complete',
				'sha256'     => $state['sha256'],
				'size'       => $state['size'],
				'rows'       => $state['rows'],
				'finished'   => Util::now(),
				'table_list' => $tables,
			)
		);
	}

	/**
	 * Keep baselines and report the result.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function complete( array $params, array &$state, JobContext $ctx ) {
		if ( ! in_array( (string) ( $params['kind'] ?? 'export' ), array( 'snapshot', 'sanitize' ), true ) && empty( $params['sanitize'] ) ) {
			Baseline::promote( $ctx->db, $ctx->baselines_table(), 'tmp:' . ( $ctx->job ? $ctx->job->id : 0 ), (string) ( $params['peer'] ?? 'export' ) );
		}
		$ctx->log(
			'info',
			sprintf(
				/* translators: 1: row count, 2: size */
				__( 'Package ready: %1$s rows, %2$s.', 'estasoft-migrate' ),
				Util::number( $state['rows'] ),
				Util::format_bytes( $state['size'] ?? 0 )
			)
		);
		if ( ! empty( $state['_sanitizer'] ) ) {
			$ctx->log( 'info', 'Values masked while exporting.', array( 'masked' => $state['masked'] ?? array() ) );
		}
	}

	/**
	 * Delete the unfinished package and provisional baselines.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function cleanup( array $params, array &$state, JobContext $ctx ) {
		if ( ! empty( $state['package'] ) && 'done' !== ( $state['phase'] ?? '' ) ) {
			$ctx->storage->delete( $state['package'] );
		}
		Baseline::discard( $ctx->db, $ctx->baselines_table(), 'tmp:' . ( $ctx->job ? $ctx->job->id : 0 ) );
	}

	/**
	 * Progress from rows read against the table size estimates.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return float
	 */
	public function progress( array $params, array $state ) {
		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			return 100.0;
		}
		$total = 0;
		$done  = 0;
		foreach ( (array) ( $state['tables'] ?? array() ) as $t ) {
			$est    = max( (int) $t['rows_est'], (int) $t['read'] );
			$total += max( 1, $est );
			$done  += $t['done'] ? max( 1, $est ) : (int) $t['read'];
		}
		return $total > 0 ? min( 99.0, $done / $total * 100 ) : 0.0;
	}

	/**
	 * What is happening now.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return string
	 */
	public function describe( array $params, array $state ) {
		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			return __( 'Package ready.', 'estasoft-migrate' );
		}
		$i = (int) ( $state['index'] ?? 0 );
		if ( isset( $state['tables'][ $i ] ) ) {
			return sprintf(
				/* translators: 1: table name, 2: number of tables done, 3: number of tables */
				__( 'Exporting %1$s (table %2$d of %3$d).', 'estasoft-migrate' ),
				$state['tables'][ $i ]['name'],
				$i + 1,
				count( $state['tables'] )
			);
		}
		return __( 'Finishing the package.', 'estasoft-migrate' );
	}

	/**
	 * Per-table progress.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	public function tables( array $params, array $state ) {
		$out = array();
		foreach ( (array) ( $state['tables'] ?? array() ) as $i => $t ) {
			$out[] = array(
				'name'   => $t['name'],
				'done'   => (int) $t['read'],
				'total'  => max( (int) $t['rows_est'], (int) $t['read'] ),
				'status' => $t['done'] ? 'done' : ( (int) ( $state['index'] ?? 0 ) === $i ? 'running' : 'waiting' ),
			);
		}
		return $out;
	}

	/**
	 * Steps.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	public function steps( array $params, array $state ) {
		$phase = (string) ( $state['phase'] ?? 'tables' );
		return array(
			array(
				'id'     => 'tables',
				'label'  => __( 'Copying tables', 'estasoft-migrate' ),
				'status' => 'tables' === $phase ? 'current' : 'done',
			),
			array(
				'id'     => 'finish',
				'label'  => __( 'Sealing the package', 'estasoft-migrate' ),
				'status' => 'done' === $phase ? 'done' : ( 'finish' === $phase ? 'current' : 'pending' ),
			),
		);
	}
}
