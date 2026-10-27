<?php
/**
 * Keeps jobs moving when nobody has the Progress screen open.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

use Estasoft\Migrate\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * The browser drives a job while the Progress screen is open. In the background the same job is
 * continued by Action Scheduler (when it is installed) or WP-Cron. Every runner takes the job lock
 * first, so two runners never work on a job at the same time.
 */
class Scheduler {

	const HOOK = 'estasoft_migrate_run_job';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * The runner that is really used.
	 *
	 * @return string ajax, cron, action_scheduler or cli.
	 */
	public function mode() {
		$mode = (string) $this->plugin->settings()->get( 'runner', 'auto' );
		if ( 'auto' === $mode ) {
			return function_exists( 'as_enqueue_async_action' ) ? 'action_scheduler' : 'cron';
		}
		if ( 'action_scheduler' === $mode && ! function_exists( 'as_enqueue_async_action' ) ) {
			return 'cron';
		}
		return $mode;
	}

	/**
	 * Is WP-Cron switched off in wp-config.php?
	 *
	 * @return bool
	 */
	public static function cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Ask for the job to be continued in the background.
	 *
	 * @param int $job_id Job id.
	 * @param int $delay  Seconds from now.
	 * @return void
	 */
	public function dispatch( $job_id, $delay = 0 ) {
		$job_id = (int) $job_id;
		$mode   = $this->mode();
		if ( in_array( $mode, array( 'ajax', 'cli' ), true ) ) {
			return;
		}
		if ( 'action_scheduler' === $mode ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, self::HOOK, array( $job_id ), 'estasoft-migrate' );
			} else {
				as_enqueue_async_action( self::HOOK, array( $job_id ), 'estasoft-migrate' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::HOOK, array( $job_id ) );
		}
		if ( ! self::cron_disabled() && 0 === (int) $delay ) {
			spawn_cron();
		}
	}

	/**
	 * Background entry point: work on the job for a while, then ask to be called again.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function handle( $job_id ) {
		$job_id = (int) $job_id;
		$runner = $this->plugin->runner();
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$seconds = (int) $this->plugin->settings()->get( 'slice_seconds', 15 );
		$result  = $runner->run_slice( $job_id, Budget::for_request( $seconds ), $this->mode() );
		if ( $result['job'] && $result['job']->is_runnable() ) {
			$this->dispatch( $job_id, $result['locked'] ? 5 : 1 );
		}
	}

	/**
	 * Restart background work for runnable jobs that have not moved for a while (for example after a
	 * server restart killed the request that was running them).
	 *
	 * @param int $idle_seconds How long a job may be quiet before it is nudged.
	 * @return int Number of jobs nudged.
	 */
	public function nudge_stalled( $idle_seconds = 120 ) {
		$count = 0;
		foreach ( $this->plugin->store()->active() as $job ) {
			if ( ! $job->is_runnable() || $job->locked_until > time() ) {
				continue;
			}
			$updated = $job->updated_gmt ? strtotime( $job->updated_gmt . ' UTC' ) : 0;
			if ( time() - $updated > $idle_seconds ) {
				$this->dispatch( $job->id );
				++$count;
			}
		}
		return $count;
	}
}
