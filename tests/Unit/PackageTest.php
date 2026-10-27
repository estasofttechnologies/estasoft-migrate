<?php
namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Package\PackageException;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Package\PackageWriter;
use Estasoft\Migrate\Package\RowCodec;
use PHPUnit\Framework\TestCase;

class PackageTest extends TestCase {

	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/estm-pkg-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $f ) {
			unlink( $f );
		}
		rmdir( $this->dir );
	}

	private function write_sample( array $options = array() ): string {
		$path = $this->dir . '/a.estm';
		$w    = PackageWriter::create( $path, $options );
		$w->frame( 'M', array( 'format' => 1, 'site' => 'http://example.com' ) );
		$w->frame( 'T', array( 'name' => 'wp_posts', 'ddl' => 'CREATE TABLE x (id int)' ) );
		$w->raw_frame( 'R', RowCodec::encode( 'wp_posts', array( 'id', 'body', 'bin' ), array(
			array( 'id' => '1', 'body' => 'héllo "quoted" / slash', 'bin' => "\xff\xfe\x00bytes" ),
			array( 'id' => '2', 'body' => null, 'bin' => '' ),
		) ) );
		$w->frame( 'U', array( 'name' => 'wp_posts', 'rows' => 2 ) );
		$w->frame( 'E', array( 'tables' => 1 ) );
		$w->close();
		return $path;
	}

	public function test_round_trip_plain_and_compressed(): void {
		foreach ( array( true, false ) as $compress ) {
			$path = $this->write_sample( array( 'compress' => $compress ) );
			$r    = PackageReader::open( $path );
			$types = array();
			$rows  = null;
			while ( $f = $r->next() ) {
				$types[] = $f['type'];
				if ( 'R' === $f['type'] ) {
					$rows = RowCodec::decode( $f['data'] );
				}
			}
			$this->assertSame( array( 'M', 'T', 'R', 'U', 'E' ), $types );
			$this->assertSame( 'héllo "quoted" / slash', $rows['rows'][0][1] );
			$this->assertSame( "\xff\xfe\x00bytes", $rows['rows'][0][2] );
			$this->assertNull( $rows['rows'][1][1] );
			$this->assertSame( '', $rows['rows'][1][2] );
		}
	}

	public function test_encrypted_with_passphrase(): void {
		$path = $this->write_sample( array( 'passphrase' => 'correct horse' ) );
		$this->assertStringNotContainsString( 'wp_posts', (string) file_get_contents( $path ) );

		$r = PackageReader::open( $path, array( 'passphrase' => 'correct horse' ) );
		$this->assertSame( 'M', $r->next()['type'] );

		$this->expectException( PackageException::class );
		try {
			PackageReader::open( $path, array( 'passphrase' => 'wrong' ) )->next();
		} catch ( PackageException $e ) {
			$this->assertSame( PackageException::BAD_PASSPHRASE, $e->reason );
			throw $e;
		}
	}

	public function test_encrypted_package_needs_a_key(): void {
		$path = $this->write_sample( array( 'secret' => 'esm_secret' ) );
		try {
			PackageReader::open( $path );
			$this->fail( 'Expected an exception.' );
		} catch ( PackageException $e ) {
			$this->assertSame( PackageException::NEEDS_KEY, $e->reason );
		}
		$r = PackageReader::open( $path, array( 'secret' => 'esm_secret' ) );
		$this->assertSame( 'M', $r->next()['type'] );
	}

	public function test_verify_works_without_the_password_and_detects_damage(): void {
		$path   = $this->write_sample( array( 'passphrase' => 'pw' ) );
		$result = PackageReader::verify( $path );
		$this->assertSame( 5, $result['frames'] );
		$this->assertTrue( $result['complete'] );

		$bytes    = file_get_contents( $path );
		$flipped  = $bytes;
		$flipped[ strlen( $bytes ) - 100 ] = chr( ord( $flipped[ strlen( $bytes ) - 100 ] ) ^ 0x01 );
		file_put_contents( $path, $flipped );
		$this->expectException( PackageException::class );
		PackageReader::verify( $path );
	}

	public function test_truncated_package_is_detected(): void {
		$path  = $this->write_sample();
		$bytes = file_get_contents( $path );
		file_put_contents( $path, substr( $bytes, 0, strlen( $bytes ) - 20 ) );
		$this->expectException( PackageException::class );
		PackageReader::verify( $path );
	}

	public function test_not_a_package(): void {
		$path = $this->dir . '/x.estm';
		file_put_contents( $path, str_repeat( 'x', 100 ) );
		$this->expectException( PackageException::class );
		PackageReader::open( $path );
	}

	public function test_resume_continues_after_the_last_good_frame(): void {
		$path = $this->dir . '/r.estm';
		$w    = PackageWriter::create( $path, array( 'passphrase' => 'pw' ) );
		$w->frame( 'M', array( 'a' => 1 ) );
		$offset = $w->offset();
		$seq    = $w->seq();
		$w->frame( 'T', array( 'name' => 'lost' ) ); // Written but never recorded: must be discarded.
		$w->close();

		$w = PackageWriter::resume( $path, $offset, $seq, array( 'passphrase' => 'pw' ) );
		$w->frame( 'T', array( 'name' => 'kept' ) );
		$w->frame( 'E', array() );
		$w->close();

		$r     = PackageReader::open( $path, array( 'passphrase' => 'pw' ) );
		$names = array();
		while ( $f = $r->next() ) {
			if ( 'T' === $f['type'] ) {
				$names[] = $f['data']['name'];
			}
		}
		$this->assertSame( array( 'kept' ), $names );
		$this->assertTrue( PackageReader::verify( $path )['complete'] );
	}

	public function test_seek_to_saved_offset(): void {
		$path = $this->write_sample();
		$r    = PackageReader::open( $path );
		$r->next();
		$saved = $r->offset();
		$r->next();
		$r2 = PackageReader::open( $path );
		$r2->seek( $saved );
		$this->assertSame( 'T', $r2->next()['type'] );
	}
}
