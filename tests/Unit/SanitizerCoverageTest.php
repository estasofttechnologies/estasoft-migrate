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
}
