<?php
/**
 * Builds a small WordPress-shaped database on SQLite and wires the engine around it.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Support;

use Estasoft\Migrate\Engine\Budget;
use Estasoft\Migrate\Engine\JobContext;
use Estasoft\Migrate\Engine\JobRunner;
use Estasoft\Migrate\Engine\JobStore;
use Estasoft\Migrate\Engine\TaskRegistry;
use Estasoft\Migrate\Logger;
use Estasoft\Migrate\Remote\Client;
use Estasoft\Migrate\Storage;

/**
 * Logger that keeps lines in memory instead of writing to the WordPress database.
 */
class MemoryLogger extends Logger {

	/**
	 * Lines.
	 *
	 * @var array
	 */
	public $lines = array();

	/**
	 * Keep the line.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param int    $job_id  Job.
	 * @param array  $context Context.
	 * @return void
	 */
	public function log( $level, $message, $job_id = 0, array $context = array() ) {
		$this->lines[] = array( $level, $message, $job_id, $context );
	}

	/**
	 * Same shape as Logger::query(), over the lines kept in memory. Ids are positions, starting at 1.
	 *
	 * @param array $args job_id, level, levels, search, after_id, limit, order.
	 * @return array[]
	 */
	public function query( array $args = array() ) {
		$args    = array_merge( array( 'job_id' => null, 'level' => '', 'search' => '', 'after_id' => 0, 'limit' => 200, 'order' => 'desc' ), $args );
		$allowed = '' === $args['level'] ? self::LEVELS : array_slice( self::LEVELS, (int) array_search( $args['level'], self::LEVELS, true ) );
		$out     = array();
		foreach ( $this->lines as $i => $line ) {
			$id = $i + 1;
			if ( null !== $args['job_id'] && (int) $line[2] !== (int) $args['job_id'] ) {
				continue;
			}
			if ( ! in_array( $line[0], $allowed, true ) || $id <= (int) $args['after_id'] ) {
				continue;
			}
			if ( ! empty( $args['levels'] ) && ! in_array( $line[0], (array) $args['levels'], true ) ) {
				continue;
			}
			if ( '' !== $args['search'] && false === stripos( $line[1], $args['search'] ) ) {
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'job_id'      => (int) $line[2],
				'level'       => $line[0],
				'message'     => $line[1],
				'context'     => $line[3],
				'created_gmt' => '2026-01-01 00:00:00',
			);
		}
		if ( 'asc' !== $args['order'] ) {
			$out = array_reverse( $out );
		}
		return array_slice( $out, 0, (int) $args['limit'] );
	}

	/**
	 * Drop the lines of one job.
	 *
	 * @param int|null $job_id     Job.
	 * @param int      $older_days Ignored.
	 * @return void
	 */
	public function purge( $job_id = null, $older_days = 0 ) {
		if ( null === $job_id ) {
			return;
		}
		foreach ( $this->lines as $i => $line ) {
			if ( (int) $line[2] === (int) $job_id ) {
				$this->lines[ $i ][1] = '';
			}
		}
		$this->lines = array_values( array_filter( $this->lines, static function ( $l ) {
			return '' !== $l[1];
		} ) );
	}

	/**
	 * Drop every line.
	 *
	 * @return void
	 */
	public function clear() {
		$this->lines = array();
	}

	/**
	 * Messages of one level.
	 *
	 * @param string $level Level.
	 * @return string[]
	 */
	public function messages( $level ) {
		$out = array();
		foreach ( $this->lines as $line ) {
			if ( $line[0] === $level ) {
				$out[] = $line[1];
			}
		}
		return $out;
	}
}

/**
 * Test rig.
 */
class EngineHarness {

	/**
	 * Database.
	 *
	 * @var \Estasoft\Migrate\Db\Database
	 */
	public $db;

	/**
	 * Context.
	 *
	 * @var JobContext
	 */
	public $ctx;

	/**
	 * Runner.
	 *
	 * @var JobRunner
	 */
	public $runner;

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	public $store;

	/**
	 * Logger.
	 *
	 * @var MemoryLogger
	 */
	public $logger;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	public $storage;

	/**
	 * Storage folder.
	 *
	 * @var string
	 */
	public $dir;

	/**
	 * Number of slices run by run_in_small_slices().
	 *
	 * @var int
	 */
	public $slices = 0;

	/**
	 * Site facts reported by this rig.
	 *
	 * @var array
	 */
	public $site;

