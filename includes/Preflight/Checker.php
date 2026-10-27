<?php
/**
 * Checks a migration before it starts and explains every finding in plain language.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Preflight;

use Estasoft\Migrate\Db\Identifier;
use Estasoft\Migrate\Engine\JobConflicts;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\JobParams;
use Estasoft\Migrate\Engine\TableSelector;
use Estasoft\Migrate\Package\Crypto;
use Estasoft\Migrate\Package\PackageException;
use Estasoft\Migrate\Package\PackageReader;
use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Remote\ClientFactory;
use Estasoft\Migrate\Remote\RemoteException;
use Estasoft\Migrate\Storage;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Every check is pass, warn or block. A warning always says what the plugin will do about it; a block
 * says what to change. Nothing here changes anything.
 */
class Checker {

	const PASS  = 'pass';
	const WARN  = 'warn';
	const BLOCK = 'block';

	/**
	 * Services.
	 *
	 * @var JobContext
	 */
	private $ctx;

	/**
	 * Facts about this server (overridable for tests).
	 *
	 * @var array
	 */
	private $env;

	/**
	 * Findings.
	 *
	 * @var array
	 */
	private $checks = array();

	/**
	 * Constructor.
	 *
	 * @param JobContext $ctx Services.
	 * @param array      $env Server facts: php, memory, max_exec, cron_off, sodium, zlib, multisite, runner, db (server_info), free (bytes).
	 */
	public function __construct( JobContext $ctx, array $env = array() ) {
		$this->ctx = $ctx;
		$this->env = array_merge(
			array(
				'php'       => PHP_VERSION,
				'memory'    => Util::bytes_from_ini( ini_get( 'memory_limit' ) ),
				'max_exec'  => (int) ini_get( 'max_execution_time' ),
				'cron_off'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'sodium'    => Crypto::available(),
				'zlib'      => function_exists( 'gzdeflate' ) && function_exists( 'gzinflate' ),
				'multisite' => function_exists( 'is_multisite' ) && is_multisite(),
				'runner'    => 'auto',
				'https'     => function_exists( 'is_ssl' ) ? is_ssl() : true,
				'db'        => null,
				'free'      => null,
			),
			$env
		);
	}

	/**
	 * Run the checks for a planned job.
	 *
	 * @param array $input mode (push, pull, export, import, sanitize, search_replace), tables, exclude_tables,
	 *                     remote_url, remote_key, package_id, snapshot, password, protect_with_site_key, dry_run.
	 * @return array status, counts, checks, summary, safety.
	 */
	public function run( array $input ) {
		$this->checks = array();
		$mode         = (string) ( $input['mode'] ?? 'export' );

		$tables = TableSelector::select( $this->ctx->db, $input );
		$stats  = $this->size( $tables );

		$large  = $tables;
		$remote = null;
		if ( in_array( $mode, array( 'push', 'pull' ), true ) ) {
			$remote = $this->check_remote( $input, $stats, $mode );
			if ( 'pull' === $mode && $remote && ! empty( $remote['stats'] ) ) {
				// Pulling brings the other site's tables here, so their size is what matters.
				$stats = $this->stats( $remote['stats']['tables'] ?? 0, $remote['stats']['rows'] ?? 0, $remote['stats']['bytes'] ?? 0 );
				// The biggest table that will arrive, if the other site says which it is (older versions do not).
				$large = ! empty( $remote['stats']['largest']['name'] ) ? array( $remote['stats']['largest'] ) : array();
			}
		}

		$this->check_php();
		$this->check_database();
		$this->check_multisite();
		$this->check_memory();
		$this->check_time();
		$this->check_storage( $stats, $mode, $input );
		$this->check_extensions( $input );
		$this->check_runner( $mode );
		$this->check_packet();
		$this->check_large_tables( $large );
		$this->check_no_key( $tables, $mode );
		$this->check_other_jobs( $mode, $input );

		if ( 'import' === $mode ) {
			$this->check_package( $input );
		}
		if ( 'search_replace' === $mode ) {
			$this->check_search_replace( $input, $tables );
		}

		return $this->result( $mode, $tables, $stats, $remote, $input );
	}

	/**
	 * Add a finding.
	 *
	 * @param string $status pass, warn or block.
	 * @param string $id     Stable id.
	 * @param string $title  Short title.
	 * @param string $detail What it means and what will happen.
	 * @return void
	 */
	private function add( $status, $id, $title, $detail ) {
		$this->checks[] = array(
			'id'     => $id,
			'status' => $status,
			'title'  => $title,
			'detail' => $detail,
		);
	}

