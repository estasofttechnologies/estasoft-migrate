<?php
/**
 * Runs jobs one slice at a time.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\DatabaseException;
use Estasoft\Migrate\Package\PackageException;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Takes the lock, calls the task, saves the result, and turns failures into plain messages.
 *
 * Any runner can call run_slice(): the browser (AJAX), WP-Cron, Action Scheduler or WP-CLI. The
 * lock keeps two runners from working on the same job at once, and a crashed runner's lock
 * expires so the next one picks the job up from the last saved checkpoint.
 */
class JobRunner {

	const MAX_TRANSIENT_FAILURES = 5;
	const LOCK_SECONDS           = 90;

	/**
	 * How many steps in a row may end without finishing (the server killed them) before the job gives up.
	 */
	const MAX_INTERRUPTED_SLICES = 3;

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	private $store;

	/**
	 * Task factory.
	 *
	 * @var TaskRegistry
	 */
	private $tasks;

	/**
	 * Context.
	 *
	 * @var JobContext
	 */
	private $ctx;

	/**
	 * Constructor.
	 *
	 * @param JobStore     $store Store.
	 * @param TaskRegistry $tasks Tasks.
	 * @param JobContext   $ctx   Context.
	 */
	public function __construct( JobStore $store, TaskRegistry $tasks, JobContext $ctx ) {
		$this->store = $store;
		$this->tasks = $tasks;
		$this->ctx   = $ctx;
	}

	/**
	 * Create a job.
	 *
	 * @param string $type   Task type.
	 * @param string $title  Title.
	 * @param array  $params Parameters.
	 * @return Job
	 * @throws TaskException When the type is unknown.
	 */
	public function create( $type, $title, array $params ) {
		$this->tasks->make( $type ); // Fails early for unknown types.
		$job = $this->store->create( $type, $title, $params );
		$this->ctx->logger->info( sprintf( 'Job created: %s.', $title ), $job->id, array( 'type' => $type ) );
		return $job;
	}

	/**
	 * Run one slice.
	 *
	 * @param int    $job_id Job id.
	 * @param Budget $budget Limits for this slice.
	 * @param string $runner Who is running it: ajax, cron, action_scheduler or cli.
	 * @return array{ran:bool, locked:bool, job:Job|null}
	 */
	public function run_slice( $job_id, Budget $budget, $runner = 'ajax' ) {
		$job = $this->store->find( $job_id );
		if ( ! $job ) {
			return array(
				'ran'    => false,
				'locked' => false,
				'job'    => null,
			);
		}
		if ( ! $job->is_runnable() ) {
			return array(
				'ran'    => false,
				'locked' => false,
				'job'    => $job,
			);
		}
		if ( ! $this->store->lock( $job_id, self::LOCK_SECONDS ) ) {
			return array(
				'ran'    => false,
				'locked' => true,
				'job'    => $job,
			);
		}

		$released = false;
		$store    = $this->store;
		register_shutdown_function(
			static function () use ( &$released, $store, $job_id ) {
				if ( ! $released ) {
					$store->unlock( $job_id );
				}
			}
		);

		// Dates are read and written in UTC while a job works, so a row means the same moment on every server.
		$this->ctx->db->utc_session( true );
		try {
			$job = $this->store->find( $job_id );
			if ( $job && $job->is_runnable() ) {
				$this->work( $job, $budget, $runner );
			}
		} finally {
			$this->ctx->db->utc_session( false );
			$this->store->unlock( $job_id );
			$released = true;
		}

		return array(
			'ran'    => true,
			'locked' => false,
			'job'    => $this->store->find( $job_id ),
		);
	}