	/**
	 * Build a rig.
	 *
	 * @param string $prefix Table prefix.
	 * @param string $url    Site address.
	 * @param string $dir    Storage folder, a new temporary folder when empty.
	 * @param string $dsn    PDO DSN.
	 * @param \Estasoft\Migrate\Db\Database|null $database Use this database instead of a new SQLite one (tables must exist).
	 */
	public function __construct( $prefix = 'wp_', $url = 'https://old.example.com', $dir = '', $dsn = 'sqlite::memory:', $database = null ) {
		$this->dir = $dir ? $dir : sys_get_temp_dir() . '/estm-test-' . bin2hex( random_bytes( 6 ) );
		if ( $database ) {
			$this->db = $database;
		} else {
			$this->db = new PdoDatabase( $prefix, $dsn );
			self::create_wordpress_tables( $this->db, $prefix );
		}

		$this->site = array(
			'url'            => $url,
			'siteurl'        => $url,
			'prefix'         => $prefix,
			'abspath'        => '/var/www/old',
			'name'           => 'Test site',
			'wp_version'     => '6.8',
			'php_version'    => PHP_VERSION,
			'db'             => $this->db->server_info(),
			'multisite'      => false,
			'plugin_version' => '1.0.0',
		);

		$this->logger  = new MemoryLogger();
		$this->storage = new Storage( null, $this->dir );
		$this->store   = new JobStore( $this->db, $prefix . 'estasoft_jobs' );
		$this->ctx     = new JobContext( $this->db, $this->logger, $this->storage, $this->store );

		$this->ctx->secret   = 'test-secret-' . bin2hex( random_bytes( 6 ) );
		$this->ctx->services = array(
			'site_info'       => function () {
				return $this->site;
			},
			'plugin_basename' => 'estasoft-migrate/estasoft-migrate.php',
		);
		$this->runner        = new JobRunner( $this->store, new TaskRegistry(), $this->ctx );
	}

	/**
	 * Settings used when this site acts as the receiving end.
	 *
	 * @var ArraySettings
	 */
	public $settings;

	/**
	 * Receiving end of this site.
	 *
	 * @var \Estasoft\Migrate\Remote\Server|null
	 */
	public $server = null;

	/**
	 * Make this site able to receive migrations.
	 *
	 * @param array $settings Setting overrides.
	 * @return \Estasoft\Migrate\Remote\Server
	 */
	public function serve( array $settings = array() ) {
		$this->settings = new ArraySettings( array_merge( array( 'accept_incoming' => true, 'require_https' => true, 'connection_key' => 'esm_TESTKEY' . bin2hex( random_bytes( 8 ) ) ), $settings ) );
		$this->server   = new \Estasoft\Migrate\Remote\Server( $this->settings, $this->runner, $this->ctx, new MemoryNonces() );
		return $this->server;
	}

	/**
	 * Let this site talk to another one.
	 *
	 * @param EngineHarness $remote Site that serves.
	 * @param string|null   $key    Key to use, defaults to the remote's key.
	 * @return LoopbackTransport
	 */
	public function connect_to( EngineHarness $remote, $key = null ) {
		if ( ! $remote->server ) {
			$remote->serve();
		}
		$transport = new LoopbackTransport( $remote->server );
		$this->ctx->services['remote_client'] = function ( $url, $given ) use ( $transport ) {
			$client              = new Client( $transport, $url, $given );
			$client->retry_pause = 0;
			return $client;
		};
		$this->remote_url = $remote->site['url'];
		$this->remote_key = null === $key ? $remote->settings->get( 'connection_key' ) : $key;
		return $transport;
	}

	/**
	 * Address of the connected site.
	 *
	 * @var string
	 */
	public $remote_url = '';

	/**
	 * Key of the connected site.
	 *
	 * @var string
	 */
	public $remote_key = '';

	/**
	 * Job parameters for talking to the connected site, with the key sealed.
	 *
	 * @param array $extra More parameters.
	 * @return array
	 */
	public function remote_params( array $extra = array() ) {
		return \Estasoft\Migrate\Engine\Tasks\RemoteTask::seal_params(
			array_merge( array( 'remote_url' => $this->remote_url, 'remote_key' => $this->remote_key ), $extra ),
			$this->ctx->secret
		);
	}

	/**
	 * Change the address this site reports (to simulate another site).
	 *
	 * @param string $url New address.
	 * @return void
	 */
	public function set_url( $url ) {
		$this->site['url']     = $url;
		$this->site['siteurl'] = $url;
	}

