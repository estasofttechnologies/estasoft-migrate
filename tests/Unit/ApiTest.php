<?php
/**
 * The API behind the admin screens.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Engine\JobConflicts;
use Estasoft\Migrate\Engine\TaskRegistry;
use Estasoft\Migrate\Rest\Api;
use Estasoft\Migrate\Rest\ApiException;
use Estasoft\Migrate\Sanitizer\SavedConfig;
use Estasoft\Migrate\Tests\Support\ArraySettings;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Jobs, ticking, packages, uploads, logs, settings and the sanitizer preview.
 */
class ApiTest extends TestCase {

	/**
	 * Rigs to clean up.
	 *
	 * @var EngineHarness[]
	 */
	private $rigs = array();

	/**
	 * Job ids passed to the background dispatcher.
	 *
	 * @var int[]
	 */
	private $dispatched = array();

	protected function tearDown(): void {
		foreach ( $this->rigs as $rig ) {
			$rig->cleanup();
		}
		$this->rigs       = array();
		$this->dispatched = array();
	}

	/**
	 * A seeded site and an API on top of it.
	 *
	 * @param string $url   Address.
	 * @param int    $posts Posts.
	 * @param array  $opts  API options.
	 * @return array{0:EngineHarness,1:Api}
	 */
	private function rig( $url = 'https://old.example.com', $posts = 12, array $opts = array() ) {
		$rig = new EngineHarness( 'wp_', $url );
		$rig->seed( $url, $posts );
		$this->rigs[] = $rig;
		return array( $rig, $this->api( $rig, $opts ) );
	}

	/**
	 * An API for a rig.
	 *
	 * @param EngineHarness $rig  Rig.
	 * @param array         $opts Options.
	 * @return Api
	 */
	private function api( EngineHarness $rig, array $opts = array() ) {
		$settings = $rig->settings ? $rig->settings : new ArraySettings();
		return new Api(
			$rig->ctx,
			$rig->runner,
			new TaskRegistry(),
			$settings,
			array_merge(
				array(
					'budget'   => function () {
						return Budget::units( 4 );
					},
					'dispatch' => function ( $id ) {
						$this->dispatched[] = $id;
					},
					'env'      => array( 'free' => 50 * 1073741824 ),
					'endpoint' => 'https://old.example.com/?estm_remote=',
				),
				$opts
			)
		);
	}

	/**
	 * Tick until the job is over.
	 *
	 * @param Api $api Api.
	 * @param int $id  Job id.
	 * @return array Last view.
	 */
	private function finish( Api $api, $id ) {
		$cursor = 0;
		$view   = array();
		for ( $i = 0; $i < 400; $i++ ) {
			$view   = $api->tick( $id, $cursor );
			$cursor = $view['log_cursor'];
			if ( in_array( $view['status'], array( Job::COMPLETED, Job::FAILED, Job::CANCELLED ), true ) ) {
				return $view;
			}
		}
		$this->fail( 'The job did not finish: ' . $view['status'] . ' ' . $view['message'] );
	}

	/**
	 * Run a callable that must throw an ApiException.
	 *
	 * @param callable $fn Code.
	 * @return ApiException
	 */
	private function fails( callable $fn ) {
		try {
			$fn();
		} catch ( ApiException $e ) {
			return $e;
		}
		$this->fail( 'An ApiException was expected.' );
	}