	/**
	 * Row and byte totals of the chosen tables.
	 *
	 * @param array $tables Tables.
	 * @return array
	 */
	private function size( array $tables ) {
		$rows  = 0;
		$bytes = 0;
		foreach ( $tables as $t ) {
			$rows  += (int) $t['rows_est'];
			$bytes += (int) $t['bytes'];
		}
		return $this->stats( count( $tables ), $rows, $bytes );
	}

	/**
	 * Totals in the shape the checks use.
	 *
	 * @param int $tables Number of tables.
	 * @param int $rows   Estimated rows.
	 * @param int $bytes  Data and index bytes.
	 * @return array
	 */
	private function stats( $tables, $rows, $bytes ) {
		return array(
			'tables'  => (int) $tables,
			'rows'    => (int) $rows,
			'data'    => (int) $bytes,
			'bytes'   => (int) $bytes,
			'package' => (int) ( $bytes * 0.5 ),
		);
	}

	/**
	 * Will a rollback snapshot be saved? Yes, unless the person turned it off.
	 *
	 * @param array $input Input.
	 * @return bool
	 */
	private function wants_snapshot( array $input ) {
		return ! isset( $input['snapshot'] ) || JobParams::truthy( $input['snapshot'] );
	}

	/**
	 * "MariaDB 10.11" or "MySQL 8.0.36".
	 *
	 * @param array $info type and version.
	 * @return string
	 */
	private static function db_label( array $info ) {
		$type = (string) ( $info['type'] ?? '' );
		$name = 'mariadb' === $type ? 'MariaDB' : ( 'mysql' === $type ? 'MySQL' : ucfirst( $type ) );
		return trim( $name . ' ' . (string) ( $info['version'] ?? '' ) );
	}

	/**
	 * PHP version.
	 *
	 * @return void
	 */
	private function check_php() {
		if ( version_compare( $this->env['php'], '7.4', '<' ) ) {
			$this->add( self::BLOCK, 'php', __( 'PHP version', 'estasoft-migrate' ), sprintf( /* translators: %s: version */ __( 'This server runs PHP %s. Estasoft Migrate needs PHP 7.4 or newer. Ask your host to switch the site to a newer PHP.', 'estasoft-migrate' ), $this->env['php'] ) );
			return;
		}
		$this->add( self::PASS, 'php', __( 'PHP version', 'estasoft-migrate' ), sprintf( /* translators: %s: version */ __( 'PHP %s on this site.', 'estasoft-migrate' ), $this->env['php'] ) );
	}

	/**
	 * Database server.
	 *
	 * @return void
	 */
	private function check_database() {
		$info = is_array( $this->env['db'] ) ? $this->env['db'] : $this->ctx->db->server_info();
		$name = 'mariadb' === $info['type'] ? 'MariaDB' : ( 'mysql' === $info['type'] ? 'MySQL' : ucfirst( $info['type'] ) );
		if ( ! in_array( $info['type'], array( 'mysql', 'mariadb' ), true ) ) {
			$this->add( self::BLOCK, 'database', __( 'Database server', 'estasoft-migrate' ), __( 'Only MySQL and MariaDB are supported.', 'estasoft-migrate' ) );
			return;
		}
		$old = 'mariadb' === $info['type'] ? version_compare( $info['version'], '10.3', '<' ) : version_compare( $info['version'], '5.7', '<' );
		if ( $old ) {
			$this->add( self::WARN, 'database', __( 'Database server', 'estasoft-migrate' ), sprintf( /* translators: 1: server, 2: version */ __( '%1$s %2$s is old. It should work, but newer versions are better tested.', 'estasoft-migrate' ), $name, $info['version'] ) );
			return;
		}
		$this->add( self::PASS, 'database', __( 'Database server', 'estasoft-migrate' ), sprintf( /* translators: 1: server, 2: version */ __( '%1$s %2$s is supported.', 'estasoft-migrate' ), $name, $info['version'] ) );
	}

	/**
	 * Multisite is not supported.
	 *
	 * @return void
	 */
	private function check_multisite() {
		if ( $this->env['multisite'] ) {
			$this->add( self::BLOCK, 'multisite', __( 'Single site', 'estasoft-migrate' ), __( 'This is a WordPress multisite network. This version migrates single sites only.', 'estasoft-migrate' ) );
		}
	}

