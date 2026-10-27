<?php
/**
 * Bootstrap for integration tests: a real WordPress and a real MySQL or MariaDB server.
 *
 * Environment:
 *   ESTM_WP_ROOT    Path of a WordPress install used for the tests (default /home/claude/wp-test).
 *   ESTM_DEST_DB    Name of a second, empty database on the same server (default wp_dest).
 *
 * The tests create and drop tables whose names start with the WordPress prefix of that install.
 * Never point them at a site you care about.
 *
 * @package Estasoft\Migrate
 */

$estm_root = getenv( 'ESTM_WP_ROOT' ) ? getenv( 'ESTM_WP_ROOT' ) : '/home/claude/wp-test';
if ( ! is_file( $estm_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress not found at {$estm_root}. Set ESTM_WP_ROOT.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
define( 'WP_USE_THEMES', false );

require_once $estm_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// The plugin may already be active on the install used for testing; then WordPress has defined these.
$estm_constants = array(
	'ESTASOFT_MIGRATE_VERSION' => '1.0.0',
	'ESTASOFT_MIGRATE_FILE'    => dirname( __DIR__ ) . '/estasoft-migrate.php',
	'ESTASOFT_MIGRATE_DIR'     => dirname( __DIR__ ) . '/',
	'ESTASOFT_MIGRATE_URL'     => 'http://localhost/wp-content/plugins/estasoft-migrate/',
);
foreach ( $estm_constants as $estm_name => $estm_value ) {
	if ( ! defined( $estm_name ) ) {
		define( $estm_name, $estm_value );
	}
}

require_once dirname( __DIR__ ) . '/includes/autoload.php';
require_once __DIR__ . '/Support/PdoDatabase.php';
require_once __DIR__ . '/Support/Loopback.php';
require_once __DIR__ . '/Support/EngineHarness.php';
