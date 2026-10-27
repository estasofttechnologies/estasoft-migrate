<?php
/**
 * Pre-flight checks.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Preflight\Checker;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Every finding is explained, and nothing is changed.
 */
class PreflightTest extends TestCase {

	/**
	 * Rigs to clean up.
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
	 * A seeded site.
	 *
	 * @param string $url Address.
	 * @return EngineHarness
	 */
	private function site( $url = 'https://old.example.com' ) {
		$rig = new EngineHarness( 'wp_', $url );
		$rig->seed( $url, 10 );
		$this->rigs[] = $rig;
		return $rig;
	}

	/**
	 * Server facts that look like a normal healthy host.
	 *
	 * @param array $more Overrides.
	 * @return array
	 */
	private function healthy( array $more = array() ) {
		return array_merge(
			array(
				'php'       => '8.2.0',
				'memory'    => 256 * 1048576,
				'max_exec'  => 300,
				'cron_off'  => false,
				'sodium'    => true,
				'zlib'      => true,
				'multisite' => false,
				'db'        => array(
					'type'      => 'mysql',
					'version'   => '8.0.36',
					'charset'   => 'utf8mb4',
					'collation' => 'utf8mb4_unicode_ci',
				),
				'free'      => 50 * 1073741824,
			),
			$more
		);
	}

	/**
	 * Look a check up by id.
	 *
	 * @param array  $result Checker output.
	 * @param string $id     Check id.
	 * @return array|null
	 */
	private function find( array $result, $id ) {
		foreach ( $result['checks'] as $c ) {
			if ( $id === $c['id'] ) {
				return $c;
			}
		}
		return null;
	}

	public function test_a_healthy_site_is_ready() {
		$rig    = $this->site();
		$result = ( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'ready', $result['status'], print_r( $result['checks'], true ) );
		$this->assertSame( 0, $result['counts']['block'] );
		$this->assertSame( 0, $result['counts']['warn'] );
		$this->assertGreaterThan( 5, $result['counts']['pass'] );
		$this->assertSame( 6, $result['summary']['tables'] );
		$this->assertSame( 'pass', $this->find( $result, 'storage' )['status'] );
	}

