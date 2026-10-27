<?php
/**
 * Everything the admin screens can ask for, as plain PHP.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Rest;

use Estasoft\Migrate\Db\Identifier;
use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Engine\JobConflicts;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\JobParams;
use Estasoft\Migrate\Engine\JobRunner;
use Estasoft\Migrate\Engine\TableSelector;
use Estasoft\Migrate\Engine\TaskRegistry;
use Estasoft\Migrate\Package\Crypto;
use Estasoft\Migrate\Package\PackageException;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Preflight\Checker;
use Estasoft\Migrate\Remote\ClientFactory;
use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Sanitizer\KeepResolver;
use Estasoft\Migrate\Sanitizer\PasswordHasher;
use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Sanitizer\RowSanitizer;
use Estasoft\Migrate\Sanitizer\SecretScrubber;
use Estasoft\Migrate\Settings;
use Estasoft\Migrate\Storage;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * The REST routes, the command line and the tests all call this class. It returns arrays and
 * throws ApiException, so it does not depend on WordPress request objects.
 */
final class Api {

	/**
	 * Services.
	 *
	 * @var JobContext
	 */
	private $ctx;

	/**
	 * Runner.
	 *
	 * @var JobRunner
	 */
	private $runner;

	/**
	 * Task types.
	 *
	 * @var TaskRegistry
	 */
	private $tasks;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Options: dispatch (callable), budget (callable), env (array), endpoint (string), runner_mode (callable).
	 *
	 * @var array
	 */
	private $opts;

	/**
	 * Constructor.
	 *
	 * @param JobContext   $ctx      Services.
	 * @param JobRunner    $runner   Runner.
	 * @param TaskRegistry $tasks    Task types.
	 * @param Settings     $settings Settings.
	 * @param array        $opts     Options.
	 */
	public function __construct( JobContext $ctx, JobRunner $runner, TaskRegistry $tasks, Settings $settings, array $opts = array() ) {
		$this->ctx      = $ctx;
		$this->runner   = $runner;
		$this->tasks    = $tasks;
		$this->settings = $settings;
		$this->opts     = array_merge(
			array(
				'dispatch'    => null,
				'budget'      => null,
				'env'         => array(),
				'endpoint'    => '',
				'runner_mode' => null,
			),
			$opts
		);
	}

	/* ------------------------------------------------------------------ Overview and tables */

	/**
	 * Data for the Overview screen.
	 *
	 * @return array
	 */
	public function overview() {
		$site    = $this->ctx->site_info();
		$tables  = TableSelector::select( $this->ctx->db );
		$rows    = 0;
		$bytes   = 0;
		$largest = null;
		foreach ( $tables as $t ) {
			$rows  += (int) $t['rows_est'];
			$bytes += (int) $t['bytes'];
			if ( null === $largest || (int) $t['bytes'] > $largest['bytes'] ) {
				$largest = array(
					'name'  => $t['name'],
					'rows'  => (int) $t['rows_est'],
					'bytes' => (int) $t['bytes'],
				);
			}
		}

		$last = null;
		foreach ( $this->ctx->store->all(
			array(
				'status' => array( Job::COMPLETED ),
				'limit'  => 30,
			)
		) as $job ) {
			if ( in_array( $job->type, array( 'export', 'import', 'push', 'pull' ), true ) ) {
				$last = $job;
				break;
			}
		}

		$current = $this->ctx->store->current();
		$recent  = array();
		foreach ( $this->ctx->store->all( array( 'limit' => 8 ) ) as $job ) {
			$recent[] = $this->job_summary( $job );
		}

		$packages  = $this->ctx->storage->list_packages();
		$exports   = $this->count_packages( $packages, 'export' );
		$snapshots = $this->count_packages( $packages, 'snapshot' );
		$received  = $this->count_packages( $packages, 'received' );

		$sanitized = 0;
		foreach ( $packages as $pkg ) {
			if ( ! empty( $pkg['sanitized'] ) ) {
				++$sanitized;
			}
		}

		return array(
			'site'       => array(
				'url'        => (string) ( $site['url'] ?? '' ),
				'name'       => (string) ( $site['name'] ?? '' ),
				'prefix'     => $this->ctx->db->prefix(),
				'wp_version' => (string) ( $site['wp_version'] ?? '' ),
				'php'        => (string) ( $site['php_version'] ?? PHP_VERSION ),
				'db'         => $this->ctx->db->server_info(),
				'multisite'  => ! empty( $site['multisite'] ),
			),
			'database'   => array(
				'tables'  => count( $tables ),
				'rows'    => $rows,
				'bytes'   => $bytes,
				'largest' => $largest,
			),
			'last'       => $last ? $this->job_summary( $last ) : null,
			'current'    => $current ? $this->job_view( $current ) : null,
			'recent'     => $recent,
			'packages'   => array(
				'exports'   => $exports,
				'snapshots' => $snapshots,
				'received'  => $received,
				'sanitized' => $sanitized,
				'free'      => $this->free_space(),
			),
			'connection' => array(
				'accepting' => (bool) $this->settings->get( 'accept_incoming', false ),
				'https'     => (bool) $this->settings->get( 'require_https', true ),
				'key_id'    => Settings::key_id( $this->settings->connection_key() ),
			),
			'health'     => $this->environment( false ),
		);
	}

	/**
	 * Tables of this site.
	 *
	 * @return array
	 */
	public function tables() {
		$out   = array();
		$rows  = 0;
		$bytes = 0;
		foreach ( TableSelector::select( $this->ctx->db ) as $t ) {
			$out[]  = array(
				'name'    => $t['name'],
				'logical' => $t['logical'],
				'rows'    => (int) $t['rows_est'],
				'bytes'   => (int) $t['bytes'],
			);
			$rows  += (int) $t['rows_est'];
			$bytes += (int) $t['bytes'];
		}
		return array(
			'tables' => $out,
			'totals' => array(
				'tables' => count( $out ),
				'rows'   => $rows,
				'bytes'  => $bytes,
			),
			'prefix' => $this->ctx->db->prefix(),
		);
	}

	/* ------------------------------------------------------------------ Pre-flight */

	/**
	 * Check a planned job.
	 *
	 * @param array $in Input as for a new job, plus mode.
	 * @return array
	 */
	public function preflight( array $in ) {
		$mode = (string) ( $in['mode'] ?? '' );
		if ( ! in_array( $mode, array( 'export', 'sanitize', 'import', 'search_replace', 'push', 'pull' ), true ) ) {
			throw new ApiException( 'estm_invalid', __( 'Choose what to do first.', 'estasoft-migrate' ), 400 );
		}
		return ( new Checker( $this->ctx, (array) $this->opts['env'] ) )->run( $in );
	}

