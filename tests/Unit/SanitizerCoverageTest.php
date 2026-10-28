<?php
/**
 * Values the sanitizer has to catch that are easy to forget.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Sanitizer\RowSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Row level checks on the sanitizer.
 */
class SanitizerCoverageTest extends TestCase {

	/**
	 * Sanitizer for a profile.
	 *
	 * @param string $profile Profile.
	 * @return RowSanitizer
	 */
	private function sanitizer( $profile = 'staging' ) {
		return new RowSanitizer( Profiles::build_config( $profile ), 'test-salt', 'hash' );
	}

	public function test_the_password_of_a_protected_post_is_replaced_and_the_post_stays_protected() {
		$san = $this->sanitizer();
		$row = $san->process( 'posts', array( 'ID' => 5, 'post_type' => 'post', 'post_password' => 'hunter2', 'post_title' => 'T' ) );
		$this->assertNotSame( 'hunter2', $row['post_password'] );
		$this->assertNotSame( '', $row['post_password'], 'Blank would publish the post.' );
		$this->assertSame( $row['post_password'], $this->sanitizer()->process( 'posts', array( 'post_type' => 'page', 'post_password' => 'hunter2' ) )['post_password'], 'The same password gets the same stand-in.' );

		$open = $san->process( 'posts', array( 'ID' => 6, 'post_type' => 'post', 'post_password' => '' ) );
		$this->assertSame( '', $open['post_password'], 'Posts without a password are left alone.' );
	}

	public function test_privacy_requests_do_not_keep_the_requesters_email_address() {
		$row = $this->sanitizer()->process(
			'posts',
			array(
				'post_type'    => 'user_request',
				'post_title'   => 'sara.whitfield@mail.test',
				'post_content' => '{"export":"x"}',
			)
		);
		$this->assertStringNotContainsString( 'sara.whitfield', $row['post_title'] );
		$this->assertStringContainsString( '@', $row['post_title'] );
		$this->assertSame( '', $row['post_content'] );
	}

	public function test_ordinary_posts_are_not_changed() {
		$in  = array( 'post_type' => 'post', 'post_title' => 'sara@mail.test wrote this', 'post_content' => 'Hello', 'post_password' => '' );
		$this->assertSame( $in, $this->sanitizer()->process( 'posts', $in ) );
	}

	public function test_a_user_meta_value_that_is_only_an_address_is_masked_whatever_its_key() {
		$san = $this->sanitizer( 'gdpr' );
		$ip  = $san->process( 'usermeta', array( 'user_id' => 9, 'meta_key' => 'last_login_ip', 'meta_value' => '203.0.113.77' ) );
		$this->assertSame( '203.0.113.0', $ip['meta_value'] );
		$v6 = $san->process( 'usermeta', array( 'user_id' => 9, 'meta_key' => 'seen_from', 'meta_value' => '2001:db8:1234:5678::1' ) );
		$this->assertStringNotContainsString( '5678', $v6['meta_value'] );
		$mail = $san->process( 'usermeta', array( 'user_id' => 9, 'meta_key' => 'paypal_account', 'meta_value' => 'sara.whitfield@mail.test' ) );
		$this->assertStringEndsWith( '@example.invalid', $mail['meta_value'] );

		foreach ( array( 'Lives at 203.0.113.77 now', 'write to sara@mail.test or call', '12', 'blue', '1.2' ) as $other ) {
			$row = array( 'user_id' => 9, 'meta_key' => 'custom_note_x', 'meta_value' => $other );
			$this->assertSame( $row, $san->process( 'usermeta', $row ), 'Only a value that is nothing but an address is touched: ' . $other );
		}

		$off = new RowSanitizer( Profiles::build_config( 'gdpr', array( 'rules' => array( 'ips' => false, 'emails' => false ) ) ), 's', 'h' );
		$row = array( 'user_id' => 9, 'meta_key' => 'last_login_ip', 'meta_value' => '203.0.113.77' );
		$this->assertSame( $row, $off->process( 'usermeta', $row ), 'A rule that is switched off masks nothing.' );
	}

	public function test_addresses_in_the_arguments_of_scheduled_events_are_masked_and_the_events_stay_findable() {
		$plain = array( 'sync', 42 );
		$cron  = array(
			1900000000 => array(
				'send_digest' => array(
					md5( serialize( array( 'sara.whitfield@mail.test', 7 ) ) ) => array( 'schedule' => 'daily', 'args' => array( 'sara.whitfield@mail.test', 7 ), 'interval' => 86400 ),
				),
				'audit_login' => array(
					md5( serialize( array( array( 'ip' => '203.0.113.77', 'tag' => 'x' ) ) ) ) => array( 'schedule' => false, 'args' => array( array( 'ip' => '203.0.113.77', 'tag' => 'x' ) ) ),
				),
				'plain_job'   => array(
					md5( serialize( $plain ) ) => array( 'schedule' => 'hourly', 'args' => $plain, 'interval' => 3600 ),
					md5( serialize( array() ) ) => array( 'schedule' => 'hourly', 'args' => array(), 'interval' => 3600 ),
				),
			),
			'version'  => 2,
		);
		$row = $this->sanitizer( 'gdpr' )->process( 'options', array( 'option_name' => 'cron', 'option_value' => serialize( $cron ) ) );
		$out = unserialize( $row['option_value'], array( 'allowed_classes' => false ) );

		$this->assertSame( 2, $out['version'] );
		$this->assertStringNotContainsString( 'sara.whitfield', $row['option_value'] );
		$this->assertStringNotContainsString( '203.0.113.77', $row['option_value'] );

		foreach ( $out[1900000000] as $hook => $events ) {
			foreach ( $events as $key => $event ) {
				$this->assertSame( md5( serialize( $event['args'] ) ), $key, "WordPress finds the {$hook} event by the md5 of its arguments, so the key follows them." );
			}
		}
		$digest = reset( $out[1900000000]['send_digest'] );
		$this->assertStringEndsWith( '@example.invalid', $digest['args'][0] );
		$this->assertSame( 7, $digest['args'][1] );
		$this->assertSame( 'daily', $digest['schedule'] );
		$login = reset( $out[1900000000]['audit_login'] );
		$this->assertSame( array( array( 'ip' => '203.0.113.0', 'tag' => 'x' ) ), $login['args'] );
		$this->assertSame( $cron[1900000000]['plain_job'], $out[1900000000]['plain_job'], 'Events without an address are left exactly as they were.' );

		$same = array( 'option_name' => 'cron', 'option_value' => serialize( array( 1900000000 => array( 'plain_job' => $cron[1900000000]['plain_job'] ), 'version' => 2 ) ) );
		$this->assertSame( $same, $this->sanitizer( 'gdpr' )->process( 'options', $same ), 'Nothing to mask: the value is not even re-serialized.' );
		$odd = array( 'option_name' => 'cron', 'option_value' => 'not serialized' );
		$this->assertSame( $odd, $this->sanitizer( 'gdpr' )->process( 'options', $odd ) );
	}
}
