<?php
/**
 * Plugin container: builds the services once and connects them to WordPress.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

use Estasoft\Migrate\Db\Database;
use Estasoft\Migrate\Db\WpdbDatabase;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\JobRunner;
use Estasoft\Migrate\Engine\JobStore;
use Estasoft\Migrate\Engine\TaskRegistry;
use Estasoft\Migrate\Sanitizer\SafetySwitches;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the plugin.
 */
final class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Built services.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Get the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Connect to WordPress. Nothing heavy happens until WordPress has loaded all plugins.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 5 );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		load_plugin_textdomain( 'estasoft-migrate', false, dirname( plugin_basename( ESTASOFT_MIGRATE_FILE ) ) . '/languages' );

		Activator::maybe_upgrade( $this->settings() );
		SafetySwitches::boot();

		// Requests from another site are answered before the theme and the other plugins do any work.
		( new Remote\Endpoint( $this ) )->register();

		$this->scheduler()->register();
		add_action( Activator::DAILY_HOOK, array( $this, 'daily' ) );
		add_action( 'rest_api_init', array( new Rest\Router( $this ), 'register' ) );

		if ( is_admin() ) {
			( new Admin\Admin( $this ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'estasoft-migrate', new Cli\Command( $this ) );
		}
	}

	/**
	 * Daily housekeeping: old packages, finished jobs, old log lines.
	 *
	 * @return void
	 */
	public function daily() {
		$settings = $this->settings();
		$this->storage()->clean_up( (int) $settings->get( 'delete_packages_days', 30 ), (int) $settings->get( 'keep_snapshots', 2 ), (int) $settings->get( 'delete_snapshots_days', 30 ) );
		$this->store()->purge_finished( 60 );
		$this->logger()->purge( null, 60 );
		$this->scheduler()->nudge_stalled();
	}

	/**
	 * Settings.
	 *
	 * @return Settings
	 */
	public function settings() {
		if ( ! isset( $this->services['settings'] ) ) {
			$this->services['settings'] = new Settings();
		}
		return $this->services['settings'];
	}

	/**
	 * Database.
	 *
	 * @return Database
	 */
	public function db() {
		if ( ! isset( $this->services['db'] ) ) {
			$this->services['db'] = new WpdbDatabase();
		}
		return $this->services['db'];
	}

	/**
	 * Storage.
	 *
	 * @return Storage
	 */
	public function storage() {
		if ( ! isset( $this->services['storage'] ) ) {
			$this->services['storage'] = new Storage( $this->settings() );
		}
		return $this->services['storage'];
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	public function logger() {
		if ( ! isset( $this->services['logger'] ) ) {
			$this->services['logger'] = new Logger();
		}
		return $this->services['logger'];
	}

	/**
	 * Job store.
	 *
	 * @return JobStore
	 */
	public function store() {
		if ( ! isset( $this->services['store'] ) ) {
			$this->services['store'] = new JobStore( $this->db(), Schema::table( 'jobs' ) );
		}
		return $this->services['store'];
	}

	/**
	 * Secret that belongs to this installation. It comes from the WordPress salts in wp-config.php,
	 * so it survives an import (which replaces the database) and differs on every site.
	 *
	 * @return string
	 */
	public function site_secret() {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|estasoft-migrate|site-secret' );
	}

	/**
	 * Job context.
	 *
	 * @return JobContext
	 */
	public function context() {
		if ( ! isset( $this->services['context'] ) ) {
			$ctx                       = new JobContext( $this->db(), $this->logger(), $this->storage(), $this->store() );
			$ctx->secret               = $this->site_secret();
			$ctx->max_batch            = (int) $this->settings()->get( 'max_batch', 20000 );
			$ctx->services             = array(
				'plugin_basename' => plugin_basename( ESTASOFT_MIGRATE_FILE ),
				'keep_snapshots'  => (int) $this->settings()->get( 'keep_snapshots', 2 ),
				'after_import'    => array( $this, 'after_import' ),
				'current_user'    => static function () {
					$user = wp_get_current_user();
					return array(
						'id'    => (int) $user->ID,
						'login' => (string) $user->user_login,
					);
				},
			);
			$this->services['context'] = $ctx;
		}
		return $this->services['context'];
	}

	/**
	 * Called when an import has swapped the tables: forget everything WordPress cached about the old database.
	 *
	 * @param array $state Import state.
	 * @return void
	 */
	public function after_import( $state ) {
		wp_cache_flush();
		$this->settings()->flush();
		/**
		 * Runs after an import finished and the caches were cleared.
		 *
		 * @param array $state Import state.
		 */
		do_action( 'estasoft_migrate_after_import', $state );
	}

	/**
	 * Task types.
	 *
	 * @return TaskRegistry
	 */
	public function tasks() {
		if ( ! isset( $this->services['tasks'] ) ) {
			$this->services['tasks'] = new TaskRegistry();
		}
		return $this->services['tasks'];
	}

	/**
	 * Job runner.
	 *
	 * @return JobRunner
	 */
	public function runner() {
		if ( ! isset( $this->services['runner'] ) ) {
			$this->services['runner'] = new JobRunner( $this->store(), $this->tasks(), $this->context() );
		}
		return $this->services['runner'];
	}

	/**
	 * The API used by the screens and the command line.
	 *
	 * @param bool $background Ask the scheduler to continue jobs when nobody is watching (the command line does not).
	 * @return Rest\Api
	 */
	public function api( $background = true ) {
		$key = $background ? 'api' : 'api_cli';
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new Rest\Api(
				$this->context(),
				$this->runner(),
				$this->tasks(),
				$this->settings(),
				array(
					'dispatch'    => $background ? array( $this->scheduler(), 'dispatch' ) : null,
					'endpoint'    => home_url( '/?estm_remote=' ),
					'runner_mode' => array( $this->scheduler(), 'mode' ),
				)
			);
		}
		return $this->services[ $key ];
	}

	/**
	 * Scheduler.
	 *
	 * @return Scheduler
	 */
	public function scheduler() {
		if ( ! isset( $this->services['scheduler'] ) ) {
			$this->services['scheduler'] = new Scheduler( $this );
		}
		return $this->services['scheduler'];
	}
}
