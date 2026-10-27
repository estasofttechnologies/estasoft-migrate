<?php
/**
 * Imports a package into this site with a staged table swap.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine\Tasks;

use Estasoft\Migrate\Db\Identifier;
use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\DdlTranslator;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\RowPipeline;
use Estasoft\Migrate\Engine\SiteInfo;
use Estasoft\Migrate\Engine\Task;
use Estasoft\Migrate\Engine\TaskException;
use Estasoft\Migrate\Package\PackageException;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Package\RowCodec;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Replace\Replacer;
use Estasoft\Migrate\Sanitizer\SafetySwitches;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Zero-downtime import.
 *
 * 1. verify    Check every block of the package. A damaged or cut-off package stops here,
 *              before anything on the site is touched.
 * 2. snapshot  Save the tables that will be replaced as a rollback snapshot.
 * 3. stage     Create copies of the tables under temporary names and fill them in small batches.
 *              The live site keeps running on the old tables the whole time.
 * 4. swap      Rename all tables in one atomic statement. This takes a moment, not minutes.
 * 5. finish    Restore this plugin's own settings, apply safety switches, clean up.
 *
 * Parameters: package_id, passphrase_enc or passphrase, snapshot (default true), rewrite (default
 * true: point addresses in the package at this site), replace { pairs }, safety { mail, seo, pay, smtp },
 * tables (subset of source table names), collation (force one), kind (import or restore).
 */
class ImportTask extends Task {

	const PHASES = array( 'verify', 'snapshot', 'stage', 'swap', 'finish', 'done' );