	/**
	 * Create a job and run it until it finishes.
	 *
	 * @param string $type   Task type.
	 * @param array  $params Parameters.
	 * @param int    $slices Maximum number of slices.
	 * @return \Estasoft\Migrate\Engine\Job
	 */
	public function run( $type, array $params, $slices = 500 ) {
		$job = $this->runner->create( $type, $type, $params );
		for ( $i = 0; $i < $slices; $i++ ) {
			$result = $this->runner->run_slice( $job->id, Budget::unlimited(), 'cli' );
			$job    = $this->store->find( $job->id );
			if ( $job->is_finished() ) {
				break;
			}
		}
		return $job;
	}

	/**
	 * Create a job and run it in slices of a few operations only, so every checkpoint path is used.
	 *
	 * @param string $type    Task type.
	 * @param array  $params  Parameters.
	 * @param int    $units   Work units (batches) allowed per slice.
	 * @return \Estasoft\Migrate\Engine\Job
	 */
	public function run_in_small_slices( $type, array $params, $units = 1 ) {
		$job = $this->runner->create( $type, $type, $params );
		for ( $i = 0; $i < 5000; $i++ ) {
			$this->runner->run_slice( $job->id, Budget::units( $units ), 'cli' );
			++$this->slices;
			$job = $this->store->find( $job->id );
			if ( $job->is_finished() ) {
				break;
			}
		}
		return $job;
	}

	/**
	 * Tables of a WordPress install, shaped like MySQL (backtick quoted).
	 *
	 * @param PdoDatabase $db     Database.
	 * @param string      $prefix Prefix.
	 * @return void
	 */
	public static function create_wordpress_tables( PdoDatabase $db, $prefix ) {
		$p   = $prefix;
		$sql = array(
			"CREATE TABLE `{$p}options` (`option_id` INTEGER PRIMARY KEY AUTOINCREMENT, `option_name` TEXT NOT NULL UNIQUE, `option_value` TEXT NOT NULL, `autoload` TEXT NOT NULL DEFAULT 'yes')",
			"CREATE TABLE `{$p}posts` (`ID` INTEGER PRIMARY KEY AUTOINCREMENT, `post_author` INTEGER NOT NULL DEFAULT 0, `post_title` TEXT NOT NULL, `post_content` TEXT NOT NULL, `post_status` TEXT NOT NULL DEFAULT 'publish', `guid` TEXT NOT NULL DEFAULT '')",
			"CREATE TABLE `{$p}postmeta` (`meta_id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL DEFAULT 0, `meta_key` TEXT, `meta_value` TEXT)",
			"CREATE TABLE `{$p}users` (`ID` INTEGER PRIMARY KEY AUTOINCREMENT, `user_login` TEXT NOT NULL, `user_pass` TEXT NOT NULL, `user_nicename` TEXT NOT NULL, `user_email` TEXT NOT NULL, `user_url` TEXT NOT NULL DEFAULT '', `display_name` TEXT NOT NULL)",
			"CREATE TABLE `{$p}usermeta` (`umeta_id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL DEFAULT 0, `meta_key` TEXT, `meta_value` TEXT)",
			"CREATE TABLE `{$p}comments` (`comment_ID` INTEGER PRIMARY KEY AUTOINCREMENT, `comment_post_ID` INTEGER NOT NULL DEFAULT 0, `comment_author` TEXT NOT NULL, `comment_author_email` TEXT NOT NULL DEFAULT '', `comment_author_url` TEXT NOT NULL DEFAULT '', `comment_author_IP` TEXT NOT NULL DEFAULT '', `comment_content` TEXT NOT NULL, `user_id` INTEGER NOT NULL DEFAULT 0)",
			"CREATE TABLE `{$p}estasoft_jobs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `type` TEXT NOT NULL, `status` TEXT NOT NULL, `title` TEXT, `params` TEXT, `state` TEXT, `progress` REAL NOT NULL DEFAULT 0, `message` TEXT, `runner` TEXT, `locked_until` INTEGER NOT NULL DEFAULT 0, `created_gmt` TEXT, `updated_gmt` TEXT, `started_gmt` TEXT, `finished_gmt` TEXT)",
			"CREATE TABLE `{$p}estasoft_baselines` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `peer` TEXT NOT NULL, `table_name` TEXT NOT NULL, `row_count` INTEGER NOT NULL DEFAULT 0, `max_key` TEXT, `checksum` TEXT, `buckets` TEXT, `watermark` TEXT, `job_id` INTEGER NOT NULL DEFAULT 0, `created_gmt` TEXT, UNIQUE (`peer`, `table_name`))",
		);
		foreach ( $sql as $statement ) {
			$db->query( $statement );
		}
	}