	/**
	 * Memory.
	 *
	 * @return void
	 */
	private function check_memory() {
		$mem = (int) $this->env['memory'];
		if ( $mem <= 0 ) {
			$this->add( self::PASS, 'memory', __( 'Memory limit', 'estasoft-migrate' ), __( 'No limit is set.', 'estasoft-migrate' ) );
			return;
		}
		$text = Util::format_bytes( $mem );
		if ( $mem < 64 * 1048576 ) {
			$this->add( self::BLOCK, 'memory', __( 'Memory limit', 'estasoft-migrate' ), sprintf( /* translators: %s: size */ __( 'The memory limit is only %s. At least 64 MB is needed. Raise WP_MEMORY_LIMIT in wp-config.php or ask your host.', 'estasoft-migrate' ), $text ) );
		} elseif ( $mem < 128 * 1048576 ) {
			$this->add( self::WARN, 'memory', __( 'Memory limit', 'estasoft-migrate' ), sprintf( /* translators: %s: size */ __( 'The memory limit is %s. Estasoft Migrate will use smaller batches. 128 MB or more is faster.', 'estasoft-migrate' ), $text ) );
		} else {
			$this->add( self::PASS, 'memory', __( 'Memory limit', 'estasoft-migrate' ), sprintf( /* translators: %s: size */ __( '%s. At least 128 MB is recommended.', 'estasoft-migrate' ), $text ) );
		}
	}

	/**
	 * Execution time.
	 *
	 * @return void
	 */
	private function check_time() {
		$sec = (int) $this->env['max_exec'];
		if ( 0 === $sec || $sec >= 120 ) {
			$this->add( self::PASS, 'time', __( 'Maximum execution time', 'estasoft-migrate' ), 0 === $sec ? __( 'No limit.', 'estasoft-migrate' ) : sprintf( /* translators: %d: seconds */ __( '%d seconds.', 'estasoft-migrate' ), $sec ) );
			return;
		}
		$this->add(
			self::WARN,
			'time',
			sprintf( /* translators: %d: seconds */ __( 'Maximum execution time is %d seconds', 'estasoft-migrate' ), $sec ),
			__( 'Estasoft Migrate will use small batches that finish inside the limit and resume on their own. The run takes a little longer.', 'estasoft-migrate' )
		);
	}

	/**
	 * Package folder and disk space.
	 *
	 * @param array  $stats Size totals.
	 * @param string $mode  Mode.
	 * @param array  $input Input.
	 * @return void
	 */
	private function check_storage( array $stats, $mode, array $input ) {
		$storage = $this->ctx->storage;
		if ( ! $storage->is_writable() ) {
			$this->add( self::BLOCK, 'storage', __( 'Package folder', 'estasoft-migrate' ), __( 'The folder for packages is not writable. Make wp-content/uploads writable by the web server.', 'estasoft-migrate' ) );
			return;
		}
		$dir       = $storage->dir();
		$protected = is_file( $dir . '/index.php' ) && ( is_file( $dir . '/.htaccess' ) || is_file( $dir . '/web.config' ) );
		$this->add(
			$protected ? self::PASS : self::WARN,
			'storage',
			__( 'Package folder is writable and protected', 'estasoft-migrate' ),
			$protected
				? __( 'Direct downloads are blocked; packages are served only to signed-in administrators.', 'estasoft-migrate' )
				: __( 'The folder could not be fully protected. On nginx, deny access to the folder in the server settings.', 'estasoft-migrate' )
		);

		$free = null !== $this->env['free'] ? (int) $this->env['free'] : $storage->free_space();
		if ( null === $free || $free <= 0 ) {
			return;
		}
		$need     = (int) $stats['package'];
		$snapshot = in_array( $mode, array( 'import', 'pull', 'search_replace' ), true ) && $this->wants_snapshot( $input ) ? (int) $stats['package'] : 0;
		if ( 'search_replace' === $mode && ! empty( $input['dry_run'] ) ) {
			$need     = 0;
			$snapshot = 0;
		}
		if ( 'import' === $mode ) {
			$need = 0;
		}
		$total = $need + $snapshot;
		if ( $free < $total * 1.1 ) {
			$this->add( self::BLOCK, 'disk', __( 'Free disk space', 'estasoft-migrate' ), sprintf( /* translators: 1: free, 2: needed */ __( 'Only %1$s is free, and about %2$s is needed for the package and the rollback snapshot. Free some space or exclude large tables.', 'estasoft-migrate' ), Util::format_bytes( $free ), Util::format_bytes( $total ) ) );
		} elseif ( $free < $total * 2 ) {
			$this->add( self::WARN, 'disk', __( 'Free disk space is tight', 'estasoft-migrate' ), sprintf( /* translators: 1: free, 2: needed */ __( '%1$s free; about %2$s is needed. It should fit, but there is little to spare.', 'estasoft-migrate' ), Util::format_bytes( $free ), Util::format_bytes( $total ) ) );
		} else {
			$this->add( self::PASS, 'disk', __( 'Free disk space', 'estasoft-migrate' ), sprintf( /* translators: 1: free, 2: needed */ __( '%1$s free here. The run needs about %2$s, including the snapshot.', 'estasoft-migrate' ), Util::format_bytes( $free ), Util::format_bytes( $total ) ) );
		}
	}

