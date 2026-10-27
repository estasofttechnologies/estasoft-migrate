<?php
/**
 * Saves and loads jobs.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\Database;
use Estasoft\Migrate\Db\Identifier;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs live in their own table. Every batch saves the job state there, so a job can resume
 * after a timeout, a crash or a server restart.
 */
class JobStore {

	/**
	 * Database.
	 *
	 * @var Database
	 */
	private $db;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param Database $db    Database.
	 * @param string   $table Jobs table name.
	 */
	public function __construct( Database $db, $table ) {
		$this->db    = $db;
		$this->table = $table;
	}

	/**
	 * Quoted table name.
	 *
	 * @return string
	 */
	private function t() {
		return Identifier::quote( $this->table );
	}

	/**
	 * Create a job.
	 *
	 * @param string $type   Task type.
	 * @param string $title  Title.
	 * @param array  $params Parameters.
	 * @return Job
	 */
	public function create( $type, $title, array $params ) {
		$now = Util::now();
		$this->db->query(
			'INSERT INTO ' . $this->t() . ' (type, status, title, params, state, progress, message, runner, locked_until, created_gmt, updated_gmt) VALUES ('
			. $this->db->quote( $type ) . ', ' . $this->db->quote( Job::QUEUED ) . ', ' . $this->db->quote( $title ) . ', '
			. $this->db->quote( self::encode( $params ) ) . ', ' . $this->db->quote( '{}' ) . ", 0, '', '', 0, "
			. $this->db->quote( $now ) . ', ' . $this->db->quote( $now ) . ')'
		);
		$job = $this->find( $this->db->insert_id() );
		return $job;
	}

	/**
	 * Load a job.
	 *
	 * @param int $id Job id.
	 * @return Job|null
	 */
	public function find( $id ) {
		$rows = $this->db->get_results( 'SELECT * FROM ' . $this->t() . ' WHERE id = %d', array( (int) $id ) );
		return $rows ? $this->hydrate( $rows[0] ) : null;
	}

	/**
	 * Save changes to status, title, state, progress and message.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function save( Job $job ) {
		$job->updated_gmt = Util::now();
		// A pause or cancel that arrived while a slice was running must not be undone by that slice's checkpoint.
		$status = $this->db->quote( $job->status );
		if ( in_array( $job->status, array( Job::RUNNING, Job::QUEUED ), true ) ) {
			$status = "CASE WHEN status IN ('paused','cancelled') THEN status ELSE " . $status . ' END';
		}
		$this->db->query(
			'UPDATE ' . $this->t() . ' SET status = ' . $status
			. ', title = ' . $this->db->quote( $job->title )
			. ', params = ' . $this->db->quote( self::encode( $job->params ) )
			. ', state = ' . $this->db->quote( self::encode( $job->state ) )
			. ', progress = ' . $this->db->quote( number_format( min( 100, max( 0, (float) $job->progress ) ), 2, '.', '' ) )
			. ', message = ' . $this->db->quote( (string) $job->message )
			. ', runner = ' . $this->db->quote( (string) $job->runner )
			. ', started_gmt = ' . $this->db->quote( $job->started_gmt )
			. ', finished_gmt = ' . $this->db->quote( $job->finished_gmt )
			. ', updated_gmt = ' . $this->db->quote( $job->updated_gmt )
			. ' WHERE id = ' . (int) $job->id
		);
	}

	/**
	 * Read only the status column (cheap check used between batches).
	 *
	 * @param int $id Job id.
	 * @return string|null
	 */
	public function status_of( $id ) {
		return $this->db->get_var( 'SELECT status FROM ' . $this->t() . ' WHERE id = %d', array( (int) $id ) );
	}

	/**
	 * Change the status only, without touching state.
	 *
	 * @param int    $id      Job id.
	 * @param string $status  New status.
	 * @param string $message Optional message.
	 * @param bool   $release_lock Clear the lock when the job ends. Cancelling leaves it, so a slice that is running keeps its lock.
	 * @return void
	 */
	public function set_status( $id, $status, $message = null, $release_lock = true ) {
		$sql = 'UPDATE ' . $this->t() . ' SET status = ' . $this->db->quote( $status ) . ', updated_gmt = ' . $this->db->quote( Util::now() );
		if ( null !== $message ) {
			$sql .= ', message = ' . $this->db->quote( $message );
		}
		if ( in_array( $status, array( Job::COMPLETED, Job::FAILED, Job::CANCELLED ), true ) ) {
			$sql .= ', finished_gmt = ' . $this->db->quote( Util::now() ) . ( $release_lock ? ', locked_until = 0' : '' );
		}
		$this->db->query( $sql . ' WHERE id = ' . (int) $id );
	}

