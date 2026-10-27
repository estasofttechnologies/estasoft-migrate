<?php
/**
 * Bootstrap for unit tests: no WordPress, no MySQL.
 *
 * @package Estasoft\Migrate
 */

define( 'ESTASOFT_MIGRATE_TESTING', true );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__ ) . '/includes/autoload.php';
require_once __DIR__ . '/Support/PdoDatabase.php';
require_once __DIR__ . '/Support/Loopback.php';
require_once __DIR__ . '/Support/EngineHarness.php';
require_once __DIR__ . '/Support/BigTablesDatabase.php';
require_once __DIR__ . '/Support/wp-stubs.php';
