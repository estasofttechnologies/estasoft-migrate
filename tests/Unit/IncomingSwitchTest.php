<?php
/**
 * Switching incoming migrations off by themselves, stopping an old connection key, and the request function used for outgoing calls.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Remote\WpTransport;
use Estasoft\Migrate\Settings;
use Estasoft\Migrate\Tests\Support\ArraySettings;
use PHPUnit\Framework\TestCase;

class IncomingSwitchTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['estm_http_calls'] = array();
	}

	public function test_the_time_can_only_be_one_of_the_offered_choices() {
		foreach ( array( 1, 24, 168, 0 ) as $ok ) {
			$this->assertSame( $ok, Settings::sanitize( array( 'accept_hours' => (string) $ok ), array() )['accept_hours'] );
		}
		foreach ( array( 5, -3, 'soon', '', 100000 ) as $bad ) {
			$this->assertSame( 24, Settings::sanitize( array( 'accept_hours' => $bad ), array() )['accept_hours'], 'Anything else becomes the default of 24 hours.' );
		}
	}

	public function test_switching_on_starts_the_clock_and_switching_off_stops_it() {
		$settings = new ArraySettings( array( 'accept_incoming' => false ) );

		$settings->update( array( 'accept_incoming' => true ) );
		$this->assertEqualsWithDelta( time() + 24 * 3600, $settings->get( 'accept_until' ), 5 );
		$this->assertTrue( $settings->incoming_open() );

		$settings->update( array( 'accept_hours' => 1 ) );
		$this->assertEqualsWithDelta( time() + 3600, $settings->get( 'accept_until' ), 5, 'A different time starts the clock again.' );

		$settings->update( array( 'accept_hours' => 0 ) );
		$this->assertSame( 0, $settings->get( 'accept_until' ), 'Never means no deadline.' );
		$this->assertTrue( $settings->incoming_open() );

		$settings->update( array( 'accept_incoming' => false ) );
		$this->assertSame( 0, $settings->get( 'accept_until' ) );
		$this->assertFalse( $settings->incoming_open() );
	}

	public function test_changing_other_settings_does_not_move_the_deadline() {
		$settings = new ArraySettings( array( 'accept_incoming' => true, 'accept_until' => time() + 100 ) );
		$settings->update( array( 'runner' => 'ajax', 'max_batch' => 500 ) );
		$this->assertEqualsWithDelta( time() + 100, $settings->get( 'accept_until' ), 5 );
	}

	public function test_incoming_switches_itself_off_when_the_time_has_passed() {
		$ended    = time() - 30;
		$settings = new ArraySettings( array( 'accept_incoming' => true, 'accept_until' => $ended ) );

		$this->assertFalse( $settings->incoming_open() );
		$this->assertFalse( $settings->get( 'accept_incoming' ), 'The switch itself is off, so the Settings screen shows it off.' );
		$this->assertSame( $ended, $settings->get( 'accept_ended' ), 'When it happened is kept, to tell the person.' );
		$this->assertSame( 0, $settings->get( 'accept_until' ) );
		$this->assertFalse( $settings->incoming_open() );

		$settings->update( array( 'accept_incoming' => true ) );
		$this->assertSame( 0, $settings->get( 'accept_ended' ), 'Switching it on again clears the note.' );
	}

	public function test_an_accepted_request_moves_the_deadline_forward_but_not_on_every_request() {
		$settings = new ArraySettings( array( 'accept_incoming' => true, 'accept_hours' => 24, 'accept_until' => time() + 600 ) );
		$settings->keep_incoming_open();
		$this->assertEqualsWithDelta( time() + 24 * 3600, $settings->get( 'accept_until' ), 5, 'Less than half a period was left.' );

		$settings->update_raw( array( 'accept_until' => time() + 20 * 3600 ) );
		$settings->keep_incoming_open();
		$this->assertEqualsWithDelta( time() + 20 * 3600, $settings->get( 'accept_until' ), 5, 'More than half was left: nothing is written.' );

		$never = new ArraySettings( array( 'accept_incoming' => true, 'accept_hours' => 0, 'accept_until' => 0 ) );
		$never->keep_incoming_open();
		$this->assertSame( 0, $never->get( 'accept_until' ) );

		$off = new ArraySettings( array( 'accept_incoming' => false, 'accept_hours' => 24, 'accept_until' => 0 ) );
		$off->keep_incoming_open();
		$this->assertSame( 0, $off->get( 'accept_until' ), 'A request cannot switch anything on.' );
	}

	public function test_an_old_key_works_for_a_while_unless_it_is_stopped() {
		$settings = new ArraySettings( array( 'connection_key' => 'esm_OLD' ) );

		$settings->regenerate_key();
		$this->assertCount( 2, $settings->valid_keys() );
		$this->assertContains( 'esm_OLD', $settings->valid_keys() );

		$settings->revoke_previous_key();
		$this->assertCount( 1, $settings->valid_keys() );
		$this->assertNotContains( 'esm_OLD', $settings->valid_keys() );
		$this->assertSame( 0, $settings->get( 'previous_key_until' ) );

		$current = $settings->connection_key();
		$settings->regenerate_key( false );
		$this->assertCount( 1, $settings->valid_keys(), 'Replacing the key without a grace period leaves only the new one.' );
		$this->assertNotContains( $current, $settings->valid_keys() );
		$this->assertSame( '', $settings->get( 'previous_key' ) );
	}

	public function test_local_and_private_addresses_are_refused_unless_they_are_allowed() {
		foreach ( array( 'http://localhost:8080', 'http://192.168.1.20/site', 'https://10.0.0.5' ) as $url ) {
			try {
				( new WpTransport() )->post( $url . '/?estm_remote=hello', array(), '{}', 5 );
				$this->fail( 'A private address must be refused: ' . $url );
			} catch ( RemoteException $e ) {
				$this->assertSame( 'estm_private_host', $e->error_code );
				$this->assertStringContainsString( 'Allow local and private addresses', $e->getMessage(), 'The message says where to change it.' );
			}
		}
		$this->assertSame( array(), $GLOBALS['estm_http_calls'], 'Nothing was sent.' );
	}

	public function test_a_public_address_is_called_with_the_safe_function_and_a_private_one_with_the_plain_function_when_allowed() {
		try {
			( new WpTransport() )->post( 'https://live.example.com/?estm_remote=hello', array(), '{}', 5 );
		} catch ( RemoteException $e ) {
			$this->assertSame( 'estm_unreachable', $e->error_code, 'The stub answers with an error.' );
		}
		try {
			( new WpTransport( true ) )->post( 'http://localhost:8080/?estm_remote=hello', array(), '{}', 5 );
		} catch ( RemoteException $e ) {
			$this->assertSame( 'estm_unreachable', $e->error_code );
		}
		$this->assertSame(
			array( 'safe https://live.example.com/?estm_remote=hello', 'open http://localhost:8080/?estm_remote=hello' ),
			$GLOBALS['estm_http_calls']
		);
	}
}