	/**
	 * Open the package, read the manifest and plan the work.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the package cannot be imported.
	 */
	public function prepare( array $params, array &$state, JobContext $ctx ) {
		$db   = $ctx->db;
		$id   = (string) ( $params['package_id'] ?? '' );
		$path = $ctx->storage->package_path( $id );
		if ( '' === $id || ! is_file( $path ) ) {
			throw new TaskException(
				__( 'The package file could not be found.', 'estasoft-migrate' ),
				array(
					'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ),
					'advice'    => __( 'Upload the package again or choose another one on the Packages screen.', 'estasoft-migrate' ),
				)
			);
		}

		$reader = $this->open( $params, $ctx, $path );
		$first  = $this->read_frame( $reader );
		if ( ! $first || 'M' !== $first['type'] ) {
			throw new TaskException( __( 'This file does not start with a package manifest, so it cannot be imported.', 'estasoft-migrate' ), array( 'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ) ) );
		}
		$manifest = $first['data'];
		$offset   = $first['end'];
		$seq      = $first['seq'] + 1;
		$reader->close();

		$source = (array) ( $manifest['source'] ?? array() );
		if ( ! empty( $source['multisite'] ) ) {
			throw new TaskException(
				__( 'This package comes from a WordPress multisite network. Multisite is not supported yet.', 'estasoft-migrate' ),
				array( 'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ) )
			);
		}

		$local  = $ctx->site_info();
		$prefix = $db->prefix();
		$from   = (string) ( $source['prefix'] ?? $prefix );

		$only   = ! empty( $params['tables'] ) ? array_flip( (array) $params['tables'] ) : null;
		$tables = array();
		foreach ( (array) ( $manifest['tables'] ?? array() ) as $i => $t ) {
			$name = (string) $t['name'];
			if ( null !== $only && ! isset( $only[ $name ] ) ) {
				continue;
			}
			$logical = Util::unprefix( $name, $from );
			if ( 0 === strpos( $logical, 'estasoft_' ) ) {
				// The plugin's own tables (jobs, log, baselines) belong to this site and are never replaced by a package.
				continue;
			}
			$target   = $prefix . $logical;
			$tables[] = array(
				'src'      => $name,
				'logical'  => $logical,
				'target'   => $target,
				'tmp'      => '_estm_' . $ctx->job->id . '_' . $i,
				'old'      => '_estm_old_' . $ctx->job->id . '_' . $i,
				'rows_est' => (int) ( $t['rows_est'] ?? 0 ),
				'rows'     => 0,
				'expect'   => null,
				'done'     => false,
			);
		}
		if ( ! $tables ) {
			throw new TaskException( __( 'The package has no tables to import.', 'estasoft-migrate' ), array( 'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ) ) );
		}

		$pairs = array();
		if ( ! isset( $params['rewrite'] ) || $params['rewrite'] ) {
			$pairs = SiteInfo::rewrite_pairs( $source, $local );
		}
		foreach ( (array) ( $params['replace']['pairs'] ?? array() ) as $pair ) {
			$pairs[] = $pair;
		}

		$state['phase']    = 'verify';
		$state['package']  = $id;
		$state['manifest'] = array(
			'created'   => $manifest['created'] ?? '',
			'kind'      => $manifest['kind'] ?? '',
			'sanitized' => $manifest['sanitized'] ?? null,
			'safety'    => $manifest['safety'] ?? array(),
			'source'    => array(
				'url'    => $source['url'] ?? '',
				'prefix' => $from,
				'db'     => $source['db'] ?? array(),
			),
		);
		$state['tables']   = $tables;
		$state['pairs']    = $pairs;
		$state['file']     = array(
			'offset' => $offset,
			'seq'    => $seq,
		);
		clearstatcache( true, $path );
		$state['verify']      = array(
			'offset' => $offset,
			'seq'    => $seq,
			'blocks' => 0,
			'size'   => (int) filesize( $path ),
		);
		$state['cur']         = -1;
		$state['snapshot']    = null;
		$state['snapshot_id'] = null;
		$state['notes']       = array();
		$state['swapped']     = false;
		$state['_preserved']  = array();
		$state['applied']     = array();

		$origin = (string) ( $source['url'] ?? '' );
		if ( '' === $origin ) {
			$origin = __( 'an unknown site', 'estasoft-migrate' );
		}
		$ctx->log(
			'info',
			sprintf(
				/* translators: 1: number of tables, 2: package id, 3: source address */
				_n( 'Importing %1$d table from package %2$s (made on %3$s).', 'Importing %1$d tables from package %2$s (made on %3$s).', count( $tables ), 'estasoft-migrate' ),
				count( $tables ),
				$id,
				$origin
			)
		);
		if ( $pairs ) {
			$ctx->log(
				'info',
				'Addresses in the data will be rewritten: ' . implode(
					', ',
					array_map(
						static function ( $p ) {
							return $p[0] . ' → ' . $p[1];
						},
						array_slice( $pairs, 0, 4 )
					)
				) . ( count( $pairs ) > 4 ? ', …' : '' )
			);
		}
	}

	/**
	 * Key options for reading the package.
	 *
	 * @param array      $params Parameters.
	 * @param JobContext $ctx    Services.
	 * @return array
	 */
	private function key_options( array $params, JobContext $ctx ) {
		$opts = array( 'secret' => $ctx->secret );
		$pass = isset( $params['passphrase'] ) ? (string) $params['passphrase'] : '';
		if ( '' === $pass && ! empty( $params['passphrase_enc'] ) ) {
			$pass = Secrets::open( (string) $params['passphrase_enc'], $ctx->secret );
		}
		if ( '' !== $pass ) {
			$opts['passphrase'] = $pass;
		}
		return $opts;
	}

	/**
	 * Open the package, turning package problems into readable job errors.
	 *
	 * @param array      $params Parameters.
	 * @param JobContext $ctx    Services.
	 * @param string     $path   Package path.
	 * @return PackageReader
	 * @throws TaskException On failure.
	 */
	private function open( array $params, JobContext $ctx, $path ) {
		try {
			return PackageReader::open( $path, $this->key_options( $params, $ctx ) );
		} catch ( PackageException $e ) {
			throw $this->package_error( $e );
		}
	}

	/**
	 * Read a frame, mapping package errors.
	 *
	 * @param PackageReader $reader Reader.
	 * @param bool          $decode Decode the payload.
	 * @return array|null
	 * @throws TaskException On failure.
	 */
	private function read_frame( PackageReader $reader, $decode = true ) {
		try {
			return $reader->next( $decode );
		} catch ( PackageException $e ) {
			throw $this->package_error( $e );
		}
	}

	/**
	 * Package error to job error.
	 *
	 * @param PackageException $e Error.
	 * @return TaskException
	 */
	private function package_error( PackageException $e ) {
		$advice = __( 'Check the package, then try again.', 'estasoft-migrate' );
		if ( PackageException::BAD_PASSPHRASE === $e->reason || PackageException::NEEDS_KEY === $e->reason ) {
			$advice = __( 'Start the import again and enter the password used when the package was made.', 'estasoft-migrate' );
		} elseif ( PackageException::TRUNCATED === $e->reason ) {
			$advice = __( 'Upload or download the package again. It looks incomplete.', 'estasoft-migrate' );
		} elseif ( PackageException::CORRUPT === $e->reason ) {
			$advice = __( 'The file was damaged in transit. Upload or download it again.', 'estasoft-migrate' );
		}
		return new TaskException(
			$e->getMessage(),
			array(
				'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ),
				'advice'    => $advice,
			),
			false,
			$e
		);
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
				case 'verify':
					$this->verify( $params, $state, $budget, $ctx );
					break;
				case 'snapshot':
					$this->snapshot( $params, $state, $budget, $ctx );
					break;
				case 'stage':
					$this->stage( $params, $state, $budget, $ctx );
					break;
				case 'swap':
					$this->swap( $params, $state, $ctx );
					break;
				case 'finish':
					$this->finish( $params, $state, $ctx );
					break;
			}
		}
		return true;
	}

	/**
	 * Phase 1: check every block.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the package is damaged or incomplete.
	 */
	private function verify( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$path   = $ctx->storage->package_path( $state['package'] );
		$reader = $this->open( $params, $ctx, $path );
		$reader->seek( $state['verify']['offset'] );
		$seq      = (int) $state['verify']['seq'];
		$complete = ! empty( $state['verify']['complete'] );
		try {
			while ( true ) {
				if ( $budget->exhausted() ) {
					break;
				}
				$f = $this->read_frame( $reader, false );
				if ( null === $f ) {
					if ( ! $complete ) {
						throw new TaskException(
							__( 'The package is incomplete: its closing block is missing.', 'estasoft-migrate' ),
							array(
								'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ),
								'advice'    => __( 'Upload or download the package again.', 'estasoft-migrate' ),
							)
						);
					}
					$state['phase'] = ! isset( $params['snapshot'] ) || $params['snapshot'] ? 'snapshot' : 'stage';
					$ctx->log( 'info', sprintf( 'Package verified: %d blocks, all checksums match.', $state['verify']['blocks'] ) );
					break;
				}
				if ( $f['seq'] !== $seq ) {
					throw new TaskException(
						sprintf( 'A block is missing from the package (expected %1$d, found %2$d).', $seq, $f['seq'] ),
						array( 'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ) )
					);
				}
				++$seq;
				++$state['verify']['blocks'];
				$complete                    = 'E' === $f['type'];
				$state['verify']['complete'] = $complete;
				$state['verify']['offset']   = $f['end'];
				$state['verify']['seq']      = $seq;
			}
		} finally {
			$reader->close();
		}
		$ctx->checkpoint();
	}

	/**
	 * Phase 2: rollback snapshot of the tables about to be replaced.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	private function snapshot( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$export = new ExportTask();
		$sub    = array(
			'kind'                  => 'snapshot',
			'label'                 => 'before-' . ( $params['kind'] ?? 'import' ),
			'tables'                => array(),
			'protect_with_site_key' => true,
			'compress'              => true,
		);
		foreach ( $state['tables'] as $t ) {
			if ( $ctx->db->table_exists( $t['target'] ) ) {
				$sub['tables'][] = $t['target'];
			}
		}
		if ( ! $sub['tables'] ) {
			$state['phase'] = 'stage';
			return;
		}
		if ( null === $state['snapshot'] ) {
			$state['snapshot'] = array();
			$export->prepare( $sub, $state['snapshot'], $ctx );
			$ctx->checkpoint();
		}
		$done = $export->step( $sub, $state['snapshot'], $budget, $ctx );
		if ( $done ) {
			$state['snapshot_id'] = $state['snapshot']['package'];
			$export->complete( $sub, $state['snapshot'], $ctx );
			$ctx->log( 'info', 'Rollback snapshot saved. You can restore it from the Packages screen if anything goes wrong.', array( 'snapshot' => $state['snapshot_id'] ) );
			$state['phase'] = 'stage';
		}
		$ctx->checkpoint();
	}

	/**
	 * Row pipeline for staging.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx Services.
	 * @return RowPipeline
	 */
	private function pipeline( array $params, array $state, JobContext $ctx ) {
		$replacer = null;
		if ( ! empty( $state['pairs'] ) ) {
			$replacer = new Replacer( $state['pairs'], array( 'case_sensitive' => true ) );
		}
		return new RowPipeline( $replacer, null, (string) $state['manifest']['source']['prefix'], $ctx->db->prefix() );
	}

	/**
	 * Phase 3: fill the temporary tables from the package.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When a table cannot be created or filled.
	 */
	private function stage( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		$db     = $ctx->db;
		$path   = $ctx->storage->package_path( $state['package'] );
		$reader = $this->open( $params, $ctx, $path );
		$reader->seek( $state['file']['offset'] );
		$pipe  = $this->pipeline( $params, $state, $ctx );
		$index = array();
		foreach ( $state['tables'] as $i => $t ) {
			$index[ $t['src'] ] = $i;
		}

		try {
			while ( true ) {
				if ( $budget->exhausted() || $ctx->should_stop() ) {
					break;
				}
				$f = $this->read_frame( $reader );
				if ( null === $f ) {
					throw new TaskException( __( 'The package ended before its closing block.', 'estasoft-migrate' ), array( 'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ) ) );
				}
				$data = $f['data'];

				switch ( $f['type'] ) {
					case 'T':
						$i = $index[ $data['name'] ] ?? null;
						if ( null === $i ) {
							$state['cur'] = -1;
							break;
						}
						$state['cur'] = $i;
						$entry        = &$state['tables'][ $i ];
						// Where this table starts in the file, in case it has to be read again from the top.
						$entry['t_offset'] = (int) $state['file']['offset'];
						$entry['t_seq']    = (int) $state['file']['seq'];
						$this->create_staging_table( $data, $entry, $state, $params, $ctx );
						unset( $entry );
						break;

					case 'R':
						$i = $index[ $data['t'] ] ?? null;
						if ( null === $i ) {
							break;
						}
						$entry = &$state['tables'][ $i ];
						if ( $this->has_unsaved_rows( $entry, $ctx ) ) {
							// An earlier try wrote a block and stopped before saving its place. Without a key the rows
							// cannot be told apart, so the table is filled again from its first block.
							$ctx->log( 'warning', sprintf( '%s was only partly staged by an earlier try. It is staged again from the start.', $entry['target'] ) );
							$entry['rows'] = 0;
							$state['file'] = array(
								'offset' => (int) $entry['t_offset'],
								'seq'    => (int) ( $entry['t_seq'] ?? 0 ),
							);
							$reader->seek( (int) $entry['t_offset'] );
							unset( $entry );
							$ctx->checkpoint();
							continue 2;
						}
						$this->insert_block( $data, $entry, $pipe, $ctx );
						unset( $entry );
						break;

					case 'U':
						$i = $index[ $data['name'] ] ?? null;
						if ( null !== $i ) {
							$state['tables'][ $i ]['expect'] = (int) $data['rows'];
							$state['tables'][ $i ]['done']   = true;
							$ctx->log( 'info', sprintf( '%1$s: %2$s rows staged.', $state['tables'][ $i ]['target'], Util::number( $state['tables'][ $i ]['rows'] ) ) );
						}
						break;

					case 'E':
						$state['phase'] = 'swap';
						break;
				}

				$state['file'] = array(
					'offset' => $f['end'],
					'seq'    => $f['seq'] + 1,
				);
				$ctx->checkpoint();
				if ( 'swap' === $state['phase'] ) {
					break;
				}
			}
		} finally {
			$reader->close();
		}
	}

	/**
	 * Does a table without a key hold more rows than the job has saved for it?
	 *
	 * @param array      $entry Table entry.
	 * @param JobContext $ctx   Services.
	 * @return bool
	 */
	private function has_unsaved_rows( array $entry, JobContext $ctx ) {
		if ( ! empty( $entry['pk'] ) || ! isset( $entry['t_offset'] ) ) {
			return false;
		}
		return $ctx->db->count_rows( $entry['tmp'] ) !== (int) $entry['rows'];
	}

	/**
	 * Create an empty temporary table from the package's CREATE statement.
	 *
	 * @param array      $data   T block.
	 * @param array      $entry  Table entry in the state (updated).
	 * @param array      $state  State.
	 * @param array      $params Parameters.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the table cannot be created.
	 */
	private function create_staging_table( array $data, array &$entry, array &$state, array $params, JobContext $ctx ) {
		$db = $ctx->db;
		$db->drop_table( $entry['tmp'] );
		list( $ddl, $notes ) = DdlTranslator::translate( (string) ( $data['ddl'] ?? '' ), $entry['tmp'], $db, (string) ( $params['collation'] ?? '' ) );
		if ( ! DdlTranslator::is_single_create_table( $ddl, $entry['tmp'] ) ) {
			throw new TaskException(
				sprintf(
					/* translators: %s: table name */
					__( 'The definition of the table %s in this package is not a plain table definition, so it was not run.', 'estasoft-migrate' ),
					$entry['target']
				),
				array(
					'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ),
					'advice'    => __( 'Only import packages you made yourself or got from someone you trust. This package may have been changed after it was made.', 'estasoft-migrate' ),
					'table'     => $entry['target'],
				)
			);
		}
		try {
			$db->query( $ddl );
		} catch ( \Throwable $e ) {
			throw new TaskException(
				sprintf(
					/* translators: 1: table name, 2: database message */
					__( 'The table %1$s could not be created on this server: %2$s', 'estasoft-migrate' ),
					$entry['target'],
					$e->getMessage()
				),
				array(
					'untouched' => __( 'Your site was not changed. Only a temporary copy was affected.', 'estasoft-migrate' ),
					'advice'    => __( 'The database versions may differ. Try again with a collation chosen in the import options.', 'estasoft-migrate' ),
					'table'     => $entry['target'],
				),
				false,
				$e
			);
		}
		$db->forget( $entry['tmp'] );
		foreach ( $notes as $note ) {
			if ( ! in_array( $note, $state['notes'], true ) ) {
				$state['notes'][] = $note;
				$ctx->log( 'warning', $note );
			}
		}
		$entry['rows'] = 0;
		$entry['pk']   = (array) ( $data['pk'] ?? array() );
	}

	/**
	 * Insert one block of rows into a temporary table.
	 *
	 * @param array       $data  R block.
	 * @param array       $entry Table entry (updated).
	 * @param RowPipeline $pipe  Pipeline.
	 * @param JobContext  $ctx   Services.
	 * @return void
	 * @throws TaskException When the insert fails.
	 */
	private function insert_block( array $data, array &$entry, RowPipeline $pipe, JobContext $ctx ) {
		$db      = $ctx->db;
		$decoded = RowCodec::decode( $data );
		$columns = $decoded['columns'];
		$cols    = $db->columns( $entry['tmp'] );
		$pipe->begin_table( $entry['logical'], $cols, (array) ( $entry['pk'] ?? array() ) );

		$rows = array();
		foreach ( $decoded['rows'] as $line ) {
			$assoc = array_combine( $columns, $line );
			$out   = $pipe->row( $assoc );
			if ( null !== $out ) {
				$rows[] = array_values( $out );
			}
		}
		try {
			$db->insert_rows( $entry['tmp'], $columns, $rows, 'replace' );
		} catch ( \Throwable $e ) {
			throw new TaskException(
				sprintf(
					/* translators: 1: table name, 2: database message */
					__( 'A batch of rows for %1$s could not be imported: %2$s', 'estasoft-migrate' ),
					$entry['target'],
					$e->getMessage()
				),
				array(
					'untouched' => __( 'Your live site was not changed. Rows already staged are kept.', 'estasoft-migrate' ),
					'advice'    => __( 'Retry the failed batch. If it fails again, raise max_allowed_packet or check the database error in the log.', 'estasoft-migrate' ),
					'table'     => $entry['target'],
				),
				$e instanceof \Estasoft\Migrate\Db\DatabaseException && $e->is_transient(),
				$e
			);
		}
		$entry['rows'] += count( $rows );
	}

	/**
	 * Phase 4: swap the temporary tables in with one atomic rename.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the data does not match the package.
	 */
	private function swap( array $params, array &$state, JobContext $ctx ) {
		$db     = $ctx->db;
		$prefix = $db->prefix();

		// A crash right after the rename leaves no temporary tables behind: nothing left to swap.
		$pending = 0;
		foreach ( $state['tables'] as $t ) {
			if ( $db->table_exists( $t['tmp'] ) ) {
				++$pending;
			}
		}
		if ( $state['swapped'] && 0 === $pending ) {
			$ctx->log( 'info', 'The tables were already swapped; finishing up.' );
			$state['phase'] = 'finish';
			$ctx->checkpoint();
			return;
		}

		foreach ( $state['tables'] as $t ) {
			if ( ! $t['done'] || ! $db->table_exists( $t['tmp'] ) ) {
				throw new TaskException(
					sprintf( 'The staged copy of %s is missing or incomplete.', $t['target'] ),
					array(
						'untouched' => __( 'Your live site was not changed.', 'estasoft-migrate' ),
						'advice'    => __( 'Retry the import.', 'estasoft-migrate' ),
					)
				);
			}
			$have = $db->count_rows( $t['tmp'] );
			if ( null !== $t['expect'] && $have !== (int) $t['expect'] && 'options' !== $t['logical'] && 'usermeta' !== $t['logical'] ) {
				throw new TaskException(
					sprintf( 'The staged copy of %1$s has %2$d rows but the package holds %3$d.', $t['target'], $have, $t['expect'] ),
					array(
						'untouched' => __( 'Your live site was not changed.', 'estasoft-migrate' ),
						'advice'    => __( 'Retry the import. If it happens again, the package may be damaged.', 'estasoft-migrate' ),
					)
				);
			}
		}

		// Remember this plugin's own settings: they must survive the swap. Read them only once.
		$options = $prefix . 'options';
		if ( empty( $state['preserved_taken'] ) ) {
			if ( $db->table_exists( $options ) ) {
				$state['_preserved'] = $db->get_results(
					'SELECT option_name, option_value, autoload FROM ' . Identifier::quote( $options ) . ' WHERE SUBSTR(option_name, 1, 17) = %s',
					array( 'estasoft_migrate_' )
				);
			}
			// The person who started the import stays signed in, if their account is in the package too.
			$state['_session']        = $this->current_session( $params, $ctx, $prefix );
			$state['preserved_taken'] = true;
			$ctx->checkpoint();
		}

		$rename = array();
		foreach ( $state['tables'] as $t ) {
			if ( $db->table_exists( $t['target'] ) ) {
				$rename[ $t['target'] ] = $t['old'];
			}
		}
		foreach ( $state['tables'] as $t ) {
			$rename[ $t['tmp'] ] = $t['target'];
		}

		// Persist the plan first, so a crash right after the rename can still be finished.
		$state['swapped'] = true;
		$ctx->checkpoint();
		try {
			$db->rename_tables( $rename );
		} catch ( \Throwable $e ) {
			// The server may report a problem after it did the rename (a dropped connection, say): look at the tables.
			$db->forget();
			$left = 0;
			foreach ( $state['tables'] as $t ) {
				if ( $db->table_exists( $t['tmp'] ) ) {
					++$left;
				}
			}
			if ( 0 === $left ) {
				$ctx->log( 'warning', 'The server reported a problem during the swap, but the tables are in place. Finishing up.' );
				$state['phase'] = 'finish';
				$ctx->checkpoint();
				return;
			}
			$state['swapped'] = false;
			$ctx->checkpoint(); // The saved plan said "swapped"; it did not happen.
			throw new TaskException(
				sprintf( 'The tables could not be swapped: %s', $e->getMessage() ),
				array(
					'untouched' => __( 'Your live site was not changed.', 'estasoft-migrate' ),
					'advice'    => __( 'Retry the import.', 'estasoft-migrate' ),
				),
				false,
				$e
			);
		}
		$db->forget();
		$ctx->log( 'info', sprintf( 'Swapped %d tables in one step.', count( $state['tables'] ) ) );
		$state['phase'] = 'finish';
		$ctx->checkpoint();
	}

	/**
	 * The sign-in of the person running this import, read before the tables are swapped.
	 *
	 * @param array      $params Parameters ("started_by" is the account that created the job).
	 * @param JobContext $ctx    Services.
	 * @param string     $prefix Table prefix.
	 * @return array|null id, login and the stored session tokens; null when there is nobody to keep signed in.
	 */
	private function current_session( array $params, JobContext $ctx, $prefix ) {
		// A background run has no signed-in user, so the job remembers who started it.
		$who = $params['started_by'] ?? null;
		if ( ! is_array( $who ) ) {
			$service = $ctx->service( 'current_user' );
			$who     = is_callable( $service ) ? call_user_func( $service ) : null;
		}
		if ( ! is_array( $who ) || (int) ( $who['id'] ?? 0 ) < 1 ) {
			return null;
		}
		$db = $ctx->db;
		if ( ! $db->table_exists( $prefix . 'usermeta' ) ) {
			return null;
		}
		$tokens = $db->get_var(
			'SELECT meta_value FROM ' . Identifier::quote( $prefix . 'usermeta' ) . ' WHERE user_id = %d AND meta_key = %s',
			array( (int) $who['id'], 'session_tokens' )
		);
		if ( null === $tokens || '' === $tokens ) {
			return null;
		}
		return array(
			'id'     => (int) $who['id'],
			'login'  => (string) ( $who['login'] ?? '' ),
			'tokens' => (string) $tokens,
		);
	}

	/**
	 * Keep the person who started the import signed in, when the imported site has the same account.
	 *
	 * Without this, WordPress would sign them out the moment the users table is replaced. If the account is not
	 * in the package (or has another password), they sign in again with an account from the imported site.
	 *
	 * @param JobContext $ctx    Services.
	 * @param array      $state  State.
	 * @param string     $prefix Table prefix.
	 * @return void
	 */
	private function restore_session( JobContext $ctx, array $state, $prefix ) {
		$session = $state['_session'] ?? null;
		if ( ! is_array( $session ) ) {
			return;
		}
		$db       = $ctx->db;
		$users    = $prefix . 'users';
		$usermeta = $prefix . 'usermeta';
		if ( ! $db->table_exists( $users ) || ! $db->table_exists( $usermeta ) ) {
			return;
		}
		$same = (int) $db->get_var(
			'SELECT COUNT(*) FROM ' . Identifier::quote( $users ) . ' WHERE ID = %d AND user_login = %s',
			array( $session['id'], $session['login'] )
		);
		if ( 1 !== $same ) {
			$ctx->log( 'info', 'The account that started the import is not in the imported site, so the browser will ask to sign in again.' );
			return;
		}
		$db->query( 'DELETE FROM ' . Identifier::quote( $usermeta ) . ' WHERE user_id = ' . (int) $session['id'] . ' AND meta_key = ' . $db->quote( 'session_tokens' ) );
		$db->insert_rows( $usermeta, array( 'user_id', 'meta_key', 'meta_value' ), array( array( $session['id'], 'session_tokens', $session['tokens'] ) ), 'insert' );
	}

	/**
	 * Phase 5: restore plugin settings, apply safety switches and remove the old tables.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	private function finish( array $params, array &$state, JobContext $ctx ) {
		$db      = $ctx->db;
		$prefix  = $db->prefix();
		$options = $prefix . 'options';
		$qo      = Identifier::quote( $options );

		// A partial import (some tables only) leaves the live options alone: nothing in them was replaced.
		$options_swapped = in_array( $options, array_column( (array) $state['tables'], 'target' ), true );

		if ( $options_swapped && $db->table_exists( $options ) ) {
			// This plugin's own settings come back (the package never carries them).
			$db->query( 'DELETE FROM ' . $qo . ' WHERE SUBSTR(option_name, 1, 17) = ' . $db->quote( 'estasoft_migrate_' ) );
			$keep = array();
			foreach ( (array) $state['_preserved'] as $row ) {
				$keep[] = array( $row['option_name'], $row['option_value'], $row['autoload'] );
			}
			if ( $keep ) {
				$db->insert_rows( $options, array( 'option_name', 'option_value', 'autoload' ), $keep, 'insert' );
			}

			$this->restore_session( $ctx, $state, $prefix );

			// The plugin must stay active, or the site owner loses the way back.
			$basename = (string) $ctx->service( 'plugin_basename' );
			if ( '' === $basename ) {
				$basename = 'estasoft-migrate/estasoft-migrate.php';
			}
			$stored = $db->get_var( 'SELECT option_value FROM ' . $qo . ' WHERE option_name = %s', array( 'active_plugins' ) );
			$list   = null === $stored ? array() : @unserialize( $stored, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions
			if ( is_array( $list ) && ! in_array( $basename, $list, true ) ) {
				$list[] = $basename;
				SafetySwitches::set_option( $db, $options, 'active_plugins', serialize( array_values( $list ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}

			// Permalinks are rebuilt on the next request.
			$db->query( 'DELETE FROM ' . $qo . " WHERE option_name = 'rewrite_rules'" );
		}

		// Safety switches: the ones chosen for this import, or the ones the sanitized package asks for. The package's
		// own request only applies when the imported options replaced the live ones; a partial import never changes them.
		$flags = (array) ( $params['safety'] ?? array() );
		if ( ! $flags && $options_swapped && ! empty( $state['manifest']['safety'] ) ) {
			$flags = (array) $state['manifest']['safety'];
		}
		if ( $flags && $db->table_exists( $options ) ) {
			foreach ( SafetySwitches::apply( $db, $prefix, $flags ) as $done ) {
				$ctx->log( 'info', $done );
			}
		}

		foreach ( $state['tables'] as $t ) {
			$db->drop_table( $t['old'] );
		}

		$hook = $ctx->service( 'after_import' );
		if ( is_callable( $hook ) ) {
			call_user_func( $hook, $state );
		}
		$state['phase'] = 'done';
		$ctx->checkpoint();
	}

	/**
	 * Final log line and snapshot rotation.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function complete( array $params, array &$state, JobContext $ctx ) {
		$rows = 0;
		foreach ( $state['tables'] as $t ) {
			$rows += (int) $t['rows'];
		}
		$ctx->log( 'info', sprintf( 'Import finished: %1$d tables, %2$s rows.', count( $state['tables'] ), Util::number( $rows ) ) );
		$keep = $ctx->service( 'keep_snapshots' );
		if ( $keep ) {
			$ctx->storage->clean_up( 0, (int) $keep );
		}
	}

	/**
	 * Once the tables are swapped the new data is live; stopping then would skip the finishing steps.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return bool
	 */
	public function can_cancel( array $params, array $state ) {
		return empty( $state['swapped'] );
	}

	/**
	 * Remove temporary tables when the job is cancelled or fails for good.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function cleanup( array $params, array &$state, JobContext $ctx ) {
		foreach ( (array) ( $state['tables'] ?? array() ) as $t ) {
			$ctx->db->drop_table( $t['tmp'] );
			if ( empty( $state['swapped'] ) ) {
				$ctx->db->drop_table( $t['old'] );
			}
		}
		if ( ! empty( $state['snapshot'] ) && empty( $state['snapshot_id'] ) ) {
			$export = new ExportTask();
			$export->cleanup( array(), $state['snapshot'], $ctx );
		}
		$ctx->log( 'info', 'Temporary tables removed. The live site was not changed.' );
	}

	/**
	 * Overall progress, weighted by phase.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return float
	 */
	public function progress( array $params, array $state ) {
		$phase    = (string) ( $state['phase'] ?? 'verify' );
		$snapshot = ! isset( $params['snapshot'] ) || $params['snapshot'];
		$w        = $snapshot ? array( 5, 20, 70, 5 ) : array( 5, 0, 90, 5 );
		if ( 'done' === $phase ) {
			return 100.0;
		}
		$acc = 0.0;

		$verify = 0.0;
		$size   = 0;
		if ( 'verify' === $phase ) {
			$total  = max( 1, (int) ( $state['verify']['size'] ?? 0 ) );
			$verify = min( 1.0, (int) $state['verify']['offset'] / $total );
		} else {
			$verify = 1.0;
		}
		unset( $size );
		$acc += $w[0] * $verify;

		if ( $w[1] ) {
			if ( in_array( $phase, array( 'stage', 'swap', 'finish' ), true ) ) {
				$acc += $w[1];
			} elseif ( 'snapshot' === $phase && ! empty( $state['snapshot'] ) ) {
				$export = new ExportTask();
				$acc   += $w[1] * $export->progress( array(), $state['snapshot'] ) / 100;
			}
		}

		if ( in_array( $phase, array( 'swap', 'finish' ), true ) ) {
			$acc += $w[2];
		} elseif ( 'stage' === $phase ) {
			$total = 0;
			$done  = 0;
			foreach ( $state['tables'] as $t ) {
				$est    = max( 1, (int) $t['rows_est'], (int) $t['rows'] );
				$total += $est;
				$done  += $t['done'] ? $est : (int) $t['rows'];
			}
			$acc += $w[2] * ( $total ? min( 1.0, $done / $total ) : 0 );
		}

		if ( 'finish' === $phase ) {
			$acc += $w[3] * 0.5;
		}
		return min( 99.0, $acc );
	}

	/**
	 * What is happening now.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return string
	 */
	public function describe( array $params, array $state ) {
		switch ( $state['phase'] ?? '' ) {
			case 'verify':
				return __( 'Checking the package.', 'estasoft-migrate' );
			case 'snapshot':
				return __( 'Saving a rollback snapshot of the current tables.', 'estasoft-migrate' );
			case 'stage':
				$cur = (int) ( $state['cur'] ?? -1 );
				if ( isset( $state['tables'][ $cur ] ) ) {
					/* translators: %s: table name */
					return sprintf( __( 'Importing %s into a temporary copy.', 'estasoft-migrate' ), $state['tables'][ $cur ]['target'] );
				}
				return __( 'Importing tables into temporary copies.', 'estasoft-migrate' );
			case 'swap':
				return __( 'Switching to the imported tables.', 'estasoft-migrate' );
			case 'finish':
				return __( 'Finishing up.', 'estasoft-migrate' );
			case 'done':
				return __( 'Import complete.', 'estasoft-migrate' );
		}
		return '';
	}

	/**
	 * Per-table progress.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	public function tables( array $params, array $state ) {
		if ( 'snapshot' === ( $state['phase'] ?? '' ) && ! empty( $state['snapshot'] ) ) {
			$export = new ExportTask();
			return $export->tables( array(), $state['snapshot'] );
		}
		$out = array();
		foreach ( (array) ( $state['tables'] ?? array() ) as $i => $t ) {
			$out[] = array(
				'name'   => $t['target'],
				'done'   => (int) $t['rows'],
				'total'  => max( (int) $t['rows_est'], (int) $t['rows'] ),
				'status' => $t['done'] ? 'done' : ( (int) ( $state['cur'] ?? -1 ) === $i ? 'running' : 'waiting' ),
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
		$phase = (string) ( $state['phase'] ?? 'verify' );
		$order = array( 'verify', 'snapshot', 'stage', 'swap', 'finish' );
		$label = array(
			'verify'   => __( 'Check the package', 'estasoft-migrate' ),
			'snapshot' => __( 'Save a rollback snapshot', 'estasoft-migrate' ),
			'stage'    => __( 'Import into temporary tables', 'estasoft-migrate' ),
			'swap'     => __( 'Switch tables', 'estasoft-migrate' ),
			'finish'   => __( 'Finish up', 'estasoft-migrate' ),
		);
		if ( isset( $params['snapshot'] ) && ! $params['snapshot'] ) {
			unset( $order[1] );
			$order = array_values( $order );
		}
		$pos = array_search( $phase, $order, true );
		$out = array();
		foreach ( $order as $i => $id ) {
			if ( 'done' === $phase ) {
				$status = 'done';
			} elseif ( false === $pos ) {
				$status = 'pending';
			} else {
				$status = $i < $pos ? 'done' : ( $i === $pos ? 'current' : 'pending' );
			}
			$out[] = array(
				'id'     => $id,
				'label'  => $label[ $id ],
				'status' => $status,
			);
		}
		return $out;
	}
}
