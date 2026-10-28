<?php
/**
 * Plugin settings.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || exit;

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
	 * How long "Accept incoming migrations" stays on without a migration, in hours. 0 means it stays on until it is switched off.
	 */
	const INCOMING_HOURS = array( 1, 24, 168, 0 );

	/**
	 * How long a replaced connection key keeps working, in seconds.
	 */
	const KEY_GRACE = 600;

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
			'accept_hours'          => 24,
			'accept_until'          => 0,
			'accept_ended'          => 0,
			'allow_private_hosts'   => false,
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

		foreach ( array( 'accept_incoming', 'require_https', 'allow_private_hosts', 'encrypt_packages', 'delete_on_uninstall' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$out[ $flag ] = filter_var( $input[ $flag ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		if ( array_key_exists( 'accept_hours', $input ) ) {
			// Anything that is not a number counts as no answer (24 hours), never as 0, which would mean "stay on".
			$hours               = is_numeric( $input['accept_hours'] ) ? (int) $input['accept_hours'] : 24;
			$out['accept_hours'] = in_array( $hours, self::INCOMING_HOURS, true ) ? $hours : 24;
		}
		// Switching incoming migrations on (or changing how long they stay on) starts the clock again; switching them off stops it.
		if ( array_key_exists( 'accept_incoming', $input ) || array_key_exists( 'accept_hours', $input ) ) {
			$on    = array_key_exists( 'accept_incoming', $out ) ? $out['accept_incoming'] : ! empty( $current['accept_incoming'] );
			$hours = array_key_exists( 'accept_hours', $out ) ? $out['accept_hours'] : (int) ( $current['accept_hours'] ?? 24 );

			$out['accept_until'] = $on && $hours > 0 ? time() + $hours * 3600 : 0;
			if ( $on ) {
				$out['accept_ended'] = 0;
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
	 * Create a new connection key.
	 *
	 * @param bool $grace Keep the old key working for a short while (so the new key can be pasted into the other sites), or stop it at once.
	 * @return string The new key.
	 */
	public function regenerate_key( $grace = true ) {
		$old = (string) $this->get( 'connection_key', '' );
		$new = self::generate_key();
		$this->update_raw(
			array(
				'connection_key'     => $new,
				'previous_key'       => $grace ? $old : '',
				'previous_key_until' => $grace && '' !== $old ? time() + self::KEY_GRACE : 0,
			)
		);
		return $new;
	}

	/**
	 * Stop the previous key now, without waiting for its grace period to end.
	 *
	 * @return void
	 */
	public function revoke_previous_key() {
		$this->update_raw(
			array(
				'previous_key'       => '',
				'previous_key_until' => 0,
			)
		);
	}

	/**
	 * Is this site accepting migrations right now? Incoming migrations that were switched on for a limited time
	 * switch themselves off here, once that time has passed without a migration.
	 *
	 * @return bool
	 */
	public function incoming_open() {
		if ( ! $this->get( 'accept_incoming', false ) ) {
			return false;
		}
		$until = (int) $this->get( 'accept_until', 0 );
		if ( $until > 0 && $until <= time() ) {
			$this->update_raw(
				array(
					'accept_incoming' => false,
					'accept_until'    => 0,
					'accept_ended'    => $until,
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * A request from another site was accepted: push the switch-off time forward, so a migration that is running is never cut off,
	 * and the switch-off only follows a period without any. Written at most once in half a period.
	 *
	 * @return void
	 */
	public function keep_incoming_open() {
		$hours = (int) $this->get( 'accept_hours', 24 );
		if ( $hours <= 0 || ! $this->get( 'accept_incoming', false ) ) {
			return;
		}
		$window = $hours * 3600;
		if ( (int) $this->get( 'accept_until', 0 ) - time() < $window / 2 ) {
			$this->update_raw( array( 'accept_until' => time() + $window ) );
		}
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