	public function test_a_job_is_created_ticked_and_reports_its_result() {
		list( $rig, $api ) = $this->rig();
		$view              = $api->create_job( 'export', array( 'label' => 'from-screen' ) );
		$this->assertSame( Job::QUEUED, $view['status'] );
		$this->assertSame( array( $view['id'] ), $this->dispatched, 'Background work is requested.' );
		$this->assertTrue( $view['can']['cancel'] );
		$this->assertFalse( $view['can']['retry'] );
		$this->assertNull( $view['result'] );

		$seen_progress = array();
		$cursor        = 0;
		$lines         = array();
		for ( $i = 0; $i < 400; $i++ ) {
			$t = $api->tick( $view['id'], $cursor );
			$this->assertTrue( $t['ran'] || $t['locked'] || Job::COMPLETED === $t['status'] );
			$cursor         = $t['log_cursor'];
			$lines          = array_merge( $lines, $t['logs'] );
			$seen_progress[] = $t['progress'];
			if ( Job::COMPLETED === $t['status'] ) {
				break;
			}
		}
		$this->assertSame( Job::COMPLETED, $t['status'] );
		$this->assertSame( 100.0, (float) $t['progress'] );
		$sorted = $seen_progress;
		sort( $sorted );
		$this->assertSame( $sorted, $seen_progress, 'Progress never goes backwards.' );
		$this->assertGreaterThan( 1, count( array_unique( $seen_progress ) ), 'The job needed several ticks.' );

		$this->assertSame( 'export', $t['result']['type'] );
		$this->assertNotEmpty( $t['result']['package'] );
		$this->assertGreaterThan( 0, $t['result']['size'] );
		$this->assertSame( 6, $t['result']['tables'] );
		$this->assertTrue( $t['can']['delete'] );
		$this->assertFalse( $t['can']['cancel'] );
		$this->assertNotEmpty( $t['steps'] );
		$this->assertSame( 'done', $t['steps'][0]['status'] );

		$ids = array_column( $lines, 'id' );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'Each log line is delivered once.' );
		$this->assertContains( 'Job completed.', array_column( $lines, 'message' ) );
	}

	public function test_bad_input_is_explained() {
		list( , $api ) = $this->rig();
		$e = $this->fails( function () use ( $api ) {
			$api->create_job( 'teleport', array() );
		} );
		$this->assertSame( 'estm_invalid', $e->error_code );
		$this->assertSame( 400, $e->status );

		$e = $this->fails( function () use ( $api ) {
			$api->create_job( 'search_replace', array( 'pairs' => array() ) );
		} );
		$this->assertStringContainsString( 'text to search for', $e->getMessage() );

		$e = $this->fails( function () use ( $api ) {
			$api->create_job( 'push', array( 'remote_url' => 'not a url', 'remote_key' => 'esm_x' ) );
		} );
		$this->assertStringContainsString( 'full address', $e->getMessage() );

		$e = $this->fails( function () use ( $api ) {
			$api->create_job( 'export', array( 'tables' => array( 'wp_nothing' ) ) );
		} );
		$this->assertStringContainsString( 'wp_nothing', $e->getMessage() );

		$e = $this->fails( function () use ( $api ) {
			$api->job( 9999 );
		} );
		$this->assertSame( 404, $e->status );
		$this->assertSame( array(), $this->dispatched, 'Nothing was started.' );
	}

	public function test_secrets_never_appear_in_a_job_view() {
		list( , $api ) = $this->rig();
		$view          = $api->create_job( 'export', array( 'password' => 'correct horse battery', 'sanitize' => array( 'profile' => 'staging', 'overrides' => array( 'password' => 'newpass-123' ) ) ) );
		$json          = json_encode( $api->job( $view['id'], true ) );
		$this->assertStringNotContainsString( 'correct horse', $json );
		$this->assertStringNotContainsString( 'newpass-123', $json );
		$this->assertStringNotContainsString( 'passphrase_enc"', $json );
		$this->assertTrue( $view['params']['passphrase_enc_set'] );
		$done = $this->finish( $api, $view['id'] );
		$this->assertSame( 'password', $done['result']['protection'] );
		$this->assertTrue( $done['result']['sanitized'] );
		$this->assertStringNotContainsString( 'correct horse', json_encode( $done ) );
	}

	public function test_a_running_import_blocks_new_work_but_not_a_package_check() {
		list( $rig, $api ) = $this->rig();
		$export            = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$pkg               = $export['result']['package'];

		$import = $api->create_job( 'import', array( 'package_id' => $pkg ) );
		$e      = $this->fails( function () use ( $api, $pkg ) {
			$api->create_job( 'import', array( 'package_id' => $pkg ) );
		} );
		$this->assertSame( 409, $e->status );
		$this->assertSame( 'estm_busy', $e->error_code );
		$this->assertSame( $import['id'], $e->data['job_id'] );

		$e = $this->fails( function () use ( $api ) {
			$api->create_job( 'export', array() );
		} );
		$this->assertSame( 'estm_busy', $e->error_code, 'An export would read half-swapped tables.' );

		$verify = $api->create_job( 'verify', array( 'package_id' => $pkg ) );
		$this->assertSame( 'verify', $verify['type'] );

		$this->finish( $api, $import['id'] );
		$again = $api->create_job( 'export', array() );
		$this->assertSame( Job::QUEUED, $again['status'] );
	}

	public function test_a_dry_run_preview_may_run_next_to_an_export_but_a_real_replace_may_not() {
		list( , $api ) = $this->rig();
		$api->create_job( 'export', array() );
		$preview = $api->create_job( 'search_replace', array( 'pairs' => array( array( 'old.example.com', 'new.example.com' ) ) ) );
		$this->assertTrue( $preview['dry_run'] );
		$e = $this->fails( function () use ( $api ) {
			$api->create_job( 'search_replace', array( 'pairs' => array( array( 'a', 'b' ) ), 'dry_run' => false ) );
		} );
		$this->assertSame( 'estm_busy', $e->error_code );
	}

	public function test_pause_resume_cancel_and_the_rules_around_them() {
		list( , $api ) = $this->rig();
		$job           = $api->create_job( 'export', array() );
		$id            = $job['id'];

		$e = $this->fails( function () use ( $api, $id ) {
			$api->job_action( $id, 'resume' );
		} );
		$this->assertSame( 409, $e->status );
		$e = $this->fails( function () use ( $api, $id ) {
			$api->job_action( $id, 'retry' );
		} );
		$this->assertSame( 409, $e->status );
		$e = $this->fails( function () use ( $api, $id ) {
			$api->delete_job( $id );
		} );
		$this->assertStringContainsString( 'Cancel the job', $e->getMessage() );

		$api->tick( $id );
		$paused = $api->job_action( $id, 'pause' );
		$this->assertSame( Job::PAUSED, $paused['status'] );
		$this->assertTrue( $paused['can']['resume'] );
		$before = $api->tick( $id );
		$this->assertFalse( $before['ran'], 'A paused job is not worked on.' );

		$this->dispatched = array();
		$resumed          = $api->job_action( $id, 'resume' );
		$this->assertContains( $resumed['status'], array( Job::RUNNING, Job::QUEUED ) );
		$this->assertSame( array( $id ), $this->dispatched );

		$cancelled = $api->job_action( $id, 'cancel' );
		$this->assertSame( Job::CANCELLED, $cancelled['status'] );
		$this->assertFalse( $cancelled['can']['cancel'] );
		$this->assertSame( array(), $api->packages( 'export' )['packages'], 'The unfinished package is cleaned up.' );

		$e = $this->fails( function () use ( $api, $id ) {
			$api->job_action( $id, 'cancel' );
		} );
		$this->assertSame( 409, $e->status );
		$e = $this->fails( function () use ( $api, $id ) {
			$api->job_action( $id, 'explode' );
		} );
		$this->assertSame( 400, $e->status );

		$this->assertSame( array( 'deleted' => true ), $api->delete_job( $id ) );
		$this->assertSame( 404, $this->fails( function () use ( $api, $id ) {
			$api->job( $id );
		} )->status );
	}

	public function test_a_damaged_package_fails_with_a_reason_and_can_be_retried() {
		list( $rig, $api ) = $this->rig();
		$export            = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$pkg               = $export['result']['package'];
		$path              = $rig->storage->package_path( $pkg );
		$bytes             = file_get_contents( $path );
		$bytes[ (int) ( strlen( $bytes ) / 2 ) ] = chr( ord( $bytes[ (int) ( strlen( $bytes ) / 2 ) ] ) ^ 0xFF );
		file_put_contents( $path, $bytes );

		$view = $this->finish( $api, $api->create_job( 'verify', array( 'package_id' => $pkg ) )['id'] );
		$this->assertSame( Job::FAILED, $view['status'] );
		$this->assertNotEmpty( $view['error']['message'] );
		$this->assertArrayHasKey( 'advice', $view['error']['details'] );
		$this->assertTrue( $view['can']['retry'] );
		$this->assertNull( $view['result'] );

		$listed = $api->packages( 'export' )['packages'][0];
		$this->assertFalse( $listed['verified']['ok'] );

		$retry = $api->job_action( $view['id'], 'retry' );
		$this->assertContains( $retry['status'], array( Job::RUNNING, Job::QUEUED ) );
		$this->assertNull( $retry['error'] );
	}

	public function test_packages_are_listed_with_what_the_screen_needs_and_in_use_ones_are_kept() {
		list( , $api ) = $this->rig();
		$done          = $this->finish( $api, $api->create_job( 'export', array( 'label' => 'nightly', 'password' => 'pw-pw-pw' ) )['id'] );
		$id            = $done['result']['package'];

		$list = $api->packages();
		$this->assertCount( 1, $list['packages'] );
		$pkg = $list['packages'][0];
		$this->assertSame( $id, $pkg['id'] );
		$this->assertSame( 'export', $pkg['type'] );
		$this->assertSame( 'complete', $pkg['status'] );
		$this->assertSame( 'password', $pkg['protection'] );
		$this->assertTrue( $pkg['encrypted'] );
		$this->assertSame( $id . '.estm', $pkg['file'] );
		$this->assertGreaterThan( 0, $pkg['size'] );
		$this->assertSame( 6, $pkg['tables'] );
		$this->assertNull( $pkg['verified'] );
		$this->assertFalse( $pkg['in_use'] );
		$this->assertSame( $pkg['size'], $list['totals']['bytes'] );
		$this->assertSame( array(), $api->packages( 'snapshot' )['packages'] );

		$info = $api->download_info( $id );
		$this->assertSame( $id . '.estm', $info['name'] );
		$this->assertFileExists( $info['path'] );

		$verify = $api->create_job( 'verify', array( 'package_id' => $id ) );
		$e      = $this->fails( function () use ( $api, $id ) {
			$api->delete_package( $id );
		} );
		$this->assertSame( 409, $e->status );
		$this->assertStringContainsString( 'still in progress', $e->getMessage() );

		$this->finish( $api, $verify['id'] );
		$this->assertTrue( $api->packages()['packages'][0]['verified']['ok'] );
		$this->assertSame( array( 'deleted' => true ), $api->delete_package( $id ) );
		$this->assertSame( array(), $api->packages()['packages'] );
		$this->assertSame( 404, $this->fails( function () use ( $api, $id ) {
			$api->delete_package( $id );
		} )->status );
		$this->assertSame( 404, $this->fails( function () use ( $api ) {
			$api->download_info( '../../etc/passwd' );
		} )->status );
	}

	public function test_a_snapshot_is_listed_after_an_import_and_can_be_restored() {
		list( $rig, $api ) = $this->rig( 'https://old.example.com', 8 );
		$export            = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$rig->db->query( "INSERT INTO wp_posts (post_author, post_title, post_content, guid) VALUES (1, 'Added later', 'x', 'g')" );
		$this->assertSame( '9', $rig->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );

		$import = $this->finish( $api, $api->create_job( 'import', array( 'package_id' => $export['result']['package'] ) )['id'] );
		$this->assertSame( Job::COMPLETED, $import['status'], $import['message'] );
		$this->assertSame( '8', $rig->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertNotEmpty( $import['result']['snapshot'] );
		$this->assertSame( 6, $import['result']['tables'] );
		$this->assertGreaterThan( 0, $import['result']['rows'] );

		$snaps = $api->packages( 'snapshot' )['packages'];
		$this->assertCount( 1, $snaps );
		$this->assertSame( $import['result']['snapshot'], $snaps[0]['id'] );

		$restore = $this->finish( $api, $api->create_job( 'import', array( 'package_id' => $snaps[0]['id'] ) )['id'] );
		$this->assertSame( Job::COMPLETED, $restore['status'], $restore['message'] );
		$this->assertTrue( $restore['result']['restore'] );
		$this->assertSame( '9', $rig->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ), 'The snapshot brought the added post back.' );
	}

	public function test_snapshots_say_which_job_they_were_taken_for() {
		list( $rig, $api ) = $this->rig( 'https://old.example.com', 8 );
		$export            = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$import            = $this->finish( $api, $api->create_job( 'import', array( 'package_id' => $export['result']['package'] ) )['id'] );

		$snap = $api->packages( 'snapshot' )['packages'][0];
		$this->assertIsArray( $snap['origin'] );
		$this->assertSame( $import['id'], $snap['origin']['job_id'] );
		$this->assertSame( 'import', $snap['origin']['type'] );
		$this->assertSame( $import['title'], $snap['origin']['title'] );

		$plain = $api->packages( 'export' )['packages'][0];
		$this->assertSame( $export['id'], $plain['origin']['job_id'], 'An export points to the job that made it.' );

		$rig->ctx->store->delete( $import['id'] );
		$this->assertNull( $api->packages( 'snapshot' )['packages'][0]['origin'], 'A cleaned-up job leaves no origin.' );
	}

	public function test_exports_need_a_password_when_packages_are_set_to_be_encrypted() {
		list( $rig, $api ) = $this->rig();
		$api->save_settings( array( 'encrypt_packages' => true ) );

		$e = $this->fails( function () use ( $api ) {
			$api->create_job( 'export', array() );
		} );
		$this->assertSame( 'estm_password_required', $e->error_code );
		$this->assertSame( 400, $e->status );
		$this->assertSame( array(), $rig->store->active(), 'Nothing was started.' );

		$ok = $api->create_job( 'export', array( 'password' => 'a-long-password' ) );
		$this->assertSame( Job::QUEUED, $ok['status'] );
		$done = $this->finish( $api, $ok['id'] );
		$this->assertSame( 'password', $api->packages()['packages'][0]['protection'] );
		$this->assertSame( Job::COMPLETED, $done['status'] );

		$api->save_settings( array( 'encrypt_packages' => false ) );
		$this->assertSame( Job::QUEUED, $api->create_job( 'export', array() )['status'] );
	}

	public function test_an_upload_cannot_grow_past_the_size_it_announced() {
		list( , $api ) = $this->rig();
		$id            = $api->upload_init( 'x.estm', 100 )['package_id'];
		$api->upload_chunk( $id, 0, str_repeat( 'a', 60 ) );

		$e = $this->fails( function () use ( $api, $id ) {
			$api->upload_chunk( $id, 60, str_repeat( 'b', 50 ) );
		} );
		$this->assertSame( 413, $e->status );
		$this->assertSame( 60, $api->upload_chunk( $id, 0, str_repeat( 'a', 60 ) )['received'], 'What was stored is untouched.' );

		$e = $this->fails( function () use ( $api ) {
			$api->upload_init( 'huge.estm', \Estasoft\Migrate\Storage::max_package_bytes() + 1 );
		} );
		$this->assertSame( 413, $e->status );
	}

	public function test_a_list_of_allowed_addresses_with_no_valid_entry_is_refused() {
		list( , $api ) = $this->rig();
		$api->save_settings( array( 'ip_allowlist' => '203.0.113.7' ) );

		$e = $this->fails( function () use ( $api ) {
			$api->save_settings( array( 'ip_allowlist' => "203.0.113.700\nnope" ) );
		} );
		$this->assertSame( 'estm_invalid', $e->error_code );
		$this->assertSame( '203.0.113.7', $api->settings()['values']['ip_allowlist'], 'The list that worked is still there.' );
		$this->assertSame( '', $api->save_settings( array( 'ip_allowlist' => '' ) )['values']['ip_allowlist'], 'Clearing the list on purpose is allowed.' );
	}

	public function test_the_sanitizer_settings_are_saved_and_can_start_a_sanitized_export() {
		list( , $api ) = $this->rig();
		$info          = $api->sanitizer_info();
		$this->assertSame( 'staging', $info['saved']['profile'] );
		$this->assertFalse( $info['saved']['on_export'] );
		$this->assertTrue( $info['saved']['rules']['emails'] );

		$saved = $api->save_sanitizer(
			array(
				'profile'      => 'gdpr',
				'rules'        => array( 'phones' => false, 'bogus' => true ),
				'safety'       => array( 'mail' => 'false' ),
				'keep_users'   => "ops@example.com, qa@example.com\nops@example.com",
				'keep_admins'  => '0',
				'skip_tables'  => array( 'wp_audit', '' ),
				'skip_columns' => 'wp_posts.post_excerpt',
				'on_export'    => '1',
			)
		)['saved'];
		$this->assertSame( 'gdpr', $saved['profile'] );
		$this->assertFalse( $saved['rules']['phones'] );
		$this->assertArrayNotHasKey( 'bogus', $saved['rules'] );
		$this->assertFalse( $saved['safety']['mail'] );
		$this->assertSame( array( 'ops@example.com', 'qa@example.com' ), $saved['keep_users'] );
		$this->assertFalse( $saved['keep_admins'] );
		$this->assertSame( array( 'wp_audit' ), $saved['skip_tables'] );
		$this->assertSame( array( 'wp_posts.post_excerpt' ), $saved['skip_columns'] );
		$this->assertTrue( $saved['on_export'] );
		$this->assertSame( $saved, $api->sanitizer_info()['saved'], 'The next visit shows what was saved.' );

		$e = $this->fails( function () use ( $api ) {
			$api->save_sanitizer( array( 'profile' => 'nope' ) );
		} );
		$this->assertSame( 'estm_invalid', $e->error_code );

		$job = $api->create_job( 'export', array( 'sanitize' => SavedConfig::to_job_input( $saved ) ) );
		$this->assertSame( 'sanitize', $job['kind'] );
		$done = $this->finish( $api, $job['id'] );
		$this->assertSame( Job::COMPLETED, $done['status'], $done['message'] );
	}

	public function test_a_package_can_be_uploaded_in_pieces() {
		list( $source, $api ) = $this->rig();
		$export               = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$bytes                = file_get_contents( $source->storage->package_path( $export['result']['package'] ) );

		list( $dest, $dest_api ) = $this->rig( 'https://other.example.com', 1 );
		$init                    = $dest_api->upload_init( 'my backup.estm', strlen( $bytes ) );
		$id                      = $init['package_id'];
		$this->assertStringContainsString( 'my-backup', $id );

		$half = (int) ( strlen( $bytes ) / 2 );
		$this->assertSame( $half, $dest_api->upload_chunk( $id, 0, substr( $bytes, 0, $half ) )['received'] );
		$this->assertSame( $half, $dest_api->upload_chunk( $id, 0, substr( $bytes, 0, $half ) )['received'], 'Sending a piece again is harmless.' );

		$e = $this->fails( function () use ( $dest_api, $id, $bytes, $half ) {
			$dest_api->upload_chunk( $id, $half + 100, substr( $bytes, $half + 100, 50 ) );
		} );
		$this->assertSame( 409, $e->status );
		$this->assertSame( $half, $e->data['received'], 'The screen is told where to continue.' );

		$e = $this->fails( function () use ( $dest_api, $id, $bytes ) {
			$dest_api->upload_done( $id, strlen( $bytes ) );
		} );
		$this->assertStringContainsString( 'incomplete', $e->getMessage() );

		$this->assertSame( strlen( $bytes ), $dest_api->upload_chunk( $id, $half, substr( $bytes, $half ) )['received'] );
		$done = $dest_api->upload_done( $id, strlen( $bytes ) )['package'];
		$this->assertSame( 'received', $done['type'] );
		$this->assertSame( 'complete', $done['status'] );
		$this->assertSame( hash( 'sha256', $bytes ), $done['sha256'] );
		$this->assertSame( 'https://old.example.com', $done['source_url'], 'The package says where it came from.' );
		$this->assertSame( 6, $done['tables'] );
		$this->assertGreaterThan( 20, $done['rows'] );

		$import = $this->finish( $dest_api, $dest_api->create_job( 'import', array( 'package_id' => $id ) )['id'] );
		$this->assertSame( Job::COMPLETED, $import['status'], $import['message'] );
		$this->assertSame( '12', $dest->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );

		$e = $this->fails( function () use ( $dest_api, $id ) {
			$dest_api->upload_chunk( $id, 0, 'late' );
		} );
		$this->assertSame( 409, $e->status, 'A finished package cannot be written to.' );
	}

	public function test_the_person_who_runs_an_import_stays_signed_in() {
		list( $source, $api ) = $this->rig();
		$export               = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$bytes                = file_get_contents( $source->storage->package_path( $export['result']['package'] ) );

		list( $dest, $dest_api ) = $this->rig( 'https://other.example.com', 1 );
		$dest->db->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'session_tokens', 'live-session')" );
		$dest->ctx->services['current_user'] = static function () {
			return array( 'id' => 1, 'login' => 'admin' );
		};
		$id = $dest_api->upload_init( 'copy.estm', strlen( $bytes ) )['package_id'];
		$dest_api->upload_chunk( $id, 0, $bytes );
		$dest_api->upload_done( $id, strlen( $bytes ) );

		$import = $this->finish( $dest_api, $dest_api->create_job( 'import', array( 'package_id' => $id ) )['id'] );
		$this->assertSame( Job::COMPLETED, $import['status'], $import['message'] );
		$this->assertSame( 'live-session', $dest->db->get_var( "SELECT meta_value FROM wp_usermeta WHERE user_id = 1 AND meta_key = 'session_tokens'" ), 'The sign-in of the person who ran the import survives the swap.' );
		$this->assertSame( 'abc', $dest->db->get_var( "SELECT meta_value FROM wp_usermeta WHERE user_id = 2 AND meta_key = 'session_tokens'" ), 'Everyone else gets what the package holds.' );
	}

	public function test_an_account_that_is_not_in_the_package_is_not_invented() {
		list( $source, $api ) = $this->rig();
		$export               = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$bytes                = file_get_contents( $source->storage->package_path( $export['result']['package'] ) );

		list( $dest, $dest_api ) = $this->rig( 'https://other.example.com', 1 );
		$dest->db->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'session_tokens', 'live-session')" );
		$dest->ctx->services['current_user'] = static function () {
			return array( 'id' => 1, 'login' => 'someone-else' );
		};
		$id = $dest_api->upload_init( 'copy.estm', strlen( $bytes ) )['package_id'];
		$dest_api->upload_chunk( $id, 0, $bytes );
		$dest_api->upload_done( $id, strlen( $bytes ) );

		$import = $this->finish( $dest_api, $dest_api->create_job( 'import', array( 'package_id' => $id ) )['id'] );
		$this->assertSame( Job::COMPLETED, $import['status'], $import['message'] );
		$this->assertNull( $dest->db->get_var( "SELECT meta_value FROM wp_usermeta WHERE user_id = 1 AND meta_key = 'session_tokens'" ), 'A different account with the same number does not inherit the sign-in.' );
	}

	public function test_an_upload_that_is_not_a_package_is_refused_and_removed() {
		list( $rig, $api ) = $this->rig();
		$junk              = str_repeat( 'not a package ', 20 );
		$id                = $api->upload_init( 'notes.txt', strlen( $junk ) )['package_id'];
		$api->upload_chunk( $id, 0, $junk );
		$e = $this->fails( function () use ( $api, $id, $junk ) {
			$api->upload_done( $id, strlen( $junk ) );
		} );
		$this->assertSame( 422, $e->status );
		$this->assertStringContainsString( 'not an Estasoft Migrate package', $e->getMessage() );
		$this->assertFileDoesNotExist( $rig->storage->package_path( $id ) );
		$this->assertSame( 400, $this->fails( function () use ( $api ) {
			$api->upload_init( 'empty.estm', 0 );
		} )->status );
	}

	public function test_an_upload_that_does_not_fit_on_the_disk_is_refused_up_front() {
		list( , $api ) = $this->rig( 'https://old.example.com', 3, array( 'env' => array( 'free' => 1000 ) ) );
		$e = $this->fails( function () use ( $api ) {
			$api->upload_init( 'big.estm', 5000000 );
		} );
		$this->assertSame( 507, $e->status );
		$this->assertStringContainsString( 'Not enough disk space', $e->getMessage() );
	}

	public function test_logs_can_be_filtered_downloaded_and_cleared() {
		list( $rig, $api ) = $this->rig();
		$a                 = $this->finish( $api, $api->create_job( 'export', array() )['id'] );
		$rig->logger->log( 'error', 'Something broke here', $a['id'], array( 'table' => 'wp_posts' ) );
		$rig->logger->log( 'warning', 'Unrelated warning', 0 );

		$all = $api->logs( array( 'order' => 'asc' ) );
		$this->assertGreaterThan( 3, count( $all['lines'] ) );
		$this->assertSame( $a['id'], $all['jobs'][0]['id'] );

		$errors = $api->logs( array( 'level' => 'error' ) );
		$this->assertCount( 1, $errors['lines'] );
		$this->assertSame( 'Something broke here', $errors['lines'][0]['message'] );

		$only_warn = $api->logs( array( 'levels' => array( 'warning' ) ) );
		$this->assertCount( 1, $only_warn['lines'] );
		$this->assertSame( 'Unrelated warning', $only_warn['lines'][0]['message'] );
		$both = $api->logs( array( 'levels' => 'error,warning' ) );
		$this->assertCount( 2, $both['lines'] );

		$found = $api->logs( array( 'search' => 'unrelated' ) );
		$this->assertCount( 1, $found['lines'] );
		$this->assertCount( 0, $api->logs( array( 'job_id' => 999 ) )['lines'] );

		$text = $api->logs_text( $a['id'] );
		$this->assertStringContainsString( 'ERROR', $text );
		$this->assertStringContainsString( 'Something broke here', $text );
		$this->assertStringNotContainsString( 'Unrelated warning', $text );

		$api->clear_logs( $a['id'] );
		$this->assertSame( array(), $api->logs( array( 'job_id' => $a['id'] ) )['lines'] );
		$this->assertNotEmpty( $api->logs()['lines'] );
		$api->clear_logs();
		$this->assertSame( array(), $api->logs()['lines'] );
	}

	public function test_settings_are_returned_saved_and_the_key_can_be_replaced() {
		list( , $api ) = $this->rig();
		$s             = $api->settings();
		$this->assertStringStartsWith( 'esm_', $s['connection']['key'] );
		$this->assertSame( 16, strlen( $s['connection']['key_id'] ) );
		$this->assertSame( 'https://old.example.com/?estm_remote=', $s['connection']['endpoint'] );
		$this->assertFalse( $s['values']['accept_incoming'] );
		$this->assertTrue( $s['values']['require_https'] );
		$this->assertArrayNotHasKey( 'connection_key', $s['values'] );
		$this->assertArrayNotHasKey( 'storage_token', $s['values'] );
		$this->assertContains( 'auto', $s['runners'] );
		$this->assertTrue( $s['environment']['storage']['writable'] );
		$this->assertSame( PHP_VERSION, $s['environment']['php'] );

		$this->assertSame( 30, $s['values']['delete_snapshots_days'] );
		$this->assertSame( 2, $s['defaults']['keep_snapshots'], 'Defaults are sent so the screen can reset to them.' );
		$this->assertFalse( $s['defaults']['accept_incoming'] );
		$this->assertArrayNotHasKey( 'connection_key', $s['defaults'] );

		$saved = $api->save_settings( array( 'accept_incoming' => true, 'max_batch' => 5, 'runner' => 'nonsense', 'ip_allowlist' => "203.0.113.0/24\nnot-an-ip", 'keep_snapshots' => '3', 'delete_snapshots_days' => '9999' ) );
		$this->assertSame( 3650, $saved['values']['delete_snapshots_days'] );
		$this->assertSame( 0, $api->save_settings( array( 'delete_snapshots_days' => -4 ) )['values']['delete_snapshots_days'] );
		$this->assertTrue( $saved['values']['accept_incoming'] );
		$this->assertSame( 100, $saved['values']['max_batch'], 'Batch size is kept within limits.' );
		$this->assertSame( 'auto', $saved['values']['runner'] );
		$this->assertSame( '203.0.113.0/24', $saved['values']['ip_allowlist'] );
		$this->assertSame( 3, $saved['values']['keep_snapshots'] );
		$this->assertSame( $s['connection']['key'], $saved['connection']['key'], 'Saving does not change the key.' );

		$new = $api->regenerate_key();
		$this->assertNotSame( $s['connection']['key'], $new['connection']['key'] );
		$this->assertGreaterThan( time(), $new['connection']['previous_key_until'], 'The old key works for a short while.' );
	}

	public function test_a_connection_can_be_tested() {
		list( $local, $api ) = $this->rig( 'https://local.example.com', 3 );
		$remote              = new EngineHarness( 'wp_', 'https://live.example.com' );
		$remote->seed( 'https://live.example.com', 5 );
		$this->rigs[] = $remote;
		$remote->serve();
		$local->connect_to( $remote );

		$ok = $api->test_connection( 'https://live.example.com/', $local->remote_key );
		$this->assertTrue( $ok['ok'] );
		$this->assertTrue( $ok['https'] );
		$this->assertSame( 'https://live.example.com', $ok['site']['url'] );
		$this->assertTrue( $ok['writable'] );
		$this->assertSame( 6, $ok['stats']['tables'] );
		$this->assertLessThan( 5, $ok['skew'] );

		$e = $this->fails( function () use ( $api ) {
			$api->test_connection( 'https://live.example.com', 'esm_wrongwrongwrongwrong' );
		} );
		$this->assertSame( 'estm_connection', $e->error_code );
		$this->assertSame( 'estm_bad_key', $e->data['remote_code'] );

		$this->assertStringContainsString( 'full address', $this->fails( function () use ( $api ) {
			$api->test_connection( 'live.example.com', 'esm_x' );
		} )->getMessage() );
		$this->assertStringContainsString( 'esm_', $this->fails( function () use ( $api ) {
			$api->test_connection( 'https://live.example.com', 'secret' );
		} )->getMessage() );
	}

	public function test_the_sanitizer_screen_data_and_preview_never_reveal_secrets() {
		list( $rig, $api ) = $this->rig();
		$info              = $api->sanitizer_info();
		$this->assertSame( array( 'staging', 'dev', 'gdpr' ), array_column( $info['profiles'], 'id' ) );
		$this->assertCount( 8, $info['rules'] );
		$this->assertCount( 4, $info['safety'] );
		$this->assertSame( array( 'admin' ), array_column( $info['admins'], 'login' ) );
		$this->assertTrue( $info['profiles'][0]['rules']['names'] );
		$this->assertFalse( $info['profiles'][0]['rules']['notes'] );

		$preview = $api->sanitizer_preview( array( 'profile' => 'staging' ) );
		$json    = json_encode( $preview, JSON_UNESCAPED_UNICODE );
		$by      = array_column( $preview['tables'], null, 'logical' );
		$this->assertArrayHasKey( 'users', $by );
		$user = $by['users']['examples'][0];
		$this->assertContains( 'user_email', $user['changed'] );
		$this->assertNotSame( $user['before']['user_email'], $user['after']['user_email'] );
		$this->assertSame( 1, $preview['kept'], 'The administrator stays as it is.' );
		$this->assertStringNotContainsString( 'boss@old.example.com', $json );
		$this->assertStringNotContainsString( 'hash-jane', $json );
		$this->assertStringNotContainsString( 'test-secret-abcdefghijklmnopqrstuvwxyz', $json );
		$this->assertStringContainsString( 'hash••••••••', $json );

		$gdpr = $api->sanitizer_preview( array( 'profile' => 'gdpr', 'per_table' => 1 ) );
		$this->assertCount( 1, array_column( $gdpr['tables'], null, 'logical' )['users']['examples'] );

		$none = $api->sanitizer_preview( array( 'profile' => 'staging', 'overrides' => array( 'rules' => array( 'emails' => false, 'names' => false, 'pw' => false, 'ips' => false, 'secrets' => false, 'phones' => false, 'addr' => false ) ) ) );
		$this->assertSame( array(), $none['tables'], 'With every rule off nothing would change.' );

		$e = $this->fails( function () use ( $api ) {
			$api->sanitizer_preview( array( 'profile' => 'wild' ) );
		} );
		$this->assertSame( 400, $e->status );
		$this->assertSame( 'hash-jane', $rig->db->get_var( 'SELECT user_pass FROM wp_users WHERE ID = 2' ), 'The preview changes nothing.' );
	}

	public function test_overview_and_tables_describe_the_site() {
		list( $rig, $api ) = $this->rig();
		$over              = $api->overview();
		$this->assertSame( 'https://old.example.com', $over['site']['url'] );
		$this->assertSame( 'wp_', $over['site']['prefix'] );
		$this->assertSame( 6, $over['database']['tables'] );
		$this->assertGreaterThan( 20, $over['database']['rows'] );
		$this->assertNull( $over['current'] );
		$this->assertNull( $over['last'] );
		$this->assertSame( 0, $over['packages']['exports']['count'] );
		$this->assertFalse( $over['connection']['accepting'] );
		$this->assertTrue( $over['health']['storage']['writable'] );

		$running = $api->create_job( 'export', array() );
		$over    = $api->overview();
		$this->assertSame( $running['id'], $over['current']['id'] );
		$this->assertSame( $running['id'], $over['recent'][0]['id'] );

		$export = $this->finish( $api, $running['id'] );
		$over   = $api->overview();
		$this->assertNull( $over['current'] );
		$this->assertSame( 1, $over['packages']['exports']['count'] );
		$this->assertSame( 'export', $over['last']['type'], 'A finished export counts as the last completed migration.' );
		$this->assertSame( $export['result']['package'], $over['last']['package'] );

		$this->finish( $api, $api->create_job( 'import', array( 'package_id' => $export['result']['package'] ) )['id'] );
		$over = $api->overview();
		$this->assertSame( 'import', $over['last']['type'] );
		$this->assertSame( 1, $over['packages']['snapshots']['count'] );

		$t = $api->tables();
		$this->assertSame( 6, $t['totals']['tables'] );
		$this->assertSame( 'posts', array_column( $t['tables'], 'logical' )[ array_search( 'wp_posts', array_column( $t['tables'], 'name' ), true ) ] );
	}

	public function test_preflight_through_the_api() {
		list( , $api ) = $this->rig( 'https://old.example.com', 4, array( 'env' => array( 'free' => 50 * 1073741824, 'php' => '8.2.0', 'memory' => 268435456, 'max_exec' => 300, 'sodium' => true, 'zlib' => true, 'db' => array( 'type' => 'mysql', 'version' => '8.0.36' ) ) ) );
		$res           = $api->preflight( array( 'mode' => 'export' ) );
		$this->assertSame( 'ready', $res['status'], json_encode( $res['checks'] ) );
		$this->assertSame( 400, $this->fails( function () use ( $api ) {
			$api->preflight( array( 'mode' => 'dance' ) );
		} )->status );

		$api->create_job( 'import', array( 'package_id' => $this->an_export( $api ) ) );
		$busy = $api->preflight( array( 'mode' => 'export' ) );
		$this->assertSame( 'blocked', $busy['status'] );
	}

	/**
	 * Make an export and return its id.
	 *
	 * @param Api $api Api.
	 * @return string
	 */
	private function an_export( Api $api ) {
		return $this->finish( $api, $api->create_job( 'export', array() )['id'] )['result']['package'];
	}

	public function test_job_conflict_rules() {
		$mk = function ( $type, array $params = array() ) {
			$job         = new Job();
			$job->type   = $type;
			$job->params = $params;
			return $job;
		};
		$this->assertTrue( JobConflicts::writes( 'import' ) );
		$this->assertTrue( JobConflicts::writes( 'pull' ) );
		$this->assertTrue( JobConflicts::writes( 'search_replace', array( 'dry_run' => false ) ) );
		$this->assertFalse( JobConflicts::writes( 'search_replace', array( 'dry_run' => true ) ) );
		$this->assertTrue( JobConflicts::reads( 'push' ) );
		$this->assertTrue( JobConflicts::reads( 'export' ) );
		$this->assertTrue( JobConflicts::reads( 'search_replace', array( 'dry_run' => true ) ) );
		$this->assertFalse( JobConflicts::reads( 'verify' ) );

		$import = $mk( 'import' );
		$export = $mk( 'export' );
		$this->assertSame( $import, JobConflicts::find( array( $import ), 'export' ) );
		$this->assertSame( $import, JobConflicts::find( array( $import ), 'import' ) );
		$this->assertSame( $import, JobConflicts::find( array( $import ), 'push' ) );
		$this->assertNull( JobConflicts::find( array( $import ), 'verify' ) );
		$this->assertNull( JobConflicts::find( array( $export ), 'export' ), 'Two readers may share the tables.' );
		$this->assertNull( JobConflicts::find( array( $export ), 'push' ) );
		$this->assertSame( $export, JobConflicts::find( array( $export ), 'import' ) );
		$this->assertSame( $export, JobConflicts::find( array( $export ), 'search_replace', array( 'dry_run' => false ) ) );
		$this->assertNull( JobConflicts::find( array(), 'import' ) );
	}
}
