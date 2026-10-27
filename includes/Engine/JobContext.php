<?php
/**
 * Services a task may use.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\Database;
use Estasoft\Migrate\Logger;
use Estasoft\Migrate\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Passed to every task call. Holds the database, logger, storage and the job being run.
 */
class JobContext {

	/**
	 * Database of this site.
	 *
	 * @var Database
	 */
	public $db;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	public $logger;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	public $storage;

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	public $store;

	/**
	 * Secret used to protect snapshots (the site's connection key).
	 *
	 * @var string
	 */
	public $secret = '';

	/**
	 * Largest batch in rows.
	 *
	 * @var int
	 */
	public $max_batch = 20000;

	/**
	 * Services such as the HTTP client for remote jobs, set by the plugin.
	 *
	 * @var array<string,mixed>
	 */
	public $services = array();

	/**
	 * Job being run.
	 *
	 * @var Job|null
	 */
	public $job;

	/**
	 * Constructor.
	 *
	 * @param Database $db      Database.
	 * @param Logger   $logger  Logger.
	 * @param Storage  $storage Storage.
	 * @param JobStore $store   Job store.
	 */
	public function __construct( Database $db, Logger $logger, Storage $storage, JobStore $store ) {
		$this->db      = $db;
		$this->logger  = $logger;
		$this->storage = $storage;
		$this->store   = $store;
	}

	/**
	 * Write a log line for the current job.
	 *
	 * @param string $level   debug, info, warning, error.
	 * @param string $message Text.
	 * @param array  $context Details.
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		$this->logger->log( $level, $message, $this->job ? $this->job->id : 0, $context );
	}

	/**
	 * Save the job state now. Tasks call this after every batch.
	 *
	 * @return void
	 */
	public function checkpoint() {
		if ( $this->job && $this->job->id ) {
			$this->store->save( $this->job );
			$this->store->extend_lock( $this->job->id, 120 );
		}
	}

	/**
	 * Has someone paused or cancelled the job?
	 *
	 * @return bool
	 */
	public function should_stop() {
		if ( ! $this->job || ! $this->job->id ) {
			return false;
		}
		$status = $this->store->status_of( $this->job->id );
		return Job::RUNNING !== $status && Job::QUEUED !== $status;
	}

	/**
	 * Facts about this site (URL, prefix, versions), for package manifests and URL rewriting.
	 *
	 * @return array
	 */
	public function site_info() {
		$provider = $this->service( 'site_info' );
		if ( is_callable( $provider ) ) {
			return (array) call_user_func( $provider );
		}
		return SiteInfo::collect( $this->db );
	}

	/**
	 * Name of the baselines table.
	 *
	 * @return string
	 */
	public function baselines_table() {
		return $this->db->prefix() . 'estasoft_baselines';
	}

	/**
	 * Get a named service.
	 *
	 * @param string $name Service name.
	 * @return mixed|null
	 */
	public function service( $name ) {
		return $this->services[ $name ] ?? null;
	}
}
