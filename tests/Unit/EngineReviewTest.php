<?php
/**
 * Regression tests for the data-integrity problems found in the pre-release review of the engine.
 *
 * Each test builds the situation that used to go wrong and checks that it now ends safely.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Db\DatabaseException;
use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use Estasoft\Migrate\Tests\Support\PdoDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A database that lets a test act at an exact point of an import: right after the tables are swapped,
 * or in the middle of filling a staging table (like another request arriving at that moment).
 */
class HookedDatabase extends PdoDatabase {

	/**
	 * Called once after RENAME TABLE.
	 *
	 * @var callable|null
	 */
	public $after_rename = null;

	/**
	 * Called once, on the first insert into a staging table.
	 *
	 * @var callable|null
	 */
	public $on_stage_insert = null;

	/**
	 * Fail the next RENAME TABLE.
	 *
	 * @var bool
	 */
	public $fail_rename = false;

	/**
	 * {@inheritdoc}
	 *
	 * @param array $pairs Old name => new name.
	 * @return void
	 */
	public function rename_tables( array $pairs ) {
		if ( $this->fail_rename ) {
			throw new DatabaseException( 'Table already exists', 1050 );
		}
		parent::rename_tables( $pairs );
		if ( $this->after_rename ) {
			$hook               = $this->after_rename;
			$this->after_rename = null;
			call_user_func( $hook );
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $table   Table.
	 * @param string[] $columns Columns.
	 * @param array[]  $rows    Rows.
	 * @param string   $mode    Mode.
	 * @return int
	 */
	public function insert_rows( $table, array $columns, array $rows, $mode = 'replace' ) {
		$n = parent::insert_rows( $table, $columns, $rows, $mode );
		if ( $this->on_stage_insert && 0 === strpos( $table, '_estm_' ) ) {
			$hook                  = $this->on_stage_insert;
			$this->on_stage_insert = null;
			call_user_func( $hook );
		}
		return $n;
	}
}

/**
 * A database that loses the connection once, right after a block of rows was written into a staging table
 * and before the job could save that it did.
 */
class FlakyInsertDatabase extends PdoDatabase {

	/**
	 * Columns of the table to fail on.
	 *
	 * @var string[]
	 */
	public $fail_columns = array();

	/**
	 * Has the failure happened?
	 *
	 * @var bool
	 */
	public $fired = false;

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $table   Table.
	 * @param string[] $columns Columns.
	 * @param array[]  $rows    Rows.
	 * @param string   $mode    Mode.
	 * @return int
	 */
	public function insert_rows( $table, array $columns, array $rows, $mode = 'replace' ) {
		$n = parent::insert_rows( $table, $columns, $rows, $mode );
		if ( ! $this->fired && 0 === strpos( $table, '_estm_' ) && $columns === $this->fail_columns && $this->count_rows( $table ) >= 10 ) {
			$this->fired = true;
			throw new DatabaseException( 'MySQL server has gone away', 2006 );
		}
		return $n;
	}
}

class EngineReviewTest extends TestCase {

	/**
	 * Rigs to remove.
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

	private function rig( $prefix, $url, $posts = 5, $db = null ) {
		$rig = $db ? new EngineHarness( $prefix, $url, '', 'sqlite::memory:', $db ) : new EngineHarness( $prefix, $url );
		$rig->seed( $url, $posts );
		$this->rigs[] = $rig;
		return $rig;
	}

	private function hooked_rig( $url, $posts = 3 ) {
		$db = new HookedDatabase( 'wp_' );
		EngineHarness::create_wordpress_tables( $db, 'wp_' );
		return $this->rig( 'wp_', $url, $posts, $db );
	}

	private function transfer( EngineHarness $from, EngineHarness $to, $id ) {
		$to->storage->dir();
		copy( $from->storage->package_path( $id ), $to->storage->package_path( $id ) );
		copy( $from->storage->meta_path( $id ), $to->storage->meta_path( $id ) );
	}

	private function staging_tables( EngineHarness $rig ) {
		return array_values(
			array_filter(
				$rig->db->list_tables(),
				static function ( $t ) {
					return 0 === strpos( $t, '_estm_' );
				}
			)
		);
	}

	private function option( EngineHarness $rig, $name ) {
		return $rig->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = '$name'" );
	}

	/** A key made of raw bytes used to end the export after the first slice, with the job reporting success. */
	public function test_a_binary_primary_key_does_not_cut_the_export_short() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 1 );
		$source->db->query( 'CREATE TABLE `wp_uuids` (`k` BLOB NOT NULL PRIMARY KEY, `v` TEXT)' );
		for ( $i = 0; $i < 100; $i++ ) {
			$key = "\xff" . chr( 1 + $i ) . "\x80";
			$source->db->query( 'INSERT INTO `wp_uuids` (k, v) VALUES (X\'' . bin2hex( $key ) . "', 'row $i')" );
		}
		$source->ctx->max_batch = 10;
		$job = $source->run_in_small_slices( 'export', array( 'kind' => 'export', 'label' => 'x', 'tables' => array( 'wp_uuids' ) ), 1 );

		$this->assertSame( Job::COMPLETED, $job->status, $job->message );
		$reader = PackageReader::open( $source->storage->package_path( $job->state['package'] ), array() );
		$rows   = 0;
		while ( $frame = $reader->next() ) {
			if ( 'R' === $frame['type'] ) {
				$rows += count( $frame['data']['r'] );
			}
		}
		$this->assertSame( 100, $rows, 'Every row of the table must be in the package.' );
	}

