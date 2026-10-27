<?php
/**
 * The engine on a real MySQL / MariaDB server and a real WordPress database.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Tests\Integration;

use Estasoft\Migrate\Db\WpdbDatabase;
use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Schema;
use Estasoft\Migrate\Tests\Support\EngineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Round trips that exercise things SQLite cannot: real column types, binary data, utf8mb4,
 * composite keys, tables without a key, atomic RENAME TABLE, collations.
 */
class MysqlRoundtripTest extends TestCase {

	/**
	 * Source rig.
	 *
	 * @var EngineHarness
	 */
	private $source;

	/**
	 * Destination rig.
	 *
	 * @var EngineHarness
	 */
	private $dest;

	/**
	 * Destination connection.
	 *
	 * @var \wpdb
	 */
	private $dest_wpdb;

	/**
	 * Marker used in the test content.
	 *
	 * @var string
	 */
	private $url_a = 'https://source.example.test';

	protected function setUp(): void {
		global $wpdb;
		Schema::install();
		$this->remove_test_content();

		$name            = getenv( 'ESTM_DEST_DB' ) ? getenv( 'ESTM_DEST_DB' ) : 'wp_dest';
		$this->dest_wpdb = new \wpdb( DB_USER, DB_PASSWORD, $name, DB_HOST );
		$this->dest_wpdb->set_prefix( $wpdb->prefix );
		$this->wipe( $this->dest_wpdb );

		$this->make_custom_tables();
		$this->seed();

		$this->source = new EngineHarness( $wpdb->prefix, $this->url_a, '', '', new WpdbDatabase( $wpdb ) );
		$this->dest   = new EngineHarness( $wpdb->prefix, 'https://dest.example.test', '', '', new WpdbDatabase( $this->dest_wpdb ) );
		foreach ( array( 'jobs', 'baselines' ) as $short ) {
			$t = $wpdb->prefix . 'estasoft_' . $short;
			$this->dest_wpdb->query( "CREATE TABLE IF NOT EXISTS `{$t}` LIKE `" . DB_NAME . "`.`{$t}`" );
		}
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( array( $this->source, $this->dest ) as $rig ) {
			if ( $rig ) {
				$rig->cleanup();
			}
		}
		foreach ( array( 'estm_blob', 'estm_nopk', 'estm_multi', 'estm_emoji', 'estm_big' ) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`" );
		}
		$this->remove_test_content();
		$this->wipe( $this->dest_wpdb );
	}

	/**
	 * Remove content added by earlier runs (including runs that stopped half way).
	 *
	 * @return void
	 */
	private function remove_test_content() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_estm_meta'" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title LIKE 'Post % ✓ ünïcödé%'" );
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_author = 'Tester'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'estm\\_test\\_%'" );
		wp_cache_flush();
	}

	/**
	 * Drop every table of a database.
	 *
	 * @param \wpdb $db Connection.
	 * @return void
	 */
	private function wipe( $db ) {
		$tables = $db->get_col( 'SHOW TABLES' );
		if ( $tables ) {
			$db->query( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $tables as $t ) {
				$db->query( "DROP TABLE IF EXISTS `{$t}`" );
			}
		}
	}

	/**
	 * Tables with the awkward shapes real sites have.
	 *
	 * @return void
	 */
	private function make_custom_tables() {
		global $wpdb;
		$p = $wpdb->prefix;
		foreach ( array( 'estm_blob', 'estm_nopk', 'estm_multi', 'estm_emoji', 'estm_big' ) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$p}{$t}`" );
		}
		$wpdb->query( "CREATE TABLE `{$p}estm_blob` (id INT UNSIGNED NOT NULL AUTO_INCREMENT, data LONGBLOB, hash BINARY(16), note TEXT, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}estm_nopk` (a INT, b VARCHAR(50), c DATETIME NULL, d DECIMAL(10,2)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}estm_multi` (a INT NOT NULL, b VARCHAR(20) NOT NULL, v TEXT, PRIMARY KEY (a,b)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}estm_emoji` (id INT NOT NULL AUTO_INCREMENT, t TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$wpdb->query( "CREATE TABLE `{$p}estm_big` (id INT NOT NULL AUTO_INCREMENT, body LONGTEXT, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
	}

	/**
	 * Fill the site with content, through the real WordPress functions where possible.
	 *
	 * @return void
	 */
	private function seed() {
		global $wpdb;
		$p   = $wpdb->prefix;
		$url = $this->url_a;

		update_option( 'estm_test_serialized', array( 'a' => $url . '/x', 'b' => array( 1, 2, $url ), 'c' => (object) array( 'u' => $url ) ) );
		update_option( 'estm_test_json', wp_json_encode( array( 'home' => $url, 'esc' => str_replace( '/', '\/', $url ) ) ) );
		update_option( 'estm_test_plain', $url . '/plain' );

		for ( $i = 1; $i <= 30; $i++ ) {
			$id = wp_insert_post(
				array(
					'post_title'   => "Post $i ✓ ünïcödé 🎉",
					'post_content' => "<p>Visit <a href=\"$url/page-$i\">here</a></p><!-- wp:image {\"src\":\"$url/img$i.png\"} -->",
					'post_status'  => 'publish',
				)
			);
			update_post_meta( $id, '_estm_meta', array( 'url' => "$url/m$i", 'n' => $i, 'nested' => wp_json_encode( array( 'u' => "$url/n$i" ) ) ) );
		}
		wp_insert_comment( array( 'comment_post_ID' => 1, 'comment_author' => 'Tester', 'comment_content' => "See $url", 'comment_approved' => 1 ) );

		$wpdb->query( "INSERT INTO `{$p}estm_blob` (data, hash, note) VALUES (UNHEX('00FF10FE80'), UNHEX('000102030405060708090A0B0C0D0E0F'), 'binary'), (REPEAT(CHAR(255 USING latin1), 1000), UNHEX('FF000000000000000000000000000000'), NULL), ('', UNHEX('00000000000000000000000000000000'), '')" );
		$wpdb->query( "INSERT INTO `{$p}estm_nopk` VALUES (1,'one','2020-01-01 00:00:00',1.50),(2,'two',NULL,0.00),(3,NULL,'1999-12-31 23:59:59',-7.25)" );
		for ( $i = 0; $i < 25; $i++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$p}estm_nopk` VALUES (%d, %s, NULL, %f)", 100 + $i, "row $i", $i / 4 ) );
		}
		$wpdb->query( "INSERT INTO `{$p}estm_multi` VALUES (1,'a','x'),(1,'b','y'),(2,'a','z'),(10,'a','after ten'),(2,'B','case')" );
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$p}estm_emoji` (t) VALUES (%s),(%s)", "😀 emoji + ' quote + \\ backslash + \" double", "Ünï\nnew line\ttab\0nul" ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$p}estm_big` (body) VALUES (%s)", str_repeat( "line of text with $url link\n", 40000 ) ) );
	}

	/**
	 * Export the source and copy the package into the destination's storage.
	 *
	 * @param array $params Export parameters.
	 * @return string Package id.
	 */
	private function export( array $params = array() ) {
		$job = $this->source->run( 'export', array_merge( array( 'kind' => 'export', 'label' => 'it' ), $params ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->source->logger->messages( 'error' ), true ) );
		$id = $job->state['package'];
		$this->dest->storage->dir();
		copy( $this->source->storage->package_path( $id ), $this->dest->storage->package_path( $id ) );
		copy( $this->source->storage->meta_path( $id ), $this->dest->storage->meta_path( $id ) );
		return $id;
	}

	/**
	 * Checksums of the tables worth comparing.
	 *
	 * @param \wpdb $db Connection.
	 * @return array<string,string>
	 */
	private function checksums( $db ) {
		$out = array();
		foreach ( $db->get_col( 'SHOW TABLES' ) as $t ) {
			if ( false !== strpos( $t, 'estasoft_' ) || $t === $db->prefix . 'options' ) {
				continue;
			}
			$row        = $db->get_row( "CHECKSUM TABLE `{$t}`", ARRAY_N );
			$out[ $t ] = (string) $row[1];
		}
		ksort( $out );
		return $out;
	}

	public function test_a_copy_with_the_same_address_is_bit_for_bit_identical() {
		global $wpdb;
		$this->dest->set_url( $this->url_a );
		$id  = $this->export();
		$job = $this->dest->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->dest->logger->messages( 'error' ), true ) );

		$this->assertSame( $this->checksums( $wpdb ), $this->checksums( $this->dest_wpdb ), 'Every table must have the same checksum, including binary, emoji and NULL values.' );
		$this->assertSame(
			$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT IN ('rewrite_rules') AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE 'estasoft\\_%'" ),
			$this->dest_wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT IN ('rewrite_rules') AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE 'estasoft\\_%'" )
		);
	}

