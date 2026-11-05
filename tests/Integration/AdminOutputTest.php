<?php
/**
 * What the plugin prints into wp-admin outside its own screens.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Integration;

use Estasoft\Migrate\Admin\Admin;
use Estasoft\Migrate\Plugin;
use Estasoft\Migrate\Sanitizer\SafetySwitches;
use PHPUnit\Framework\TestCase;

/**
 * Styles go through the enqueue functions, and the one notice the plugin can show is limited to two screens.
 */
class AdminOutputTest extends TestCase {

	protected function setUp(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		wp_styles()->remove( Admin::MENU_STYLE );
		wp_styles()->dequeue( Admin::MENU_STYLE );
		delete_option( SafetySwitches::MAIL_OPTION );
	}

	protected function tearDown(): void {
		delete_option( SafetySwitches::MAIL_OPTION );
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
	}

	public function test_the_menu_rule_is_added_as_an_inline_style_and_not_printed_directly() {
		$admin = new Admin( Plugin::instance() );

		ob_start();
		$admin->menu_style();
		$printed = ob_get_clean();

		$this->assertSame( '', $printed, 'Nothing is echoed; the rule goes through wp_add_inline_style().' );
		$this->assertTrue( wp_style_is( Admin::MENU_STYLE, 'enqueued' ) );

		$after = wp_styles()->get_data( Admin::MENU_STYLE, 'after' );
		$css   = is_array( $after ) ? implode( '', $after ) : '';
		$this->assertStringContainsString( 'page=estasoft-migrate-preflight"]', $css );
		$this->assertStringContainsString( 'page=estasoft-migrate-progress"]', $css );
		$this->assertStringContainsString( '{display:none}', $css );
		$this->assertStringNotContainsString( 'estasoft-migrate-settings', $css, 'Screens that are in the menu stay visible.' );
	}

	public function test_the_blocked_mail_notice_is_limited_to_the_dashboard_and_the_plugins_list() {
		update_option( SafetySwitches::MAIL_OPTION, '1' );
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		if ( ! $admins ) {
			$this->markTestSkipped( 'The test site has no administrator.' );
		}
		wp_set_current_user( $admins[0]->ID );

		$shown = array();
		foreach ( array( 'dashboard', 'plugins', 'edit-post', 'options-general', 'toplevel_page_estasoft-migrate' ) as $id ) {
			$GLOBALS['current_screen'] = \WP_Screen::get( $id );
			ob_start();
			SafetySwitches::notice();
			$shown[ $id ] = '' !== ob_get_clean();
		}

		$this->assertSame(
			array(
				'dashboard'                      => true,
				'plugins'                        => true,
				'edit-post'                      => false,
				'options-general'                => false,
				'toplevel_page_estasoft-migrate' => false,
			),
			$shown
		);
	}

	public function test_the_notice_is_silent_when_mail_is_not_blocked_or_the_person_is_not_an_administrator() {
		$GLOBALS['current_screen'] = \WP_Screen::get( 'dashboard' );

		wp_set_current_user( 0 );
		update_option( SafetySwitches::MAIL_OPTION, '1' );
		ob_start();
		SafetySwitches::notice();
		$this->assertSame( '', ob_get_clean(), 'Visitors and editors see nothing.' );

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		if ( $admins ) {
			wp_set_current_user( $admins[0]->ID );
			delete_option( SafetySwitches::MAIL_OPTION );
			ob_start();
			SafetySwitches::notice();
			$this->assertSame( '', ob_get_clean(), 'No notice while mail is not blocked.' );
		}
	}
}
