<?php
/**
 * Protected folder for packages, snapshots and uploads.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the storage directory and the small JSON file kept next to every package.
 *
 * Files live in wp-content/uploads/estasoft-migrate-<random>/. The folder name is random,
 * direct downloads are blocked with .htaccess, web.config and an index file, and downloads
 * go through a capability-checked PHP handler.
 */
class Storage {

	const EXT      = 'estm';
	const META_EXT = 'estm.json';

	/**
	 * Absolute directory, set explicitly in tests.
	 *
	 * @var string|null
	 */
	private $dir = null;

	/**
	 * Settings service.
	 *
	 * @var Settings|null
	 */
	private $settings;

	/**
	 * Web address of the storage folder, for tests. Empty: worked out from the uploads address.
	 *
	 * @var string
	 */
	public $public_url = '';

	/**
	 * Fetches an address and returns array( 'code' => int, 'body' => string ), or null when that is not possible. Tests replace it.
	 *
	 * @var callable|null
	 */
	public $http = null;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings, null in tests.
	 * @param string|null   $dir      Fixed directory, for tests.
	 */
	public function __construct( ?Settings $settings = null, $dir = null ) {
		$this->settings = $settings;
		$this->dir      = $dir ? rtrim( $dir, '/\\' ) : null;
	}

	/**
	 * Absolute path of the storage directory (created and protected on first use).
	 *
	 * @return string
	 */
	public function dir() {
		if ( null === $this->dir ) {
			$token = $this->settings ? (string) $this->settings->get( 'storage_token', '' ) : '';
			if ( '' === $token ) {
				$token = strtolower( wp_generate_password( 12, false, false ) );
				if ( $this->settings ) {
					$this->settings->update_raw( array( 'storage_token' => $token ) );
				}
			}
			$uploads   = wp_upload_dir( null, false );
			$this->dir = rtrim( $uploads['basedir'], '/\\' ) . '/estasoft-migrate-' . $token;
		}
		$this->protect();
		return $this->dir;
	}

