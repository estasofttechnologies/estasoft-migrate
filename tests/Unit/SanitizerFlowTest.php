<?php
/**
 * Sanitizing while exporting, then importing the clean copy.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Unit;

use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * End to end sanitizer behaviour on SQLite.
 */
class SanitizerFlowTest extends TestCase {

	/**
	 * Rigs.
	 *
	 * @var EngineHarness[]
	 */
	private $rigs = array();

	protected function tearDown(): void {
		foreach ( $this->rigs as $rig ) {
			$rig->cleanup();
		}
	}

	/**
	 * Source and target rigs with a sanitized package copied across and imported.
	 *
	 * @param array $overrides Sanitizer overrides.
	 * @param string $profile  Profile.
	 * @return array{0:EngineHarness,1:EngineHarness,2:string}
	 */
	private function flow( array $overrides = array(), $profile = 'staging' ) {
		$source = new EngineHarness( 'wp_', 'https://old.example.com' );
		$source->seed();
		$target = new EngineHarness( 'wp_', 'https://stage.example.com' );
		$target->seed( 'https://stage.example.com', 2 );
		$this->rigs = array( $source, $target );

		$job = $source->run(
			'export',
			array(
				'kind'     => 'sanitize',
				'label'    => 'clean',
				'sanitize' => Profiles::build_config( $profile, $overrides ),
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $source->logger->messages( 'error' ), true ) );
		$id = $job->state['package'];

		$target->storage->dir();
		copy( $source->storage->package_path( $id ), $target->storage->package_path( $id ) );
		copy( $source->storage->meta_path( $id ), $target->storage->meta_path( $id ) );

		$import = $target->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( Job::COMPLETED, $import->status, (string) $import->message . print_r( $target->logger->messages( 'error' ), true ) );
		return array( $source, $target, $id );
	}

	public function test_personal_data_is_replaced_in_the_copy_and_the_source_is_untouched() {
		list( $source, $target ) = $this->flow();

		$email = $target->db->get_var( "SELECT user_email FROM wp_users WHERE ID = 2" );
		$this->assertMatchesRegularExpression( '/^user\d+@example\.invalid$/', $email );
		$this->assertSame( 'jane.doe@gmail.com', $source->db->get_var( "SELECT user_email FROM wp_users WHERE ID = 2" ), 'The live source must never change.' );

		$this->assertNotSame( 'Jane Doe', $target->db->get_var( "SELECT display_name FROM wp_users WHERE ID = 2" ) );
		$this->assertNotSame( 'hash-jane', $target->db->get_var( "SELECT user_pass FROM wp_users WHERE ID = 2" ) );
		$this->assertNotSame( '+1 415 555 0134', $target->db->get_var( "SELECT meta_value FROM wp_usermeta WHERE meta_key = 'billing_phone'" ) );
		$this->assertNotSame( '12 Main Street', $target->db->get_var( "SELECT meta_value FROM wp_usermeta WHERE meta_key = 'billing_address_1'" ) );
		$this->assertSame( '203.0.113.0', $target->db->get_var( "SELECT comment_author_IP FROM wp_comments WHERE comment_ID = 1" ) );
		$this->assertSame( '0', $target->db->get_var( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key = 'session_tokens'" ), 'Login sessions are dropped.' );
		$this->assertNotSame( 'test-secret-abcdefghijklmnopqrstuvwxyz', $target->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'stripe_api_key'" ) );
	}

	public function test_administrators_are_kept_by_default() {
		list( , $target ) = $this->flow();
		$this->assertSame( 'boss@old.example.com', $target->db->get_var( "SELECT user_email FROM wp_users WHERE ID = 1" ) );
		$this->assertSame( 'hash-admin', $target->db->get_var( "SELECT user_pass FROM wp_users WHERE ID = 1" ) );
	}

	public function test_keep_list_is_honoured() {
		list( , $target ) = $this->flow( array( 'keep_users' => array( 'bob' ), 'keep_admins' => false ) );
		$this->assertSame( 'bob@customer.org', $target->db->get_var( "SELECT user_email FROM wp_users WHERE ID = 3" ) );
		$this->assertNotSame( 'boss@old.example.com', $target->db->get_var( "SELECT user_email FROM wp_users WHERE ID = 1" ) );
	}

	public function test_safety_switches_are_applied_after_the_import() {
		list( , $target ) = $this->flow();

		$active = unserialize( $target->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" ) );
		$this->assertNotContains( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', $active, 'Payment gateway must be switched off.' );
		$this->assertContains( 'woocommerce/woocommerce.php', $active, 'The shop itself must keep working.' );
		$this->assertNotContains( 'wp-mail-smtp/wp_mail_smtp.php', $active, 'SMTP plugin must be switched off.' );
		$this->assertContains( 'akismet/akismet.php', $active );
		$this->assertContains( 'estasoft-migrate/estasoft-migrate.php', $active, 'This plugin must stay active.' );
		$this->assertSame( '0', $target->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'blog_public'" ) );
		$this->assertSame( '1', $target->db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'estasoft_migrate_block_mail'" ) );
	}

	public function test_fake_data_is_the_same_every_time_for_the_same_seed() {
		list( $a ) = $this->flow( array( 'seed' => 'fixed' ) );
		list( , $first ) = array( null, $this->rigs[1] );
		$one = $first->db->get_var( "SELECT user_email FROM wp_users WHERE ID = 2" );
		$this->tearDown();
		$this->flow( array( 'seed' => 'fixed' ) );
		$two = $this->rigs[1]->db->get_var( "SELECT user_email FROM wp_users WHERE ID = 2" );
		$this->assertSame( $one, $two );
	}

	public function test_gdpr_profile_removes_free_text() {
		list( , $target ) = $this->flow( array(), 'gdpr' );
		$this->assertSame( '(removed)', $target->db->get_var( "SELECT comment_content FROM wp_comments WHERE comment_ID = 1" ) );
	}
}
