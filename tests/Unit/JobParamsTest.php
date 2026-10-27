<?php
/**
 * Validation of what people enter.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\JobParams;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Job parameter tests.
 */
class JobParamsTest extends TestCase {

	/**
	 * Rig.
	 *
	 * @var EngineHarness
	 */
	private $rig;

	protected function setUp(): void {
		$this->rig = new EngineHarness();
		$this->rig->seed( 'https://old.example.com', 2 );
	}

	protected function tearDown(): void {
		$this->rig->cleanup();
	}

	public function test_export_keeps_the_password_sealed_not_in_clear_text() {
		list( , $params ) = JobParams::build( 'export', array( 'password' => 'hunter2', 'label' => 'Before the redesign' ), $this->rig->ctx );
		$this->assertArrayNotHasKey( 'password', $params );
		$this->assertArrayNotHasKey( 'passphrase', $params );
		$this->assertStringNotContainsString( 'hunter2', json_encode( $params ) );
		$this->assertSame( 'hunter2', Secrets::open( $params['passphrase_enc'], $this->rig->ctx->secret ) );
	}

	public function test_sanitize_makes_a_sanitized_export() {
		list( $title, $params ) = JobParams::build( 'export', array( 'sanitize' => array( 'profile' => 'gdpr', 'overrides' => array( 'keep_users' => 'bob, jane' ) ) ), $this->rig->ctx );
		$this->assertSame( 'sanitize', $params['kind'] );
		$this->assertSame( 'gdpr', $params['sanitize']['profile'] );
		$this->assertSame( array( 'bob', 'jane' ), $params['sanitize']['keep_users'] );
		$this->assertSame( 'Sanitized copy', $title );
	}

	public function test_unknown_tables_and_profiles_are_refused() {
		$this->expectException( \InvalidArgumentException::class );
		JobParams::build( 'export', array( 'tables' => array( 'wp_options', 'wp_nope' ) ), $this->rig->ctx );
	}

	public function test_unknown_profile_is_refused() {
		$this->expectExceptionMessage( 'profiles' );
		JobParams::build( 'export', array( 'sanitize' => array( 'profile' => 'evil' ) ), $this->rig->ctx );
	}

	public function test_search_replace_defaults_to_a_safe_dry_run() {
		list( , $params ) = JobParams::build( 'search_replace', array( 'pairs' => array( array( 'a', 'b' ) ) ), $this->rig->ctx );
		$this->assertTrue( $params['dry_run'] );
		$this->assertFalse( $params['snapshot'] );
		list( , $live ) = JobParams::build( 'search_replace', array( 'pairs' => array( array( 'a', 'b' ) ), 'dry_run' => 'false' ), $this->rig->ctx );
		$this->assertFalse( $live['dry_run'] );
		$this->assertTrue( $live['snapshot'], 'A live run takes a snapshot unless told otherwise.' );
	}

	public function test_search_replace_needs_a_search_text_and_a_valid_pattern() {
		try {
			JobParams::build( 'search_replace', array( 'pairs' => array( array( '', 'x' ) ) ), $this->rig->ctx );
			$this->fail( 'Empty search accepted' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'search for', $e->getMessage() );
		}
		$this->expectExceptionMessage( 'regular expression' );
		JobParams::build( 'search_replace', array( 'pairs' => array( array( '(oops', 'x' ) ), 'regex' => true ), $this->rig->ctx );
	}

	public function test_remote_details_are_checked_and_the_key_is_sealed() {
		list( , $params ) = JobParams::build( 'push', array( 'remote_url' => 'https://live.example.com/', 'remote_key' => 'esm_abc' ), $this->rig->ctx );
		$this->assertSame( 'https://live.example.com', $params['remote_url'] );
		$this->assertArrayNotHasKey( 'remote_key', $params );
		$this->assertSame( 'esm_abc', Secrets::open( $params['key_enc'], $this->rig->ctx->secret ) );

		foreach ( array(
			array( 'remote_url' => 'live.example.com', 'remote_key' => 'esm_x' ),
			array( 'remote_url' => 'https://live.example.com', 'remote_key' => '' ),
			array( 'remote_url' => 'https://live.example.com', 'remote_key' => 'password123' ),
			array( 'remote_url' => 'javascript:alert(1)', 'remote_key' => 'esm_x' ),
		) as $bad ) {
			try {
				JobParams::build( 'pull', $bad, $this->rig->ctx );
				$this->fail( 'Accepted ' . json_encode( $bad ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}
	}

	public function test_import_needs_an_existing_package() {
		$this->expectException( \InvalidArgumentException::class );
		JobParams::build( 'import', array( 'package_id' => '20250101-000000-nothing-abcdef12' ), $this->rig->ctx );
	}

	public function test_package_ids_cannot_escape_the_storage_folder() {
		foreach ( array( '../../wp-config', '..%2f..', 'a/b', '' ) as $id ) {
			try {
				JobParams::build( 'verify', array( 'package_id' => $id ), $this->rig->ctx );
				$this->fail( 'Accepted ' . $id );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertTrue( true );
			}
		}
	}

	public function test_verify_job_accepts_good_packages_and_flags_damaged_ones() {
		$job = $this->rig->run( 'export', array( 'kind' => 'export', 'label' => 'v' ) );
		$id  = $job->state['package'];

		$ok = $this->rig->run( 'verify', array( 'package_id' => $id ) );
		$this->assertSame( 'completed', $ok->status, (string) $ok->message );
		$this->assertTrue( $this->rig->storage->read_meta( $id )['verified_ok'] );

		$path = $this->rig->storage->package_path( $id );
		$raw  = file_get_contents( $path );
		file_put_contents( $path, substr( $raw, 0, (int) ( strlen( $raw ) * 0.6 ) ) );
		$bad = $this->rig->run( 'verify', array( 'package_id' => $id ) );
		$this->assertSame( 'failed', $bad->status );
		$this->assertFalse( $this->rig->storage->read_meta( $id )['verified_ok'] );
	}
}