	/**
	 * Sodium and zlib.
	 *
	 * @param array $input Input.
	 * @return void
	 */
	private function check_extensions( array $input ) {
		$wants_enc = ! empty( $input['password'] ) || ! empty( $input['passphrase'] ) || ! empty( $input['protect_with_site_key'] );
		if ( ! $this->env['sodium'] ) {
			$this->add(
				$wants_enc ? self::BLOCK : self::WARN,
				'sodium',
				__( 'Encryption', 'estasoft-migrate' ),
				$wants_enc
					? __( 'Password protection needs the PHP sodium extension, which this server does not have. Ask your host to enable it, or continue without a password.', 'estasoft-migrate' )
					: __( 'The PHP sodium extension is missing, so packages cannot be encrypted. Snapshots and packages are stored unencrypted in the protected folder.', 'estasoft-migrate' )
			);
		}
		if ( ! $this->env['zlib'] ) {
			$this->add( self::WARN, 'zlib', __( 'Compression', 'estasoft-migrate' ), __( 'PHP zlib is missing, so packages are not compressed and will be larger.', 'estasoft-migrate' ) );
		}
	}

	/**
	 * Background runner.
	 *
	 * @param string $mode Mode.
	 * @return void
	 */
	private function check_runner( $mode ) {
		if ( ! in_array( $mode, array( 'push', 'pull', 'import', 'export', 'sanitize' ), true ) ) {
			return;
		}
		$runner = (string) $this->env['runner'];
		if ( $this->env['cron_off'] && in_array( $runner, array( 'auto', 'cron' ), true ) && ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->add( self::WARN, 'cron', __( 'WP-Cron is switched off', 'estasoft-migrate' ), __( 'The job keeps running while the Progress screen is open. To let it continue on its own, enable WP-Cron, install Action Scheduler, or run it with WP-CLI.', 'estasoft-migrate' ) );
		}
	}

	/**
	 * Packet size.
	 *
	 * @return void
	 */
	private function check_packet() {
		$packet = $this->ctx->db->max_allowed_packet();
		if ( $packet < 1048576 ) {
			$this->add( self::WARN, 'packet', __( 'Database packet size is small', 'estasoft-migrate' ), sprintf( /* translators: %s: size */ __( 'max_allowed_packet is %s. Rows are written in smaller groups, which is slower. Rows larger than this cannot be copied.', 'estasoft-migrate' ), Util::format_bytes( $packet ) ) );
		}
	}

	/**
	 * Large tables, informational.
	 *
	 * @param array $tables Tables.
	 * @return void
	 */
	private function check_large_tables( array $tables ) {
		usort(
			$tables,
			static function ( $a, $b ) {
				return $b['rows_est'] <=> $a['rows_est'];
			}
		);
		$top = $tables ? $tables[0] : null;
		if ( ! $top || $top['rows_est'] < 50000 ) {
			return;
		}
		$chunk = max( 100, min( (int) $this->ctx->max_batch, 20000 ) );
		$this->add(
			self::PASS,
			'large',
			__( 'Large tables', 'estasoft-migrate' ),
			sprintf(
				/* translators: 1: table, 2: row count, 3: chunk count, 4: chunk size */
				__( '%1$s has about %2$s rows. It will be copied in about %3$s chunks of up to %4$s rows, so no single request runs for long.', 'estasoft-migrate' ),
				$top['name'],
				Util::number( $top['rows_est'] ),
				Util::number( (int) ceil( $top['rows_est'] / $chunk ) ),
				Util::number( $chunk )
			)
		);
	}

	/**
	 * Tables that cannot be read in key order.
	 *
	 * @param array  $tables Tables.
	 * @param string $mode   Mode.
	 * @return void
	 */
	private function check_no_key( array $tables, $mode ) {
		$none = array();
		foreach ( $tables as $t ) {
			if ( ! $this->ctx->db->primary_key( $t['name'] ) ) {
				$none[] = $t['name'];
			}
		}
		if ( ! $none ) {
			return;
		}
		$list = implode( ', ', array_slice( $none, 0, 5 ) ) . ( count( $none ) > 5 ? '…' : '' );
		$this->add(
			self::WARN,
			'nokey',
			sprintf( /* translators: %d: number of tables */ _n( '%d table has no primary key', '%d tables have no primary key', count( $none ), 'estasoft-migrate' ), count( $none ) ),
			'search_replace' === $mode
				? sprintf( /* translators: %s: table names */ __( 'Rows cannot be updated safely without a key, so these tables are skipped: %s', 'estasoft-migrate' ), $list )
				: sprintf( /* translators: %s: table names */ __( 'They are copied in order with a slower method and are fine to migrate: %s', 'estasoft-migrate' ), $list )
		);
	}

