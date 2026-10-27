<?php
/**
 * Push and pull between two test sites, through the real signing and server code.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Remote\Signer;
use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Remote flows.
 */
class RemoteFlowTest extends TestCase {

	/**
	 * Rigs.
	 *
	 * @var EngineHarness[]
	 */
	private $rigs = array();

	protected function tearDown(): void {
		foreach ( $this->rigs as $rig ) {
			$rig->cleanup();
		}
		$this->rigs = array();
	}

	/**
	 * Two sites, the first connected to the second.
	 *
	 * @param int $local_posts  Posts on the sending side.
	 * @param int $remote_posts Posts on the other side.
	 * @return EngineHarness[]
	 */
	private function pair( $local_posts = 25, $remote_posts = 3 ) {
		$local  = new EngineHarness( 'wp_', 'https://local.example.com' );
		$remote = new EngineHarness( 'wp_', 'https://live.example.com' );
		$local->seed( 'https://local.example.com', $local_posts );
		$remote->seed( 'https://live.example.com', $remote_posts );
		$remote->serve();
		$local->connect_to( $remote );
		$this->rigs = array( $local, $remote );
		return array( $local, $remote );
	}

	public function test_push_sends_the_site_and_the_other_side_imports_it() {
		list( $local, $remote ) = $this->pair();
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $local->logger->messages( 'error' ), true ) );

		$this->assertSame( '25', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertSame( 'https://live.example.com', $remote->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" ) );
		$this->assertStringContainsString( 'https://live.example.com/wp-content/uploads/img9.jpg', $remote->db->get_var( 'SELECT post_content FROM wp_posts WHERE ID = 9' ) );
		$this->assertCount( 1, $remote->storage->list_packages( 'snapshot' ), 'The other site keeps a rollback snapshot.' );
		$this->assertSame( array(), $remote->storage->list_packages( 'received' ), 'The transfer copy is removed.' );
		$this->assertSame( array(), $local->storage->list_packages( 'export' ), 'The local package is removed.' );
		$this->assertSame( 'https://local.example.com', $local->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" ), 'The sending site is untouched.' );

		$peer = \Estasoft\Migrate\Engine\Tasks\RemoteTask::peer_id( 'https://live.example.com' );
		$this->assertGreaterThan( 5, (int) $local->db->get_var( 'SELECT COUNT(*) FROM wp_estasoft_baselines WHERE peer = ' . $local->db->quote( $peer ) ), 'Baselines are kept for later incremental syncs.' );
	}

	public function test_the_package_is_encrypted_on_the_way() {
		list( $local, $remote ) = $this->pair( 5 );
		$seen = array();
		$t    = $local->connect_to( $remote );
		$t->before = function ( $action, $headers, $body ) use ( &$seen ) {
			if ( 'put_part' === $action ) {
				$seen[] = base64_decode( json_decode( $body, true )['data'] );
			}
			return null;
		};
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$this->assertNotEmpty( $seen );
		$this->assertStringNotContainsString( 'local.example.com', implode( '', $seen ), 'Package data must not travel in readable form.' );
	}

	public function test_pull_brings_the_other_site_here() {
		list( $local, $remote ) = $this->pair( 3, 25 );
		$job = $local->run( 'pull', $local->remote_params() );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $local->logger->messages( 'error' ), true ) );

		$this->assertSame( '25', $local->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertSame( 'https://local.example.com', $local->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" ) );
		$this->assertStringContainsString( 'https://local.example.com/wp-content/uploads/img9.jpg', $local->db->get_var( 'SELECT post_content FROM wp_posts WHERE ID = 9' ) );
		$this->assertSame( array(), $remote->storage->list_packages( 'export' ), 'The other site cleaned up its package.' );
		$this->assertSame( array(), $local->storage->list_packages( 'received' ), 'The downloaded copy is removed.' );
		$this->assertCount( 1, $local->storage->list_packages( 'snapshot' ), 'A rollback snapshot of the old local site exists.' );
		$this->assertSame( '25', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
	}

	public function test_pull_can_sanitize_before_anything_leaves_the_other_site() {
		list( $local, $remote ) = $this->pair( 3, 10 );
		// Local pulls from remote with the staging profile.
		$local->connect_to( $remote );
		$job = $local->run( 'pull', $local->remote_params( array( 'sanitize' => array( 'profile' => 'staging', 'overrides' => array( 'seed' => 'x' ) ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $local->logger->messages( 'error' ), true ) );

		$this->assertMatchesRegularExpression( '/^user\d+@example\.invalid$/', $local->db->get_var( 'SELECT user_email FROM wp_users WHERE ID = 2' ) );
		$this->assertSame( 'jane.doe@gmail.com', $remote->db->get_var( 'SELECT user_email FROM wp_users WHERE ID = 2' ), 'The live site keeps its real data.' );
		$active = unserialize( $local->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" ) );
		$this->assertNotContains( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', $active );
		$this->assertSame( 'https://local.example.com', $local->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" ) );
	}

	public function test_push_continues_after_being_stopped_many_times() {
		list( $local, $remote ) = $this->pair( 40 );
		$local->ctx->max_batch  = 9;
		$remote->ctx->max_batch = 9;
		$job = $local->run_in_small_slices( 'push', $local->remote_params(), 1 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $local->logger->messages( 'error' ), true ) );
		$this->assertSame( '40', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertGreaterThan( 15, $local->slices );
	}

	public function test_a_lost_answer_is_retried_without_sending_data_twice() {
		list( $local, $remote ) = $this->pair( 10 );
		$transport = $local->connect_to( $remote );
		$lost      = 0;
		$transport->after = function ( $action, $reply ) use ( &$lost ) {
			if ( 'put_part' === $action && 0 === $lost ) {
				++$lost;
				throw new RemoteException( 'estm_timeout', 'timed out', 0, array(), true );
			}
			return null;
		};
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $local->logger->messages( 'error' ), true ) );
		$this->assertSame( 1, $lost );
		$this->assertSame( '10', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
	}

	public function test_a_failed_import_on_the_other_side_fails_the_push_and_a_retry_can_succeed() {
		list( $local, $remote ) = $this->pair( 10 );
		$transport = $local->connect_to( $remote );
		$original  = array();
		$sabotage  = true;
		// Damage the package on the other site right after it arrived.
		$transport->after = function ( $action, $reply ) use ( $remote, &$original, &$sabotage ) {
			if ( 'put_done' === $action && $sabotage ) {
				foreach ( glob( $remote->dir . '/*.estm' ) as $path ) {
					$raw              = file_get_contents( $path );
					$original[ $path ] = $raw;
					file_put_contents( $path, substr_replace( $raw, 'XXXXXXXX', (int) ( strlen( $raw ) / 2 ), 8 ) );
				}
			}
			return null;
		};

		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::FAILED, $job->status, 'A damaged package must stop the import.' );
		$this->assertStringContainsString( 'The other site stopped the job', $job->message );
		$this->assertSame( '3', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ), 'The other site was not touched.' );
		$this->assertSame( 'https://live.example.com', $remote->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" ) );
		$this->assertSame( 0, $local->store->find( $job->id )->state['import']['job_id'], 'A retry will start a fresh import.' );

		// The cause is fixed (here: the file is put back) and the job is retried.
		$this->assertNotEmpty( $original );
		foreach ( $original as $path => $raw ) {
			file_put_contents( $path, $raw );
		}
		$sabotage = false;
		$local->runner->retry( $job->id );
		$job = $local->runner->run_to_end( $job->id );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$this->assertSame( '10', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
	}

	public function test_a_package_the_other_site_lost_is_sent_again() {
		list( $local, $remote ) = $this->pair( 10 );
		$this->add_bulk( $local, 400 );
		$transport = $local->connect_to( $remote );
		$dropped   = 0;
		$transport->after = function ( $action, $reply ) use ( $remote, &$dropped ) {
			if ( 'put_part' === $action && 0 === $dropped ) {
				++$dropped;
				foreach ( $remote->storage->list_packages() as $pkg ) {
					$remote->storage->delete( $pkg['id'] );
				}
				foreach ( glob( $remote->dir . '/*.estm*' ) as $f ) {
					unlink( $f );
				}
			}
			return null;
		};
		$job = $local->run( 'push', $local->remote_params( array( 'part_size' => 65536 ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $local->logger->messages( 'error' ), true ) );
		$this->assertSame( 1, $dropped );
		$this->assertSame( '10', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertGreaterThan( 3, count( array_keys( $transport->log, 'put_part', true ) ), 'The upload really used several parts.' );
	}

	/**
	 * Add rows that do not compress, so the package becomes large.
	 *
	 * @param EngineHarness $rig  Rig.
	 * @param int           $rows Rows.
	 * @return void
	 */
	private function add_bulk( EngineHarness $rig, $rows ) {
		$rig->db->begin();
		for ( $i = 0; $i < $rows; $i++ ) {
			$rig->db->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, 'bulk', " . $rig->db->quote( base64_encode( random_bytes( 900 ) ) ) . ')' );
		}
		$rig->db->commit();
	}

	public function test_wrong_key_is_explained() {
		list( $local, $remote ) = $this->pair();
		$local->connect_to( $remote, 'esm_wrong-key' );
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'did not accept the connection key', $job->message );
		$this->assertSame( '3', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
	}

	public function test_receiving_must_be_switched_on() {
		list( $local, $remote ) = $this->pair();
		$remote->settings->update_raw( array( 'accept_incoming' => false ) );
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'Accept incoming migrations', $job->message );
	}

	public function test_https_can_be_required() {
		list( $local, $remote ) = $this->pair();
		$t        = $local->connect_to( $remote );
		$t->https = false;
		$job      = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::FAILED, $job->status );

		$remote->settings->update_raw( array( 'require_https' => false ) );
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
	}

	public function test_ip_allow_list_is_enforced() {
		list( $local, $remote ) = $this->pair();
		$remote->settings->update_raw( array( 'ip_allowlist' => "198.51.100.0/24\n2001:db8::/32" ) );
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( '203.0.113.50', $job->message );

		$remote->settings->update_raw( array( 'ip_allowlist' => '203.0.113.0/24' ) );
		$this->assertSame( Job::COMPLETED, $local->run( 'push', $local->remote_params() )->status );
	}

	public function test_clock_differences_are_reported() {
		list( $local, $remote ) = $this->pair();
		$remote->server->clock  = function () {
			return time() + 3600;
		};
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'clocks', $job->message );
	}

	public function test_a_captured_request_cannot_be_replayed_or_altered() {
		list( , $remote ) = $this->pair();
		$key     = $remote->settings->get( 'connection_key' );
		$body    = '{}';
		$headers = array_change_key_case( Signer::request_headers( $key, 'hello', $body ), CASE_LOWER );

		$first = $remote->server->handle( 'hello', $body, $headers, '203.0.113.9', true );
		$this->assertSame( 200, $first['status'] );
		$this->assertArrayHasKey( 'X-Estm-Resp-Sig', $first['headers'] );

		$replay = $remote->server->handle( 'hello', $body, $headers, '203.0.113.9', true );
		$this->assertSame( 401, $replay['status'] );
		$this->assertStringContainsString( 'estm_replay', $replay['body'] );

		$fresh = array_change_key_case( Signer::request_headers( $key, 'hello', $body ), CASE_LOWER );
		$other = $remote->server->handle( 'hello', '{"x":1}', $fresh, '203.0.113.9', true );
		$this->assertStringContainsString( 'estm_bad_sig', $other['body'], 'A changed body is refused.' );

		$fresh2 = array_change_key_case( Signer::request_headers( $key, 'hello', $body ), CASE_LOWER );
		$wrong  = $remote->server->handle( 'put_init', $body, $fresh2, '203.0.113.9', true );
		$this->assertStringContainsString( 'estm_bad_sig', $wrong['body'], 'A signature for one action does not work for another.' );
	}

	public function test_answers_that_are_not_signed_are_rejected() {
		list( $local, $remote ) = $this->pair();
		$t        = $local->connect_to( $remote );
		$t->after = function ( $action, $reply ) {
			$reply['body'] = str_replace( '"ok":true', '"ok":true,"extra":1', $reply['body'] );
			return $reply;
		};
		$job = $local->run( 'push', $local->remote_params() );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'not signed', $job->message );
	}

	public function test_too_many_failures_block_the_address() {
		list( , $remote ) = $this->pair();
		for ( $i = 0; $i < 21; $i++ ) {
			$h = array_change_key_case( Signer::request_headers( 'esm_nope', 'hello', '{}' ), CASE_LOWER );
			$remote->server->handle( 'hello', '{}', $h, '203.0.113.99', true );
		}
		$key = $remote->settings->get( 'connection_key' );
		$h   = array_change_key_case( Signer::request_headers( $key, 'hello', '{}' ), CASE_LOWER );
		$res = $remote->server->handle( 'hello', '{}', $h, '203.0.113.99', true );
		$this->assertSame( 429, $res['status'] );
	}

	public function test_only_remote_jobs_and_transfer_packages_are_reachable() {
		list( $local, $remote ) = $this->pair();
		// A job created in the admin of the other site is not reachable with the key.
		$admin_job = $remote->runner->create( 'export', 'Backup', array( 'kind' => 'export' ) );
		$key       = $remote->settings->get( 'connection_key' );
		$h         = array_change_key_case( Signer::request_headers( $key, 'job_tick', json_encode( array( 'job_id' => $admin_job->id ) ) ), CASE_LOWER );
		$res       = $remote->server->handle( 'job_tick', json_encode( array( 'job_id' => $admin_job->id ) ), $h, '203.0.113.9', true );
		$this->assertStringContainsString( 'estm_no_job', $res['body'] );

		// Snapshots and ordinary exports cannot be downloaded.
		$done = $remote->run( 'export', array( 'kind' => 'snapshot', 'label' => 'snap' ) );
		$id   = $done->state['package'];
		$body = json_encode( array( 'package_id' => $id, 'offset' => 0 ) );
		$h    = array_change_key_case( Signer::request_headers( $key, 'get_part', $body ), CASE_LOWER );
		$res  = $remote->server->handle( 'get_part', $body, $h, '203.0.113.9', true );
		$this->assertSame( 403, $res['status'] );
	}

	public function test_cancelling_a_push_cleans_up_both_sides() {
		list( $local, $remote ) = $this->pair( 30 );
		$local->ctx->max_batch = 5;
		$job = $local->runner->create( 'push', 'push', $local->remote_params() );
		// Run a few slices: export is under way and the package exists locally.
		for ( $i = 0; $i < 3; $i++ ) {
			$local->runner->run_slice( $job->id, \Estasoft\Migrate\Engine\Budget::units( 1 ), 'cli' );
		}
		$this->assertNotEmpty( $local->storage->list_packages( 'export' ) );
		$local->runner->cancel( $job->id );
		$this->assertSame( Job::CANCELLED, $local->store->find( $job->id )->status );
		$this->assertSame( array(), $local->storage->list_packages( 'export' ), 'The half-built package is removed.' );
		$this->assertSame( '3', $remote->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
	}
}