	/**
	 * Do the work while holding the lock.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @param string $runner Runner name.
	 * @return void
	 */
	private function work( Job $job, Budget $budget, $runner ) {
		$this->ctx->job = $job;
		$job->runner    = $runner;

		try {
			$task = $this->tasks->make( $job->type );

			if ( Job::QUEUED === $job->status ) {
				$task->prepare( $job->params, $job->state, $this->ctx );
				$job->status      = Job::RUNNING;
				$job->started_gmt = Util::now();
				$job->message     = __( 'Started.', 'estasoft-migrate' );
				$this->ctx->log( 'info', 'Job started.' );
				$this->store->save( $job );
			}

			// If the last step never reported back, the server killed it (most often: out of memory). Use smaller
			// batches, and stop after a few tries instead of dying in the same place for ever.
			$this->note_interrupted_step( $job );
			$job->state['_slice'] = time();
			$this->store->save( $job );

			$done = $task->step( $job->params, $job->state, $budget, $this->ctx );

			unset( $job->state['_slice'], $job->state['_crashes'] );
			$job->state['_failures'] = 0;
			$job->progress           = $task->progress( $job->params, $job->state );
			$job->message            = $task->describe( $job->params, $job->state );

			// A pause or cancel request made while we were working must win, unless it came too late to be undone.
			$db_status = $this->store->status_of( $job->id );
			if ( Job::CANCELLED === $db_status && ! $task->can_cancel( $job->params, $job->state ) ) {
				$db_status = Job::RUNNING;
				$this->store->set_status( $job->id, Job::RUNNING );
				$this->ctx->log( 'warning', 'A cancel request arrived after the new data was already in place, so the job is being finished instead.' );
			}
			if ( in_array( $db_status, array( Job::PAUSED, Job::CANCELLED ), true ) ) {
				$job->status = $db_status;
				if ( Job::CANCELLED === $db_status ) {
					$task->cleanup( $job->params, $job->state, $this->ctx );
					$job->finished_gmt = Util::now();
					$this->ctx->log( 'warning', 'Job cancelled.' );
				} else {
					$this->ctx->log( 'info', 'Job paused.' );
				}
				$this->store->save( $job );
				return;
			}

			if ( $done ) {
				$task->complete( $job->params, $job->state, $this->ctx );
				$job->status       = Job::COMPLETED;
				$job->progress     = 100;
				$job->finished_gmt = Util::now();
				$job->message      = $task->describe( $job->params, $job->state );
				$this->ctx->log( 'info', 'Job completed.' );
			}
			$this->store->save( $job );
		} catch ( \Throwable $e ) {
			$this->handle_failure( $job, $e );
		}
	}

