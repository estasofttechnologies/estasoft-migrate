<?php
/**
 * Activation, deactivation and upgrades.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the tables on activation and keeps them up to date.
 */
class Activator {

	const DAILY_HOOK = 'estasoft_migrate_daily';

	/**
	 * Plugin activated.
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();
		$settings = new Settings();
		$settings->update_raw( array( 'db_version' => Schema::DB_VERSION ) );
		$settings->connection_key();
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Plugin deactivated. Data stays; only the schedules are removed.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_unschedule_hook( Scheduler::HOOK );
	}

	/**
	 * Bring the tables up to date after the plugin files were updated.
	 *
	 * @param Settings $settings Settings.
	 * @return void
	 */
	public static function maybe_upgrade( Settings $settings ) {
		if ( (int) $settings->get( 'db_version', 0 ) < Schema::DB_VERSION ) {
			Schema::install();
			$settings->update_raw( array( 'db_version' => Schema::DB_VERSION ) );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}
}
