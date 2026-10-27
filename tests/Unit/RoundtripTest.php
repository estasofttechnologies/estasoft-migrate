<?php
/**
 * Export a site into a package and import it into another one.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Round trip tests on SQLite.
 */
class RoundtripTest extends TestCase {

	/**
	 * Rigs created by a test, removed afterwards.
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
	 * Make a rig.
	 *
	 * @param string $url  Address.
	 * @param int    $posts Posts.
	 * @param bool   $seed Fill with data.
	 * @return EngineHarness
	 */
	private function rig( $url = 'https://old.example.com', $posts = 25, $seed = true ) {
		$rig = new EngineHarness( 'wp_', $url );
		if ( $seed ) {
			$rig->seed( $url, $posts );
		}
		$this->rigs[] = $rig;
		return $rig;
	}

	/**
	 * Copy a package between two rigs, as if it were downloaded and uploaded.
	 *
	 * @param EngineHarness $from Source.
	 * @param EngineHarness $to   Destination.
	 * @param string        $id   Package id.
	 * @return void
	 */
	private function transfer( EngineHarness $from, EngineHarness $to, $id ) {
		$to->storage->dir();
		copy( $from->storage->package_path( $id ), $to->storage->package_path( $id ) );
		copy( $from->storage->meta_path( $id ), $to->storage->meta_path( $id ) );
	}

	/**
	 * Run an export and return the package id.
	 *
	 * @param EngineHarness $rig    Rig.
	 * @param array         $params Extra parameters.
	 * @return string
	 */
	private function export( EngineHarness $rig, array $params = array() ) {
		$job = $rig->run( 'export', array_merge( array( 'kind' => 'export', 'label' => 'test' ), $params ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . ' ' . print_r( $rig->logger->messages( 'error' ), true ) );
		$this->assertNotEmpty( $job->state['package'] );
		return $job->state['package'];
	}

	public function test_export_then_import_rewrites_addresses_and_keeps_serialized_data_valid() {
		$source = $this->rig( 'https://old.example.com' );
		$target = $this->rig( 'https://new.example.com', 3 );
		$id     = $this->export( $source );
		$this->transfer( $source, $target, $id );

		$job = $target->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . ' ' . print_r( $target->logger->messages( 'error' ), true ) );

		$db = $target->db;
		$this->assertSame( '25', $db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertSame( 'https://new.example.com', $db->get_var( "SELECT option_value FROM wp_options WHERE option_name='siteurl'" ) );

		$content = $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID = 7' );
		$this->assertStringContainsString( 'https://new.example.com/wp-content/uploads/img7.jpg', $content );
		$this->assertStringNotContainsString( 'old.example.com', $content );

		$meta = $db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 7 AND meta_key = '_data'" );
		$data = unserialize( $meta );
		$this->assertIsArray( $data, 'Serialized value must still unserialize.' );
		$this->assertSame( 'https://new.example.com/page-7', $data['link'] );
		$this->assertSame( 7, $data['count'] );
		$this->assertSame( array( 'u' => 'https://new.example.com/x/7' ), json_decode( $data['nested'], true ) );

		$widget = unserialize( (string) $db->get_var( "SELECT option_value FROM wp_options WHERE option_name='widget_text'" ) );
		$this->assertStringContainsString( 'https://new.example.com/about', $widget[2]['text'] );

		// GUIDs are rewritten by the import's own URL change only because they are the same address;
		// the important thing is that no old table or temporary table is left behind.
		foreach ( $db->list_tables() as $t ) {
			$this->assertDoesNotMatchRegularExpression( '/_estm_/', $t, 'Temporary table left behind: ' . $t );
		}
	}

	public function test_import_takes_a_rollback_snapshot_and_keeps_this_sites_plugin_settings() {
		$source = $this->rig( 'https://old.example.com' );
		$target = $this->rig( 'https://new.example.com', 3 );
		$target->db->query( "UPDATE wp_options SET option_value = 'LOCAL-SETTINGS' WHERE option_name = 'estasoft_migrate_settings'" );

		$id = $this->export( $source );
		$this->transfer( $source, $target, $id );
		$job = $target->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );

		$this->assertSame( 'LOCAL-SETTINGS', $target->db->get_var( "SELECT option_value FROM wp_options WHERE option_name='estasoft_migrate_settings'" ) );

		$snapshots = $target->storage->list_packages( 'snapshot' );
		$this->assertCount( 1, $snapshots, 'A rollback snapshot should exist.' );
	}

