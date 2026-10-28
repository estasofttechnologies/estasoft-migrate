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

/*
 * Just enough of the HTTP API to see which request function WpTransport picks. Both stubs record the call and answer with
 * an error, so no response has to be built.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { // phpcs:ignore
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { // phpcs:ignore
		return 'https://this-site.example.test' . $path;
	}
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	/** Like the real one for the cases the tests need: local and private addresses are refused. */
	function wp_http_validate_url( $url ) { // phpcs:ignore
		$host = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( 'localhost' === $host || 1 === preg_match( '/^(10\.|127\.|192\.168\.)/', $host ) ) {
			return false;
		}
		return $url;
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) { // phpcs:ignore
		$GLOBALS['estm_http_calls'][] = 'open ' . $url;
		return new WP_Error( 'http_request_failed', 'stub-open' );
	}
}
if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args = array() ) { // phpcs:ignore
		$GLOBALS['estm_http_calls'][] = 'safe ' . $url;
		return new WP_Error( 'http_request_failed', 'stub-safe' );
	}
}
