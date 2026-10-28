<?php
/**
 * The replay guard on a real options table.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Integration;

use Estasoft\Migrate\Engine\RowPipeline;
use Estasoft\Migrate\Remote\WpNonces;
use PHPUnit\Framework\TestCase;

/**
 * A request number must be accepted once, even when two requests carry it at the same moment and even when no
 * object cache is in play. That is a property of the unique index of the options table, so it is tested there.
 */
class ReplayGuardTest extends TestCase {

	protected function setUp(): void {
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( WpNonces::PREFIX ) . '%' ) );
	}

	private function nonce() {
		return bin2hex( random_bytes( 16 ) );
	}

	public function test_a_number_is_accepted_once() {
		$guard = new WpNonces();
		$n     = $this->nonce();
		$this->assertTrue( $guard->use_once( $n, 600 ) );
		$this->assertFalse( $guard->use_once( $n, 600 ), 'The same number again is a replay.' );
		$this->assertTrue( $guard->use_once( $this->nonce(), 600 ), 'Another number is fine.' );
	}

	public function test_it_does_not_depend_on_the_object_cache_or_on_one_connection() {
		global $wpdb;
		$guard = new WpNonces();
		$n     = $this->nonce();
		$this->assertTrue( $guard->use_once( $n, 600 ) );

		// A second request is a second PHP process with its own connection and an empty cache.
		wp_cache_flush();
		$real   = $wpdb;
		$other  = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $real->prefix );
		$GLOBALS['wpdb'] = $other;
		try {
			$this->assertFalse( ( new WpNonces() )->use_once( $n, 600 ) );
			$this->assertTrue( ( new WpNonces() )->use_once( $this->nonce(), 600 ) );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
	}

	public function test_a_number_that_cannot_be_written_is_refused() {
		global $wpdb;
		$real = $wpdb;
		$gone = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$gone->set_prefix( 'no_such_prefix_' );
		$gone->suppress_errors( true );
		$GLOBALS['wpdb'] = $gone;
		try {
			$this->assertFalse( ( new WpNonces() )->use_once( $this->nonce(), 600 ), 'Failing closed: a request that cannot be remembered is not let in.' );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
	}

	public function test_old_numbers_are_forgotten_and_other_options_are_left_alone() {
		global $wpdb;
		$guard = new WpNonces();
		$old   = WpNonces::PREFIX . str_repeat( 'a', 32 );
		$live  = WpNonces::PREFIX . str_repeat( 'b', 32 );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no'), (%s, %s, 'no')", $old, (string) ( time() - 5 ), $live, (string) ( time() + 500 ) ) );
		update_option( 'estasoft_migrate_test_marker', 'keep me', false );

		$guard->purge( time() );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $old ) ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $live ) ) );
		$this->assertSame( 'keep me', get_option( 'estasoft_migrate_test_marker' ) );
		delete_option( 'estasoft_migrate_test_marker' );
	}

	public function test_the_rows_are_not_loaded_on_every_page_and_never_travel_in_a_package() {
		global $wpdb;
		$name = WpNonces::PREFIX . str_repeat( 'c', 32 );
		$this->assertTrue( ( new WpNonces() )->use_once( 'x' . $this->nonce(), 600 ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload NOT IN ('no', 'off')", $wpdb->esc_like( WpNonces::PREFIX ) . '%' ) ) );
		$this->assertTrue( RowPipeline::is_plugin_option( $name ), 'They carry the plugin prefix, so exports skip them and imports keep them.' );
	}
}