	public function test_a_snapshot_restores_the_previous_content() {
		$source = $this->rig( 'https://old.example.com' );
		$target = $this->rig( 'https://new.example.com', 3 );
		$id     = $this->export( $source );
		$this->transfer( $source, $target, $id );
		$target->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( '25', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );

		$snapshots = $target->storage->list_packages( 'snapshot' );
		$job       = $target->run( 'import', array( 'package_id' => $snapshots[0]['id'], 'snapshot' => false, 'kind' => 'restore' ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $target->logger->messages( 'error' ), true ) );
		$this->assertSame( '3', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertSame( 'https://new.example.com', $target->db->get_var( "SELECT option_value FROM wp_options WHERE option_name='siteurl'" ) );
	}

	public function test_export_and_import_survive_being_stopped_after_every_batch() {
		$source = $this->rig( 'https://old.example.com', 60 );
		$target = $this->rig( 'https://new.example.com', 3 );
		// Tiny batches make every table stop part way, so the saved positions are really used.
		$source->ctx->max_batch = 7;
		$target->ctx->max_batch = 7;

		$job = $source->run_in_small_slices( 'export', array( 'kind' => 'export', 'label' => 'slow' ), 1 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$id = $job->state['package'];
		$this->transfer( $source, $target, $id );

		$job = $target->run_in_small_slices( 'import', array( 'package_id' => $id ), 1 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $target->logger->messages( 'error' ), true ) );
		$this->assertSame( '60', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
		$this->assertSame(
			$source->db->get_var( 'SELECT COUNT(*) FROM wp_postmeta' ),
			$target->db->get_var( 'SELECT COUNT(*) FROM wp_postmeta' )
		);
		// Every row arrived exactly once and in order.
		$this->assertSame(
			$source->db->get_var( "SELECT group_concat(ID, ',') FROM (SELECT ID FROM wp_posts ORDER BY ID)" ),
			$target->db->get_var( "SELECT group_concat(ID, ',') FROM (SELECT ID FROM wp_posts ORDER BY ID)" )
		);
		$this->assertGreaterThan( 10, $source->slices, 'The export really ran in many slices.' );
		$this->assertGreaterThan( 10, $target->slices, 'The import really ran in many slices.' );
	}

	public function test_a_damaged_package_is_rejected_before_anything_changes() {
		$source = $this->rig( 'https://old.example.com' );
		$target = $this->rig( 'https://new.example.com', 3 );
		$id     = $this->export( $source );
		$this->transfer( $source, $target, $id );

		$path = $target->storage->package_path( $id );
		$raw  = file_get_contents( $path );
		file_put_contents( $path, substr_replace( $raw, 'XXXX', (int) ( strlen( $raw ) / 2 ), 4 ) );

		$job = $target->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( '3', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ), 'The site must be untouched.' );
		$this->assertSame( 'https://new.example.com', $target->db->get_var( "SELECT option_value FROM wp_options WHERE option_name='siteurl'" ) );
	}

	public function test_encrypted_package_needs_the_right_password() {
		$source = $this->rig( 'https://old.example.com' );
		$target = $this->rig( 'https://new.example.com', 3 );
		$id     = $this->export( $source, array( 'passphrase' => 'correct horse battery staple' ) );
		$this->transfer( $source, $target, $id );

		$bad = $target->run( 'import', array( 'package_id' => $id, 'passphrase' => 'wrong' ) );
		$this->assertSame( Job::FAILED, $bad->status );
		$this->assertSame( '3', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );

		$good = $target->run( 'import', array( 'package_id' => $id, 'passphrase' => 'correct horse battery staple' ) );
		$this->assertSame( Job::COMPLETED, $good->status, (string) $good->message );
		$this->assertSame( '25', $target->db->get_var( 'SELECT COUNT(*) FROM wp_posts' ) );
	}
}
