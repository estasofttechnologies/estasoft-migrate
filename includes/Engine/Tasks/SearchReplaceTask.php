<?php
/**
 * Search and replace across the database.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine\Tasks;

use Estasoft\Migrate\Engine\BatchSizer;
use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\Cursor;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\TableSelector;
use Estasoft\Migrate\Engine\Task;
use Estasoft\Migrate\Engine\TaskException;
use Estasoft\Migrate\Replace\Replacer;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Finds and replaces text in every text column of the chosen tables, including inside serialized
 * and JSON data. A dry run counts matches and collects examples but writes nothing.
 *
 * Parameters: pairs ([[search, replace]]), regex, case_sensitive (default true), replace_keys (default
 * true), tables, dry_run (default true), skip_guid (default true), skip_columns ("table.column"),
 * snapshot (default false), sample_limit (default 20).
 *
 * Each batch of updates is committed together with the job's saved position, so a job that is
 * stopped half way never applies a replacement twice.
 */
class SearchReplaceTask extends Task {

	/**
	 * Build the table list.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 * @throws TaskException When the request is not valid.
	 */
	public function prepare( array $params, array &$state, JobContext $ctx ) {
		$pairs = $this->pairs( $params );
		if ( ! $pairs ) {
			throw new TaskException( __( 'Enter the text to search for.', 'estasoft-migrate' ), array( 'untouched' => __( 'Nothing was changed.', 'estasoft-migrate' ) ) );
		}
		try {
			$this->replacer( $params );
		} catch ( \InvalidArgumentException $e ) {
			throw new TaskException( $e->getMessage(), array( 'untouched' => __( 'Nothing was changed.', 'estasoft-migrate' ) ) );
		}

		$tables = TableSelector::select( $ctx->db, $params );
		if ( ! $tables ) {
			throw new TaskException( __( 'There are no tables to search.', 'estasoft-migrate' ) );
		}

		$state['phase']       = ( ! $this->dry( $params ) && ! empty( $params['snapshot'] ) ) ? 'snapshot' : 'scan';
		$state['tables']      = array_map(
			static function ( $t ) {
				return array(
					'name'     => $t['name'],
					'logical'  => $t['logical'],
					'rows_est' => $t['rows_est'],
					'scanned'  => 0,
					'changed'  => 0,
					'matches'  => 0,
					'nested'   => 0,
					'columns'  => array(),
					'skipped'  => '',
					'done'     => false,
				);
			},
			$tables
		);
		$state['index']       = 0;
		$state['cursor']      = null;
		$state['skip']        = 0;
		$state['batch']       = ( new BatchSizer( $ctx->max_batch, 200 ) )->export_state();
		$state['samples']     = array();
		$state['totals']      = array(
			'scanned' => 0,
			'changed' => 0,
			'matches' => 0,
			'nested'  => 0,
		);
		$state['snapshot']    = null;
		$state['snapshot_id'] = null;

		$ctx->log(
			'info',
			sprintf(
				/* translators: 1: dry run or live run, 2: number of tables */
				_n( '%1$s: searching %2$d table.', '%1$s: searching %2$d tables.', count( $tables ), 'estasoft-migrate' ),
				$this->dry( $params ) ? 'Dry run' : 'Search and replace',
				count( $tables )
			)
		);
	}

	/**
	 * Is this a dry run? (The default.)
	 *
	 * @param array $params Parameters.
	 * @return bool
	 */
	private function dry( array $params ) {
		return ! isset( $params['dry_run'] ) || $params['dry_run'];
	}