	/**
	 * Turn an exception into a job status. Temporary database problems are retried.
	 *
	 * @param Job        $job Job.
	 * @param \Throwable $e   Failure.
	 * @return void
	 */
	private function handle_failure( Job $job, \Throwable $e ) {
		// Go back to the last saved checkpoint, because the in-memory state may be half updated.
		$fresh = $this->store->find( $job->id );
		if ( $fresh ) {
			$job = $fresh;
		}
		unset( $job->state['_slice'] );
		$failures = (int) ( $job->state['_failures'] ?? 0 ) + 1;

		$transient = ( $e instanceof DatabaseException && $e->is_transient() ) || ( $e instanceof TaskException && $e->retryable );
		if ( $transient && $failures < self::MAX_TRANSIENT_FAILURES ) {
			$job->state['_failures'] = $failures;
			$job->message            = sprintf(
				/* translators: %d: attempt number */
				__( 'A temporary problem interrupted the job. Trying again (attempt %d).', 'estasoft-migrate' ),
				$failures + 1
			);
			$this->ctx->job = $job;
			$this->ctx->log( 'warning', 'Temporary problem: ' . rtrim( $e->getMessage(), ". \t\n" ) . '. The job will try again.', array( 'attempt' => $failures ) );
			$this->store->save( $job );
			return;
		}

		$details = array();
		$message = $e->getMessage();
		if ( $e instanceof TaskException ) {
			$details = $e->details;
		} elseif ( $e instanceof PackageException ) {
			$details = array(
				'untouched' => __( 'Your site was not changed.', 'estasoft-migrate' ),
				'advice'    => __( 'Check the package, then retry.', 'estasoft-migrate' ),
			);
		} elseif ( $e instanceof DatabaseException ) {
			$details = array(
				'untouched' => __( 'Rows already handled are safe. The job stopped at the last saved position.', 'estasoft-migrate' ),
				'advice'    => __( 'Retry the job. If it fails again, check the database error in the log.', 'estasoft-migrate' ),
			);
		} else {
			$message = sprintf(
				/* translators: %s: error message */
				__( 'Unexpected error: %s', 'estasoft-migrate' ),
				$e->getMessage()
			);
		}

		$job->status          = Job::FAILED;
		$job->message         = $message;
		$job->finished_gmt    = Util::now();
		$job->state['_error'] = array(
			'message' => $message,
			'details' => $details,
		);
		$this->ctx->job       = $job;
		$this->ctx->log(
			'error',
			$message,
			array_merge(
				$details,
				array(
					'exception' => get_class( $e ),
					'at'        => basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			)
		);
		$this->store->save( $job );
	}

	/**
	 * Run until the job finishes, pauses or fails (command line).
	 *
	 * @param int           $job_id  Job id.
	 * @param callable|null $on_tick Called with the job after every slice.
	 * @param string        $runner  Runner name.
	 * @return Job|null
	 */
	public function run_to_end( $job_id, $on_tick = null, $runner = 'cli' ) {
		$job = $this->store->find( $job_id );
		while ( $job && $job->is_runnable() ) {
			$result = $this->run_slice( $job_id, Budget::unlimited(), $runner );
			$job    = $result['job'];
			if ( $on_tick && $job ) {
				call_user_func( $on_tick, $job );
			}
			if ( $result['locked'] ) {
				usleep( 500000 );
			}
		}
		return $job;
	}

	/**
	 * Pause a job after its current batch.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function pause( $job_id ) {
		$job = $this->store->find( $job_id );
		if ( $job && $job->is_runnable() ) {
			$this->store->set_status( $job_id, Job::PAUSED );
			$this->ctx->logger->info( 'Pause requested.', $job_id );
		}
	}

	/**
	 * Continue a paused job.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function resume( $job_id ) {
		$job = $this->store->find( $job_id );
		if ( $job && Job::PAUSED === $job->status ) {
			$this->store->set_status( $job_id, $job->started_gmt ? Job::RUNNING : Job::QUEUED );
			$this->ctx->logger->info( 'Job resumed.', $job_id );
		}
	}

	/**
	 * Retry a failed job from its last saved position.
	 *
	 * @param int $job_id Job id.
	 * @return bool
	 */
	public function retry( $job_id ) {
		$job = $this->store->find( $job_id );
		if ( ! $job || Job::FAILED !== $job->status ) {
			return false;
		}
		$retried = (int) ( $job->state['_retried'] ?? 0 ) + 1;
		unset( $job->state['_error'] );
		$job->state['_failures'] = 0;
		unset( $job->state['_crashes'], $job->state['_slice'] );
		$job->state['_retried'] = $retried;
		$job->status            = $job->started_gmt ? Job::RUNNING : Job::QUEUED;
		$job->finished_gmt      = null;
		$job->message           = __( 'Retrying from the last saved position.', 'estasoft-migrate' );
		$this->store->save( $job );
		$this->store->unlock( $job_id );
		$this->ctx->logger->info( 'Retry requested. The job continues from its last saved position.', $job_id );
		return true;
	}

	/**
	 * Cancel a job and clean up what it left behind.
	 *
	 * @param int $job_id Job id.
	 * @return bool False when the job is finished already or can no longer be cancelled (its new data is in place).
	 */
	public function cancel( $job_id ) {
		$job = $this->store->find( $job_id );
		if ( ! $job || $job->is_finished() ) {
			return false;
		}
		$task = $this->tasks->make( $job->type );
		if ( ! $task->can_cancel( $job->params, $job->state ) ) {
			$this->ctx->logger->warning( 'Cancel refused: the new data is already in place and the job is finishing.', $job_id );
			return false;
		}
		// Mark it first, without touching the lock: a slice that is running looks at the status between batches and stops.
		$this->store->set_status( $job_id, Job::CANCELLED, __( 'Cancelled.', 'estasoft-migrate' ), false );

		// If no runner is working on it right now, clean up here. Otherwise the running slice does it.
		if ( $this->store->lock( $job_id, 60 ) ) {
			try {
				$job            = $this->store->find( $job_id );
				$this->ctx->job = $job;
				$task->cleanup( $job->params, $job->state, $this->ctx );
				$this->store->save( $job );
				$this->ctx->log( 'warning', 'Job cancelled.' );
			} catch ( \Throwable $e ) {
				$this->ctx->log( 'error', 'Clean-up after cancelling failed: ' . $e->getMessage() );
			} finally {
				$this->store->unlock( $job_id );
			}
		}
		return true;
	}

	/**
	 * Remove a finished job. A failed job keeps its temporary tables so it can be retried, so they go first.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function discard( $job_id ) {
		$job = $this->store->find( $job_id );
		if ( $job && Job::FAILED === $job->status ) {
			$this->clean_failed( $job );
		}
		$this->store->delete( $job_id );
	}

	/**
	 * Housekeeping: free what failed jobs left behind once nobody retried them, and any temporary table
	 * that no job owns any more.
	 *
	 * @param int $failed_days Days a failed job may wait for a retry.
	 * @return int Tables removed.
	 */
	public function sweep( $failed_days = 7 ) {
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $failed_days ) * 86400 );
		foreach ( $this->store->all(
			array(
				'status' => Job::FAILED,
				'limit'  => 200,
			)
		) as $job ) {
			if ( empty( $job->state['_cleaned'] ) && (string) $job->updated_gmt < $cut ) {
				$this->clean_failed( $job, true );
			}
		}

