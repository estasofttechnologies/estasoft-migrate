<?php
/**
 * The few WordPress functions that otherwise-pure classes call.
 * Only loaded by the unit tests; the integration tests use real WordPress.
 *
 * @package Estasoft\Migrate
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) { // phpcs:ignore
		return is_dir( $dir ) || mkdir( $dir, 0775, true );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) { // phpcs:ignore
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}
if ( ! function_exists( 'wp_delete_file_from_directory' ) ) {
	function wp_delete_file_from_directory( $file, $dir ) { // phpcs:ignore
		if ( 0 !== strpos( realpath( $file ), realpath( $dir ) ) ) {
			return false;
		}
		wp_delete_file( $file );
		return true;
	}
}
if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) { // phpcs:ignore
		return is_writable( $path );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12 ) { // phpcs:ignore
		return substr( bin2hex( random_bytes( $length ) ), 0, $length );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) { // phpcs:ignore
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { // phpcs:ignore
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text ) { // phpcs:ignore
		return esc_html( $text );
	}
}
