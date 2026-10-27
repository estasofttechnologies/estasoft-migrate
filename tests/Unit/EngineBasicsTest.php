<?php
/**
 * Small engine parts: batch sizing, time budgets, delta-sync baselines and the collation fallback.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Baseline;
use Estasoft\Migrate\Engine\BatchSizer;
use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\DdlTranslator;
use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use Estasoft\Migrate\Tests\Support\PdoDatabase;
use PHPUnit\Framework\TestCase;

/**
 * These are the pieces that keep big jobs from timing out or running out of memory, and that make
 * incremental sync possible later, so each one is pinned down on its own.
 */
class EngineBasicsTest extends TestCase {

	/* ---------------------------------------------------------------- BatchSizer */

	public function test_the_batch_grows_when_batches_are_quick_but_never_more_than_double() {
		$sizer = new BatchSizer( 20000, 200 );
		$sizer->record( 200, 0.01, 20000 );
		$this->assertGreaterThan( 200, $sizer->size() );
		$this->assertLessThanOrEqual( 400, $sizer->size(), 'At most double per batch.' );
		for ( $i = 0; $i < 30; $i++ ) {
			$sizer->record( $sizer->size(), 0.01, $sizer->size() * 100 );
		}
		$this->assertSame( 20000, $sizer->size(), 'It stops at the largest allowed batch.' );
	}

	public function test_slow_batches_shrink_the_next_one() {
		$sizer = new BatchSizer( 20000, 4000 );
		$sizer->record( 4000, 8.0, 400000 );
		$this->assertLessThan( 4000, $sizer->size() );
		$this->assertGreaterThanOrEqual( 1, $sizer->size() );
	}

	public function test_big_rows_keep_a_batch_inside_the_size_target() {
		$sizer = new BatchSizer( 20000, 1000, 1.0, 1048576 );
		$sizer->record( 1000, 0.05, 1000 * 262144 ); // 256 KB per row.
		$this->assertLessThanOrEqual( 4, $sizer->size(), 'About 1 MB of data per batch at most.' );
	}

	public function test_shrinking_halves_and_state_survives_a_restart() {
		$sizer = new BatchSizer( 20000, 1000 );
		$sizer->shrink();
		$this->assertSame( 500, $sizer->size() );
		$again = BatchSizer::from_state( $sizer->export_state(), 20000 );
		$this->assertSame( 500, $again->size() );
		$again->limit( 100 );
		$this->assertSame( 100, $again->size(), 'A lower limit in Settings applies at once.' );
		for ( $i = 0; $i < 20; $i++ ) {
			$again->shrink();
		}
		$this->assertSame( 1, $again->size(), 'Never below one row.' );
	}

	public function test_empty_batches_teach_nothing() {
		$sizer = new BatchSizer( 20000, 300 );
		$sizer->record( 0, 1.0, 0 );
		$this->assertSame( 300, $sizer->size() );
	}

	/* ---------------------------------------------------------------- Budget */

	public function test_a_unit_budget_allows_exactly_that_many_batches() {
		$budget = Budget::units( 3 );
		$this->assertFalse( $budget->exhausted() );
		$this->assertFalse( $budget->exhausted() );
		$this->assertFalse( $budget->exhausted() );
		$this->assertTrue( $budget->exhausted() );
		$this->assertTrue( $budget->exhausted(), 'And it stays exhausted.' );
	}

	public function test_checking_expiry_between_phases_does_not_use_up_a_unit() {
		$budget = Budget::units( 1 );
		$this->assertFalse( $budget->expired() );
		$this->assertFalse( $budget->expired() );
		$this->assertFalse( $budget->exhausted() );
		$this->assertTrue( $budget->expired() );
	}

	public function test_a_time_budget_runs_out() {
		$budget = new Budget( 0.0 );
		$this->assertTrue( $budget->exhausted() );
		$this->assertTrue( $budget->expired() );
		$this->assertSame( 0.0, $budget->time_left() );
		$long = new Budget( 600 );
		$this->assertFalse( $long->exhausted() );
		$this->assertGreaterThan( 500, $long->time_left() );
	}

