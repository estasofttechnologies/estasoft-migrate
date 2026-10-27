<?php
/**
 * Clean-up of old packages and snapshots.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Storage;
use PHPUnit\Framework\TestCase;

/**
 * Exports age out by days. Snapshots are limited by count and by age, and the newest always stays.
 */
class StorageCleanupTest extends TestCase {

	/**
	 * Folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private $storage;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/estm-clean-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0777, true );
		$this->storage = new Storage( null, $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/{,.}[!.]*', GLOB_BRACE ) as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		rmdir( $this->dir );
	}

	/**
	 * Make a package that is a number of days old.
	 *
	 * @param string $id   Id.
	 * @param string $type export or snapshot.
	 * @param int    $days Age.
	 * @return void
	 */
	private function package( $id, $type, $days ) {
		file_put_contents( $this->storage->package_path( $id ), 'x' );
		$this->storage->write_meta(
			$id,
			array(
				'type'        => $type,
				'status'      => 'complete',
				'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ),
			)
		);
	}

	/**
	 * Ids left, sorted.
	 *
	 * @param string $type Type.
	 * @return string[]
	 */
	private function left( $type ) {
		$ids = array_column( $this->storage->list_packages( $type ), 'id' );
		sort( $ids );
		return $ids;
	}

	public function test_old_exports_are_deleted_and_young_ones_stay() {
		$this->package( 'old-export', 'export', 40 );
		$this->package( 'new-export', 'export', 3 );
		$this->assertSame( 1, $this->storage->clean_up( 30, 5 ) );
		$this->assertSame( array( 'new-export' ), $this->left( 'export' ) );

		$this->assertSame( 0, $this->storage->clean_up( 0, 5 ), 'Zero days means keep forever.' );
	}

	public function test_snapshots_beyond_the_keep_count_go_whatever_their_age() {
		$this->package( 'snap-001', 'snapshot', 1 );
		$this->package( 'snap-002', 'snapshot', 2 );
		$this->package( 'snap-003', 'snapshot', 3 );
		$this->assertSame( 1, $this->storage->clean_up( 0, 2 ) );
		$this->assertSame( array( 'snap-001', 'snap-002' ), $this->left( 'snapshot' ) );
	}

	public function test_old_snapshots_go_by_age_but_the_newest_always_stays() {
		$this->package( 'snap-fresh', 'snapshot', 2 );
		$this->package( 'snap-stale', 'snapshot', 45 );
		$this->assertSame( 1, $this->storage->clean_up( 0, 5, 30 ) );
		$this->assertSame( array( 'snap-fresh' ), $this->left( 'snapshot' ) );

		$this->storage->delete( 'snap-fresh' );
		$this->package( 'snap-only', 'snapshot', 400 );
		$this->assertSame( 0, $this->storage->clean_up( 0, 5, 30 ), 'The only snapshot is the last way back, so it stays.' );
		$this->assertSame( array( 'snap-only' ), $this->left( 'snapshot' ) );
	}

	public function test_zero_snapshot_days_keeps_snapshots_by_count_only() {
		$this->package( 'snap-aaa', 'snapshot', 500 );
		$this->package( 'snap-bbb', 'snapshot', 600 );
		$this->assertSame( 0, $this->storage->clean_up( 0, 2, 0 ) );
		$this->assertCount( 2, $this->left( 'snapshot' ) );
	}
}