	/**
	 * Other running jobs.
	 *
	 * @param string $mode  Mode.
	 * @param array  $input Input.
	 * @return void
	 */
	private function check_other_jobs( $mode, array $input ) {
		$type   = in_array( $mode, array( 'sanitize' ), true ) ? 'export' : $mode;
		$params = array( 'dry_run' => ! isset( $input['dry_run'] ) || (bool) $input['dry_run'] );
		$job    = JobConflicts::find( $this->ctx->store->active(), $type, $params );
		if ( $job ) {
			$this->add(
				self::BLOCK,
				'busy',
				__( 'Another job is running', 'estasoft-migrate' ),
				sprintf( /* translators: %s: job title */ __( 'Wait for it to finish, or cancel it: %s', 'estasoft-migrate' ), $job->title )
			);
			return;
		}
		$this->add( self::PASS, 'busy', __( 'Nothing else is running', 'estasoft-migrate' ), __( 'No other migration or search and replace job is active.', 'estasoft-migrate' ) );
	}

	/**
	 * Checks that need the other site.
	 *
	 * @param array  $input Input.
	 * @param array  $stats Size totals.
	 * @param string $mode  push or pull.
	 * @return array|null Facts from the other site.
	 */
	private function check_remote( array $input, array $stats, $mode ) {
		$url = rtrim( trim( (string) ( $input['remote_url'] ?? '' ) ), '/' );
		$key = trim( (string) ( $input['remote_key'] ?? '' ) );
		if ( '' === $key && ! empty( $input['key_enc'] ) ) {
			$key = (string) Secrets::open( (string) $input['key_enc'], $this->ctx->secret );
		}
		if ( '' === $url || '' === $key ) {
			$this->add( self::BLOCK, 'connection', __( 'Connection to the other site', 'estasoft-migrate' ), __( 'Enter the address and the connection key of the other site.', 'estasoft-migrate' ) );
			return null;
		}

		$hello = null;
		try {
			$before = microtime( true );
			$hello  = ClientFactory::make( $this->ctx, $url, $key )->call( 'hello', array(), 20, 1 );
			$took   = microtime( true ) - $before;
		} catch ( RemoteException $e ) {
			$this->add( self::BLOCK, 'connection', __( 'Connection to the other site', 'estasoft-migrate' ), $e->getMessage() );
			return null;
		}

		$site  = (array) ( $hello['site'] ?? array() );
		$skew  = abs( time() - (int) ( $hello['time'] ?? time() ) );
		$https = 0 === strpos( strtolower( $url ), 'https://' );
		$this->add(
			$https ? self::PASS : self::WARN,
			'connection',
			$https ? __( 'Secure connection', 'estasoft-migrate' ) : __( 'The connection is not secure', 'estasoft-migrate' ),
			$https
				? sprintf( /* translators: 1: seconds of clock difference, 2: milliseconds */ __( 'HTTPS. Connection key accepted, clock difference %1$d seconds, answered in %2$d ms.', 'estasoft-migrate' ), $skew, (int) ( $took * 1000 ) )
				: __( 'The address starts with http://. Data is signed and packages are encrypted, but use https:// if the other site has it.', 'estasoft-migrate' )
		);
		if ( $skew > 60 ) {
			$this->add( self::WARN, 'clock', __( 'Clocks differ', 'estasoft-migrate' ), sprintf( /* translators: %d: seconds */ __( 'The two sites differ by %d seconds. Requests are refused above five minutes, so set the time correctly on one of them.', 'estasoft-migrate' ), $skew ) );
		}

		if ( ! empty( $site['multisite'] ) ) {
			$this->add( self::BLOCK, 'remote_multisite', __( 'Other site is a multisite network', 'estasoft-migrate' ), __( 'This version migrates single sites only.', 'estasoft-migrate' ) );
		}
		if ( empty( $hello['writable'] ) ) {
			$this->add( self::BLOCK, 'remote_storage', __( 'Other site cannot store packages', 'estasoft-migrate' ), __( 'Its uploads folder is not writable.', 'estasoft-migrate' ) );
		}

		$mine = $this->ctx->site_info();
		$a    = (string) ( $mine['php_version'] ?? $this->env['php'] );
		$b    = (string) ( $hello['php'] ?? ( $site['php_version'] ?? '' ) );
		$this->add(
			self::PASS,
			'php_pair',
			__( 'PHP versions', 'estasoft-migrate' ),
			$a === $b ? sprintf( /* translators: %s: version */ __( 'PHP %s on both sites.', 'estasoft-migrate' ), $a ) : sprintf( /* translators: 1: version, 2: version */ __( 'PHP %1$s here and %2$s on the other site.', 'estasoft-migrate' ), $a, $b )
		);

		$db_a = is_array( $this->env['db'] ) ? $this->env['db'] : $this->ctx->db->server_info();
		$db_b = (array) ( $site['db'] ?? array() );
		if ( $db_b ) {
			$this->add(
				self::PASS,
				'db_pair',
				__( 'Database servers', 'estasoft-migrate' ),
				sprintf( /* translators: 1: server and version, 2: server and version */ __( '%1$s here and %2$s on the other site. Both are supported.', 'estasoft-migrate' ), self::db_label( $db_a ), self::db_label( $db_b ) )
			);
			$source = 'push' === $mode ? $db_a : $db_b;
			$dest   = 'push' === $mode ? $db_b : $db_a;
			$col    = (string) ( $source['collation'] ?? '' );
			if ( '' !== $col && (string) ( $dest['collation'] ?? '' ) !== $col ) {
				$missing = 'pull' === $mode
					? ! $this->ctx->db->has_collation( $col )
					: $this->collation_unsupported( $source, $dest );
				if ( $missing ) {
					$this->add(
						self::WARN,
						'collation',
						__( 'Charset and collation differ', 'estasoft-migrate' ),
						sprintf(
							/* translators: 1: collation, 2: server and version, 3: replacement */
							__( 'The source uses %1$s, which %2$s does not have. Tables will be converted to %3$s automatically.', 'estasoft-migrate' ),
							$col,
							self::db_label( $dest ),
							'utf8mb4_unicode_520_ci'
						)
					);
				}
			}
		}

		$prefix_a = $this->ctx->db->prefix();
		$prefix_b = (string) ( $site['prefix'] ?? '' );
		if ( '' !== $prefix_b && $prefix_a !== $prefix_b ) {
			$dest_prefix = 'push' === $mode ? $prefix_b : $prefix_a;
			$this->add( self::WARN, 'prefix', sprintf( /* translators: 1: prefix, 2: prefix */ __( 'Table prefix differs: %1$s and %2$s', 'estasoft-migrate' ), $prefix_a, $prefix_b ), sprintf( /* translators: %s: prefix */ __( 'Table names, and option and user meta keys that contain the prefix, will be rewritten to %s.', 'estasoft-migrate' ), $dest_prefix ) );
		}

		if ( 'push' === $mode && ! empty( $hello['free_bytes'] ) ) {
			$need = (int) $stats['package'] * 2;
			if ( (int) $hello['free_bytes'] < $need * 1.1 ) {
				$this->add( self::BLOCK, 'remote_disk', __( 'Other site is low on disk space', 'estasoft-migrate' ), sprintf( /* translators: 1: free, 2: needed */ __( 'It has %1$s free and needs about %2$s for the package and its rollback snapshot.', 'estasoft-migrate' ), Util::format_bytes( (int) $hello['free_bytes'] ), Util::format_bytes( $need ) ) );
			}
		}
		if ( ! empty( $hello['cron_off'] ) ) {
			$this->add( self::WARN, 'remote_cron', __( 'WP-Cron is off on the other site', 'estasoft-migrate' ), __( 'This site drives the other site step by step while the Progress screen is open, so the job still finishes. Keep the page open.', 'estasoft-migrate' ) );
		}
		return $hello;
	}

