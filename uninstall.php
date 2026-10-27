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

wp_clear_scheduled_hook( 'estasoft_migrate_daily' );
wp_unschedule_hook( 'estasoft_migrate_run_job' );

$estm_settings = get_option( 'estasoft_migrate_settings', array() );
if ( ! is_array( $estm_settings ) || empty( $estm_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Packages and snapshots.
if ( ! empty( $estm_settings['storage_token'] ) ) {
	$estm_uploads = wp_upload_dir( null, false );
	$estm_dir     = rtrim( $estm_uploads['basedir'], '/\\' ) . '/estasoft-migrate-' . $estm_settings['storage_token'];
	if ( is_dir( $estm_dir ) ) {
		$estm_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $estm_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $estm_items as $estm_item ) {
			$estm_item->isDir() ? rmdir( $estm_item->getPathname() ) : wp_delete_file( $estm_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		rmdir( $estm_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

// Tables and options.
foreach ( array( 'jobs', 'logs', 'baselines' ) as $estm_short ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'estasoft_' . $estm_short . '`' ); // phpcs:ignore WordPress.DB
}
delete_option( 'estasoft_migrate_settings' );
delete_option( 'estasoft_migrate_block_mail' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_estm\_%' OR option_name LIKE '\_transient\_timeout\_estm\_%'" ); // phpcs:ignore WordPress.DB