	/**
	 * Fill the tables with a small realistic site.
	 *
	 * @param string $url Site address used inside the content.
	 * @param int    $posts Number of posts.
	 * @return void
	 */
	public function seed( $url = 'https://old.example.com', $posts = 25 ) {
		$p  = $this->db->prefix();
		$db = $this->db;

		$opts = array(
			'siteurl'            => $url,
			'home'               => $url,
			'blogname'           => 'Test site',
			'admin_email'        => 'owner@old.example.com',
			'blog_public'        => '1',
			'active_plugins'     => serialize( array( 'estasoft-migrate/estasoft-migrate.php', 'woocommerce/woocommerce.php', 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', 'wp-mail-smtp/wp_mail_smtp.php', 'akismet/akismet.php' ) ),
			'widget_text'        => serialize( array( 2 => array( 'title' => 'Hello', 'text' => '<a href="' . $url . '/about">About</a>' ) ) ),
			'theme_mods_twenty'  => serialize( array( 'custom_logo' => 12, 'header' => array( 'url' => $url . '/wp-content/uploads/logo.png' ) ) ),
			'transient_foo'      => 'cached',
			'estasoft_migrate_settings' => serialize( array( 'connection_key' => 'esm_OLDKEYOLDKEYOLDKEY', 'runner' => 'ajax' ) ),
			'stripe_api_key'     => 'test-secret-abcdefghijklmnopqrstuvwxyz',
		);
		foreach ( $opts as $name => $value ) {
			$db->query( "INSERT INTO `{$p}options` (option_name, option_value, autoload) VALUES (" . $db->quote( $name ) . ',' . $db->quote( $value ) . ",'yes')" );
		}

		$db->query( "INSERT INTO `{$p}users` (user_login,user_pass,user_nicename,user_email,display_name) VALUES ('admin','hash-admin','admin','boss@old.example.com','Boss'),('jane','hash-jane','jane','jane.doe@gmail.com','Jane Doe'),('bob','hash-bob','bob','bob@customer.org','Bob Customer')" );
		$db->query( "INSERT INTO `{$p}usermeta` (user_id, meta_key, meta_value) VALUES (1,'{$p}capabilities'," . $db->quote( serialize( array( 'administrator' => true ) ) ) . "),(2,'{$p}capabilities'," . $db->quote( serialize( array( 'customer' => true ) ) ) . "),(2,'billing_phone','+1 415 555 0134'),(2,'billing_address_1','12 Main Street'),(2,'session_tokens','abc'),(3,'first_name','Bob')" );

		for ( $i = 1; $i <= $posts; $i++ ) {
			$content = "<p>Post $i</p><img src=\"$url/wp-content/uploads/img$i.jpg\"><!-- wp:image {\"url\":\"$url/wp-content/uploads/img$i.jpg\"} -->";
			$db->query( "INSERT INTO `{$p}posts` (post_author, post_title, post_content, guid) VALUES (1," . $db->quote( "Title $i" ) . ',' . $db->quote( $content ) . ',' . $db->quote( "$url/?p=$i" ) . ')' );
			$meta = serialize( array( 'link' => "$url/page-$i", 'count' => $i, 'nested' => json_encode( array( 'u' => "$url/x/$i" ) ) ) );
			$db->query( "INSERT INTO `{$p}postmeta` (post_id, meta_key, meta_value) VALUES ($i,'_data'," . $db->quote( $meta ) . ")" );
		}
		$db->query( "INSERT INTO `{$p}comments` (comment_post_ID, comment_author, comment_author_email, comment_author_IP, comment_content, user_id) VALUES (1,'Jane Doe','jane.doe@gmail.com','203.0.113.77','Great post by jane',2),(1,'Visitor','vis@example.net','198.51.100.9','Nice',0)" );
	}

	/**
	 * Remove the temporary storage folder.
	 *
	 * @return void
	 */
	public function cleanup() {
		self::rrmdir( $this->dir );
	}

	/**
	 * Delete a folder tree.
	 *
	 * @param string $dir Folder.
	 * @return void
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$path = $dir . '/' . $f;
			is_dir( $path ) ? self::rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