	public function test_a_cancel_that_comes_after_the_swap_is_refused_and_the_import_finishes() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 10 );
		$job    = $source->run( 'export', array( 'kind' => 'sanitize', 'label' => 'x', 'sanitize' => Profiles::build_config( 'staging', array( 'seed' => 's' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status );
		$id = $job->state['package'];

		$target = $this->hooked_rig( 'https://new.example.com', 3 );
		$target->db->query( "UPDATE wp_options SET option_value = 'LOCAL-SETTINGS' WHERE option_name = 'estasoft_migrate_settings'" );
		// A request number remembered by the replay guard, which an import must not make forgettable.
		$target->db->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('estasoft_migrate_n_0123456789abcdef0123456789abcdef', '" . ( time() + 600 ) . "', 'no')" );
		$this->transfer( $source, $target, $id );

		$imp       = $target->runner->create( 'import', 'import', array( 'package_id' => $id, 'snapshot' => false ) );
		$cancelled = null;
		$target->db->after_rename = function () use ( $target, $imp, &$cancelled ) {
			$cancelled = $target->runner->cancel( $imp->id );
		};
		for ( $i = 0; $i < 50; $i++ ) {
			$target->runner->run_slice( $imp->id, Budget::unlimited(), 'cli' );
			if ( $target->store->find( $imp->id )->is_finished() ) {
				break;
			}
		}
		$done = $target->store->find( $imp->id );

		$this->assertFalse( $cancelled, 'Cancelling is refused once the new data is live.' );
		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$this->assertSame( '10', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertNotNull( $this->option( $target, 'estasoft_migrate_settings' ), 'The plugin settings survive.' );
		$this->assertNotNull( $this->option( $target, 'estasoft_migrate_n_0123456789abcdef0123456789abcdef' ), 'So does the memory of used request numbers.' );
		$this->assertSame( '0', $this->option( $target, 'blog_public' ), 'The staging safety switches were applied.' );
		$this->assertSame( array(), $this->staging_tables( $target ), 'No copy of the old site is left behind.' );
	}

	public function test_a_cancel_that_arrives_during_the_swap_slice_is_undone() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 6 );
		$job    = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$id     = $job->state['package'];

		$target = $this->hooked_rig( 'https://new.example.com', 2 );
		$this->transfer( $source, $target, $id );
		$imp = $target->runner->create( 'import', 'import', array( 'package_id' => $id, 'snapshot' => false ) );

		// Marked as cancelled in the database by another request, while this slice is about to swap.
		$target->db->on_stage_insert = function () use ( $target, $imp ) {
			$target->store->set_status( $imp->id, Job::CANCELLED, 'Cancelled.', false );
		};
		$target->db->after_rename = null;
		for ( $i = 0; $i < 60; $i++ ) {
			$target->runner->run_slice( $imp->id, Budget::unlimited(), 'cli' );
			$status = $target->store->find( $imp->id )->status;
			if ( in_array( $status, array( Job::CANCELLED, Job::COMPLETED, Job::FAILED ), true ) ) {
				break;
			}
		}
		// Cancelled before the swap: it is cleaned up and the live site is untouched.
		$this->assertSame( Job::CANCELLED, $target->store->find( $imp->id )->status );
		$this->assertSame( array(), $this->staging_tables( $target ) );
		$this->assertSame( '2', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ), 'The live site was not changed.' );
	}

	public function test_a_partial_import_does_not_touch_the_live_options() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 6 );
		$job    = $source->run( 'export', array( 'kind' => 'sanitize', 'label' => 'x', 'sanitize' => Profiles::build_config( 'staging', array( 'seed' => 's' ) ) ) );
		$id     = $job->state['package'];
		$target = $this->rig( 'wp_', 'https://new.example.com', 2 );
		$this->transfer( $source, $target, $id );
		$active_before = $this->option( $target, 'active_plugins' );

		$done = $target->run( 'import', array( 'package_id' => $id, 'snapshot' => false, 'tables' => array( 'wp_posts' ) ) );

		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$this->assertSame( '1', $this->option( $target, 'blog_public' ), 'The live site stays visible to search engines.' );
		$this->assertSame( $active_before, $this->option( $target, 'active_plugins' ), 'No plugin was switched off.' );
		$this->assertNull( $this->option( $target, 'estasoft_migrate_block_mail' ), 'Mail is not blocked on the live site.' );
	}

	public function test_an_export_whose_password_cannot_be_read_fails_instead_of_writing_an_open_file() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 3 );
		$params = array(
			'kind'           => 'export',
			'label'          => 'x',
			'passphrase_enc' => Secrets::seal( 'correct horse', 'ANOTHER-SECRET' ),
		);
		$job = $source->run( 'export', $params );

		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'protected', $job->message );
		$this->assertSame( array(), $source->storage->list_packages(), 'No unprotected package was kept.' );
	}

	public function test_cancelling_does_not_clean_up_under_a_slice_that_is_still_running() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 40 );
		$source->ctx->max_batch = 5;
		$job = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$id  = $job->state['package'];

		$target = $this->hooked_rig( 'https://new.example.com', 3 );
		$target->ctx->max_batch = 5;
		$this->transfer( $source, $target, $id );
		$imp = $target->runner->create( 'import', 'import', array( 'package_id' => $id, 'snapshot' => false ) );

		// Another request cancels while this one is in the middle of filling a staging table.
		$staging_seen = null;
		$cancelled    = null;
		$target->db->on_stage_insert = function () use ( $target, $imp, &$staging_seen, &$cancelled ) {
			$cancelled    = $target->runner->cancel( $imp->id );
			$staging_seen = count( array_filter( $target->db->list_tables(), static function ( $t ) {
				return 0 === strpos( $t, '_estm_' );
			} ) );
		};
		for ( $i = 0; $i < 400; $i++ ) {
			$target->runner->run_slice( $imp->id, Budget::unlimited(), 'cli' );
			if ( $target->store->find( $imp->id )->is_finished() ) {
				break;
			}
		}

		$this->assertTrue( $cancelled );
		$this->assertGreaterThan( 0, $staging_seen, 'The staging tables are still there while the slice is running.' );
		$final = $target->store->find( $imp->id );
		$this->assertSame( Job::CANCELLED, $final->status );
		$this->assertSame( array(), $this->staging_tables( $target ), 'The running slice cleans up when it ends.' );
		$this->assertSame( '3', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
	}

	public function test_a_slice_that_starts_with_little_memory_left_still_moves_the_job_forward() {
		$rig = $this->rig( 'wp_', 'https://old.example.com', 10 );
		$job = $rig->runner->create( 'export', 'x', array( 'kind' => 'export', 'label' => 'x' ) );
		for ( $i = 0; $i < 60; $i++ ) {
			// Memory is above this limit from the first instruction, like a busy small site.
			$rig->runner->run_slice( $job->id, Budget::guaranteed( 15, 1024 ), 'ajax' );
			if ( $rig->store->find( $job->id )->is_finished() ) {
				break;
			}
		}
		$this->assertSame( Job::COMPLETED, $rig->store->find( $job->id )->status );
	}

	public function test_a_step_that_was_killed_leaves_smaller_batches_behind() {
		$rig = $this->rig( 'wp_', 'https://old.example.com', 30 );
		$job = $rig->runner->create( 'export', 'x', array( 'kind' => 'export', 'label' => 'x' ) );
		$rig->runner->run_slice( $job->id, Budget::units( 1 ), 'ajax' );
		$job = $rig->store->find( $job->id );
		$this->assertArrayNotHasKey( '_slice', $job->state, 'A step that ends normally leaves no marker.' );
		$size = $job->state['batch']['size'];
		$this->assertGreaterThan( 1, $size );

		// What a step that the server killed leaves behind: the marker it saved before starting.
		$job->state['_slice'] = time();
		$rig->store->save( $job );
		$rig->runner->run_slice( $job->id, Budget::units( 1 ), 'ajax' );
		$job = $rig->store->find( $job->id );

		$this->assertLessThanOrEqual( (int) floor( $size / 2 ), $job->state['batch']['size'], 'The next step uses a batch no bigger than half.' );
		$this->assertLessThanOrEqual( (int) floor( $size / 2 ), $job->state['batch']['cap'], 'And the batch cannot grow back past it.' );
		$this->assertSame( Job::RUNNING, $job->status );
	}

	public function test_a_job_that_is_killed_again_and_again_stops_with_a_clear_message() {
		$rig    = $this->rig( 'wp_', 'https://old.example.com', 5 );
		$runner = $rig->runner;
		$job    = new Job();
		$job->id = 1;
		$job->state = array( 'batch' => array( 'size' => 200 ) );
		$note   = new \ReflectionMethod( $runner, 'note_interrupted_step' );
		$note->setAccessible( true );

		$thrown = null;
		for ( $n = 1; $n <= 3; $n++ ) {
			$job->state['_slice'] = time(); // Saved before the step; the step never came back.
			try {
				$note->invoke( $runner, $job );
			} catch ( \Estasoft\Migrate\Engine\TaskException $e ) {
				$thrown = $e;
			}
		}
		$this->assertNotNull( $thrown, 'The third interrupted step ends the job.' );
		$this->assertFalse( $thrown->retryable );
		$this->assertStringContainsString( 'memory or time', $thrown->getMessage() );
		$this->assertSame( 25, $job->state['batch']['size'], '200, then 100, 50 and 25.' );
	}

	public function test_deleting_a_failed_import_removes_its_temporary_tables() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 8 );
		$job    = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$id     = $job->state['package'];
		$target = $this->hooked_rig( 'https://new.example.com', 3 );
		$this->transfer( $source, $target, $id );
		$target->db->fail_rename = true;
		$failed = $target->run( 'import', array( 'package_id' => $id, 'snapshot' => false ), 30 );

		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertNotEmpty( $this->staging_tables( $target ), 'A failed import keeps them so that it can be retried.' );

		$target->runner->discard( $failed->id );

		$this->assertSame( array(), $this->staging_tables( $target ) );
		$this->assertNull( $target->store->find( $failed->id ) );
	}

	public function test_the_daily_sweep_removes_temporary_tables_that_no_job_owns() {
		$rig = $this->rig( 'wp_', 'https://old.example.com', 2 );
		$rig->db->query( 'CREATE TABLE `_estm_999_0` (`a` INTEGER)' );
		$rig->db->query( 'CREATE TABLE `_estm_old_999_0` (`a` INTEGER)' );
		$rig->db->query( 'CREATE TABLE `wp_estm_note` (`a` INTEGER)' );

		$removed = $rig->runner->sweep();

		$this->assertSame( 2, $removed );
		$this->assertSame( array(), $this->staging_tables( $rig ) );
		$this->assertTrue( $rig->db->table_exists( 'wp_estm_note' ), 'Only the plugin\'s own temporary tables are touched.' );
	}

	public function test_a_table_without_a_key_is_not_duplicated_when_an_import_is_retried() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 3 );
		$source->db->query( 'CREATE TABLE `wp_nopk` (`a` TEXT, `b` TEXT)' );
		for ( $i = 0; $i < 25; $i++ ) {
			$source->db->query( "INSERT INTO wp_nopk VALUES ('r$i','v$i')" );
		}
		$source->ctx->max_batch = 10;
		$job = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$this->assertSame( Job::COMPLETED, $job->status );
		$id = $job->state['package'];

		$flaky               = new FlakyInsertDatabase( 'wp_' );
		$flaky->fail_columns = array( 'a', 'b' );
		EngineHarness::create_wordpress_tables( $flaky, 'wp_' );
		$target = $this->rig( 'wp_', 'https://new.example.com', 3, $flaky );
		$this->transfer( $source, $target, $id );

		$done = $target->run( 'import', array( 'package_id' => $id, 'snapshot' => false ), 60 );

		$this->assertTrue( $flaky->fired, 'The simulated connection loss happened.' );
		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$this->assertSame( '25', $target->db->get_var( 'SELECT COUNT(*) FROM wp_nopk' ), 'Each row exactly once.' );
	}

	public function test_addresses_are_only_rewritten_as_whole_addresses() {
		$pairs    = \Estasoft\Migrate\Engine\SiteInfo::rewrite_pairs(
			array( 'url' => 'https://acme.com', 'siteurl' => 'https://acme.com', 'abspath' => '/var/www/old' ),
			array( 'url' => 'https://staging.test', 'siteurl' => 'https://staging.test', 'abspath' => '/srv/new' )
		);
		$replacer = new \Estasoft\Migrate\Replace\Replacer( $pairs, array( 'case_sensitive' => true ) );
		$in       = '<a href="https://acme.com.au/shop">a</a> <a href="https://acme.community/">b</a> <a href="https://acme.com/x">own</a> '
			. 'Visit https://acme.com. Also //acme.com/y and {"u":"https:\/\/acme.com\/json"} in /var/www/old/file but not /var/www/old2/file or /var/www/old.bak/f';
		$out      = $replacer->replace( $in );

		$this->assertStringContainsString( 'https://acme.com.au/shop', $out, 'A different host that starts the same is left alone.' );
		$this->assertStringContainsString( 'https://acme.community/', $out );
		$this->assertStringContainsString( '<a href="https://staging.test/x">own</a>', $out );
		$this->assertStringContainsString( 'Visit https://staging.test.', $out, 'A full stop at the end of a sentence is not part of the name.' );
		$this->assertStringContainsString( '//staging.test/y', $out );
		$this->assertStringContainsString( 'https:\/\/staging.test\/json', $out, 'JSON-escaped addresses are rewritten too.' );
		$this->assertStringContainsString( '/srv/new/file', $out );
		$this->assertStringContainsString( '/var/www/old2/file', $out );
		$this->assertStringContainsString( '/var/www/old.bak/f', $out );
	}

	public function test_a_prefix_change_renames_only_the_keys_wordpress_builds_from_the_prefix() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 2 );
		foreach ( array( 'wp_user_roles', 'wp_page_for_privacy_policy', 'wp_rocket_settings' ) as $name ) {
			$source->db->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$name','x','yes')" );
		}
		$source->db->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'wp_user_level','10'),(1,'wp_persisted_preferences','a'),(1,'wp_custom_thing','b')" );
		$job    = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$id     = $job->state['package'];
		$target = $this->rig( 'site2_', 'https://new.example.com', 1 );
		$this->transfer( $source, $target, $id );

		$done = $target->run( 'import', array( 'package_id' => $id, 'snapshot' => false ) );

		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$options = array_column( $target->db->get_results( 'SELECT option_name FROM site2_options' ), 'option_name' );
		$this->assertContains( 'site2_user_roles', $options );
		$this->assertContains( 'wp_page_for_privacy_policy', $options, 'WordPress reads this one under the wp_ name.' );
		$this->assertContains( 'wp_rocket_settings', $options, 'Another plugin\'s setting keeps its name.' );
		$meta = array_column( $target->db->get_results( 'SELECT meta_key FROM site2_usermeta' ), 'meta_key' );
		$this->assertContains( 'site2_capabilities', $meta );
		$this->assertContains( 'site2_user_level', $meta );
		$this->assertContains( 'site2_persisted_preferences', $meta );
		$this->assertContains( 'wp_custom_thing', $meta );
	}

	public function test_a_setting_without_secrets_is_returned_exactly_as_it_was() {
		$json = '{"a":{},"id":12345678901234567890,"ratio":1.0,"u":"http:\/\/x.test\/p","s":"é"}';
		$this->assertSame( $json, \Estasoft\Migrate\Sanitizer\SecretScrubber::scrub( $json ) );

		$with = '{"a":{},"api_key":"abc123","ratio":1.0}';
		$out  = \Estasoft\Migrate\Sanitizer\SecretScrubber::scrub( $with );
		$this->assertStringContainsString( '"a":{}', $out, 'An empty object stays an object.' );
		$this->assertStringContainsString( '"ratio":1.0', $out );
		$this->assertStringContainsString( '"api_key":""', $out );
	}

	public function test_salts_and_security_keys_stored_in_settings_are_emptied() {
		$value = serialize( array( 'logged_in_key' => 'abc', 'nonce_salt' => 'def', 'secure_auth_key' => 'ghi', 'title' => 'Hello' ) );
		$out   = unserialize( \Estasoft\Migrate\Sanitizer\SecretScrubber::scrub( $value ) );
		$this->assertSame( '', $out['logged_in_key'] );
		$this->assertSame( '', $out['nonce_salt'] );
		$this->assertSame( '', $out['secure_auth_key'] );
		$this->assertSame( 'Hello', $out['title'] );
		$this->assertTrue( \Estasoft\Migrate\Sanitizer\SecretScrubber::is_secret_option( 'logged_in_salt' ) );
	}

	public function test_masked_logins_and_emails_do_not_collide() {
		$fake  = new \Estasoft\Migrate\Sanitizer\FakeData( 'salt' );
		$mails = array();
		$names = array();
		for ( $i = 0; $i < 20000; $i++ ) {
			$mails[ $fake->email( "customer$i@shop.example" ) ] = true;
			$names[ $fake->username( "login$i" ) ]              = true;
		}
		$this->assertCount( 20000, $mails, 'Different customers keep different addresses.' );
		$this->assertCount( 20000, $names, 'Different users keep different logins.' );
		$this->assertSame( $fake->email( 'same@shop.example' ), $fake->email( 'same@shop.example' ) );
	}

	public function test_a_foreign_key_followed_by_a_check_constraint_leaves_valid_sql() {
		$ddl = "CREATE TABLE `wp_t` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `parent` bigint unsigned DEFAULT NULL,\n  `qty` int NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `parent` (`parent`),\n  CONSTRAINT `fk_p` FOREIGN KEY (`parent`) REFERENCES `wp_t` (`id`),\n  CONSTRAINT `chk_qty` CHECK ((`qty` > 0))\n) ENGINE=InnoDB";
		$out = \Estasoft\Migrate\Engine\DdlTranslator::without_foreign_keys( $ddl, $count );

		$this->assertSame( 1, $count );
		$this->assertStringContainsString( "KEY `parent` (`parent`),\n  CONSTRAINT `chk_qty`", $out );
		$this->assertStringNotContainsString( 'FOREIGN KEY', $out );
		$this->assertStringContainsString( "CHECK ((`qty` > 0))\n)", $out, 'The last definition has no comma.' );

		// A foreign key as the last line: the line before it loses its comma.
		$last = "CREATE TABLE `wp_t` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `wp_o` (`id`)\n) ENGINE=InnoDB";
		$this->assertSame( "CREATE TABLE `wp_t` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB", \Estasoft\Migrate\Engine\DdlTranslator::without_foreign_keys( $last ) );
	}

	public function test_tables_and_columns_with_spaces_or_accents_are_exported_and_imported() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 3 );
		$source->db->query( 'CREATE TABLE `wp_café_log` (`id` INTEGER PRIMARY KEY, `v` TEXT)' );
		$source->db->query( "INSERT INTO `wp_café_log` VALUES (1,'x')" );
		$source->db->query( 'CREATE TABLE `wp_odd_cols` (`id` INTEGER PRIMARY KEY, `Order Number` TEXT)' );
		$source->db->query( "INSERT INTO `wp_odd_cols` VALUES (1,'x')" );
		$job = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$this->assertSame( Job::COMPLETED, $job->status, $job->message );
		$names = array_column( $job->state['tables'], 'name' );
		$this->assertContains( 'wp_café_log', $names );
		$this->assertContains( 'wp_odd_cols', $names );

		$target = $this->rig( 'wp_', 'https://new.example.com', 1 );
		$this->transfer( $source, $target, $job->state['package'] );
		$done = $target->run( 'import', array( 'package_id' => $job->state['package'], 'snapshot' => false ) );
		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$this->assertSame( 'x', $target->db->get_var( 'SELECT `Order Number` FROM `wp_odd_cols`' ) );
	}

	public function ddl_cases() {
		$ok = "CREATE TABLE `_estm_1_0` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `kind` set('a','b') DEFAULT 'a;b',\n  `note` varchar(20) DEFAULT 'it''s; fine',\n  `at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n/*!50100 PARTITION BY HASH (`id`) PARTITIONS 2 */";
		return array(
			'a normal definition'              => array( $ok, true ),
			'a second statement'               => array( "CREATE TABLE `_estm_1_0` (`id` int); DROP TABLE wp_users", false ),
			'not a create statement'           => array( 'DROP TABLE wp_users', false ),
			'another table name'               => array( "CREATE TABLE `wp_users` (`id` int)", false ),
			'rows copied from another table'   => array( "CREATE TABLE `_estm_1_0` (`id` int) AS SELECT user_pass FROM wp_users", false ),
			'a statement hidden in a version comment' => array( "CREATE TABLE `_estm_1_0` (`id` int) /*!50000 ; DROP TABLE wp_users */", false ),
			'a line comment'                   => array( "CREATE TABLE `_estm_1_0` (`id` int) -- x", false ),
			'unbalanced parentheses'           => array( "CREATE TABLE `_estm_1_0` (`id` int", false ),
			'empty'                            => array( '', false ),
		);
	}

	/**
	 * @dataProvider ddl_cases
	 */
	public function test_only_a_single_plain_create_table_is_run_from_a_package( $ddl, $expected ) {
		$this->assertSame( $expected, \Estasoft\Migrate\Engine\DdlTranslator::is_single_create_table( $ddl, '_estm_1_0' ) );
	}

	public function test_an_import_refuses_a_package_whose_table_definition_is_a_different_statement() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 3 );
		$job    = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$id     = $job->state['package'];
		$from   = \Estasoft\Migrate\Package\PackageReader::open( $source->storage->package_path( $id ) );
		$tmp    = $source->storage->package_path( $id ) . '.tampered';
		$out    = \Estasoft\Migrate\Package\PackageWriter::create( $tmp, array() );
		while ( $f = $from->next() ) {
			if ( 'R' === $f['type'] ) {
				$out->raw_frame( 'R', is_string( $f['data'] ) ? $f['data'] : json_encode( $f['data'] ) );
			} else {
				$data = $f['data'];
				if ( 'T' === $f['type'] && 'wp_options' === $data['name'] ) {
					$data['ddl'] = 'DROP TABLE wp_users';
				}
				$out->frame( $f['type'], $data );
			}
		}
		$out->close();
		rename( $tmp, $source->storage->package_path( $id ) );

		$target = $this->rig( 'wp_', 'https://new.example.com', 2 );
		$this->transfer( $source, $target, $id );
		$done = $target->run( 'import', array( 'package_id' => $id, 'snapshot' => false ) );

		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'not a plain table definition', $done->message );
		$this->assertSame( '2', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ), 'The live tables were not touched.' );
		$this->assertContains( 'wp_users', $target->db->list_tables() );
	}

	public function test_a_package_never_replaces_the_plugins_own_tables() {
		$source = $this->rig( 'wp_', 'https://old.example.com', 3 );
		$job    = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x' ) );
		$id     = $job->state['package'];
		$path   = $source->storage->package_path( $id );

		// Hand-make a package that also carries a table with the name of the plugin's job table.
		$from = \Estasoft\Migrate\Package\PackageReader::open( $path );
		$tmp  = $path . '.tampered';
		$out  = \Estasoft\Migrate\Package\PackageWriter::create( $tmp, array() );
		while ( $f = $from->next() ) {
			$data = $f['data'];
			if ( 'M' === $f['type'] ) {
				$data['tables'][] = array( 'name' => 'wp_estasoft_jobs', 'rows_est' => 1 );
				$out->frame( 'M', $data );
			} elseif ( 'E' === $f['type'] ) {
				$out->frame( 'T', array( 'name' => 'wp_estasoft_jobs', 'ddl' => 'CREATE TABLE `wp_estasoft_jobs` (`id` int, `type` varchar(20), PRIMARY KEY (`id`))', 'pk' => array( 'id' ) ) );
				$out->raw_frame( 'R', \Estasoft\Migrate\Package\RowCodec::encode( 'wp_estasoft_jobs', array( 'id', 'type' ), array( array( 'id' => '99', 'type' => 'evil' ) ) ) );
				$out->frame( 'U', array( 'name' => 'wp_estasoft_jobs', 'rows' => 1 ) );
				$out->frame( 'E', $data );
			} elseif ( 'R' === $f['type'] ) {
				$out->raw_frame( 'R', is_string( $data ) ? $data : json_encode( $data ) );
			} else {
				$out->frame( $f['type'], $data );
			}
		}
		$out->close();
		rename( $tmp, $path );

		$target = $this->rig( 'wp_', 'https://new.example.com', 2 );
		$this->transfer( $source, $target, $id );
		$done = $target->run( 'import', array( 'package_id' => $id, 'snapshot' => false ) );

		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$this->assertSame( '0', $target->db->get_var( "SELECT COUNT(*) FROM wp_estasoft_jobs WHERE type = 'evil'" ) );
		foreach ( $done->state['tables'] as $t ) {
			$this->assertStringStartsNotWith( 'estasoft_', $t['logical'] );
		}
		$this->assertCount( 0, array_filter( $target->db->list_tables(), static function ( $t ) {
			return 0 === strpos( $t, '_estm_' );
		} ), 'No leftovers.' );
	}

	public function test_a_list_of_addresses_that_cannot_be_read_does_not_let_everyone_in() {
		$this->assertTrue( \Estasoft\Migrate\Remote\IpMatcher::matches( '203.0.113.7', '' ), 'An empty list means any address.' );
		$this->assertFalse( \Estasoft\Migrate\Remote\IpMatcher::matches( '203.0.113.7', "203.0.113.700\nnot-an-address" ), 'A list with nothing readable means no address.' );
		$this->assertTrue( \Estasoft\Migrate\Remote\IpMatcher::matches( '203.0.113.7', "bad\n203.0.113.0/24" ) );
		$this->assertFalse( \Estasoft\Migrate\Remote\IpMatcher::matches( '198.51.100.9', "bad\n203.0.113.0/24" ) );
	}

	public function test_the_package_folder_is_tested_the_way_a_stranger_would_reach_it() {
		$dir = sys_get_temp_dir() . '/estm-guard-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		$storage             = new \Estasoft\Migrate\Storage( null, $dir );
		$storage->public_url = 'https://example.test/uploads/estasoft-migrate-abc';
		$asked               = array();

		// A server that ignores the guard files hands out the file.
		$storage->http = function ( $url ) use ( $dir, &$asked ) {
			$asked[] = $url;
			return array( 'code' => 200, 'body' => file_get_contents( $dir . '/' . basename( $url ) ) );
		};
		$this->assertFalse( $storage->direct_access_blocked( true ) );
		$this->assertStringStartsWith( 'https://example.test/uploads/estasoft-migrate-abc/probe-', $asked[0] );
		$this->assertSame( array(), glob( $dir . '/probe-*' ), 'The test file is removed again.' );

		$storage->http = static function () {
			return array( 'code' => 403, 'body' => 'Forbidden' );
		};
		$this->assertTrue( $storage->direct_access_blocked( true ) );

		$storage->http = static function () {
			return array( 'code' => 200, 'body' => '<html>a catch-all page</html>' );
		};
		$this->assertTrue( $storage->direct_access_blocked( true ), 'A page that is not the file does not count as the file.' );

		$storage->http = static function () {
			return null;
		};
		$this->assertNull( $storage->direct_access_blocked( true ), 'No answer: not known.' );

		$storage->http = static function () {
			return array( 'code' => 503, 'body' => '' );
		};
		$this->assertNull( $storage->direct_access_blocked( true ), 'A broken server says nothing about the folder.' );

		self::rm( $dir );
	}

	private static function rm( $dir ) {
		EngineHarness::rrmdir( $dir );
	}
}
