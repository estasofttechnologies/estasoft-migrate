<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Nothing is removed unless "Delete all plugin data when the plugin is deleted" was switched on
 * in Settings, because packages and snapshots can be the only copy of someone's data.
 *
 * @package Estasoft\Migrate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Stop what is scheduled and, if the person asked for it, remove the plugin's data.
 *
 * @return void
 */
function estasoft_migrate_uninstall() {
	global $wpdb;

	wp_clear_scheduled_hook( 'estasoft_migrate_daily' );
	wp_unschedule_hook( 'estasoft_migrate_run_job' );

	// The memory of used request numbers is of no use to anyone once the plugin is gone, whatever else is kept.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'estasoft_migrate_n_' ) . '%'
		)
	);

	$settings = get_option( 'estasoft_migrate_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	// Packages and snapshots.
	if ( ! empty( $settings['storage_token'] ) ) {
		$uploads = wp_upload_dir( null, false );
		$dir     = rtrim( $uploads['basedir'], '/\\' ) . '/estasoft-migrate-' . $settings['storage_token'];
		if ( is_dir( $dir ) ) {
			$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $items as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	// Tables and options.
	foreach ( array( 'jobs', 'logs', 'baselines' ) as $short ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'estasoft_' . $short . '`' ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A table of this plugin; $short comes from the fixed list above.
	}
	delete_option( 'estasoft_migrate_settings' );
	delete_option( 'estasoft_migrate_block_mail' );
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_estm_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_estm_' ) . '%'
		)
	);
}

estasoft_migrate_uninstall();
