<?php
/**
 * Range requests for package downloads.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Admin\Downloads;
use PHPUnit\Framework\TestCase;

/**
 * A browser or download manager that resumes a big package sends a Range header.
 */
class DownloadRangeTest extends TestCase {

	public function test_no_header_or_an_unusual_one_means_send_everything() {
		$this->assertNull( Downloads::parse_range( '', 1000 ) );
		$this->assertNull( Downloads::parse_range( 'bytes=0-10,20-30', 1000 ) );
		$this->assertNull( Downloads::parse_range( 'items=1-2', 1000 ) );
		$this->assertNull( Downloads::parse_range( 'bytes=-', 1000 ) );
	}

	public function test_a_start_and_an_optional_end() {
		$this->assertSame( array( 100, 999 ), Downloads::parse_range( 'bytes=100-', 1000 ) );
		$this->assertSame( array( 100, 199 ), Downloads::parse_range( 'bytes=100-199', 1000 ) );
		$this->assertSame( array( 900, 999 ), Downloads::parse_range( 'bytes=900-5000', 1000 ), 'The end is cut to the file size.' );
		$this->assertSame( array( 0, 0 ), Downloads::parse_range( 'bytes=0-0', 1000 ) );
	}

	public function test_a_suffix_means_the_last_bytes() {
		$this->assertSame( array( 950, 999 ), Downloads::parse_range( 'bytes=-50', 1000 ) );
		$this->assertSame( array( 0, 999 ), Downloads::parse_range( 'bytes=-5000', 1000 ) );
	}

	public function test_ranges_outside_the_file_cannot_be_satisfied() {
		$this->assertFalse( Downloads::parse_range( 'bytes=1000-', 1000 ) );
		$this->assertFalse( Downloads::parse_range( 'bytes=500-100', 1000 ) );
		$this->assertFalse( Downloads::parse_range( 'bytes=-0', 1000 ) );
	}
}