	public function test_old_php_and_tiny_memory_block_the_run() {
		$rig    = $this->site();
		$result = ( new Checker( $rig->ctx, $this->healthy( array( 'php' => '7.2.5', 'memory' => 32 * 1048576 ) ) ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'blocked', $result['status'] );
		$this->assertSame( 'block', $this->find( $result, 'php' )['status'] );
		$this->assertSame( 'block', $this->find( $result, 'memory' )['status'] );
		$this->assertSame( 'block', $result['checks'][0]['status'], 'Blocks are listed first.' );
	}

	public function test_short_time_limit_and_modest_memory_only_warn() {
		$rig    = $this->site();
		$result = ( new Checker( $rig->ctx, $this->healthy( array( 'max_exec' => 30, 'memory' => 96 * 1048576 ) ) ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'warnings', $result['status'] );
		$time = $this->find( $result, 'time' );
		$this->assertSame( 'warn', $time['status'] );
		$this->assertStringContainsString( '30 seconds', $time['title'] );
		$this->assertStringContainsString( 'small batches', $time['detail'] );
		$this->assertSame( 'warn', $this->find( $result, 'memory' )['status'] );
	}

	public function test_multisite_and_unsupported_database_are_blocked() {
		$rig    = $this->site();
		$result = ( new Checker( $rig->ctx, $this->healthy( array( 'multisite' => true, 'db' => array( 'type' => 'pgsql', 'version' => '15' ) ) ) ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'block', $this->find( $result, 'multisite' )['status'] );
		$this->assertSame( 'block', $this->find( $result, 'database' )['status'] );
	}

	public function test_old_database_server_only_warns() {
		$rig    = $this->site();
		$result = ( new Checker( $rig->ctx, $this->healthy( array( 'db' => array( 'type' => 'mariadb', 'version' => '10.1.48' ) ) ) ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'warn', $this->find( $result, 'database' )['status'] );
	}

	public function test_a_password_needs_sodium() {
		$rig    = $this->site();
		$checks = new Checker( $rig->ctx, $this->healthy( array( 'sodium' => false ) ) );
		$plain  = $checks->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'warn', $this->find( $plain, 'sodium' )['status'] );
		$locked = $checks->run( array( 'mode' => 'export', 'password' => 'hunter2' ) );
		$this->assertSame( 'block', $this->find( $locked, 'sodium' )['status'] );
	}

	public function test_not_enough_disk_space_blocks_and_tight_space_warns() {
		$rig = $this->site();
		$big = new \Estasoft\Migrate\Tests\Support\BigTablesDatabase( 'wp_', 'sqlite::memory:', 1073741824 );
		EngineHarness::create_wordpress_tables( $big, 'wp_' );
		$sized = new EngineHarness( 'wp_', 'https://big.example.com', '', 'sqlite::memory:', $big );
		$this->rigs[] = $sized;

		$checker = new Checker( $sized->ctx, $this->healthy( array( 'free' => 2 * 1073741824 ) ) );
		$result  = $checker->run( array( 'mode' => 'export' ) );
		$disk    = $this->find( $result, 'disk' );
		$this->assertSame( 'block', $disk['status'], print_r( $result['checks'], true ) );
		$this->assertStringContainsString( 'Free some space', $disk['detail'] );

		$tight = ( new Checker( $sized->ctx, $this->healthy( array( 'free' => 5 * 1073741824 ) ) ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'warn', $this->find( $tight, 'disk' )['status'] );

		$roomy = ( new Checker( $sized->ctx, $this->healthy( array( 'free' => 40 * 1073741824 ) ) ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'pass', $this->find( $roomy, 'disk' )['status'] );
		$this->assertGreaterThan( 0, $roomy['summary']['package'] );
		$this->assertGreaterThan( 20, $roomy['summary']['seconds'][0] );
	}

	public function test_another_running_job_blocks_a_new_run_until_it_is_finished() {
		$rig = $this->site();
		$job = $rig->store->create( 'import', 'Import yesterday', array( 'package_id' => 'x' ) );
		$checker = new Checker( $rig->ctx, $this->healthy() );

		$busy = $checker->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'block', $this->find( $busy, 'busy' )['status'], 'An export would read half-swapped tables.' );
		$this->assertStringContainsString( 'Import yesterday', $this->find( $busy, 'busy' )['detail'] );
		$this->assertSame( 'blocked', $busy['status'] );

		$rig->store->set_status( $job->id, Job::PAUSED );
		$this->assertSame( 'block', $this->find( $checker->run( array( 'mode' => 'export' ) ), 'busy' )['status'], 'A paused job still holds its staging tables.' );

		$rig->store->set_status( $job->id, Job::COMPLETED );
		$free = $checker->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'pass', $this->find( $free, 'busy' )['status'] );
		$this->assertSame( 'ready', $free['status'] );
	}

	public function test_a_dry_run_search_and_replace_does_not_stop_other_work() {
		$rig = $this->site();
		$rig->store->create( 'search_replace', 'Preview links', array( 'dry_run' => true, 'search' => 'a', 'replace' => 'b' ) );
		$res = ( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'export' ) );
		$this->assertSame( 'pass', $this->find( $res, 'busy' )['status'] );
	}

	public function test_tables_without_a_primary_key_are_reported() {
		$rig = $this->site();
		$rig->db->query( 'CREATE TABLE `wp_legacy` (`a` TEXT, `b` TEXT)' );
		$rig->db->forget();
		$checker = new Checker( $rig->ctx, $this->healthy() );

		$export = $checker->run( array( 'mode' => 'export' ) );
		$nokey  = $this->find( $export, 'nokey' );
		$this->assertSame( 'warn', $nokey['status'] );
		$this->assertStringContainsString( 'wp_legacy', $nokey['detail'] );
		$this->assertStringContainsString( 'fine to migrate', $nokey['detail'] );

		$replace = $checker->run( array( 'mode' => 'search_replace' ) );
		$this->assertStringContainsString( 'skipped', $this->find( $replace, 'nokey' )['detail'] );
	}

	public function test_the_table_choice_limits_what_is_counted() {
		$rig    = $this->site();
		$result = ( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'export', 'tables' => array( 'wp_posts', 'wp_options' ) ) );
		$this->assertSame( 2, $result['summary']['tables'] );
		$this->assertSame( 10 + 11, $result['summary']['rows'] );
	}

	public function test_a_package_that_is_missing_or_not_a_package_is_blocked() {
		$rig     = $this->site();
		$checker = new Checker( $rig->ctx, $this->healthy() );

		$missing = $checker->run( array( 'mode' => 'import', 'package_id' => 'nothing-here' ) );
		$this->assertSame( 'block', $this->find( $missing, 'package' )['status'] );

		$id = \Estasoft\Migrate\Storage::new_id( 'junk' );
		file_put_contents( $rig->storage->package_path( $id ), 'this is not a package at all, just text of some length.' );
		$junk = $checker->run( array( 'mode' => 'import', 'package_id' => $id ) );
		$this->assertSame( 'block', $this->find( $junk, 'package' )['status'] );
		$this->assertStringContainsString( 'not an Estasoft Migrate package', $this->find( $junk, 'package' )['detail'] );
	}

	public function test_a_plain_package_shows_the_address_change() {
		$source = $this->site( 'https://old.example.com' );
		$job    = $source->run( 'export', array( 'label' => 'plain' ) );
		$this->assertSame( Job::COMPLETED, $job->status );
		$id = $job->state['package'];

		$dest = new EngineHarness( 'wp_', 'https://new.example.com', $source->dir );
		$dest->site['url'] = 'https://new.example.com';
		$result            = ( new Checker( $dest->ctx, $this->healthy() ) )->run( array( 'mode' => 'import', 'package_id' => $id ) );

		$this->assertSame( 'pass', $this->find( $result, 'package' )['status'] );
		$this->assertSame( 'pass', $this->find( $result, 'rewrite' )['status'] );
		$this->assertStringContainsString( 'https://old.example.com', $this->find( $result, 'rewrite' )['detail'] );
		$this->assertStringContainsString( 'https://new.example.com', $this->find( $result, 'rewrite' )['detail'] );
		$this->assertNull( $this->find( $result, 'package_enc' ) );
		$this->assertNull( $this->find( $result, 'prefix' ) );
		$dest->db = null;
	}

	public function test_a_package_with_a_password_asks_for_it_and_checks_it() {
		$source = $this->site( 'https://old.example.com' );
		$job    = $source->run( 'export', array( 'label' => 'secret', 'passphrase' => 'correct horse' ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$id = $job->state['package'];

		$dest    = new EngineHarness( 'wp_', 'https://new.example.com', $source->dir );
		$checker = new Checker( $dest->ctx, $this->healthy() );

		$none = $checker->run( array( 'mode' => 'import', 'package_id' => $id ) );
		$this->assertSame( 'block', $this->find( $none, 'package_enc' )['status'] );
		$this->assertSame( 'Package password needed', $this->find( $none, 'package_enc' )['title'] );

		$wrong = $checker->run( array( 'mode' => 'import', 'package_id' => $id, 'password' => 'wrong' ) );
		$this->assertSame( 'block', $this->find( $wrong, 'package_enc' )['status'] );
		$this->assertSame( 'The password is not correct', $this->find( $wrong, 'package_enc' )['title'] );

		$right = $checker->run( array( 'mode' => 'import', 'package_id' => $id, 'password' => 'correct horse' ) );
		$this->assertSame( 'pass', $this->find( $right, 'package_enc' )['status'] );
		$this->assertSame( 'pass', $this->find( $right, 'rewrite' )['status'], 'Once unlocked, the manifest is read.' );
	}

	public function test_a_package_locked_with_another_sites_key_cannot_be_opened() {
		$source = $this->site( 'https://old.example.com' );
		$job    = $source->run( 'export', array( 'label' => 'sitekey', 'protect_with_site_key' => true ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$id = $job->state['package'];

		$dest = new EngineHarness( 'wp_', 'https://new.example.com', $source->dir );
		$res  = ( new Checker( $dest->ctx, $this->healthy() ) )->run( array( 'mode' => 'import', 'package_id' => $id ) );
		$this->assertSame( 'block', $this->find( $res, 'package_enc' )['status'] );
		$this->assertSame( 'Package belongs to another site', $this->find( $res, 'package_enc' )['title'] );

		$home = ( new Checker( $source->ctx, $this->healthy() ) )->run( array( 'mode' => 'import', 'package_id' => $id ) );
		$this->assertSame( 'pass', $this->find( $home, 'package_enc' )['status'], 'The site that made it can open it.' );
	}

	public function test_a_different_table_prefix_in_a_package_is_explained() {
		$source = new EngineHarness( 'src_', 'https://old.example.com' );
		$source->seed( 'https://old.example.com', 5 );
		$this->rigs[] = $source;
		$job          = $source->run( 'export', array( 'label' => 'prefix' ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );

		$dest    = new EngineHarness( 'wp_', 'https://new.example.com', $source->dir );
		$result  = ( new Checker( $dest->ctx, $this->healthy() ) )->run( array( 'mode' => 'import', 'package_id' => $job->state['package'] ) );
		$prefix  = $this->find( $result, 'prefix' );
		$this->assertSame( 'warn', $prefix['status'] );
		$this->assertStringContainsString( 'src_', $prefix['title'] );
		$this->assertStringContainsString( 'wp_', $prefix['title'] );
	}

	public function test_a_push_checks_the_other_site() {
		$local  = $this->site( 'https://local.example.com' );
		$remote = $this->site( 'https://live.example.com' );
		$remote->serve();
		$local->connect_to( $remote );

		$result = ( new Checker( $local->ctx, $this->healthy() ) )->run( array( 'mode' => 'push', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key ) );
		$conn   = $this->find( $result, 'connection' );
		$this->assertSame( 'pass', $conn['status'], print_r( $result['checks'], true ) );
		$this->assertStringContainsString( 'Connection key accepted', $conn['detail'] );
		$this->assertSame( 'https://live.example.com', $result['summary']['remote'] );
		$this->assertNotNull( $this->find( $result, 'php_pair' ) );
		$this->assertNotNull( $this->find( $result, 'db_pair' ) );
		$this->assertSame( 'ready', $result['status'], print_r( $result['checks'], true ) );
	}

	public function test_a_wrong_key_or_a_missing_address_blocks_the_connection() {
		$local  = $this->site( 'https://local.example.com' );
		$remote = $this->site( 'https://live.example.com' );
		$remote->serve();
		$local->connect_to( $remote, 'esm_notTheRightKeyAtAll' );

		$wrong = ( new Checker( $local->ctx, $this->healthy() ) )->run( array( 'mode' => 'push', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key ) );
		$this->assertSame( 'block', $this->find( $wrong, 'connection' )['status'] );
		$this->assertSame( 'blocked', $wrong['status'] );

		$none = ( new Checker( $local->ctx, $this->healthy() ) )->run( array( 'mode' => 'pull' ) );
		$this->assertSame( 'block', $this->find( $none, 'connection' )['status'] );
		$this->assertStringContainsString( 'connection key', $this->find( $none, 'connection' )['detail'] );
	}

	public function test_a_site_that_does_not_accept_migrations_is_explained() {
		$local  = $this->site( 'https://local.example.com' );
		$remote = $this->site( 'https://live.example.com' );
		$remote->serve( array( 'accept_incoming' => false ) );
		$local->connect_to( $remote );

		$res = ( new Checker( $local->ctx, $this->healthy() ) )->run( array( 'mode' => 'push', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key ) );
		$this->assertSame( 'block', $this->find( $res, 'connection' )['status'] );
		$this->assertSame( 'blocked', $res['status'] );
	}

	public function test_the_other_site_being_multisite_or_low_on_disk_blocks_a_push() {
		$local  = $this->site( 'https://local.example.com' );
		$remote = $this->site( 'https://live.example.com' );
		$remote->site['multisite'] = true;
		$remote->serve();
		$local->connect_to( $remote );

		$res = ( new Checker( $local->ctx, $this->healthy() ) )->run( array( 'mode' => 'push', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key ) );
		$this->assertSame( 'block', $this->find( $res, 'remote_multisite' )['status'] );
	}

	public function test_clock_difference_and_prefix_difference_with_the_other_site_warn() {
		$local  = $this->site( 'https://local.example.com' );
		$remote = new EngineHarness( 'live_', 'https://live.example.com' );
		$remote->seed( 'https://live.example.com', 4 );
		$this->rigs[] = $remote;
		$remote->serve();
		$local->connect_to( $remote );

		$res    = ( new Checker( $local->ctx, $this->healthy() ) )->run( array( 'mode' => 'push', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key ) );
		$prefix = $this->find( $res, 'prefix' );
		$this->assertSame( 'warn', $prefix['status'] );
		$this->assertStringContainsString( 'wp_', $prefix['title'] );
		$this->assertStringContainsString( 'live_', $prefix['title'] );
		$this->assertStringContainsString( 'live_', $prefix['detail'], 'Pushing: the other site keeps its prefix.' );

		$pull = ( new Checker( $local->ctx, $this->healthy() ) )->run( array( 'mode' => 'pull', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key ) );
		$this->assertStringContainsString( 'wp_', $this->find( $pull, 'prefix' )['detail'], 'Pulling: this site keeps its prefix.' );
	}

	public function test_a_pull_sizes_the_job_by_the_other_sites_data() {
		$big = new \Estasoft\Migrate\Tests\Support\BigTablesDatabase( 'wp_', 'sqlite::memory:', 1073741824 );
		EngineHarness::create_wordpress_tables( $big, 'wp_' );
		$remote = new EngineHarness( 'wp_', 'https://live.example.com', '', 'sqlite::memory:', $big );
		$local  = $this->site( 'https://local.example.com' );
		$this->rigs[] = $remote;
		$remote->serve();
		$local->connect_to( $remote );
		$input = array( 'mode' => 'pull', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key );

		$res = ( new Checker( $local->ctx, $this->healthy( array( 'free' => 2 * 1073741824 ) ) ) )->run( $input );
		$this->assertSame( 6, $res['summary']['tables'] );
		$this->assertSame( 6000000, $res['summary']['rows'] );
		$this->assertSame( 'block', $this->find( $res, 'disk' )['status'], 'A small local disk cannot hold a large remote database.' );
		$large = $this->find( $res, 'large' );
		$this->assertNotNull( $large, 'The note about big tables is about the tables that will arrive.' );
		$this->assertStringContainsString( 'wp_', $large['detail'] );

		$roomy = ( new Checker( $local->ctx, $this->healthy( array( 'free' => 60 * 1073741824 ) ) ) )->run( $input );
		$this->assertSame( 'pass', $this->find( $roomy, 'disk' )['status'], print_r( $roomy['checks'], true ) );
	}

	public function test_a_snapshot_that_is_switched_off_is_reported_as_off() {
		$rig = $this->site();
		$on  = ( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'search_replace' ) );
		$this->assertTrue( $on['safety']['snapshot'] );
		$this->assertStringContainsString( 'can be undone', $this->find( $on, 'sr_snapshot' )['detail'] );

		foreach ( array( false, 0, '0', 'false' ) as $off_value ) {
			$off = ( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'search_replace', 'snapshot' => $off_value ) );
			$this->assertFalse( $off['safety']['snapshot'], 'snapshot = ' . var_export( $off_value, true ) );
			$this->assertStringContainsString( 'cannot be undone', $this->find( $off, 'sr_snapshot' )['detail'] );
		}

		$dry = ( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'search_replace', 'dry_run' => true ) );
		$this->assertFalse( $dry['safety']['snapshot'], 'A dry run changes nothing, so it needs no snapshot.' );
	}

	public function test_database_servers_are_named_properly() {
		$local  = $this->site( 'https://local.example.com' );
		$remote = $this->site( 'https://live.example.com' );
		$remote->serve();
		$local->connect_to( $remote );
		$env    = $this->healthy( array( 'db' => array( 'type' => 'mariadb', 'version' => '10.11.6', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_520_ci' ) ) );
		$result = ( new Checker( $local->ctx, $env ) )->run( array( 'mode' => 'push', 'remote_url' => $local->remote_url, 'remote_key' => $local->remote_key ) );
		$pair   = $this->find( $result, 'db_pair' );
		$this->assertStringContainsString( 'MariaDB 10.11.6', $pair['detail'] );
		$this->assertStringNotContainsString( 'Mariadb', $pair['detail'] );
	}

	public function test_the_check_does_not_change_anything() {
		$rig    = $this->site();
		$before = $rig->db->get_var( 'SELECT COUNT(*) FROM wp_options' ) . '|' . count( $rig->store->all() ) . '|' . count( $rig->storage->list_packages() );
		( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'export' ) );
		( new Checker( $rig->ctx, $this->healthy() ) )->run( array( 'mode' => 'search_replace', 'dry_run' => false ) );
		$after = $rig->db->get_var( 'SELECT COUNT(*) FROM wp_options' ) . '|' . count( $rig->store->all() ) . '|' . count( $rig->storage->list_packages() );
		$this->assertSame( $before, $after );
	}
}
