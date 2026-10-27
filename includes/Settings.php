<?php
/**
 * Plugin settings.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Reads, validates and stores the plugin settings in a single option.
 */
class Settings {

	const OPTION = 'estasoft_migrate_settings';

	/**
	 * Runner choices, in the order they are shown on the Settings screen.
	 */
	const RUNNERS = array( 'auto', 'ajax', 'cron', 'action_scheduler', 'cli' );

	/**
	 * Cached values.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'accept_incoming'       => false,
			'connection_key'        => '',
			'previous_key'          => '',
			'previous_key_until'    => 0,
			'require_https'         => true,
			'ip_allowlist'          => '',
			'runner'                => 'auto',
			'max_batch'             => 20000,
			'slice_seconds'         => 15,
			'encrypt_packages'      => false,
			'delete_packages_days'  => 30,
			'keep_snapshots'        => 2,
			'delete_snapshots_days' => 30,
			'sanitizer'             => array(),
			'delete_on_uninstall'   => false,
			'storage_token'         => '',
			'db_version'            => 0,
		);
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$this->cache = array_merge( self::defaults(), $stored );
		}
		return $this->cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback when the name is unknown.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Validate and store new values. Unknown keys are ignored.
	 *
	 * @param array $input Raw values.
	 * @return array The settings after saving.
	 */
	public function update( array $input ) {
		$current = $this->all();
		$clean   = self::sanitize( $input, $current );
		$merged  = array_merge( $current, $clean );
		update_option( self::OPTION, $merged, false );
		$this->cache = $merged;
		return $merged;
	}

	/**
	 * Forget the cached copy (used after the options table was swapped).
	 *
	 * @return void
	 */
	public function flush() {
		$this->cache = null;
	}

	/**
	 * Validate user input.
	 *
	 * @param array $input   Raw values.
	 * @param array $current Current settings.
	 * @return array Only the keys that were present in $input and valid.
	 */
	public static function sanitize( array $input, array $current ) {
		$out = array();

		foreach ( array( 'accept_incoming', 'require_https', 'encrypt_packages', 'delete_on_uninstall' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$out[ $flag ] = filter_var( $input[ $flag ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		if ( array_key_exists( 'runner', $input ) ) {
			$runner        = is_string( $input['runner'] ) ? $input['runner'] : '';
			$out['runner'] = in_array( $runner, self::RUNNERS, true ) ? $runner : 'auto';
		}

		if ( array_key_exists( 'max_batch', $input ) ) {
			$out['max_batch'] = max( 100, min( 100000, (int) $input['max_batch'] ) );
		}
		if ( array_key_exists( 'slice_seconds', $input ) ) {
			$out['slice_seconds'] = max( 3, min( 60, (int) $input['slice_seconds'] ) );
		}
		if ( array_key_exists( 'delete_packages_days', $input ) ) {
			$out['delete_packages_days'] = max( 0, min( 3650, (int) $input['delete_packages_days'] ) );
		}
		if ( array_key_exists( 'keep_snapshots', $input ) ) {
			$out['keep_snapshots'] = max( 1, min( 20, (int) $input['keep_snapshots'] ) );
		}
		if ( array_key_exists( 'delete_snapshots_days', $input ) ) {
			$out['delete_snapshots_days'] = max( 0, min( 3650, (int) $input['delete_snapshots_days'] ) );
		}

		if ( array_key_exists( 'ip_allowlist', $input ) ) {
			$out['ip_allowlist'] = self::sanitize_ip_list( (string) $input['ip_allowlist'] );
		}

		return $out;
	}

	/**
	 * Keep only valid IPv4/IPv6 addresses and CIDR ranges, one per line.
	 *
	 * @param string $raw Text from the settings screen.
	 * @return string
	 */
	public static function sanitize_ip_list( $raw ) {
		$lines = preg_split( '/[\s,]+/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		$keep  = array();
		foreach ( (array) $lines as $line ) {
			if ( Remote\IpMatcher::is_valid_entry( $line ) ) {
				$keep[] = $line;
			}
		}
		return implode( "\n", array_unique( $keep ) );
	}

	/**
	 * Saved sanitizer settings, completed with defaults.
	 *
	 * @return array
	 */
	public function sanitizer() {
		$saved = (array) $this->get( 'sanitizer', array() );
		return Sanitizer\SavedConfig::clean( $saved ? $saved : array() );
	}

	/**
	 * Save the sanitizer settings.
	 *
	 * @param array $input Values from the Sanitizer screen.
	 * @return array What was stored.
	 */
	public function save_sanitizer( array $input ) {
		$clean = Sanitizer\SavedConfig::clean( $input );
		$this->update_raw( array( 'sanitizer' => $clean ) );
		return $clean;
	}

	/**
	 * The connection key, created on first use.
	 *
	 * @return string
	 */
	public function connection_key() {
		$key = (string) $this->get( 'connection_key', '' );
		if ( '' === $key ) {
			$key = self::generate_key();
			$this->update_raw( array( 'connection_key' => $key ) );
		}
		return $key;
	}

	/**
	 * Create a new connection key. The old key keeps working for a short grace period.
	 *
	 * @return string The new key.
	 */
	public function regenerate_key() {
		$old = (string) $this->get( 'connection_key', '' );
		$new = self::generate_key();
		$this->update_raw(
			array(
				'connection_key'     => $new,
				'previous_key'       => $old,
				'previous_key_until' => '' === $old ? 0 : time() + 600,
			)
		);
		return $new;
	}

	/**
	 * Keys that currently open the connection (the active key, plus the previous one during its grace period).
	 *
	 * @return string[]
	 */
	public function valid_keys() {
		$keys = array( $this->connection_key() );
		$prev = (string) $this->get( 'previous_key', '' );
		if ( '' !== $prev && (int) $this->get( 'previous_key_until', 0 ) > time() ) {
			$keys[] = $prev;
		}
		return $keys;
	}

	/**
	 * Write values without validation (internal use only).
	 *
	 * @param array $values Values to merge.
	 * @return void
	 */
	public function update_raw( array $values ) {
		$merged = array_merge( $this->all(), $values );
		update_option( self::OPTION, $merged, false );
		$this->cache = $merged;
	}

	/**
	 * A random connection key.
	 *
	 * @return string
	 */
	public static function generate_key() {
		return 'esm_' . self::base64url( random_bytes( 32 ) );
	}

	/**
	 * Public identifier of a key (safe to send over the wire, reveals nothing about the key).
	 *
	 * @param string $key Connection key.
	 * @return string
	 */
	public static function key_id( $key ) {
		return substr( hash( 'sha256', 'estasoft-migrate:key-id:' . $key ), 0, 16 );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