	/* ------------------------------------------------------------------ Jobs */

	/**
	 * List jobs.
	 *
	 * @param array $q status, type, limit.
	 * @return array
	 */
	public function jobs( array $q = array() ) {
		$args = array( 'limit' => (int) ( $q['limit'] ?? 50 ) );
		if ( ! empty( $q['status'] ) ) {
			$args['status'] = array_map( 'strval', (array) $q['status'] );
		}
		if ( ! empty( $q['type'] ) ) {
			$args['type'] = (string) $q['type'];
		}
		$out = array();
		foreach ( $this->ctx->store->all( $args ) as $job ) {
			$out[] = $this->job_summary( $job );
		}
		return array( 'jobs' => $out );
	}

	/**
	 * Create and start a job.
	 *
	 * @param string $type Job type.
	 * @param array  $in   Input.
	 * @return array Job view.
	 */
	public function create_job( $type, array $in ) {
		$type = (string) $type;
		if ( ! in_array( $type, JobParams::TYPES, true ) ) {
			throw new ApiException( 'estm_invalid', __( 'Unknown job type.', 'estasoft-migrate' ), 400 );
		}
		if ( 'export' === $type && $this->settings->get( 'encrypt_packages' ) && empty( $in['protect_with_site_key'] ) && '' === (string) ( $in['password'] ?? '' ) ) {
			throw new ApiException(
				'estm_password_required',
				__( 'Packages are set to be encrypted. Enter a password for this package, or turn off encryption in Settings.', 'estasoft-migrate' ),
				400
			);
		}
		try {
			list( $title, $params ) = JobParams::build( $type, $in, $this->ctx );
		} catch ( \InvalidArgumentException $e ) {
			throw new ApiException( 'estm_invalid', $e->getMessage(), 400 );
		}

		$busy = JobConflicts::find( $this->ctx->store->active(), $type, $params );
		if ( $busy ) {
			throw new ApiException(
				'estm_busy',
				sprintf(
					/* translators: %s: title of the job that is running */
					__( 'Another job is still in progress: %s. Wait for it to finish, or cancel it first.', 'estasoft-migrate' ),
					$busy->title
				),
				409,
				array( 'job_id' => $busy->id )
			);
		}

		// Imports and pulls replace the users table. The job remembers who started it, so that person stays signed in.
		if ( in_array( $type, array( 'import', 'pull' ), true ) ) {
			$who = $this->ctx->service( 'current_user' );
			$who = is_callable( $who ) ? call_user_func( $who ) : null;
			if ( is_array( $who ) && (int) ( $who['id'] ?? 0 ) > 0 ) {
				$params['started_by'] = array(
					'id'    => (int) $who['id'],
					'login' => (string) ( $who['login'] ?? '' ),
				);
			}
		}

		try {
			$job = $this->runner->create( $type, $title, $params );
		} catch ( \Throwable $e ) {
			throw new ApiException( 'estm_invalid', $e->getMessage(), 400 );
		}
		$this->dispatch( $job->id );
		return $this->job_view( $job );
	}

	/**
	 * One job.
	 *
	 * @param int  $id         Job id.
	 * @param bool $with_state Include the technical state.
	 * @return array
	 */
	public function job( $id, $with_state = false ) {
		return $this->job_view( $this->find_job( $id ), $with_state );
	}

	/**
	 * Work on a job for one slice and report. The Progress screen calls this over and over.
	 *
	 * @param int $id        Job id.
	 * @param int $after_log Highest log line id the screen already has, 0 for the first call.
	 * @return array job view fields plus ran, locked, logs, log_cursor.
	 */
	public function tick( $id, $after_log = 0 ) {
		$this->find_job( $id );
		$budget = is_callable( $this->opts['budget'] )
			? call_user_func( $this->opts['budget'] )
			: Budget::for_request( (int) $this->settings->get( 'slice_seconds', 15 ) );
		$result = $this->runner->run_slice( (int) $id, $budget, 'ajax' );
		$job    = $result['job'] ? $result['job'] : $this->find_job( $id );

		$logs               = $this->job_logs( $job->id, (int) $after_log );
		$view               = $this->job_view( $job );
		$view['ran']        = (bool) $result['ran'];
		$view['locked']     = (bool) $result['locked'];
		$view['logs']       = $logs['lines'];
		$view['log_cursor'] = $logs['cursor'];
		return $view;
	}

	/**
	 * Pause, resume, cancel or retry.
	 *
	 * @param int    $id     Job id.
	 * @param string $action pause, resume, cancel or retry.
	 * @return array
	 */
	public function job_action( $id, $action ) {
		$job = $this->find_job( $id );
		switch ( $action ) {
			case 'pause':
				if ( ! $job->is_runnable() ) {
					throw new ApiException( 'estm_state', __( 'This job is not running.', 'estasoft-migrate' ), 409 );
				}
				$this->runner->pause( $job->id );
				break;
			case 'resume':
				if ( Job::PAUSED !== $job->status ) {
					throw new ApiException( 'estm_state', __( 'This job is not paused.', 'estasoft-migrate' ), 409 );
				}
				$this->runner->resume( $job->id );
				$this->dispatch( $job->id );
				break;
			case 'cancel':
				if ( $job->is_finished() ) {
					throw new ApiException( 'estm_state', __( 'This job has already finished.', 'estasoft-migrate' ), 409 );
				}
				if ( ! $this->runner->cancel( $job->id ) ) {
					throw new ApiException( 'estm_state', __( 'This job can no longer be cancelled: its new data is already in place and it is finishing.', 'estasoft-migrate' ), 409 );
				}
				break;
			case 'retry':
				if ( ! $this->runner->retry( $job->id ) ) {
					throw new ApiException( 'estm_state', __( 'Only a failed job can be retried.', 'estasoft-migrate' ), 409 );
				}
				$this->dispatch( $job->id );
				break;
			default:
				throw new ApiException( 'estm_invalid', __( 'Unknown action.', 'estasoft-migrate' ), 400 );
		}
		return $this->job_view( $this->find_job( $id ) );
	}

	/**
	 * Remove a finished job and its log lines.
	 *
	 * @param int $id Job id.
	 * @return array
	 */
	public function delete_job( $id ) {
		$job = $this->find_job( $id );
		if ( ! $job->is_finished() ) {
			throw new ApiException( 'estm_state', __( 'Cancel the job before removing it.', 'estasoft-migrate' ), 409 );
		}
		$this->runner->discard( $job->id );
		$this->ctx->logger->purge( $job->id );
		return array( 'deleted' => true );
	}

