<?php
/**
 * Downloads of packages and logs. They stream a file, so they are admin-post handlers, not REST routes.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Admin;

use Estasoft\Migrate\Plugin;
use Estasoft\Migrate\Rest\ApiException;
use Estasoft\Migrate\Rest\Router;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Both handlers check the capability and the nonce before they send anything.
 */
class Downloads {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Stop with a readable page unless the person may download.
	 *
	 * @return void
	 */
	private function authorize() {
		if ( ! current_user_can( Router::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to download this.', 'estasoft-migrate' ), 403 );
		}
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'estm_download' ) ) {
			wp_die( esc_html__( 'This download link has expired. Go back, reload the page and try again.', 'estasoft-migrate' ), 403 );
		}
	}

	/**
	 * Send a package file. Supports resuming (HTTP range) because packages can be large.
	 *
	 * @return void
	 */
	public function package() {
		$this->authorize();
		$id = isset( $_GET['package'] ) ? sanitize_text_field( wp_unslash( $_GET['package'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		try {
			$info = $this->plugin->api()->download_info( $id );
		} catch ( ApiException $e ) {
			wp_die( esc_html( $e->getMessage() ), esc_html__( 'Download not available', 'estasoft-migrate' ), array( 'response' => (int) $e->status ) );
		}
		self::stream_file( $info['path'], $info['name'], 'application/octet-stream' );
	}

	/**
	 * Send the log as a text file.
	 *
	 * @return void
	 */
	public function log() {
		$this->authorize();
		$job  = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$text = $this->plugin->api()->logs_text( '' === $job ? null : (int) $job );
		$name = '' === $job ? 'estasoft-migrate-log.txt' : 'estasoft-migrate-job-' . (int) $job . '-log.txt';
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $text ) );
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text attachment.
		exit;
	}

	/**
	 * Parse a Range header for one range.
	 *
	 * @param string $header Header value.
	 * @param int    $size   File size.
	 * @return array|null|false [start, end] or null when absent; false when it cannot be satisfied.
	 */
	public static function parse_range( $header, $size ) {
		if ( '' === $header ) {
			return null;
		}
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return null; // Not a single range: send the whole file.
		}
		if ( '' === $m[1] ) {
			$length = (int) $m[2];
			if ( $length < 1 ) {
				return false;
			}
			return array( max( 0, $size - $length ), $size - 1 );
		}
		$start = (int) $m[1];
		$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
		if ( $start >= $size || $start > $end ) {
			return false;
		}
		return array( $start, $end );
	}

	/**
	 * Stream a file in small pieces.
	 *
	 * @param string $path File path.
	 * @param string $name Name offered to the browser.
	 * @param string $type Content type.
	 * @return void
	 */
	private static function stream_file( $path, $name, $type ) {
		$size = (int) filesize( $path );
		$http = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$span = self::parse_range( $http, $size );

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Accept-Ranges: bytes' );

		if ( false === $span ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}
		$start = 0;
		$end   = $size - 1;
		if ( is_array( $span ) ) {
			list( $start, $end ) = $span;
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions
		@set_time_limit( 0 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			exit;
		}
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) && 0 === connection_aborted() ) {
			$chunk = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file.
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
