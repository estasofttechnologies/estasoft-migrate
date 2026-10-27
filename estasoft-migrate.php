<?php
/**
 * Plugin Name:       Estasoft Migration & Database Sanitizer
 * Plugin URI:        https://estasoft.com/
 * Description:       Zero-downtime WordPress migration with resumable chunked database transfer, serialization-safe search and replace, and a rule-based database sanitizer. 100% free.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ESTASOFT TECHNOLOGIES
 * Author URI:        https://estasoft.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estasoft-migrate
 * Domain Path:       /languages
 *
 * @package Estasoft\Migrate
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'ESTASOFT_MIGRATE_VERSION' ) ) {
	return;
}

define( 'ESTASOFT_MIGRATE_VERSION', '1.0.0' );
define( 'ESTASOFT_MIGRATE_FILE', __FILE__ );
define( 'ESTASOFT_MIGRATE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTASOFT_MIGRATE_URL', plugin_dir_url( __FILE__ ) );
define( 'ESTASOFT_MIGRATE_MIN_PHP', '7.4' );
define( 'ESTASOFT_MIGRATE_MIN_WP', '5.8' );

/**
 * Stop early, with a readable notice, on servers that are too old.
 */
if ( version_compare( PHP_VERSION, ESTASOFT_MIGRATE_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'Estasoft Migration & Database Sanitizer needs PHP %1$s or newer. This server runs PHP %2$s, so the plugin is switched off.', 'estasoft-migrate' ),
						ESTASOFT_MIGRATE_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

require_once ESTASOFT_MIGRATE_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( \Estasoft\Migrate\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Estasoft\Migrate\Activator::class, 'deactivate' ) );

\Estasoft\Migrate\Plugin::instance()->boot();
