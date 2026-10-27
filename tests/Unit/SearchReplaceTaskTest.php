<?php
/**
 * Search and replace job.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Dry run, live run, safety rules.
 */
class SearchReplaceTaskTest extends TestCase {

	/**
	 * Rig.
	 *
	 * @var EngineHarness
	 */
	private $rig;

	protected function setUp(): void {
		$this->rig = new EngineHarness( 'wp_', 'https://old.example.com' );
		$this->rig->seed( 'https://old.example.com', 30 );
	}

	protected function tearDown(): void {
		$this->rig->cleanup();
	}

	/**
	 * A snapshot of the whole database for before/after comparison.
	 *
	 * @return string
	 */
	private function fingerprint() {
		$out = '';
		foreach ( array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'comments' ) as $t ) {
			$out .= md5( json_encode( $this->rig->db->get_results( "SELECT * FROM wp_{$t} ORDER BY 1" ) ) );
		}
		return $out;
	}

	public function test_a_dry_run_counts_matches_and_writes_nothing() {
		$before = $this->fingerprint();
		$job    = $this->rig->run( 'search_replace', array( 'pairs' => array( array( 'https://old.example.com', 'https://new.example.com' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$this->assertSame( $before, $this->fingerprint(), 'A dry run must not change anything.' );

		$totals = $job->state['totals'];
		$this->assertGreaterThan( 60, $totals['matches'] );
		$this->assertGreaterThan( 0, $totals['nested'], 'Matches inside serialized and JSON data are counted separately.' );
		$this->assertNotEmpty( $job->state['samples'] );
		$this->assertStringContainsString( 'old.example.com', $job->state['samples'][0]['before'] );
		$this->assertStringContainsString( 'new.example.com', $job->state['samples'][0]['after'] );
	}

	public function test_a_live_run_replaces_everywhere_but_guids() {
		$job = $this->rig->run(
			'search_replace',
			array(
				'dry_run' => false,
				'pairs'   => array( array( 'https://old.example.com', 'https://new.example.com' ) ),
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->rig->logger->messages( 'error' ), true ) );

		$db = $this->rig->db;
		$this->assertSame( '0', $db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%old.example.com%'" ) );
		$this->assertSame( '30', $db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE guid LIKE 'https://old.example.com%'" ), 'GUIDs are identifiers and must not change.' );

		$data = unserialize( $db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 4 AND meta_key = '_data'" ) );
		$this->assertSame( 'https://new.example.com/page-4', $data['link'] );
		$this->assertSame( array( 'u' => 'https://new.example.com/x/4' ), json_decode( $data['nested'], true ) );
	}

	public function test_guids_can_be_included_on_request() {
		$this->rig->run( 'search_replace', array( 'dry_run' => false, 'skip_guid' => false, 'pairs' => array( array( 'old.example.com', 'new.example.com' ) ) ) );
		$this->assertSame( '0', $this->rig->db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE guid LIKE '%old.example.com%'" ) );
	}

	public function test_a_snapshot_is_taken_before_a_live_run_when_asked() {
		$before = $this->fingerprint();
		$job    = $this->rig->run( 'search_replace', array( 'dry_run' => false, 'snapshot' => true, 'pairs' => array( array( 'Title', 'Heading' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$this->assertNotEmpty( $job->state['snapshot_id'] );
		$this->assertNotSame( $before, $this->fingerprint() );

		$restore = $this->rig->run( 'import', array( 'package_id' => $job->state['snapshot_id'], 'snapshot' => false, 'kind' => 'restore' ) );
		$this->assertSame( Job::COMPLETED, $restore->status, (string) $restore->message . print_r( $this->rig->logger->messages( 'error' ), true ) );
		$this->assertSame( '30', $this->rig->db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE 'Title %'" ) );
	}

	public function test_a_live_run_stopped_after_every_batch_never_applies_a_change_twice() {
		// A replacement that grows the text each time it is applied makes a double application obvious.
		// Tiny batches make the job stop in the middle of every table.
		$this->rig->ctx->max_batch = 4;
		$job = $this->rig->run_in_small_slices( 'search_replace', array( 'dry_run' => false, 'pairs' => array( array( 'Title', 'Title Title' ) ) ), 1 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$this->assertSame( 'Title Title 3', $this->rig->db->get_var( 'SELECT post_title FROM wp_posts WHERE ID = 3' ) );
	}

	public function test_regex_and_case_insensitive_modes() {
		$job = $this->rig->run( 'search_replace', array( 'dry_run' => false, 'regex' => true, 'pairs' => array( array( 'Title (\d+)', 'Heading $1' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message );
		$this->assertSame( 'Heading 12', $this->rig->db->get_var( 'SELECT post_title FROM wp_posts WHERE ID = 12' ) );

		$job = $this->rig->run( 'search_replace', array( 'dry_run' => false, 'case_sensitive' => false, 'pairs' => array( array( 'HEADING', 'Topic' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status );
		$this->assertSame( 'Topic 12', $this->rig->db->get_var( 'SELECT post_title FROM wp_posts WHERE ID = 12' ) );
	}

	public function test_an_invalid_pattern_fails_with_a_clear_message_and_changes_nothing() {
		$before = $this->fingerprint();
		$job    = $this->rig->run( 'search_replace', array( 'dry_run' => false, 'regex' => true, 'pairs' => array( array( '(unclosed', 'x' ) ) ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'regular expression', $job->message );
		$this->assertSame( $before, $this->fingerprint() );
	}

	public function test_empty_search_is_refused() {
		$job = $this->rig->run( 'search_replace', array( 'pairs' => array( array( '', 'x' ) ) ) );
		$this->assertSame( Job::FAILED, $job->status );
	}

	public function test_selected_tables_only() {
		$job = $this->rig->run( 'search_replace', array( 'dry_run' => false, 'tables' => array( 'wp_options' ), 'pairs' => array( array( 'https://old.example.com', 'https://new.example.com' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status );
		$this->assertSame( 'https://new.example.com', $this->rig->db->get_var( "SELECT option_value FROM wp_options WHERE option_name='home'" ) );
		$this->assertSame( '30', $this->rig->db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%old.example.com%'" ) );
	}
}