	/**
	 * Create the folder and its guard files.
	 *
	 * @return void
	 */
	private function protect() {
		if ( ! is_dir( $this->dir ) ) {
			wp_mkdir_p( $this->dir );
		}
		if ( ! is_dir( $this->dir ) ) {
			return;
		}
		$guards = array(
			'.htaccess'  => "# Estasoft Migrate: block direct downloads.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $guards as $name => $content ) {
			$file = $this->dir . '/' . $name;
			if ( ! file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $file, $content );
			}
		}
	}

	/**
	 * Largest package this site agrees to receive.
	 *
	 * @return int Bytes.
	 */
	public static function max_package_bytes() {
		$max = 64 * 1024 * 1024 * 1024;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Largest package, in bytes, that this site accepts from the browser or from another site.
			 *
			 * @param int $max Bytes. The default is 64 GB.
			 */
			$max = (int) apply_filters( 'estasoft_migrate_max_package_size', $max );
		}
		return max( 1048576, $max );
	}

	/**
	 * Can a file in the package folder be fetched by its address, without signing in?
	 *
	 * Guard files only help when the web server reads them (nginx never reads .htaccess), so the folder is tested the way
	 * a stranger would reach it: a small file with a random name is requested over HTTP, then removed. A good answer is kept
	 * for hours; a bad one for ten minutes, so a person who has just fixed the server does not wait to see it.
	 *
	 * @param bool $fresh Ignore the kept answer.
	 * @param bool $probe Make the request when there is no kept answer. False reads the kept answer only.
	 * @return bool|null True when the file could not be fetched, false when it could, null when this could not be tested.
	 */
	public function direct_access_blocked( $fresh = false, $probe = true ) {
		$tag = md5( $this->dir() );
		if ( ! $fresh && function_exists( 'get_transient' ) ) {
			$kept = get_transient( 'estm_direct_access' );
			if ( is_array( $kept ) && ( $kept['tag'] ?? '' ) === $tag && array_key_exists( 'blocked', $kept ) ) {
				return $kept['blocked'];
			}
		}
		if ( ! $probe && ! $fresh ) {
			return null;
		}
		$blocked = $this->probe_direct_access();
		if ( function_exists( 'set_transient' ) ) {
			set_transient(
				'estm_direct_access',
				array(
					'tag'     => $tag,
					'blocked' => $blocked,
				),
				true === $blocked ? 6 * HOUR_IN_SECONDS : ( null === $blocked ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS )
			);
		}
		return $blocked;
	}

	/**
	 * Request a throw-away file from the storage folder.
	 *
	 * @return bool|null See direct_access_blocked().
	 */
	private function probe_direct_access() {
		$base = $this->base_url();
		if ( '' === $base || ! $this->is_writable() ) {
			return null;
		}
		$name = 'probe-' . strtolower( wp_generate_password( 16, false, false ) ) . '.txt';
		$body = strtolower( wp_generate_password( 24, false, false ) );
		$file = $this->dir() . '/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file, $body ) ) {
			return null;
		}
		try {
			$reply = $this->http ? call_user_func( $this->http, $base . '/' . $name ) : self::fetch( $base . '/' . $name );
		} finally {
			wp_delete_file( $file );
		}
		if ( ! is_array( $reply ) || ! isset( $reply['code'] ) ) {
			return null;
		}
		$code = (int) $reply['code'];
		if ( $code < 100 || $code >= 500 ) {
			return null; // The server failed; that says nothing about the folder.
		}
		return ! ( 200 === $code && false !== strpos( (string) ( $reply['body'] ?? '' ), $body ) );
	}

	/**
	 * Web address of the storage folder.
	 *
	 * @return string
	 */
	private function base_url() {
		if ( '' !== $this->public_url ) {
			return rtrim( $this->public_url, '/' );
		}
		if ( ! function_exists( 'wp_upload_dir' ) || null !== $this->dir && null === $this->settings ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		return empty( $uploads['baseurl'] ) ? '' : rtrim( $uploads['baseurl'], '/' ) . '/' . basename( $this->dir() );
	}

	/**
	 * Fetch an address from this server, without following redirects.
	 *
	 * @param string $url Address.
	 * @return array|null
	 */
	private static function fetch( $url ) {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}
		$reply = wp_remote_get(
			$url,
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => 4096,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A core filter.
			)
		);
		if ( is_wp_error( $reply ) ) {
			return null;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $reply ),
			'body' => (string) wp_remote_retrieve_body( $reply ),
		);
	}

	/**
	 * Is the folder writable?
	 *
	 * @return bool
	 */
	public function is_writable() {
		$dir = $this->dir();
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * Free disk space in bytes, or null if unknown.
	 *
	 * @return int|null
	 */
	public function free_space() {
		$dir = $this->dir();
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $free ? null : (int) $free;
	}

	/**
	 * Turn text into a safe file name part.
	 *
	 * @param string $text Any text.
	 * @return string
	 */
	public static function slug( $text ) {
		$text = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $text ) );
		$text = trim( $text, '-' );
		return '' === $text ? 'package' : substr( $text, 0, 40 );
	}

	/**
	 * A new package id: date, label and a random part.
	 *
	 * @param string $label Short label.
	 * @return string
	 */
	public static function new_id( $label ) {
		return gmdate( 'Ymd-His' ) . '-' . self::slug( $label ) . '-' . bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Validate a package id taken from a request.
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{5,120}$/', $id ) && false === strpos( $id, '..' );
	}

	/**
	 * Path of a package file.
	 *
	 * @param string $id Package id.
	 * @return string
	 */
	public function package_path( $id ) {
		return $this->dir() . '/' . $id . '.' . self::EXT;
	}

	/**
	 * Path of the JSON file kept next to a package.
	 *
	 * @param string $id Package id.
	 * @return string
	 */
	public function meta_path( $id ) {
		return $this->dir() . '/' . $id . '.' . self::META_EXT;
	}

	/**
	 * Path for a temporary upload.
	 *
	 * @param string $id Upload id.
	 * @return string
	 */
	public function part_path( $id ) {
		return $this->dir() . '/' . $id . '.part';
	}

	/**
	 * Read package details.
	 *
	 * @param string $id Package id.
	 * @return array|null
	 */
	public function read_meta( $id ) {
		if ( ! self::is_valid_id( $id ) ) {
			return null;
		}
		$file = $this->meta_path( $id );
		if ( ! is_readable( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Store package details.
	 *
	 * @param string $id   Package id.
	 * @param array  $meta Details.
	 * @return void
	 */
	public function write_meta( $id, array $meta ) {
		$meta['id'] = $id;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->meta_path( $id ), wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Merge new details into the stored ones.
	 *
	 * @param string $id    Package id.
	 * @param array  $merge Values to set.
	 * @return array The merged details.
	 */
	public function update_meta( $id, array $merge ) {
		$meta = $this->read_meta( $id );
		$meta = is_array( $meta ) ? array_merge( $meta, $merge ) : $merge;
		$this->write_meta( $id, $meta );
		return $meta;
	}

	/**
	 * All packages, newest first.
	 *
	 * @param string|null $type Filter: export, snapshot, received. Null for all.
	 * @return array[]
	 */
	public function list_packages( $type = null ) {
		$result = array();
		$files  = glob( $this->dir() . '/*.' . self::META_EXT );
		foreach ( (array) $files as $file ) {
			$id   = basename( $file, '.' . self::META_EXT );
			$meta = $this->read_meta( $id );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			if ( null !== $type && ( $meta['type'] ?? '' ) !== $type ) {
				continue;
			}
			$path = $this->package_path( $id );
			if ( ! is_file( $path ) ) {
				continue;
			}
			$meta['id']   = $id;
			$meta['size'] = (int) filesize( $path );
			$result[]     = $meta;
		}
		usort(
			$result,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['created_gmt'] ?? '' ), (string) ( $a['created_gmt'] ?? '' ) );
			}
		);
		return $result;
	}

	/**
	 * Total size of packages of one type, in bytes.
	 *
	 * @param string|null $type Type filter.
	 * @return int
	 */
	public function total_size( $type = null ) {
		$sum = 0;
		foreach ( $this->list_packages( $type ) as $pkg ) {
			$sum += (int) $pkg['size'];
		}
		return $sum;
	}

	/**
	 * Delete a package and its details file.
	 *
	 * @param string $id Package id.
	 * @return bool
	 */
	public function delete( $id ) {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}
		$ok = true;
		foreach ( array( $this->package_path( $id ), $this->meta_path( $id ), $this->part_path( $id ) ) as $file ) {
			if ( file_exists( $file ) ) {
				$ok = wp_delete_file_from_directory( $file, $this->dir() ) && $ok;
			}
		}
		return $ok;
	}

	/**
	 * Delete packages and snapshots that are older or beyond the keep limits.
	 *
	 * @param int $days           Delete exports older than this many days (0 = keep).
	 * @param int $keep_snapshots Most snapshots to keep (the newest ones).
	 * @param int $snapshot_days  Also delete snapshots older than this many days (0 = keep). The newest one always stays.
	 * @return int Number of removed items.
	 */
	public function clean_up( $days, $keep_snapshots, $snapshot_days = 0 ) {
		$removed = 0;
		$cut     = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '';
		foreach ( $this->list_packages( 'export' ) as $pkg ) {
			if ( '' !== $cut && (string) ( $pkg['created_gmt'] ?? '' ) < $cut ) {
				$removed += $this->delete( $pkg['id'] ) ? 1 : 0;
			}
		}
		foreach ( $this->list_packages( 'received' ) as $pkg ) {
			if ( '' !== $cut && (string) ( $pkg['created_gmt'] ?? '' ) < $cut ) {
				$removed += $this->delete( $pkg['id'] ) ? 1 : 0;
			}
		}
		$snaps = $this->list_packages( 'snapshot' );
		foreach ( array_slice( $snaps, max( 1, (int) $keep_snapshots ) ) as $pkg ) {
			$removed += $this->delete( $pkg['id'] ) ? 1 : 0;
		}
		if ( $snapshot_days > 0 ) {
			$snap_cut = gmdate( 'Y-m-d H:i:s', time() - $snapshot_days * DAY_IN_SECONDS );
			foreach ( array_slice( array_slice( $snaps, 0, max( 1, (int) $keep_snapshots ) ), 1 ) as $pkg ) {
				if ( (string) ( $pkg['created_gmt'] ?? '' ) < $snap_cut ) {
					$removed += $this->delete( $pkg['id'] ) ? 1 : 0;
				}
			}
		}
		// Abandoned upload parts older than a day.
		foreach ( (array) glob( $this->dir() . '/*.part' ) as $part ) {
			if ( filemtime( $part ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file_from_directory( $part, $this->dir() );
			}
		}
		return $removed;
	}

	/**
	 * Remove the whole folder (used by uninstall).
	 *
	 * @return void
	 */
	public function destroy() {
		$dir = $this->dir();
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- The folder may hold files this plugin did not make.
		@rmdir( $dir );
	}
}
