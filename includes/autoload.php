<?php
/**
 * PSR-4 style autoloader for the Estasoft\Migrate namespace.
 *
 * The plugin ships without a vendor folder, so this file is all that is needed at run time.
 *
 * @package Estasoft\Migrate
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Estasoft\\Migrate\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