	/**
	 * Can this job still be cancelled?
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	private function can_cancel( Job $job ) {
		try {
			return $this->tasks->make( $job->type )->can_cancel( $job->params, $job->state );
		} catch ( \Throwable $e ) {
			unset( $e );
			return true;
		}
	}

	/**
	 * Load a job or fail with 404.
	 *
	 * @param int $id Job id.
	 * @return Job
	 */
	private function find_job( $id ) {
		$job = $this->ctx->store->find( (int) $id );
		if ( ! $job ) {
			throw new ApiException( 'estm_not_found', __( 'That job no longer exists.', 'estasoft-migrate' ), 404 );
		}
		return $job;
	}

	/**
	 * Ask for background work.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	private function dispatch( $job_id ) {
		if ( is_callable( $this->opts['dispatch'] ) ) {
			call_user_func( $this->opts['dispatch'], (int) $job_id );
		}
	}

	/**
	 * Short form used in lists.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	private function job_summary( Job $job ) {
		$out            = $job->to_array();
		$out['remote']  = (string) ( $job->params['remote_url'] ?? '' );
		$out['dry_run'] = ! empty( $job->params['dry_run'] );
		$out['package'] = (string) ( $job->params['package_id'] ?? ( $job->state['package'] ?? '' ) );
		return $out;
	}

	/**
	 * Full form used by the Progress screen.
	 *
	 * @param Job  $job        Job.
	 * @param bool $with_state Include the technical state.
	 * @return array
	 */
	public function job_view( Job $job, $with_state = false ) {
		$out    = $this->job_summary( $job );
		$steps  = array();
		$tables = array();
		try {
			$task   = $this->tasks->make( $job->type );
			$steps  = $task->steps( $job->params, $job->state );
			$tables = $task->tables( $job->params, $job->state );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$out['params']       = $this->public_params( $job->params );
		$out['steps']        = array_values( $steps );
		$out['tables']       = array_slice( array_values( $tables ), 0, 500 );
		$out['tables_total'] = count( $tables );
		$out['can']          = array(
			'pause'  => $job->is_runnable(),
			'resume' => Job::PAUSED === $job->status,
			'cancel' => ! $job->is_finished() && $this->can_cancel( $job ),
			'retry'  => Job::FAILED === $job->status,
			'delete' => $job->is_finished(),
		);
		$out['stats']        = $this->stats_of( $job, $tables );
		$out['warning']      = null;
		$failures            = (int) ( $job->state['_failures'] ?? 0 );
		if ( $job->is_runnable() && $failures > 0 ) {
			$out['warning'] = array(
				'message' => (string) $job->message,
				'attempt' => $failures + 1,
				'max'     => JobRunner::MAX_TRANSIENT_FAILURES,
			);
		}
		$out['error'] = null;
		if ( Job::FAILED === $job->status && ! empty( $job->state['_error'] ) ) {
			$err          = (array) $job->state['_error'];
			$out['error'] = array(
				'message' => (string) ( $err['message'] ?? $job->message ),
				'details' => (array) ( $err['details'] ?? array() ),
			);
		}
		$out['result'] = $job->is_finished() && Job::COMPLETED === $job->status ? $this->result_of( $job ) : null;
		if ( $with_state ) {
			$out['state'] = $job->public_state();
		}
		return $out;
	}

	/**
	 * Numbers for the Progress screen.
	 *
	 * @param Job   $job    Job.
	 * @param array $tables Per-table progress from the task.
	 * @return array
	 */
	private function stats_of( Job $job, array $tables ) {
		$done  = 0;
		$total = 0;
		$count = array(
			'done'    => 0,
			'running' => 0,
			'waiting' => 0,
		);
		foreach ( $tables as $t ) {
			$done  += (int) $t['done'];
			$total += max( (int) $t['total'], (int) $t['done'] );
			if ( isset( $count[ $t['status'] ] ) ) {
				++$count[ $t['status'] ];
			}
		}
		$start   = $job->started_gmt ? (int) strtotime( $job->started_gmt . ' UTC' ) : 0;
		$end     = $job->finished_gmt ? (int) strtotime( $job->finished_gmt . ' UTC' ) : time();
		$elapsed = $start ? max( 0, $end - $start ) : 0;
		$speed   = $elapsed > 0 ? (int) round( $done / $elapsed ) : 0;
		$eta     = null;
		if ( $job->is_runnable() && $elapsed > 0 && (float) $job->progress >= 1 ) {
			$eta = (int) ceil( $elapsed * ( 100 - (float) $job->progress ) / (float) $job->progress );
		}
		$s     = $job->state;
		$batch = (int) ( $s['batch']['size'] ?? ( $s['export']['batch']['size'] ?? 0 ) );
		return array(
			'rows_done'    => $done,
			'rows_total'   => $total,
			'elapsed'      => $elapsed,
			'rows_per_sec' => $speed,
			'eta'          => $eta,
			'batch'        => $batch,
			'tables'       => $count,
		);
	}

	/**
	 * Parameters without secrets.
	 *
	 * @param array $params Parameters.
	 * @return array
	 */
	private function public_params( array $params ) {
		foreach ( array( 'key_enc', 'passphrase_enc', 'passphrase', 'remote_key' ) as $secret ) {
			if ( isset( $params[ $secret ] ) ) {
				$params[ $secret . '_set' ] = true;
				unset( $params[ $secret ] );
			}
		}
		if ( isset( $params['sanitize'] ) && is_array( $params['sanitize'] ) ) {
			unset( $params['sanitize']['password'], $params['sanitize']['seed'] );
		}
		return $params;
	}

	/**
	 * What a finished job achieved, in a few numbers.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	private function result_of( Job $job ) {
		$s   = $job->state;
		$out = array( 'type' => $job->type );
		switch ( $job->type ) {
			case 'export':
				$out += array(
					'package'    => (string) ( $s['package'] ?? '' ),
					'size'       => (int) ( $s['size'] ?? 0 ),
					'rows'       => (int) ( $s['rows'] ?? 0 ),
					'tables'     => count( (array) ( $s['tables'] ?? array() ) ),
					'masked'     => (array) ( $s['masked'] ?? array() ),
					'notes'      => array_values( (array) ( $s['notes'] ?? array() ) ),
					'sanitized'  => ! empty( $job->params['sanitize'] ),
					'protection' => $this->protection_of( $job->params ),
				);
				break;
			case 'import':
				$rows = 0;
				foreach ( (array) ( $s['tables'] ?? array() ) as $t ) {
					$rows += (int) ( $t['rows'] ?? 0 );
				}
				$out += array(
					'tables'   => count( (array) ( $s['tables'] ?? array() ) ),
					'rows'     => $rows,
					'snapshot' => (string) ( $s['snapshot_id'] ?? '' ),
					'notes'    => array_values( (array) ( $s['notes'] ?? array() ) ),
					'restore'  => 'restore' === ( $job->params['kind'] ?? '' ),
				);
				break;
			case 'push':
				$out += array(
					'remote' => (string) ( $s['remote']['url'] ?? ( $job->params['remote_url'] ?? '' ) ),
					'rows'   => (int) ( $s['export']['rows'] ?? 0 ),
					'tables' => count( (array) ( $s['export']['tables'] ?? array() ) ),
					'size'   => (int) ( $s['upload']['size'] ?? 0 ),
					'notes'  => array_values( (array) ( $s['export']['notes'] ?? array() ) ),
				);
				break;
			case 'pull':
				$rows = 0;
				foreach ( (array) ( $s['import']['tables'] ?? array() ) as $t ) {
					$rows += (int) ( $t['rows'] ?? 0 );
				}
				$out += array(
					'remote'   => (string) ( $s['remote']['url'] ?? ( $job->params['remote_url'] ?? '' ) ),
					'rows'     => $rows,
					'tables'   => count( (array) ( $s['import']['tables'] ?? array() ) ),
					'size'     => (int) ( $s['download']['size'] ?? 0 ),
					'snapshot' => (string) ( $s['import']['snapshot_id'] ?? '' ),
					'notes'    => array_values( (array) ( $s['import']['notes'] ?? array() ) ),
				);
				break;
			case 'search_replace':
				$out += array(
					'dry_run'  => ! empty( $job->params['dry_run'] ),
					'totals'   => (array) ( $s['totals'] ?? array() ),
					'tables'   => array_values( $this->tables_of( $job ) ),
					'samples'  => array_values( (array) ( $s['samples'] ?? array() ) ),
					'snapshot' => (string) ( $s['snapshot_id'] ?? '' ),
					'notes'    => array_values( (array) ( $s['notes'] ?? array() ) ),
				);
				break;
			case 'verify':
				$out += array(
					'package' => (string) ( $s['package'] ?? '' ),
					'blocks'  => (int) ( $s['blocks'] ?? 0 ),
					'size'    => (int) ( $s['size'] ?? 0 ),
				);
				break;
		}
		return $out;
	}

	/**
	 * Per-table results of a search and replace.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	private function tables_of( Job $job ) {
		try {
			return (array) $this->tasks->make( $job->type )->tables( $job->params, $job->state );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * How a package is protected, from job parameters.
	 *
	 * @param array $params Parameters.
	 * @return string password, site-key or none.
	 */
	private function protection_of( array $params ) {
		if ( ! empty( $params['passphrase_enc'] ) || ! empty( $params['passphrase'] ) ) {
			return 'password';
		}
		return ! empty( $params['protect_with_site_key'] ) ? 'site-key' : 'none';
	}

	/* ------------------------------------------------------------------ Logs */

	/**
	 * Log lines for the Logs screen.
	 *
	 * @param array $q job_id, level (this level and worse), levels (exactly these), search, after_id, limit, order.
	 * @return array
	 */
	public function logs( array $q = array() ) {
		$args  = array(
			'job_id'   => isset( $q['job_id'] ) && '' !== $q['job_id'] ? (int) $q['job_id'] : null,
			'level'    => (string) ( $q['level'] ?? '' ),
			'levels'   => \Estasoft\Migrate\Util::to_list( $q['levels'] ?? array() ),
			'search'   => trim( (string) ( $q['search'] ?? '' ) ),
			'after_id' => (int) ( $q['after_id'] ?? 0 ),
			'limit'    => max( 1, min( 1000, (int) ( $q['limit'] ?? 200 ) ) ),
			'order'    => 'asc' === ( $q['order'] ?? 'desc' ) ? 'asc' : 'desc',
		);
		$lines = $this->ctx->logger->query( $args );
		$jobs  = array();
		foreach ( $this->ctx->store->all( array( 'limit' => 50 ) ) as $job ) {
			$jobs[] = array(
				'id'      => $job->id,
				'title'   => $job->title,
				'status'  => $job->status,
				'created' => $job->created_gmt,
			);
		}
		return array(
			'lines' => $lines,
			'jobs'  => $jobs,
		);
	}

	/**
	 * Log lines of one job after a given line, oldest first.
	 *
	 * @param int $job_id Job id.
	 * @param int $after  Last line id the screen has, 0 to get the latest lines.
	 * @return array lines, cursor.
	 */
	private function job_logs( $job_id, $after ) {
		if ( $after > 0 ) {
			$lines = $this->ctx->logger->query(
				array(
					'job_id'   => $job_id,
					'after_id' => $after,
					'order'    => 'asc',
					'limit'    => 200,
				)
			);
		} else {
			$lines = array_reverse(
				$this->ctx->logger->query(
					array(
						'job_id' => $job_id,
						'order'  => 'desc',
						'limit'  => 50,
					)
				)
			);
		}
		$cursor = $after;
		foreach ( $lines as $line ) {
			$cursor = max( $cursor, (int) $line['id'] );
		}
		return array(
			'lines'  => $lines,
			'cursor' => $cursor,
		);
	}

	/**
	 * Log as plain text for download.
	 *
	 * @param int|null $job_id Job id or null for everything.
	 * @return string
	 */
	public function logs_text( $job_id = null ) {
		$lines = array_reverse(
			$this->ctx->logger->query(
				array(
					'job_id' => $job_id,
					'order'  => 'desc',
					'limit'  => 1000,
				)
			)
		);
		$out   = '';
		foreach ( $lines as $l ) {
			$out .= sprintf( "%s  %-7s  job %d  %s\n", $l['created_gmt'], strtoupper( $l['level'] ), $l['job_id'], $l['message'] );
		}
		return $out;
	}

	/**
	 * Remove log lines.
	 *
	 * @param int|null $job_id Job id, or null for every line.
	 * @return array
	 */
	public function clear_logs( $job_id = null ) {
		if ( null !== $job_id ) {
			$this->ctx->logger->purge( (int) $job_id );
		} else {
			$this->ctx->logger->clear();
		}
		return array( 'cleared' => true );
	}

	/* ------------------------------------------------------------------ Packages */

	/**
	 * Packages and snapshots.
	 *
	 * @param string|null $type export, snapshot, received or null.
	 * @return array
	 */
	public function packages( $type = null ) {
		$all = $this->ctx->storage->list_packages( $type ? (string) $type : null );
		$out = array();
		foreach ( $all as $meta ) {
			$out[] = $this->package_view( $meta );
		}
		return array(
			'packages' => $out,
			'totals'   => array(
				'count' => count( $out ),
				'bytes' => (int) array_sum(
					array_map(
						static function ( $p ) {
							return (int) $p['size'];
						},
						$out
					)
				),
				'free'  => $this->free_space(),
			),
		);
	}

	/**
	 * One package for the lists.
	 *
	 * @param array $meta Stored details with id and size.
	 * @return array
	 */
	private function package_view( array $meta ) {
		$id = (string) $meta['id'];
		return array(
			'id'         => $id,
			'type'       => (string) ( $meta['type'] ?? 'export' ),
			'kind'       => (string) ( $meta['kind'] ?? '' ),
			'status'     => (string) ( $meta['status'] ?? 'complete' ),
			'label'      => (string) ( $meta['label'] ?? '' ),
			'file'       => $id . '.' . Storage::EXT,
			'size'       => (int) ( $meta['size'] ?? 0 ),
			'created'    => (string) ( $meta['created_gmt'] ?? '' ),
			'encrypted'  => ! empty( $meta['encrypted'] ),
			'protection' => (string) ( $meta['protection'] ?? 'none' ),
			'sanitized'  => $meta['sanitized'] ?? null,
			'source_url' => (string) ( $meta['source_url'] ?? '' ),
			'tables'     => (int) ( $meta['tables'] ?? 0 ),
			'rows'       => (int) ( $meta['rows'] ?? 0 ),
			'sha256'     => (string) ( $meta['sha256'] ?? '' ),
			'verified'   => isset( $meta['verified_gmt'] )
				? array(
					'ok'      => ! empty( $meta['verified_ok'] ),
					'at'      => (string) $meta['verified_gmt'],
					'message' => (string) ( $meta['verified_msg'] ?? '' ),
				)
				: null,
			'in_use'     => (bool) $this->package_in_use( $id ),
			'origin'     => $this->package_origin( $meta ),
		);
	}

	/**
	 * The job a package came from, for the "Before" column of the snapshot list.
	 *
	 * @param array $meta Stored details.
	 * @return array|null job_id, type, kind, title, remote; null when the job was cleaned up.
	 */
	private function package_origin( array $meta ) {
		$job_id = (int) ( $meta['job_id'] ?? 0 );
		if ( $job_id < 1 ) {
			return null;
		}
		$job = $this->ctx->store->find( $job_id );
		if ( ! $job ) {
			return null;
		}
		return array(
			'job_id' => $job->id,
			'type'   => $job->type,
			'kind'   => (string) ( $job->params['kind'] ?? '' ),
			'title'  => $job->title,
			'remote' => (string) ( $job->params['remote_url'] ?? '' ),
		);
	}

	/**
	 * Is an unfinished job using the package?
	 *
	 * @param string $id Package id.
	 * @return Job|null
	 */
	private function package_in_use( $id ) {
		foreach ( $this->ctx->store->active() as $job ) {
			if ( false !== strpos( (string) wp_json_encode( array( $job->params, $job->state ) ), $id ) ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * Delete a package.
	 *
	 * @param string $id Package id.
	 * @return array
	 */
	public function delete_package( $id ) {
		$id = (string) $id;
		if ( ! Storage::is_valid_id( $id ) || ! is_file( $this->ctx->storage->package_path( $id ) ) ) {
			throw new ApiException( 'estm_not_found', __( 'That package no longer exists.', 'estasoft-migrate' ), 404 );
		}
		$job = $this->package_in_use( $id );
		if ( $job ) {
			throw new ApiException(
				'estm_busy',
				sprintf(
					/* translators: %s: job title */
					__( 'This package is being used by a job that is still in progress: %s.', 'estasoft-migrate' ),
					$job->title
				),
				409
			);
		}
		$this->ctx->storage->delete( $id );
		return array( 'deleted' => true );
	}

	/**
	 * Start receiving a package from the browser.
	 *
	 * @param string $name Original file name.
	 * @param int    $size Size in bytes.
	 * @return array package_id, chunk (suggested chunk size).
	 */
	public function upload_init( $name, $size ) {
		$size = (int) $size;
		if ( $size < 1 ) {
			throw new ApiException( 'estm_invalid', __( 'The file is empty.', 'estasoft-migrate' ), 400 );
		}
		if ( $size > Storage::max_package_bytes() ) {
			throw new ApiException( 'estm_invalid', __( 'The file is larger than this site accepts as a package.', 'estasoft-migrate' ), 413 );
		}
		if ( ! $this->ctx->storage->is_writable() ) {
			throw new ApiException( 'estm_no_space', __( 'The package folder is not writable.', 'estasoft-migrate' ), 507 );
		}
		$free = $this->free_space();
		if ( null !== $free && $free > 0 && $free < $size * 1.1 ) {
			throw new ApiException(
				'estm_no_space',
				sprintf(
					/* translators: 1: needed, 2: free */
					__( 'Not enough disk space: %1$s needed, %2$s free.', 'estasoft-migrate' ),
					Util::format_bytes( $size ),
					Util::format_bytes( $free )
				),
				507
			);
		}
		$base = preg_replace( '/\.estm$/i', '', basename( (string) $name ) );
		$id   = Storage::new_id( 'uploaded-' . $base );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $this->ctx->storage->package_path( $id ), '' ) ) {
			throw new ApiException( 'estm_no_space', __( 'The package file could not be created.', 'estasoft-migrate' ), 507 );
		}
		$this->ctx->storage->write_meta(
			$id,
			array(
				'type'        => 'received',
				'kind'        => 'upload',
				'status'      => 'receiving',
				'label'       => $base,
				'created_gmt' => Util::now(),
				'expected'    => $size,
			)
		);
		return array(
			'package_id' => $id,
			'chunk'      => 1048576,
			'received'   => 0,
		);
	}

	/**
	 * Add a piece of an uploaded package. Sending the same piece twice is harmless.
	 *
	 * @param string $id     Package id.
	 * @param int    $offset Where the piece starts.
	 * @param string $bytes  The piece.
	 * @return array received.
	 */
	public function upload_chunk( $id, $offset, $bytes ) {
		$path   = $this->upload_path( $id );
		$offset = (int) $offset;
		$bytes  = (string) $bytes;
		if ( '' === $bytes || $offset < 0 ) {
			throw new ApiException( 'estm_invalid', __( 'The piece could not be read.', 'estasoft-migrate' ), 400 );
		}
		$expected = (int) ( ( (array) $this->ctx->storage->read_meta( (string) $id ) )['expected'] ?? 0 );
		if ( $expected > 0 && $offset + strlen( $bytes ) > $expected ) {
			throw new ApiException( 'estm_invalid', __( 'The piece is larger than the file announced.', 'estasoft-migrate' ), 413 );
		}
		clearstatcache( true, $path );
		$have = (int) filesize( $path );
		if ( $offset > $have ) {
			throw new ApiException( 'estm_offset', __( 'A piece is missing.', 'estasoft-migrate' ), 409, array( 'received' => $have ) );
		}
		if ( $offset + strlen( $bytes ) <= $have ) {
			return array( 'received' => $have );
		}
		$fh = fopen( $path, 'cb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new ApiException( 'estm_no_space', __( 'The package could not be written.', 'estasoft-migrate' ), 507 );
		}
		fseek( $fh, $offset );
		$written = fwrite( $fh, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( strlen( $bytes ) !== $written ) {
			throw new ApiException( 'estm_no_space', __( 'The server ran out of disk space while receiving the package.', 'estasoft-migrate' ), 507 );
		}
		clearstatcache( true, $path );
		return array( 'received' => (int) filesize( $path ) );
	}

	/**
	 * Finish an upload: check it really is a package.
	 *
	 * @param string $id   Package id.
	 * @param int    $size Expected size.
	 * @return array The package.
	 */
	public function upload_done( $id, $size ) {
		$path = $this->upload_path( $id );
		clearstatcache( true, $path );
		$have = (int) filesize( $path );
		if ( $have !== (int) $size ) {
			throw new ApiException( 'estm_offset', __( 'The file arrived incomplete. Try the upload again.', 'estasoft-migrate' ), 409, array( 'received' => $have ) );
		}
		try {
			$header = PackageReader::read_header( $path );
		} catch ( PackageException $e ) {
			$this->ctx->storage->delete( $id );
			throw new ApiException( 'estm_invalid', $e->getMessage(), 422 );
		}
		$meta         = $this->ctx->storage->update_meta(
			$id,
			array_merge(
				$this->upload_details( $path, $header ),
				array(
					'status'     => 'complete',
					'size'       => $have,
					'sha256'     => (string) hash_file( 'sha256', $path ),
					'encrypted'  => (bool) $header['encrypted'],
					'protection' => $header['encrypted'] ? ( Crypto::KDF_SECRET === $header['kdf'] ? 'site-key' : 'password' ) : 'none',
				)
			)
		);
		$meta['id']   = $id;
		$meta['size'] = $have;
		return array( 'package' => $this->package_view( $meta ) );
	}

	/**
	 * What an uploaded package says about itself: where it came from and how big it is.
	 *
	 * Only possible when the package can be opened without a password: not encrypted, or locked with this
	 * site's own key. Packages with a password show these details once the password is given in Pre-flight.
	 *
	 * @param string $path   Package path.
	 * @param array  $header Package header.
	 * @return array Details to store, empty when the package cannot be read yet.
	 */
	private function upload_details( $path, array $header ) {
		$options = array();
		if ( ! empty( $header['encrypted'] ) ) {
			if ( Crypto::KDF_SECRET !== $header['kdf'] || ! Crypto::available() ) {
				return array();
			}
			$options['secret'] = (string) $this->ctx->secret;
		}
		try {
			$reader = PackageReader::open( $path, $options );
			$frame  = $reader->next( true );
			$reader->close();
		} catch ( PackageException $e ) {
			return array();
		}
		$manifest = $frame && is_array( $frame['data'] ) ? $frame['data'] : array();
		if ( ! $manifest ) {
			return array();
		}
		$tables = (array) ( $manifest['tables'] ?? array() );
		$rows   = 0;
		foreach ( $tables as $table ) {
			$rows += (int) ( $table['rows_est'] ?? 0 );
		}
		return array(
			'source_url' => (string) ( $manifest['source']['url'] ?? '' ),
			'tables'     => count( $tables ),
			'rows'       => $rows,
			'sanitized'  => $manifest['sanitized'] ?? null,
			'kind'       => (string) ( $manifest['kind'] ?? '' ),
		);
	}

	/**
	 * Path of a package that is still being uploaded.
	 *
	 * @param string $id Package id.
	 * @return string
	 */
	private function upload_path( $id ) {
		$id = (string) $id;
		if ( ! Storage::is_valid_id( $id ) || ! is_file( $this->ctx->storage->package_path( $id ) ) ) {
			throw new ApiException( 'estm_not_found', __( 'That upload no longer exists. Start it again.', 'estasoft-migrate' ), 404 );
		}
		$meta = $this->ctx->storage->read_meta( $id );
		if ( ! is_array( $meta ) || 'receiving' !== ( $meta['status'] ?? '' ) || 'upload' !== ( $meta['kind'] ?? '' ) ) {
			throw new ApiException( 'estm_state', __( 'That package is not being uploaded.', 'estasoft-migrate' ), 409 );
		}
		return $this->ctx->storage->package_path( $id );
	}

	/**
	 * Path of a finished package for download.
	 *
	 * @param string $id Package id.
	 * @return array path, name.
	 */
	public function download_info( $id ) {
		$id = (string) $id;
		if ( ! Storage::is_valid_id( $id ) || ! is_file( $this->ctx->storage->package_path( $id ) ) ) {
			throw new ApiException( 'estm_not_found', __( 'That package no longer exists.', 'estasoft-migrate' ), 404 );
		}
		$meta = (array) $this->ctx->storage->read_meta( $id );
		if ( 'complete' !== ( $meta['status'] ?? 'complete' ) ) {
			throw new ApiException( 'estm_state', __( 'That package is not finished yet.', 'estasoft-migrate' ), 409 );
		}
		return array(
			'path' => $this->ctx->storage->package_path( $id ),
			'name' => $id . '.' . Storage::EXT,
			'size' => (int) filesize( $this->ctx->storage->package_path( $id ) ),
		);
	}

	/* ------------------------------------------------------------------ Settings */

	/**
	 * Settings for the Settings screen.
	 *
	 * @return array
	 */
	public function settings() {
		$all  = $this->settings->all();
		$keep = array( 'accept_incoming', 'require_https', 'ip_allowlist', 'runner', 'max_batch', 'slice_seconds', 'encrypt_packages', 'delete_packages_days', 'keep_snapshots', 'delete_snapshots_days', 'delete_on_uninstall' );
		$vals = array();
		$defs = array();
		$base = Settings::defaults();
		foreach ( $keep as $k ) {
			$vals[ $k ] = $all[ $k ];
			$defs[ $k ] = $base[ $k ];
		}
		$key = $this->settings->connection_key();
		return array(
			'values'      => $vals,
			'defaults'    => $defs,
			'connection'  => array(
				'key'                => $key,
				'key_id'             => Settings::key_id( $key ),
				'endpoint'           => (string) $this->opts['endpoint'],
				'previous_key_until' => (int) $all['previous_key_until'],
			),
			'environment' => $this->environment(),
			'runners'     => Settings::RUNNERS,
		);
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Values.
	 * @return array
	 */
	public function save_settings( array $in ) {
		if ( array_key_exists( 'ip_allowlist', $in ) ) {
			// Entries that are not addresses are dropped when saving. If that leaves nothing, the list would be empty and every
			// address would be let in, which is the opposite of what a person who typed a list meant.
			$given = (array) preg_split( '/[\s,]+/', trim( (string) $in['ip_allowlist'] ), -1, PREG_SPLIT_NO_EMPTY );
			$valid = array_filter( $given, array( \Estasoft\Migrate\Remote\IpMatcher::class, 'is_valid_entry' ) );
			if ( $given && ! $valid ) {
				throw new ApiException(
					'estm_invalid',
					sprintf(
						/* translators: %s: the entries that are not IP addresses */
						__( 'None of these is a valid IP address or range: %s. Use an address like 203.0.113.7 or a range like 203.0.113.0/24, one per line, or leave the list empty to accept any address.', 'estasoft-migrate' ),
						implode( ', ', array_slice( $given, 0, 5 ) )
					),
					400
				);
			}
		}
		$this->settings->update( $in );
		return $this->settings();
	}

	/**
	 * Create a new connection key.
	 *
	 * @return array
	 */
	public function regenerate_key() {
		$this->settings->regenerate_key();
		return $this->settings();
	}

	/**
	 * Try a connection to another site.
	 *
	 * @param string $url Address.
	 * @param string $key Connection key.
	 * @return array
	 */
	public function test_connection( $url, $key ) {
		$url = rtrim( trim( (string) $url ), '/' );
		$key = trim( (string) $key );
		if ( ! preg_match( '#^https?://[^\s/]+(?:/[^\s?\#]*)?$#i', $url ) ) {
			throw new ApiException( 'estm_invalid', __( 'Enter the full address of the other site, for example https://example.com', 'estasoft-migrate' ), 400 );
		}
		if ( 0 !== strpos( $key, 'esm_' ) ) {
			throw new ApiException( 'estm_invalid', __( 'That does not look like a connection key. Keys start with esm_.', 'estasoft-migrate' ), 400 );
		}
		$started = microtime( true );
		try {
			$hello = ClientFactory::make( $this->ctx, $url, $key )->call( 'hello', array(), 20, 1 );
		} catch ( RemoteException $e ) {
			throw new ApiException( 'estm_connection', $e->getMessage(), 400, array( 'remote_code' => $e->error_code ) );
		}
		$site = (array) ( $hello['site'] ?? array() );
		return array(
			'ok'         => true,
			'took_ms'    => (int) ( ( microtime( true ) - $started ) * 1000 ),
			'https'      => 0 === strpos( strtolower( $url ), 'https://' ),
			'skew'       => abs( time() - (int) ( $hello['time'] ?? time() ) ),
			'site'       => array(
				'url'        => (string) ( $site['url'] ?? $url ),
				'name'       => (string) ( $site['name'] ?? '' ),
				'wp_version' => (string) ( $site['wp_version'] ?? '' ),
				'php'        => (string) ( $hello['php'] ?? ( $site['php_version'] ?? '' ) ),
				'prefix'     => (string) ( $site['prefix'] ?? '' ),
				'db'         => (array) ( $site['db'] ?? array() ),
				'multisite'  => ! empty( $site['multisite'] ),
			),
			'version'    => (string) ( $hello['version'] ?? '' ),
			'writable'   => ! empty( $hello['writable'] ),
			'free_bytes' => (int) ( $hello['free_bytes'] ?? 0 ),
			'stats'      => (array) ( $hello['stats'] ?? array() ),
		);
	}

	/**
	 * Facts about this server, for the Settings and Overview screens.
	 *
	 * @param bool $probe Test whether the package folder can be downloaded from directly when that was not tested lately. The Overview, which polls, only reads the last answer.
	 * @return array
	 */
	private function environment( $probe = true ) {
		$site = $this->ctx->site_info();
		$mode = is_callable( $this->opts['runner_mode'] ) ? (string) call_user_func( $this->opts['runner_mode'] ) : (string) $this->settings->get( 'runner', 'auto' );
		$env  = array(
			'php'              => PHP_VERSION,
			'wp'               => (string) ( $site['wp_version'] ?? '' ),
			'db'               => $this->ctx->db->server_info(),
			'memory'           => Util::bytes_from_ini( ini_get( 'memory_limit' ) ),
			'max_exec'         => (int) ini_get( 'max_execution_time' ),
			'cron_off'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'action_scheduler' => function_exists( 'as_enqueue_async_action' ),
			'runner'           => $mode,
			'sodium'           => Crypto::available(),
			'zlib'             => function_exists( 'gzdeflate' ),
			'multisite'        => ! empty( $site['multisite'] ),
			'storage'          => array(
				'writable'  => $this->ctx->storage->is_writable(),
				// True: a test file could not be fetched. False: it could. Null: this server could not try.
				'protected' => $this->ctx->storage->direct_access_blocked( false, $probe ),
				'free'      => $this->free_space(),
				'used'      => $this->ctx->storage->total_size(),
			),
		);
		return array_replace_recursive( $env, (array) $this->opts['env'] );
	}

	/**
	 * Free disk space or null.
	 *
	 * @return int|null
	 */
	private function free_space() {
		if ( isset( $this->opts['env']['free'] ) ) {
			return (int) $this->opts['env']['free'];
		}
		return $this->ctx->storage->free_space();
	}

	/**
	 * Count and total size of one type of package.
	 *
	 * @param array  $packages From Storage::list_packages().
	 * @param string $type     Type.
	 * @return array
	 */
	private function count_packages( array $packages, $type ) {
		$count  = 0;
		$bytes  = 0;
		$newest = '';
		foreach ( $packages as $pkg ) {
			if ( ( $pkg['type'] ?? '' ) !== $type ) {
				continue;
			}
			++$count;
			$bytes += (int) $pkg['size'];
			if ( '' === $newest ) {
				$newest = (string) ( $pkg['created_gmt'] ?? '' );
			}
		}
		return array(
			'count'  => $count,
			'bytes'  => $bytes,
			'newest' => $newest,
		);
	}

	/* ------------------------------------------------------------------ Sanitizer */

	/**
	 * Profiles, rules and safety switches, plus accounts that can be kept.
	 *
	 * @return array
	 */
	public function sanitizer_info() {
		$profiles = array();
		foreach ( Profiles::all() as $id => $profile ) {
			$profiles[] = array_merge( array( 'id' => $id ), $profile );
		}
		$prefix = $this->ctx->db->prefix();
		$admins = array();
		$users  = $prefix . 'users';
		$meta   = $prefix . 'usermeta';
		if ( $this->ctx->db->table_exists( $users ) && $this->ctx->db->table_exists( $meta ) ) {
			$rows = $this->ctx->db->get_results(
				'SELECT u.ID, u.user_login, u.user_email FROM ' . Identifier::quote( $users ) . ' u INNER JOIN ' . Identifier::quote( $meta ) . ' m ON m.user_id = u.ID WHERE m.meta_key = %s AND m.meta_value LIKE %s ORDER BY u.ID LIMIT 50',
				array( $prefix . 'capabilities', '%"administrator"%' )
			);
			foreach ( $rows as $row ) {
				$admins[] = array(
					'id'    => (int) $row['ID'],
					'login' => (string) $row['user_login'],
					'email' => (string) $row['user_email'],
				);
			}
		}
		return array(
			'profiles' => $profiles,
			'rules'    => Profiles::rules_info(),
			'safety'   => Profiles::safety_info(),
			'admins'   => $admins,
			'saved'    => $this->settings->sanitizer(),
		);
	}

	/**
	 * Remember the settings of the Sanitizer screen.
	 *
	 * @param array $in profile, rules, safety, keep_users, keep_admins, skip_tables, skip_columns, on_export.
	 * @return array The settings as stored.
	 */
	public function save_sanitizer( array $in ) {
		$profile = (string) ( $in['profile'] ?? 'staging' );
		if ( ! isset( Profiles::all()[ $profile ] ) ) {
			throw new ApiException( 'estm_invalid', __( 'Choose one of the sanitizer profiles.', 'estasoft-migrate' ), 400 );
		}
		return array( 'saved' => $this->settings->save_sanitizer( $in ) );
	}

	/**
	 * Show what the sanitizer would do to a few rows. Nothing is changed.
	 *
	 * @param array $in profile, overrides, per_table (rows per table, default 3).
	 * @return array
	 */
	public function sanitizer_preview( array $in ) {
		$profile = (string) ( $in['profile'] ?? 'staging' );
		if ( ! isset( Profiles::all()[ $profile ] ) ) {
			throw new ApiException( 'estm_invalid', __( 'Choose one of the sanitizer profiles.', 'estasoft-migrate' ), 400 );
		}
		$config = Profiles::build_config( $profile, (array) ( $in['overrides'] ?? array() ) );
		$db     = $this->ctx->db;
		$prefix = $db->prefix();
		$keep   = KeepResolver::resolve( $db, $prefix, $config );
		$san    = new RowSanitizer( $config, bin2hex( random_bytes( 16 ) ), PasswordHasher::hash( (string) $config['password'] ), $keep );
		$per    = max( 1, min( 10, (int) ( $in['per_table'] ?? 3 ) ) );

		$out = array();
		foreach ( array( 'users', 'usermeta', 'comments', 'options', 'postmeta', 'wc_customer_lookup', 'wc_order_addresses', 'wc_orders' ) as $logical ) {
			$table = $prefix . $logical;
			if ( ! $db->table_exists( $table ) ) {
				continue;
			}
			$pk       = $db->primary_key( $table );
			$cursor   = null;
			$scanned  = 0;
			$examples = array();
			$dropped  = 0;
			while ( $scanned < 600 && count( $examples ) < $per ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The array grows inside the loop.
				$rows = $db->fetch_batch( $table, $pk, $cursor, 100, $scanned );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					++$scanned;
					$after = $san->process( $logical, $row );
					if ( null === $after ) {
						++$dropped;
						continue;
					}
					$changed = array();
					foreach ( $after as $col => $value ) {
						if ( (string) $value !== (string) $row[ $col ] ) {
							$changed[] = $col;
						}
					}
					if ( $changed && count( $examples ) < $per ) {
						$examples[] = array(
							'changed' => $changed,
							'before'  => $this->show_before( $logical, $row, $changed ),
							'after'   => array_intersect_key( $after, array_flip( $changed ) ),
						);
					}
				}
				if ( $pk ) {
					$last   = end( $rows );
					$cursor = array();
					foreach ( $pk as $col ) {
						$cursor[] = $last[ $col ];
					}
				}
				if ( count( $rows ) < 100 ) {
					break;
				}
			}
			if ( $examples || $dropped ) {
				$out[] = array(
					'table'    => $table,
					'logical'  => $logical,
					'examples' => $examples,
					'dropped'  => $dropped,
				);
			}
		}
		return array(
			'profile' => $profile,
			'tables'  => $out,
			'kept'    => count( $keep ),
		);
	}

	/**
	 * The "before" side of a preview, with secrets hidden.
	 *
	 * @param string $logical Table without prefix.
	 * @param array  $row     Row.
	 * @param array  $changed Changed columns.
	 * @return array
	 */
	private function show_before( $logical, array $row, array $changed ) {
		$before = array_intersect_key( $row, array_flip( $changed ) );
		foreach ( $before as $col => $value ) {
			$hide = in_array( $col, array( 'user_pass', 'user_activation_key', 'secret' ), true )
				|| ( 'options' === $logical && 'option_value' === $col && SecretScrubber::is_secret_option( (string) ( $row['option_name'] ?? '' ) ) )
				|| ( 'usermeta' === $logical && 'meta_value' === $col && in_array( (string) ( $row['meta_key'] ?? '' ), array( 'session_tokens' ), true ) );
			if ( $hide ) {
				$text           = (string) $value;
				$before[ $col ] = ( strlen( $text ) > 4 ? substr( $text, 0, 4 ) : '' ) . '••••••••';
			}
		}
		return $before;
	}
}