		$removed = 0;
		$db      = $this->ctx->db;
		foreach ( $db->list_tables() as $table ) {
			if ( 1 !== preg_match( '/^_estm_(old_)?(\d+)_\d+$/', $table, $m ) ) {
				continue;
			}
			$job = $this->store->find( (int) $m[2] );
			// Nobody can use these tables: the job is gone, or it ended without needing them.
			if ( ! $job || in_array( $job->status, array( Job::COMPLETED, Job::CANCELLED ), true ) ) {
				$db->drop_table( $table );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Run a failed job's clean-up (temporary tables, partial files) when it has not swapped anything yet.
	 *
	 * @param Job  $job  Failed job.
	 * @param bool $mark Remember that this was done.
	 * @return void
	 */
	private function clean_failed( Job $job, $mark = false ) {
		if ( ! $this->store->lock( $job->id, 60 ) ) {
			return;
		}
		try {
			$this->ctx->job = $job;
			$task           = $this->tasks->make( $job->type );
			if ( $task->can_cancel( $job->params, $job->state ) ) {
				$task->cleanup( $job->params, $job->state, $this->ctx );
			}
			if ( $mark ) {
				$job->state['_cleaned'] = true;
				$this->store->save( $job );
			}
		} catch ( \Throwable $e ) {
			$this->ctx->log( 'error', 'Clean-up of a failed job did not finish: ' . $e->getMessage() );
		} finally {
			$this->store->unlock( $job->id );
		}
	}

	/**
	 * Count a step that never reported back, and give up after too many.
	 *
	 * @param Job $job Job (state is changed).
	 * @return void
	 * @throws TaskException When the job keeps being killed in the same place.
	 */
	private function note_interrupted_step( Job $job ) {
		if ( empty( $job->state['_slice'] ) ) {
			return;
		}
		$crashes                = (int) ( $job->state['_crashes'] ?? 0 ) + 1;
		$job->state['_crashes'] = $crashes;
		unset( $job->state['_slice'] );
		if ( isset( $job->state['batch']['size'] ) ) {
			// Halve the batch and do not let it grow past that again: it was too big for this server.
			$limit                       = (int) ( $job->state['batch']['cap'] ?? $job->state['batch']['size'] );
			$half                        = max( 1, (int) floor( min( $limit, (int) $job->state['batch']['size'] ) / 2 ) );
			$job->state['batch']['size'] = $half;
			$job->state['batch']['cap']  = $half;
		}
		$this->ctx->job = $job;
		$this->ctx->log( 'warning', 'The previous step did not finish. The server probably ran out of memory or time, so smaller batches are used now.', array( 'attempt' => $crashes ) );
		if ( $crashes >= self::MAX_INTERRUPTED_SLICES ) {
			throw new TaskException(
				__( 'The server stopped this job several times in a row, most likely because it ran out of memory or time.', 'estasoft-migrate' ),
				array(
					'untouched' => __( 'Rows already handled are safe. The job stopped at the last saved position.', 'estasoft-migrate' ),
					'advice'    => __( 'Raise the PHP memory limit or lower the largest batch in Settings, then retry.', 'estasoft-migrate' ),
				),
				false
			);
		}
	}
}
