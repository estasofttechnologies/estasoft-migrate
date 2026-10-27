<?php
/**
 * Suggested text for the site's privacy policy (Settings > Privacy in the admin).
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the site owner what the plugin stores, so it can go into the privacy policy.
 */
class Privacy {

	/**
	 * Hook in. Only the admin needs this.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * Offer the text on the Privacy Policy guide.
	 *
	 * @return void
	 */
	public function add_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Estasoft Migration & Database Sanitizer', 'estasoft-migrate' ),
			wp_kses_post( wpautop( $this->text(), false ) )
		);
	}

	/**
	 * The suggested text.
	 *
	 * @return string
	 */
	public function text() {
		$paragraphs = array(
			__( 'This site uses Estasoft Migration & Database Sanitizer to copy, move and clean its database. The plugin does not send any data to ESTASOFT TECHNOLOGIES or to any other company.', 'estasoft-migrate' ),
			__( 'Packages and snapshots: a package or a rollback snapshot is a complete copy of the database, so it contains everything the database contains, including the details of customers, commenters and users, unless the sanitizer masked them. These files are kept in a protected folder inside the uploads directory until they are deleted on the Packages screen, removed by the clean-up in the plugin settings, or removed when the plugin is deleted with "Delete all plugin data" switched on.', 'estasoft-migrate' ),
			__( 'Job history and logs: the plugin records which user account started a job, when it ran, which tables it handled and how many rows, and error messages. When incoming migrations are switched on, it also records the network address of the site that connected. This history stays in the site database until it is cleared or the plugin data is deleted.', 'estasoft-migrate' ),
			__( 'Moving data to or from another site: when a person starts a push or a pull, the database content is sent to, or fetched from, only the WordPress site whose address and connection key that person entered. The transfer is signed with that key.', 'estasoft-migrate' ),
		);
		return '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for your privacy policy:', 'estasoft-migrate' ) . '</p><p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>';
	}
}
