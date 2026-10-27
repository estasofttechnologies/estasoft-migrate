<?php
/**
 * Saved sanitizer settings.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Sanitizer\SavedConfig;
use PHPUnit\Framework\TestCase;

/**
 * Whatever the screen sends, what is stored is complete and safe.
 */
class SavedConfigTest extends TestCase {

	public function test_empty_input_gives_the_staging_profile_untouched() {
		$c = SavedConfig::clean( array() );
		$this->assertSame( 'staging', $c['profile'] );
		$this->assertSame( Profiles::get( 'staging' )['rules'], $c['rules'] );
		$this->assertSame( Profiles::get( 'staging' )['safety'], $c['safety'] );
		$this->assertTrue( $c['keep_admins'] );
		$this->assertFalse( $c['on_export'] );
		$this->assertSame( array(), $c['keep_users'] );
	}

	public function test_an_unknown_profile_falls_back_and_unknown_switches_are_ignored() {
		$c = SavedConfig::clean( array( 'profile' => 'weird', 'rules' => array( 'emails' => '0', 'x' => 1 ), 'safety' => array( 'pay' => 'no', 'y' => 1 ) ) );
		$this->assertSame( 'staging', $c['profile'] );
		$this->assertFalse( $c['rules']['emails'] );
		$this->assertArrayNotHasKey( 'x', $c['rules'] );
		$this->assertFalse( $c['safety']['pay'] );
		$this->assertArrayNotHasKey( 'y', $c['safety'] );
	}

	public function test_lists_are_trimmed_deduplicated_and_limited() {
		$c = SavedConfig::clean( array( 'keep_users' => " a@x.test ,b@x.test\na@x.test", 'skip_tables' => array_merge( array( '', ' ' ), array_map( 'strval', range( 1, 300 ) ) ) ) );
		$this->assertSame( array( 'a@x.test', 'b@x.test' ), $c['keep_users'] );
		$this->assertCount( 200, $c['skip_tables'] );
		$this->assertSame( '1', $c['skip_tables'][0] );
	}

	public function test_cleaning_twice_changes_nothing() {
		$once = SavedConfig::clean( array( 'profile' => 'dev', 'keep_users' => 'a@x.test', 'on_export' => true, 'keep_admins' => false ) );
		$this->assertSame( $once, SavedConfig::clean( $once ) );
	}

	public function test_job_input_matches_what_the_sanitizer_builds_its_config_from() {
		$saved  = SavedConfig::clean( array( 'profile' => 'gdpr', 'rules' => array( 'ips' => false ), 'keep_users' => 'a@x.test', 'keep_admins' => false ) );
		$input  = SavedConfig::to_job_input( $saved );
		$config = Profiles::build_config( $input['profile'], $input['overrides'] );
		$this->assertSame( 'gdpr', $config['profile'] );
		$this->assertFalse( $config['rules']['ips'] );
		$this->assertSame( array( 'a@x.test' ), $config['keep_users'] );
		$this->assertFalse( $config['keep_admins'] );
	}
}