	public function test_keys_and_column_types_are_preserved() {
		global $wpdb;
		$this->dest->set_url( $this->url_a );
		$this->dest->run( 'import', array( 'package_id' => $this->export() ) );
		$p = $wpdb->prefix;
		// MySQL 8 prints "CHARACTER SET x" for a column whose collation was written out in the CREATE
		// statement and leaves it out for a column that inherited the table's default. A table restored
		// from a mysqldump file behaves the same way. The character set and the collation are the same
		// either way, so that wording is not compared.
		$plain = static function ( $definition ) {
			return preg_replace( '/ CHARACTER SET [a-z0-9_]+ (COLLATE [a-z0-9_]+)/i', ' $1', $definition );
		};
		foreach ( array( 'estm_blob', 'estm_multi', 'estm_nopk', 'posts', 'postmeta' ) as $t ) {
			$before = $plain( $wpdb->get_row( "SHOW CREATE TABLE `{$p}{$t}`", ARRAY_N )[1] );
			$after  = $plain( $this->dest_wpdb->get_row( "SHOW CREATE TABLE `{$p}{$t}`", ARRAY_N )[1] );
			$this->assertSame( $before, $after, "Table definition of {$t} changed." );
		}
	}

	public function test_a_different_address_is_rewritten_safely() {
		$id  = $this->export();
		$job = $this->dest->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->dest->logger->messages( 'error' ), true ) );

		$d = $this->dest_wpdb;
		$p = $d->prefix;
		$this->assertSame( 'https://dest.example.test/plain', $d->get_var( "SELECT option_value FROM {$p}options WHERE option_name = 'estm_test_plain'" ) );

		$ser = maybe_unserialize( $d->get_var( "SELECT option_value FROM {$p}options WHERE option_name = 'estm_test_serialized'" ) );
		$this->assertIsArray( $ser, 'Serialized data must still be valid.' );
		$this->assertSame( 'https://dest.example.test/x', $ser['a'] );
		$this->assertSame( 'https://dest.example.test', $ser['b'][2] );
		$this->assertInstanceOf( \stdClass::class, $ser['c'] );
		$this->assertSame( 'https://dest.example.test', $ser['c']->u );

		$json = json_decode( $d->get_var( "SELECT option_value FROM {$p}options WHERE option_name = 'estm_test_json'" ), true );
		$this->assertSame( 'https://dest.example.test', $json['home'] );
		// The value itself contained escaped slashes (a doubly escaped URL); that style is kept.
		$this->assertSame( 'https:\\/\\/dest.example.test', $json['esc'] );

		$meta = maybe_unserialize( $d->get_var( "SELECT meta_value FROM {$p}postmeta WHERE meta_key = '_estm_meta' ORDER BY meta_id LIMIT 1" ) );
		$this->assertIsArray( $meta );
		$this->assertStringStartsWith( 'https://dest.example.test/', $meta['url'] );
		$this->assertStringStartsWith( 'https://dest.example.test/', json_decode( $meta['nested'], true )['u'] );

		$this->assertSame( '0', $d->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_content LIKE '%source.example.test%'" ) );
		$this->assertStringContainsString( 'dest.example.test', $d->get_var( "SELECT body FROM {$p}estm_big LIMIT 1" ) );
		$this->assertSame( 40000, substr_count( $d->get_var( "SELECT body FROM {$p}estm_big LIMIT 1" ), "dest.example.test link\n" ) );
		$this->assertSame( 'Post 7 ✓ ünïcödé 🎉', $d->get_var( "SELECT post_title FROM {$p}posts WHERE post_title LIKE 'Post 7 %'" ) );
	}

	public function test_importing_over_existing_tables_swaps_them_and_leaves_nothing_behind() {
		global $wpdb;
		$this->dest->set_url( $this->url_a );
		$id = $this->export();
		$this->assertSame( Job::COMPLETED, $this->dest->run( 'import', array( 'package_id' => $id ) )->status );

		// Change the destination, then import again over the top.
		$this->dest_wpdb->query( "DELETE FROM {$this->dest_wpdb->prefix}posts WHERE ID > 10" );
		$this->dest_wpdb->query( "INSERT INTO {$this->dest_wpdb->prefix}estm_nopk VALUES (999,'local only',NULL,1)" );
		$job = $this->dest->run( 'import', array( 'package_id' => $id ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->dest->logger->messages( 'error' ), true ) );

		$tables = $this->dest_wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $t ) {
			$this->assertDoesNotMatchRegularExpression( '/^_estm_/', $t, "Left behind: {$t}" );
		}
		$this->assertSame( $this->checksums( $wpdb ), $this->checksums( $this->dest_wpdb ) );

		// The snapshot taken before the second import holds the changed state, and restoring brings it back.
		$snapshots = $this->dest->storage->list_packages( 'snapshot' );
		$this->assertNotEmpty( $snapshots );
		$restore = $this->dest->run( 'import', array( 'package_id' => $snapshots[0]['id'], 'snapshot' => false, 'kind' => 'restore' ) );
		$this->assertSame( Job::COMPLETED, $restore->status, (string) $restore->message . print_r( $this->dest->logger->messages( 'error' ), true ) );
		$this->assertSame( '1', $this->dest_wpdb->get_var( "SELECT COUNT(*) FROM {$this->dest_wpdb->prefix}estm_nopk WHERE a = 999" ) );
	}

	public function test_stopping_after_every_small_batch_still_gives_an_identical_copy() {
		global $wpdb;
		$this->dest->set_url( $this->url_a );
		$this->source->ctx->max_batch = 6;
		$this->dest->ctx->max_batch   = 6;
		$job = $this->source->run_in_small_slices( 'export', array( 'kind' => 'export', 'label' => 'tiny' ), 1 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->source->logger->messages( 'error' ), true ) );
		$id = $job->state['package'];
		$this->dest->storage->dir();
		copy( $this->source->storage->package_path( $id ), $this->dest->storage->package_path( $id ) );
		copy( $this->source->storage->meta_path( $id ), $this->dest->storage->meta_path( $id ) );

		$job = $this->dest->run_in_small_slices( 'import', array( 'package_id' => $id ), 1 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->dest->logger->messages( 'error' ), true ) );
		$this->assertSame( $this->checksums( $wpdb ), $this->checksums( $this->dest_wpdb ) );
		$this->assertGreaterThan( 30, $this->source->slices + $this->dest->slices );
	}

	public function test_push_and_pull_between_two_real_databases() {
		global $wpdb;
		$this->dest->set_url( $this->url_a );
		$this->dest->serve();
		$this->source->connect_to( $this->dest );
		$job = $this->source->run( 'push', $this->source->remote_params() );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->source->logger->messages( 'error' ), true ) );
		$this->assertSame( $this->checksums( $wpdb ), $this->checksums( $this->dest_wpdb ) );

		// Change the other site and pull it back.
		$this->dest_wpdb->query( "INSERT INTO {$this->dest_wpdb->prefix}estm_nopk VALUES (777,'from dest',NULL,2)" );
		$job = $this->source->run( 'pull', $this->source->remote_params() );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->source->logger->messages( 'error' ), true ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}estm_nopk WHERE a = 777" ) );
	}

	public function test_search_replace_on_real_tables_never_touches_binary_or_keys() {
		global $wpdb;
		$before = $wpdb->get_row( "CHECKSUM TABLE `{$wpdb->prefix}estm_blob`", ARRAY_N )[1];
		$job    = $this->source->run( 'search_replace', array( 'dry_run' => false, 'snapshot' => true, 'pairs' => array( array( $this->url_a, 'https://changed.example.test' ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->message . print_r( $this->source->logger->messages( 'error' ), true ) );
		$this->assertSame( $before, $wpdb->get_row( "CHECKSUM TABLE `{$wpdb->prefix}estm_blob`", ARRAY_N )[1] );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%source.example.test%'" ) );
		$this->assertSame( 'https://changed.example.test/plain', $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'estm_test_plain'" ) );
		$this->assertIsArray( maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'estm_test_serialized'" ) ) );
		$this->assertSame( '40000', (string) substr_count( $wpdb->get_var( "SELECT body FROM {$wpdb->prefix}estm_big LIMIT 1" ), 'changed.example.test link' ) );

		// Roll back with the snapshot taken before.
		$restore = $this->source->run( 'import', array( 'package_id' => $job->state['snapshot_id'], 'snapshot' => false, 'kind' => 'restore' ) );
		$this->assertSame( Job::COMPLETED, $restore->status, (string) $restore->message . print_r( $this->source->logger->messages( 'error' ), true ) );
		$this->assertSame( $this->url_a . '/plain', $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'estm_test_plain'" ) );
	}
}
