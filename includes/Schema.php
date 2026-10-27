<?php
/**
 * Database tables owned by the plugin.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and names the plugin tables: jobs, logs and delta-sync baselines.
 */
class Schema {

	const DB_VERSION = 1;

	/**
	 * Short names of the plugin tables.
	 */
	const TABLES = array( 'jobs', 'logs', 'baselines' );

	/**
	 * Full table name.
	 *
	 * @param string $short jobs, logs or baselines.
	 * @return string
	 */
	public static function table( $short ) {
		global $wpdb;
		return $wpdb->prefix . 'estasoft_' . $short;
	}

	/**
	 * Names of all plugin tables for the current prefix.
	 *
	 * @return string[]
	 */
	public static function all_tables() {
		$names = array();
		foreach ( self::TABLES as $short ) {
			$names[] = self::table( $short );
		}
		return $names;
	}

	/**
	 * Create or upgrade the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = self::table( 'jobs' );
		$logs    = self::table( 'logs' );
		$base    = self::table( 'baselines' );

		dbDelta(
			"CREATE TABLE {$jobs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	type varchar(32) NOT NULL,
	status varchar(16) NOT NULL DEFAULT 'queued',
	title varchar(190) NOT NULL DEFAULT '',
	params longtext NULL,
	state longtext NULL,
	progress decimal(5,2) NOT NULL DEFAULT 0,
	message text NULL,
	runner varchar(16) NOT NULL DEFAULT '',
	locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
	created_gmt datetime NOT NULL,
	updated_gmt datetime NOT NULL,
	started_gmt datetime NULL,
	finished_gmt datetime NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY type (type)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	job_id bigint(20) unsigned NOT NULL DEFAULT 0,
	level varchar(10) NOT NULL DEFAULT 'info',
	message text NOT NULL,
	context longtext NULL,
	created_gmt datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY job_id (job_id),
	KEY level (level)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$base} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	peer varchar(64) NOT NULL,
	table_name varchar(128) NOT NULL,
	row_count bigint(20) unsigned NOT NULL DEFAULT 0,
	max_key varchar(190) NULL,
	checksum varchar(32) NOT NULL DEFAULT '',
	buckets longtext NULL,
	watermark longtext NULL,
	job_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_gmt datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY peer_table (peer,table_name)
) {$charset};"
		);
	}

	/**
	 * Remove all plugin tables.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;
		foreach ( self::all_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is one of this plugin's own table names, fixed in all_tables().
		}
	}
}