	/**
	 * Try to take the job's lock. Only one runner may work on a job at a time.
	 *
	 * @param int $id      Job id.
	 * @param int $seconds Lock lifetime (a crashed runner frees the job after this long).
	 * @return bool
	 */
	public function lock( $id, $seconds ) {
		$now = time();
		$n   = $this->db->query(
			'UPDATE ' . $this->t() . ' SET locked_until = ' . ( $now + max( 5, (int) $seconds ) )
			. ' WHERE id = ' . (int) $id . ' AND locked_until < ' . $now
		);
		return $n > 0;
	}

	/**
	 * Extend a lock the caller already holds.
	 *
	 * @param int $id      Job id.
	 * @param int $seconds Seconds from now.
	 * @return void
	 */
	public function extend_lock( $id, $seconds ) {
		$this->db->query( 'UPDATE ' . $this->t() . ' SET locked_until = ' . ( time() + max( 5, (int) $seconds ) ) . ' WHERE id = ' . (int) $id );
	}

	/**
	 * Release the lock.
	 *
	 * @param int $id Job id.
	 * @return void
	 */
	public function unlock( $id ) {
		$this->db->query( 'UPDATE ' . $this->t() . ' SET locked_until = 0 WHERE id = ' . (int) $id );
	}

	/**
	 * Newest jobs first.
	 *
	 * @param array $args limit (int), status (string|string[]), type (string).
	 * @return Job[]
	 */
	public function all( array $args = array() ) {
		$where = array( '1=1' );
		if ( ! empty( $args['status'] ) ) {
			$list    = array_map( array( $this->db, 'quote' ), (array) $args['status'] );
			$where[] = 'status IN (' . implode( ',', $list ) . ')';
		}
		if ( ! empty( $args['type'] ) ) {
			$where[] = 'type = ' . $this->db->quote( (string) $args['type'] );
		}
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$rows  = $this->db->get_results( 'SELECT * FROM ' . $this->t() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT ' . $limit );
		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Jobs that still need work.
	 *
	 * @return Job[]
	 */
	public function active() {
		return $this->all( array( 'status' => array( Job::QUEUED, Job::RUNNING, Job::PAUSED ) ) );
	}

	/**
	 * The most recent unfinished job, if any (running first).
	 *
	 * @return Job|null
	 */
	public function current() {
		foreach ( $this->all(
			array(
				'status' => array( Job::RUNNING, Job::QUEUED, Job::PAUSED ),
				'limit'  => 20,
			)
		) as $job ) {
			if ( Job::RUNNING === $job->status ) {
				return $job;
			}
		}
		$list = $this->active();
		return $list ? $list[0] : null;
	}

	/**
	 * Delete a job.
	 *
	 * @param int $id Job id.
	 * @return void
	 */
	public function delete( $id ) {
		$this->db->query( 'DELETE FROM ' . $this->t() . ' WHERE id = ' . (int) $id );
	}

	/**
	 * Delete finished jobs older than a number of days.
	 *
	 * @param int $days Days.
	 * @return void
	 */
	public function purge_finished( $days ) {
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * 86400 );
		$this->db->query(
			'DELETE FROM ' . $this->t() . " WHERE status IN ('completed','failed','cancelled') AND updated_gmt < " . $this->db->quote( $cut )
		);
	}

	/**
	 * Row to object.
	 *
	 * @param array $row Database row.
	 * @return Job
	 */
	private function hydrate( array $row ) {
		$job               = new Job();
		$job->id           = (int) $row['id'];
		$job->type         = (string) $row['type'];
		$job->status       = (string) $row['status'];
		$job->title        = (string) $row['title'];
		$job->params       = self::decode( $row['params'] );
		$job->state        = self::decode( $row['state'] );
		$job->progress     = (float) $row['progress'];
		$job->message      = (string) $row['message'];
		$job->runner       = (string) $row['runner'];
		$job->locked_until = (int) $row['locked_until'];
		$job->created_gmt  = $row['created_gmt'];
		$job->updated_gmt  = $row['updated_gmt'];
		$job->started_gmt  = $row['started_gmt'] ?? null;
		$job->finished_gmt = $row['finished_gmt'] ?? null;
		return $job;
	}

	/**
	 * JSON for storage.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function encode( array $data ) {
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return false === $json ? '{}' : $json;
	}

	/**
	 * JSON from storage.
	 *
	 * @param string|null $json JSON.
	 * @return array
	 */
	private static function decode( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}
}