	/**
	 * Will the destination lack the source collation?
	 *
	 * @param array $source Source server details.
	 * @param array $dest   Destination server details.
	 * @return bool
	 */
	private function collation_unsupported( array $source, array $dest ) {
		$col = (string) $source['collation'];
		if ( 'mariadb' === ( $dest['type'] ?? '' ) && ( false !== strpos( $col, '0900' ) ) ) {
			return true;
		}
		if ( 'mysql' === ( $dest['type'] ?? '' ) && false !== strpos( $col, 'uca1400' ) ) {
			return true;
		}
		if ( 'mysql' === ( $dest['type'] ?? '' ) && version_compare( (string) ( $dest['version'] ?? '0' ), '8.0', '<' ) && false !== strpos( $col, '0900' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * A package that is about to be imported.
	 *
	 * @param array $input Input.
	 * @return void
	 */
	private function check_package( array $input ) {
		$id = (string) ( $input['package_id'] ?? '' );
		if ( ! Storage::is_valid_id( $id ) || ! is_file( $this->ctx->storage->package_path( $id ) ) ) {
			$this->add( self::BLOCK, 'package', __( 'Package', 'estasoft-migrate' ), __( 'The package file could not be found.', 'estasoft-migrate' ) );
			return;
		}
		$path = $this->ctx->storage->package_path( $id );
		try {
			$header = PackageReader::read_header( $path );
		} catch ( PackageException $e ) {
			$this->add( self::BLOCK, 'package', __( 'Package', 'estasoft-migrate' ), $e->getMessage() );
			return;
		}
		$meta = (array) $this->ctx->storage->read_meta( $id );
		$this->add(
			self::PASS,
			'package',
			__( 'Package', 'estasoft-migrate' ),
			sprintf(
				/* translators: 1: size, 2: date */
				__( '%1$s, made %2$s UTC.', 'estasoft-migrate' ),
				Util::format_bytes( (int) filesize( $path ) ),
				(string) ( $meta['created_gmt'] ?? '?' )
			)
		);

		$manifest = null;
		if ( $header['encrypted'] ) {
			$manifest = $this->check_package_key( $path, $header, $input );
		} else {
			$manifest = $this->read_manifest( $path, array() );
		}

		$source = (string) ( $manifest['source']['url'] ?? ( $meta['source_url'] ?? '' ) );
		$local  = (string) ( $this->ctx->site_info()['url'] ?? '' );
		if ( '' !== $source && rtrim( $source, '/' ) !== rtrim( $local, '/' ) ) {
			$this->add(
				self::PASS,
				'rewrite',
				__( 'Addresses will be updated', 'estasoft-migrate' ),
				sprintf(
					/* translators: 1: old address, 2: new address */
					__( 'Links to %1$s become links to %2$s, including inside serialized and JSON data.', 'estasoft-migrate' ),
					$source,
					$local
				)
			);
		}
		$source_prefix = (string) ( $manifest['source']['prefix'] ?? '' );
		$local_prefix  = $this->ctx->db->prefix();
		if ( '' !== $source_prefix && $source_prefix !== $local_prefix ) {
			$this->add(
				self::WARN,
				'prefix',
				sprintf( /* translators: 1: prefix, 2: prefix */ __( 'Table prefix differs: %1$s and %2$s', 'estasoft-migrate' ), $source_prefix, $local_prefix ),
				sprintf( /* translators: %s: prefix */ __( 'Table names, and option and user meta keys that contain the prefix, will be rewritten to %s.', 'estasoft-migrate' ), $local_prefix )
			);
		}
		if ( ! empty( $manifest['source']['multisite'] ) ) {
			$this->add( self::BLOCK, 'package_multisite', __( 'Package comes from a multisite network', 'estasoft-migrate' ), __( 'This version imports single sites only.', 'estasoft-migrate' ) );
		}
		$col = (string) ( $manifest['source']['db']['collation'] ?? '' );
		if ( '' !== $col && ! $this->ctx->db->has_collation( $col ) ) {
			$this->add(
				self::WARN,
				'collation',
				__( 'Charset and collation differ', 'estasoft-migrate' ),
				sprintf(
					/* translators: 1: collation, 2: replacement */
					__( 'The package uses %1$s, which this database does not have. Tables will be converted to %2$s automatically.', 'estasoft-migrate' ),
					$col,
					'utf8mb4_unicode_520_ci'
				)
			);
		}
	}

	/**
	 * Check that an encrypted package can be opened, and read its manifest if it can.
	 *
	 * @param string $path   Package path.
	 * @param array  $header Header.
	 * @param array  $input  Input.
	 * @return array|null Manifest, null when it could not be read.
	 */
	private function check_package_key( $path, array $header, array $input ) {
		$title = __( 'Package is encrypted', 'estasoft-migrate' );
		if ( ! $this->env['sodium'] ) {
			$this->add( self::BLOCK, 'package_enc', $title, __( 'This server has no PHP sodium extension, so it cannot open it.', 'estasoft-migrate' ) );
			return null;
		}
		$options = array( 'secret' => (string) $this->ctx->secret );
		$pass    = (string) ( $input['passphrase'] ?? ( $input['password'] ?? '' ) );
		if ( '' !== $pass ) {
			$options['passphrase'] = $pass;
		}
		try {
			$manifest = $this->read_manifest( $path, $options, true );
		} catch ( PackageException $e ) {
			if ( PackageException::NEEDS_KEY === $e->reason && Crypto::KDF_PASSPHRASE === $header['kdf'] ) {
				$this->add( self::BLOCK, 'package_enc', __( 'Package password needed', 'estasoft-migrate' ), __( 'Enter the password that was used when the package was made.', 'estasoft-migrate' ) );
			} elseif ( PackageException::BAD_PASSPHRASE === $e->reason ) {
				$this->add(
					self::BLOCK,
					'package_enc',
					Crypto::KDF_SECRET === $header['kdf'] ? __( 'Package belongs to another site', 'estasoft-migrate' ) : __( 'The password is not correct', 'estasoft-migrate' ),
					Crypto::KDF_SECRET === $header['kdf']
						? __( 'It was locked with the connection key of the site that made it. Export it again with a password to use it on another site.', 'estasoft-migrate' )
						: __( 'Check the password and try again.', 'estasoft-migrate' )
				);
			} else {
				$this->add( self::BLOCK, 'package_enc', $title, $e->getMessage() );
			}
			return null;
		}
		$this->add( self::PASS, 'package_enc', $title, __( 'The password is correct. The package is decrypted while it is read.', 'estasoft-migrate' ) );
		return $manifest;
	}

	/**
	 * Read the manifest (first block) of a package.
	 *
	 * @param string $path    Package path.
	 * @param array  $options Key material.
	 * @param bool   $strict  Rethrow errors.
	 * @return array|null
	 * @throws PackageException When strict and the package cannot be read.
	 */
	private function read_manifest( $path, array $options, $strict = false ) {
		try {
			$reader = PackageReader::open( $path, $options );
			$frame  = $reader->next( true );
			$reader->close();
		} catch ( PackageException $e ) {
			if ( $strict ) {
				throw $e;
			}
			return null;
		}
		return $frame && is_array( $frame['data'] ) ? $frame['data'] : null;
	}

	/**
	 * Search and replace specifics.
	 *
	 * @param array $input  Input.
	 * @param array $tables Tables.
	 * @return void
	 */
	private function check_search_replace( array $input, array $tables ) {
		if ( empty( $input['dry_run'] ) ) {
			$this->add( self::PASS, 'sr_snapshot', __( 'Rollback snapshot', 'estasoft-migrate' ), $this->wants_snapshot( $input ) ? __( 'The tables are saved first, so the change can be undone from Packages.', 'estasoft-migrate' ) : __( 'No snapshot will be saved. The change cannot be undone.', 'estasoft-migrate' ) );
		}
	}

	/**
	 * Assemble the answer.
	 *
	 * @param string     $mode   Mode.
	 * @param array      $tables Tables.
	 * @param array      $stats  Totals.
	 * @param array|null $remote Remote facts.
	 * @param array      $input  Input.
	 * @return array
	 */
	private function result( $mode, array $tables, array $stats, $remote, array $input ) {
		$counts = array(
			'pass'  => 0,
			'warn'  => 0,
			'block' => 0,
		);
		foreach ( $this->checks as $c ) {
			++$counts[ $c['status'] ];
		}
		$status = $counts['block'] ? 'blocked' : ( $counts['warn'] ? 'warnings' : 'ready' );

		$order = array(
			self::BLOCK => 0,
			self::WARN  => 1,
			self::PASS  => 2,
		);
		usort(
			$this->checks,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['status'] ] <=> $order[ $b['status'] ];
			}
		);

		// A rough time: about 4 MB of table data per second end to end, more for a remote job.
		$mb      = max( 1, $stats['data'] / 1048576 );
		$factor  = in_array( $mode, array( 'push', 'pull' ), true ) ? 1.6 : 1.0;
		$floor   = $factor > 1 ? 8 : 3; // Even a tiny database needs a moment to start up and finish.
		$seconds = max( $floor, (int) ( $mb / 4 * $factor ) );

		return array(
			'status'  => $status,
			'counts'  => $counts,
			'checks'  => $this->checks,
			'summary' => array(
				'tables'  => $stats['tables'],
				'rows'    => $stats['rows'],
				'bytes'   => $stats['bytes'],
				'package' => $stats['package'],
				'seconds' => array( $seconds, (int) ( $seconds * 1.4 ) ),
				'remote'  => $remote ? (string) ( $remote['site']['url'] ?? '' ) : '',
			),
			'safety'  => array(
				'snapshot'       => ( in_array( $mode, array( 'import', 'pull', 'push' ), true ) || ( 'search_replace' === $mode && empty( $input['dry_run'] ) ) ) && $this->wants_snapshot( $input ),
				'snapshot_bytes' => $stats['package'],
				'free'           => null !== $this->env['free'] ? (int) $this->env['free'] : $this->ctx->storage->free_space(),
				'remote_free'    => $remote ? (int) ( $remote['free_bytes'] ?? 0 ) : 0,
			),
		);
	}
}
