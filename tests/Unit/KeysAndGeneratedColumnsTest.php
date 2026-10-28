<?php
/**
 * Tables that have a unique key but no primary key, and tables with generated columns.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

class KeysAndGeneratedColumnsTest extends TestCase {

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
	}

	private function rig( $url ) {
		$rig = new EngineHarness( 'wp_', $url );
		$rig->seed( $url, 3 );
		$this->rigs[] = $rig;
		return $rig;
	}

	private function transfer( EngineHarness $from, EngineHarness $to, $id ) {
		$to->storage->dir();
		copy( $from->storage->package_path( $id ), $to->storage->package_path( $id ) );
		copy( $from->storage->meta_path( $id ), $to->storage->meta_path( $id ) );
	}

	/** Frames of one type from a package. */
	private function frames( EngineHarness $rig, $id, $type ) {
		$reader = PackageReader::open( $rig->storage->package_path( $id ), array() );
		$out    = array();
		while ( $frame = $reader->next() ) {
			if ( $type === $frame['type'] ) {
				$out[] = $frame['data'];
			}
		}
		return $out;
	}

	public function test_the_shortest_unique_key_of_columns_that_are_never_null_stands_in_for_a_primary_key() {
		$rig = $this->rig( 'https://old.example.com' );
		$rig->db->query( 'CREATE TABLE `wp_codes` (`code` TEXT NOT NULL UNIQUE, `label` TEXT)' );
		$rig->db->query( 'CREATE TABLE `wp_pair` (`a` INTEGER NOT NULL, `b` INTEGER NOT NULL, `c` TEXT, UNIQUE (`a`, `b`), UNIQUE (`a`))' );
		$rig->db->query( 'CREATE TABLE `wp_maybe` (`code` TEXT UNIQUE, `x` TEXT)' );
		$rig->db->query( 'CREATE TABLE `wp_both` (`id` INTEGER PRIMARY KEY, `code` TEXT NOT NULL UNIQUE)' );
		$rig->db->query( 'CREATE TABLE `wp_none` (`a` TEXT, `b` TEXT)' );

		$this->assertSame( array( 'code' ), $rig->db->primary_key( 'wp_codes' ) );
		$this->assertSame( array( 'a' ), $rig->db->primary_key( 'wp_pair' ), 'The shorter unique key wins.' );
		$this->assertSame( array(), $rig->db->primary_key( 'wp_maybe' ), 'A column that can be NULL cannot tell rows apart.' );
		$this->assertSame( array( 'id' ), $rig->db->primary_key( 'wp_both' ), 'A primary key is always preferred.' );
		$this->assertSame( array(), $rig->db->primary_key( 'wp_none' ) );
	}

	public function test_a_table_with_only_a_unique_key_is_read_by_key_and_can_be_searched_and_replaced() {
		$source = $this->rig( 'https://old.example.com' );
		$source->db->query( 'CREATE TABLE `wp_codes` (`code` TEXT NOT NULL UNIQUE, `label` TEXT)' );
		for ( $i = 0; $i < 25; $i++ ) {
			$source->db->query( sprintf( "INSERT INTO wp_codes VALUES ('c%02d','see https://old.example.com/%d')", $i, $i ) );
		}
		$source->ctx->max_batch = 10;
		$job                    = $source->run_in_small_slices( 'export', array( 'kind' => 'export', 'label' => 'x', 'tables' => array( 'wp_codes' ) ), 1 );
		$this->assertSame( Job::COMPLETED, $job->status, $job->message );

		$table = $this->frames( $source, $job->state['package'], 'T' )[0];
		$this->assertSame( array( 'code' ), $table['pk'], 'The package records the key it was read by.' );
		$rows = 0;
		foreach ( $this->frames( $source, $job->state['package'], 'R' ) as $block ) {
			$rows += count( $block['r'] );
		}
		$this->assertSame( 25, $rows, 'Every row once.' );
		foreach ( $source->logger->messages( 'warning' ) as $line ) {
			$this->assertStringNotContainsString( 'slower method', (string) ( is_array( $line ) ? wp_json_encode( $line ) : $line ) );
		}

		$target = $this->rig( 'https://new.example.com' );
		$this->transfer( $source, $target, $job->state['package'] );
		$done = $target->run( 'import', array( 'package_id' => $job->state['package'], 'snapshot' => false ), 60 );
		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$this->assertSame( '25', $target->db->get_var( 'SELECT COUNT(*) FROM wp_codes' ) );
		$this->assertSame( 'see https://new.example.com/7', $target->db->get_var( "SELECT label FROM wp_codes WHERE code = 'c07'" ), 'The address was rewritten.' );

		$replace = $target->run( 'search_replace', array( 'dry_run' => false, 'snapshot' => false, 'tables' => array( 'wp_codes' ), 'pairs' => array( array( 'see ', 'look at ' ) ) ), 60 );
		$this->assertSame( Job::COMPLETED, $replace->status, $replace->message );
		$this->assertSame( 'look at https://new.example.com/7', $target->db->get_var( "SELECT label FROM wp_codes WHERE code = 'c07'" ), 'Search and replace no longer skips a table that has a unique key.' );
	}

	public function test_generated_columns_are_left_out_of_the_package_and_recomputed() {
		$source = $this->rig( 'https://old.example.com' );
		$source->db->query( 'CREATE TABLE `wp_gen` (`id` INTEGER PRIMARY KEY, `title` TEXT, `shout` TEXT GENERATED ALWAYS AS (upper(`title`)) VIRTUAL, `n` INTEGER, `twice` INTEGER GENERATED ALWAYS AS (`n` * 2) STORED)' );
		for ( $i = 1; $i <= 12; $i++ ) {
			$source->db->query( "INSERT INTO wp_gen (id, title, n) VALUES ($i, 'hello $i', $i)" );
		}
		$this->assertSame( array( 'id', 'title', 'n' ), $source->db->writable_columns( 'wp_gen' ) );
		$this->assertTrue( $source->db->columns( 'wp_gen' )['shout']['generated'] );
		$this->assertTrue( $source->db->columns( 'wp_gen' )['twice']['generated'] );
		$this->assertFalse( $source->db->columns( 'wp_gen' )['title']['generated'] );

		$source->ctx->max_batch = 5;
		$job                    = $source->run( 'export', array( 'kind' => 'export', 'label' => 'x', 'tables' => array( 'wp_gen' ) ), 60 );
		$this->assertSame( Job::COMPLETED, $job->status, $job->message );
		$id = $job->state['package'];
		$this->assertSame( array( 'id', 'title', 'n' ), $this->frames( $source, $id, 'T' )[0]['columns'] );
		$this->assertSame( array( 'id', 'title', 'n' ), $this->frames( $source, $id, 'R' )[0]['c'] );

		$target = $this->rig( 'https://new.example.com' );
		$this->transfer( $source, $target, $id );
		$done = $target->run( 'import', array( 'package_id' => $id, 'snapshot' => false ), 60 );
		$this->assertSame( Job::COMPLETED, $done->status, $done->message );
		$this->assertSame( '12', $target->db->get_var( 'SELECT COUNT(*) FROM wp_gen' ) );
		$this->assertSame( 'HELLO 7', $target->db->get_var( 'SELECT shout FROM wp_gen WHERE id = 7' ), 'The table computed its own column.' );
		$this->assertSame( '14', (string) $target->db->get_var( 'SELECT twice FROM wp_gen WHERE id = 7' ) );

		$replace = $target->run( 'search_replace', array( 'dry_run' => false, 'snapshot' => false, 'tables' => array( 'wp_gen' ), 'pairs' => array( array( 'hello', 'bye' ) ) ), 60 );
		$this->assertSame( Job::COMPLETED, $replace->status, $replace->message );
		$this->assertSame( 'bye 7', $target->db->get_var( 'SELECT title FROM wp_gen WHERE id = 7' ) );
		$this->assertSame( 'BYE 7', $target->db->get_var( 'SELECT shout FROM wp_gen WHERE id = 7' ), 'The generated column follows, and was never written to.' );
	}
}
