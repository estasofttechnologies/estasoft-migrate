<?php
/**
 * The privacy-policy text the plugin offers.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Privacy;
use PHPUnit\Framework\TestCase;

/**
 * Privacy text.
 */
class PrivacyTest extends TestCase {

	public function test_the_suggested_text_covers_what_is_stored() {
		$text = ( new Privacy() )->text();
		$this->assertStringContainsString( 'Packages and snapshots', $text );
		$this->assertStringContainsString( 'Job history and logs', $text );
		$this->assertStringContainsString( 'network address', $text );
		$this->assertStringContainsString( 'does not send any data', $text );
	}

	public function test_the_text_is_safe_html() {
		$text = ( new Privacy() )->text();
		// Only paragraphs; and the ampersand in the plugin name is escaped.
		$this->assertSame( strip_tags( $text, '<p>' ), $text );
		$this->assertStringContainsString( 'Database Sanitizer', $text );
		$this->assertStringNotContainsString( ' & ', $text );
	}
}