	/**
	 * Pairs from the parameters.
	 *
	 * @param array $params Parameters.
	 * @return array
	 */
	private function pairs( array $params ) {
		$out = array();
		foreach ( (array) ( $params['pairs'] ?? array() ) as $pair ) {
			if ( isset( $pair[0] ) && '' !== (string) $pair[0] ) {
				$out[] = array( (string) $pair[0], (string) ( $pair[1] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * Build the replacer.
	 *
	 * @param array $params Parameters.
	 * @return Replacer
	 */
	private function replacer( array $params ) {
		return new Replacer(
			$this->pairs( $params ),
			array(
				'regex'          => ! empty( $params['regex'] ),
				'case_sensitive' => ! isset( $params['case_sensitive'] ) || $params['case_sensitive'],
				'replace_keys'   => ! isset( $params['replace_keys'] ) || $params['replace_keys'],
			)
		);
	}

	/**
	 * Run.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param Budget     $budget Budget.
	 * @param JobContext $ctx    Services.
	 * @return bool
	 */
	public function step( array $params, array &$state, Budget $budget, JobContext $ctx ) {
		if ( 'snapshot' === $state['phase'] ) {
			$this->snapshot( $params, $state, $budget, $ctx );
			if ( 'snapshot' === $state['phase'] ) {
				return false;
			}
		}

		$db       = $ctx->db;
		$dry      = $this->dry( $params );
		$replacer = $this->replacer( $params );
		$sizer    = BatchSizer::from_state( (array) $state['batch'], $ctx->max_batch );
		$limit    = max( 0, (int) ( $params['sample_limit'] ?? 20 ) );
		$skipcols = array_map( 'strval', (array) ( $params['skip_columns'] ?? array() ) );
		$skipguid = ! isset( $params['skip_guid'] ) || $params['skip_guid'];

		while ( 'scan' === $state['phase'] ) {
			if ( $budget->exhausted() || $ctx->should_stop() ) {
				break;
			}
			$i = (int) $state['index'];
			if ( $i >= count( $state['tables'] ) ) {
				$state['phase'] = 'done';
				break;
			}
			$entry = &$state['tables'][ $i ];
			$name  = $entry['name'];
			$pk    = $db->primary_key( $name );

			if ( ! $pk ) {
				$entry['skipped'] = 'no_primary_key';
				$entry['done']    = true;
				$ctx->log( 'warning', sprintf( 'Table %s was skipped because it has no primary key and no unique key of columns that are never NULL, so rows cannot be updated safely.', $name ) );
				$state['index']  = $i + 1;
				$state['cursor'] = null;
				unset( $entry );
				continue;
			}

			$text = array();
			foreach ( $db->columns( $name ) as $col => $spec ) {
				if ( ! empty( $spec['binary'] ) || ! empty( $spec['numeric'] ) || ! empty( $spec['generated'] ) || in_array( $col, $pk, true ) ) {
					continue;
				}
				if ( $skipguid && 'posts' === $entry['logical'] && 'guid' === $col ) {
					continue;
				}
				if ( in_array( $entry['logical'] . '.' . $col, $skipcols, true ) ) {
					continue;
				}
				$text[] = $col;
			}

			$t0   = microtime( true );
			$rows = $text ? $db->fetch_batch( $name, $pk, Cursor::unpack( $state['cursor'] ), $sizer->size() ) : array();
			if ( ! $rows ) {
				$entry['done'] = true;
				if ( $entry['changed'] || $entry['matches'] ) {
					$ctx->log(
						'info',
						sprintf(
							'%1$s: %2$s matches in %3$s rows.',
							$name,
							Util::number( $entry['matches'] ),
							Util::number( $entry['changed'] )
						)
					);
				}
				$state['index']  = $i + 1;
				$state['cursor'] = null;
				$ctx->checkpoint();
				unset( $entry );
				continue;
			}

			$bytes = 0;
			if ( ! $dry ) {
				$db->begin();
			}
			try {
				foreach ( $rows as $row ) {
					$changes = array();
					foreach ( $text as $col ) {
						$value = $row[ $col ];
						if ( null === $value || '' === $value ) {
							continue;
						}
						$bytes += strlen( $value );
						$before = $replacer->stats();
						$new    = $replacer->replace( $value );
						if ( $new !== $value ) {
							$changes[ $col ]          = $new;
							$after                    = $replacer->stats();
							$delta                    = $after['matches'] - $before['matches'];
							$entry['columns'][ $col ] = ( $entry['columns'][ $col ] ?? 0 ) + $delta;
							$entry['matches']        += $delta;
							$entry['nested']         += $after['nested'] - $before['nested'];
							if ( count( $state['samples'] ) < $limit ) {
								$state['samples'][] = array(
									'table'  => $name,
									'column' => $col,
									'key'    => implode(
										',',
										array_map(
											static function ( $c ) use ( $row ) {
												return (string) $row[ $c ];
											},
											$pk
										)
									),
									'before' => self::snippet( $value, $new, true ),
									'after'  => self::snippet( $value, $new, false ),
								);
							}
						}
					}
					if ( $changes ) {
						++$entry['changed'];
						if ( ! $dry ) {
							$where = array();
							foreach ( $pk as $c ) {
								$where[ $c ] = $row[ $c ];
							}
							$db->update_row( $name, $where, $changes );
						}
					}
				}

				$last              = end( $rows );
				$state['cursor']   = Cursor::pack( $last, $pk );
				$entry['scanned'] += count( $rows );
				$sizer->record( count( $rows ), microtime( true ) - $t0, max( 1, $bytes ) );
				if ( $budget->memory_pressure() ) {
					$sizer->shrink();
				}
				$state['batch'] = $sizer->export_state();
				$this->update_totals( $state );
				$ctx->checkpoint();
				if ( ! $dry ) {
					$db->commit();
				}
			} catch ( \Throwable $e ) {
				if ( ! $dry ) {
					try {
						$db->rollback();
					} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
						unset( $ignored );
					}
				}
				throw $e;
			}
			unset( $entry, $rows );
		}

		return 'done' === $state['phase'];
	}

	/**
	 * Add up the table counters.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function update_totals( array &$state ) {
		$t = array(
			'scanned' => 0,
			'changed' => 0,
			'matches' => 0,
			'nested'  => 0,
		);
		foreach ( $state['tables'] as $e ) {
			$t['scanned'] += $e['scanned'];
			$t['changed'] += $e['changed'];
			$t['matches'] += $e['matches'];
			$t['nested']  += $e['nested'];
		}
		$state['totals'] = $t;
	}

	/**
	 * Show a short excerpt around the first difference.
	 *
	 * @param string $old    Old value.
	 * @param string $new    New value.
	 * @param bool   $before True for the old value excerpt.
	 * @return string
	 */
	public static function snippet( $old, $new, $before ) {
		$max = min( strlen( $old ), strlen( $new ) );
		$pos = 0;
		while ( $pos < $max && $old[ $pos ] === $new[ $pos ] ) {
			++$pos;
		}
		$start = max( 0, $pos - 40 );
		$text  = $before ? $old : $new;
		$cut   = substr( $text, $start, 120 );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$cut = (string) mb_convert_encoding( $cut, 'UTF-8', 'UTF-8' );
		}
		return ( $start > 0 ? '…' : '' ) . $cut . ( strlen( $text ) > $start + 120 ? '…' : '' );
	}

	/**
	 * Rollback snapshot of the selected tables before a live run.
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
			'label'                 => 'before-search-replace',
			'tables'                => array_map(
				static function ( $t ) {
					return $t['name'];
				},
				$state['tables']
			),
			'protect_with_site_key' => true,
		);
		if ( null === $state['snapshot'] ) {
			$state['snapshot'] = array();
			$export->prepare( $sub, $state['snapshot'], $ctx );
			$ctx->checkpoint();
		}
		if ( $export->step( $sub, $state['snapshot'], $budget, $ctx ) ) {
			$state['snapshot_id'] = $state['snapshot']['package'];
			$export->complete( $sub, $state['snapshot'], $ctx );
			$ctx->log( 'info', 'Rollback snapshot saved before changing anything.', array( 'snapshot' => $state['snapshot_id'] ) );
			$state['phase'] = 'scan';
		}
		$ctx->checkpoint();
	}

	/**
	 * Summary line.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function complete( array $params, array &$state, JobContext $ctx ) {
		$t        = $state['totals'];
		$affected = 0;
		foreach ( $state['tables'] as $e ) {
			if ( $e['matches'] ) {
				++$affected;
			}
		}
		$ctx->log(
			'info',
			sprintf(
				'%1$s: %2$s matches in %3$d tables (%4$s inside serialized or JSON data). %5$s',
				$this->dry( $params ) ? 'Dry run finished' : 'Replacement finished',
				Util::number( $t['matches'] ),
				$affected,
				Util::number( $t['nested'] ),
				$this->dry( $params ) ? 'Nothing was written.' : ''
			)
		);
	}

	/**
	 * Remove an unused snapshot if the job was cancelled before it finished.
	 *
	 * @param array      $params Parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function cleanup( array $params, array &$state, JobContext $ctx ) {
		if ( ! empty( $state['snapshot'] ) && empty( $state['snapshot_id'] ) ) {
			( new ExportTask() )->cleanup( array(), $state['snapshot'], $ctx );
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
		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			return 100.0;
		}
		$total = 0;
		$done  = 0;
		foreach ( (array) ( $state['tables'] ?? array() ) as $t ) {
			$est    = max( 1, (int) $t['rows_est'], (int) $t['scanned'] );
			$total += $est;
			$done  += $t['done'] ? $est : (int) $t['scanned'];
		}
		$p = $total ? $done / $total * 100 : 0;
		if ( 'snapshot' === ( $state['phase'] ?? '' ) && ! empty( $state['snapshot'] ) ) {
			$p = ( new ExportTask() )->progress( array(), $state['snapshot'] ) * 0.3;
		}
		return min( 99.0, $p );
	}

	/**
	 * What is happening now.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return string
	 */
	public function describe( array $params, array $state ) {
		$phase = (string) ( $state['phase'] ?? '' );
		if ( 'done' === $phase ) {
			return $this->dry( $params ) ? __( 'Dry run finished. Nothing was written.', 'estasoft-migrate' ) : __( 'Replacement finished.', 'estasoft-migrate' );
		}
		if ( 'snapshot' === $phase ) {
			return __( 'Saving a rollback snapshot first.', 'estasoft-migrate' );
		}
		$i = (int) ( $state['index'] ?? 0 );
		if ( isset( $state['tables'][ $i ] ) ) {
			/* translators: %s: table name */
			return sprintf( __( 'Searching %s.', 'estasoft-migrate' ), $state['tables'][ $i ]['name'] );
		}
		return '';
	}

	/**
	 * Per-table progress, with match counts.
	 *
	 * @param array $params Parameters.
	 * @param array $state  State.
	 * @return array
	 */
	public function tables( array $params, array $state ) {
		$out = array();
		foreach ( (array) ( $state['tables'] ?? array() ) as $i => $t ) {
			$out[] = array(
				'name'    => $t['name'],
				'done'    => (int) $t['scanned'],
				'total'   => max( (int) $t['rows_est'], (int) $t['scanned'] ),
				'status'  => $t['skipped'] ? 'skipped' : ( $t['done'] ? 'done' : ( (int) ( $state['index'] ?? 0 ) === $i ? 'running' : 'waiting' ) ),
				'matches' => (int) $t['matches'],
				'rows'    => (int) $t['changed'],
			);
		}
		return $out;
	}
}