	public function test_memory_pressure_stops_work_early() {
		$tight = new Budget( 600, 1 );
		$this->assertTrue( $tight->memory_pressure() );
		$this->assertTrue( $tight->exhausted(), 'Out of memory headroom means stop, even with time left.' );
		$this->assertFalse( ( new Budget( 600, 0 ) )->memory_pressure(), 'Zero means no limit.' );
	}

	public function test_a_request_budget_never_exceeds_its_cap() {
		$this->assertLessThanOrEqual( 5.0, Budget::for_request( 5 )->time_left() );
		$this->assertGreaterThan( 86400.0, Budget::unlimited()->time_left() );
	}

	/* ---------------------------------------------------------------- Baseline */

	/**
	 * Baseline numbers for a set of rows.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function baseline( array $rows ) {
		$st = Baseline::start( array( 'id' ) );
		foreach ( $rows as $row ) {
			Baseline::add( $st, $row );
		}
		return Baseline::result( $st );
	}

	/**
	 * Rows with ids from 1 to n.
	 *
	 * @param int $n Count.
	 * @return array
	 */
	private function rows( $n ) {
		$rows = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$rows[] = array( 'id' => (string) $i, 'title' => 'Post ' . $i, 'note' => null );
		}
		return $rows;
	}

	public function test_a_baseline_counts_rows_and_notes_the_highest_key() {
		$r = $this->baseline( $this->rows( 2500 ) );
		$this->assertSame( 2500, $r['rows'] );
		$this->assertSame( '2500', $r['max'] );
		$this->assertSame( 1000, $r['buckets']['size'] );
		$this->assertSame( 3, count( $r['buckets']['blocks'] ), 'Ids 1–999, 1000–1999, 2000–2500.' );
		$this->assertSame( 999, $r['buckets']['blocks'][0][0] );
	}

	public function test_the_checksum_does_not_depend_on_row_order_but_does_on_content() {
		$rows = $this->rows( 50 );
		$a    = $this->baseline( $rows );
		$b    = $this->baseline( array_reverse( $rows ) );
		$this->assertSame( $a['checksum'], $b['checksum'] );

		$changed         = $rows;
		$changed[20]['title'] = 'Edited';
		$c = $this->baseline( $changed );
		$this->assertNotSame( $a['checksum'], $c['checksum'] );
		$this->assertSame( $a['rows'], $c['rows'] );

		$nulls            = $rows;
		$nulls[3]['note'] = '';
		$this->assertNotSame( $a['checksum'], $this->baseline( $nulls )['checksum'], 'NULL and an empty string are different.' );
	}

	public function test_a_change_shows_up_only_in_its_own_block() {
		$rows    = $this->rows( 3000 );
		$before  = $this->baseline( $rows );
		$rows[1500]['title'] = 'Edited in the middle';
		$after   = $this->baseline( $rows );
		$changed = array();
		foreach ( $after['buckets']['blocks'] as $k => $block ) {
			if ( $block !== $before['buckets']['blocks'][ $k ] ) {
				$changed[] = $k;
			}
		}
		$this->assertSame( array( 1 ), $changed, 'Row 1501 lives in block 1, so only that block has to be sent again.' );
	}

	public function test_blocks_are_merged_on_huge_tables_so_the_list_stays_small() {
		$st = Baseline::start( array( 'id' ) );
		for ( $i = 0; $i < 2100; $i++ ) {
			Baseline::add( $st, array( 'id' => (string) ( $i * 1000 ), 'v' => 'x' ) );
		}
		$r = Baseline::result( $st );
		$this->assertLessThanOrEqual( 2000, count( $r['buckets']['blocks'] ) );
		$this->assertGreaterThan( 1000, $r['buckets']['size'], 'Blocks got wider.' );
		$this->assertSame( 2100, array_sum( array_column( $r['buckets']['blocks'], 0 ) ), 'No row is lost by merging.' );
	}

	public function test_tables_without_a_single_numeric_key_still_get_a_baseline() {
		$st = Baseline::start( array( 'a', 'b' ) );
		Baseline::add( $st, array( 'a' => '1', 'b' => 'x', 'v' => '1' ) );
		Baseline::add( $st, array( 'a' => '1', 'b' => 'y', 'v' => '2' ) );
		$r = Baseline::result( $st );
		$this->assertSame( 2, $r['rows'] );
		$this->assertNull( $r['max'] );
		$this->assertSame( array( 0 ), array_keys( $r['buckets']['blocks'] ) );

		$text = Baseline::start( array( 'k' ) );
		Baseline::add( $text, array( 'k' => 'apple', 'v' => '1' ) );
		Baseline::add( $text, array( 'k' => 'pear', 'v' => '1' ) );
		$this->assertSame( 'pear', Baseline::result( $text )['max'], 'Text keys: the highest in sort order.' );
	}

	public function test_baselines_are_saved_promoted_and_discarded() {
		$rig   = new EngineHarness( 'wp_', 'https://old.example.com' );
		$db    = $rig->db;
		$table = 'wp_estasoft_baselines';
		$r     = $this->baseline( $this->rows( 10 ) );

		Baseline::save( $db, $table, 'push:pending', 'wp_posts', $r, 7 );
		Baseline::save( $db, $table, 'push:pending', 'wp_posts', $r, 8 );
		$this->assertSame( '1', $db->get_var( "SELECT COUNT(*) FROM {$table}" ), 'Saving again replaces, it does not pile up.' );

		Baseline::save( $db, $table, 'site-b', 'wp_posts', $this->baseline( $this->rows( 4 ) ), 1 );
		Baseline::promote( $db, $table, 'push:pending', 'site-b' );
		$this->assertSame( '0', $db->get_var( "SELECT COUNT(*) FROM {$table} WHERE peer = 'push:pending'" ) );
		$this->assertSame( '10', $db->get_var( "SELECT row_count FROM {$table} WHERE peer = 'site-b'" ), 'The new baseline replaced the old one.' );

		Baseline::save( $db, $table, 'push:later', 'wp_posts', $r, 9 );
		Baseline::discard( $db, $table, 'push:later' );
		$this->assertSame( '0', $db->get_var( "SELECT COUNT(*) FROM {$table} WHERE peer = 'push:later'" ) );
		$this->assertSame( '1', $db->get_var( "SELECT COUNT(*) FROM {$table}" ), 'A failed job leaves the old baseline alone.' );
		$rig->cleanup();
	}

	/* ---------------------------------------------------------------- DdlTranslator */

	/**
	 * A destination that knows only some collations.
	 *
	 * @param string   $type  Server type: mysql or mariadb.
	 * @param string[] $known Collations it has.
	 * @return PdoDatabase
	 */
	private function target( $type, array $known ) {
		return new class( $type, $known ) extends PdoDatabase {
			/**
			 * Type.
			 *
			 * @var string
			 */
			private $type;

			/**
			 * Collations.
			 *
			 * @var string[]
			 */
			private $known;

			public function __construct( $type, array $known ) {
				parent::__construct();
				$this->type  = $type;
				$this->known = $known;
			}

			public function server_info() {
				return array( 'type' => $this->type, 'version' => '10.11', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci' );
			}

			public function has_collation( $collation ) {
				return in_array( $collation, $this->known, true );
			}
		};
	}

	const DDL = "CREATE TABLE `wp_posts` (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text COLLATE utf8mb4_0900_ai_ci NOT NULL,\n  `post_name` varchar(200) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',\n  PRIMARY KEY (`ID`),\n  CONSTRAINT `fk_user` FOREIGN KEY (`post_author`) REFERENCES `wp_users` (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

	public function test_a_collation_the_destination_lacks_is_replaced_by_the_closest_one_it_has() {
		$mariadb      = $this->target( 'mariadb', array( 'utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_bin' ) );
		list( $ddl, $notes ) = DdlTranslator::translate( self::DDL, '_estm_7_0', $mariadb );

		$this->assertStringContainsString( 'CREATE TABLE `_estm_7_0`', $ddl, 'The staging name is used.' );
		$this->assertStringNotContainsString( 'utf8mb4_0900_ai_ci', $ddl );
		$this->assertSame( 3, substr_count( $ddl, 'utf8mb4_unicode_ci' ), 'Both columns and the table default.' );
		$this->assertStringContainsString( 'Collation utf8mb4_0900_ai_ci became utf8mb4_unicode_ci.', implode( ' ', $notes ) );
		$this->assertStringNotContainsString( 'FOREIGN KEY', $ddl );
		$this->assertStringContainsString( 'PRIMARY KEY (`ID`)', $ddl );
		$this->assertStringNotContainsString( ",\n)", $ddl, 'No dangling comma after the key was removed.' );
	}

	public function test_a_modern_destination_keeps_the_collation_and_says_nothing() {
		$modern = $this->target( 'mysql', array( 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci' ) );
		list( $ddl, $notes ) = DdlTranslator::translate( self::DDL, '_estm_7_0', $modern );
		$this->assertSame( 3, substr_count( $ddl, 'utf8mb4_0900_ai_ci' ) );
		$this->assertNotContains( 'Collation utf8mb4_0900_ai_ci became utf8mb4_unicode_ci.', $notes );
	}

	public function test_a_forced_collation_applies_everywhere_and_the_charset_follows() {
		$any = $this->target( 'mysql', array( 'utf8mb4_0900_ai_ci' ) );
		list( $ddl ) = DdlTranslator::translate( self::DDL, '_estm_7_0', $any, 'utf8mb4_general_ci' );
		$this->assertSame( 3, substr_count( $ddl, 'utf8mb4_general_ci' ) );
		$this->assertStringContainsString( 'CHARSET=utf8mb4', $ddl );
	}

	public function test_an_unknown_collation_with_no_fallback_is_left_alone() {
		$old = $this->target( 'mysql', array( 'latin1_swedish_ci' ) );
		$this->assertSame( 'utf8mb4_xyz_ci', DdlTranslator::supported( 'utf8mb4_xyz_ci', $old ), 'Better a clear error from the server than a silent guess.' );
		$this->assertSame( 'latin1_swedish_ci', DdlTranslator::supported( 'latin1_swedish_ci', $old ) );
	}

	public function test_mariadb_only_options_are_removed_for_mysql() {
		$ddl = "CREATE TABLE `wp_x` (\n  `j` longtext CHECK (json_valid(`j`)),\n  `id` int NOT NULL\n) ENGINE=InnoDB PAGE_CHECKSUM=1 TRANSACTIONAL=1";
		list( $out ) = DdlTranslator::translate( $ddl, '_estm_1_0', $this->target( 'mysql', array() ) );
		$this->assertStringNotContainsString( 'json_valid', $out );
		$this->assertStringNotContainsString( 'PAGE_CHECKSUM', $out );
		$this->assertStringNotContainsString( 'TRANSACTIONAL', $out );
		list( $kept ) = DdlTranslator::translate( $ddl, '_estm_1_0', $this->target( 'mariadb', array() ) );
		$this->assertStringContainsString( 'json_valid', $kept, 'MariaDB understands it.' );
	}

	/* ---------------------------------------------------------------- Pause and cancel */

	public function test_a_checkpoint_does_not_undo_a_pause_or_cancel_that_arrived_during_the_slice() {
		$rig = new EngineHarness();
		try {
			foreach ( array( Job::PAUSED, Job::CANCELLED ) as $requested ) {
				$job         = $rig->store->create( 'export', 'Export', array() );
				$job->status = Job::RUNNING;
				$rig->store->save( $job );

				// Someone presses the button while the slice is working.
				$rig->store->set_status( $job->id, $requested );
				$job->progress = 40;
				$rig->store->save( $job ); // The checkpoint at the end of the batch.

				$this->assertSame( $requested, $rig->store->status_of( $job->id ), 'The request stands.' );
				$this->assertSame( 40.0, (float) $rig->store->find( $job->id )->progress, 'Progress is still saved.' );
			}

			// The runner itself can still move a job on.
			$job         = $rig->store->create( 'export', 'Export', array() );
			$job->status = Job::COMPLETED;
			$rig->store->save( $job );
			$this->assertSame( Job::COMPLETED, $rig->store->status_of( $job->id ) );
		} finally {
			$rig->cleanup();
		}
	}
}
