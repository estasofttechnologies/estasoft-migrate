<?php
/**
 * Staging safety switches.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

use Estasoft\Migrate\Db\Database;
use Estasoft\Migrate\Db\Identifier;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a copy safe to run: no mail to real people, no search engine indexing, no live payments,
 * no mail relay or tracking. The switches are applied to the destination database after an import.
 */
final class SafetySwitches {

	const MAIL_OPTION = 'estasoft_migrate_block_mail';

	/**
	 * Register the runtime hook that blocks outgoing email.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'maybe_block_mail' ), 1, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Is outgoing email blocked on this site?
	 *
	 * @return bool
	 */
	public static function is_mail_blocked() {
		return '1' === (string) get_option( self::MAIL_OPTION, '0' );
	}

	/**
	 * Short-circuit wp_mail() while the switch is on.
	 *
	 * @param null|bool $pre  Existing short-circuit value.
	 * @param array     $atts wp_mail() arguments.
	 * @return null|bool
	 */
	public static function maybe_block_mail( $pre, $atts = array() ) {
		if ( null !== $pre || ! self::is_mail_blocked() ) {
			return $pre;
		}
		$to = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : (string) $atts['to'] ) : '';
		/**
		 * Fires when an email is blocked by Estasoft Migrate.
		 *
		 * @param string $to Recipients.
		 */
		do_action( 'estasoft_migrate_mail_blocked', $to );
		return true;
	}

	/**
	 * Remind administrators that email is blocked.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( ! self::is_mail_blocked() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'estasoft-migrate' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Outgoing email is blocked on this site by Estasoft Migration & Database Sanitizer, so no real person receives messages from this copy.', 'estasoft-migrate' ),
			esc_url( admin_url( 'admin.php?page=estasoft-migrate-settings' ) ),
			esc_html__( 'Change this', 'estasoft-migrate' )
		);
	}

	/**
	 * Apply the switches to a database.
	 *
	 * @param Database $db     Destination database.
	 * @param string   $prefix Table prefix of the destination.
	 * @param array    $flags  mail, seo, pay, smtp => bool.
	 * @return string[] Plain-language list of what was changed.
	 */
	public static function apply( Database $db, $prefix, array $flags ) {
		$done  = array();
		$table = $prefix . 'options';
		if ( ! $db->table_exists( $table ) ) {
			return $done;
		}

		if ( ! empty( $flags['mail'] ) ) {
			self::set_option( $db, $table, self::MAIL_OPTION, '1' );
			$done[] = __( 'Outgoing email is blocked.', 'estasoft-migrate' );
		}
		if ( ! empty( $flags['seo'] ) ) {
			self::set_option( $db, $table, 'blog_public', '0' );
			$done[] = __( 'Search engines are asked not to index the site.', 'estasoft-migrate' );
		}

		$folders = array();
		if ( ! empty( $flags['pay'] ) ) {
			$folders = array_merge( $folders, PluginDeactivator::PAYMENT );
		}
		if ( ! empty( $flags['smtp'] ) ) {
			$folders = array_merge( $folders, PluginDeactivator::SMTP_ANALYTICS );
		}
		if ( $folders ) {
			$stored = $db->get_var( 'SELECT option_value FROM ' . Identifier::quote( $table ) . ' WHERE option_name = %s', array( 'active_plugins' ) );
			if ( null !== $stored ) {
				list( $new, $removed ) = PluginDeactivator::filter( $stored, $folders );
				if ( $removed ) {
					self::set_option( $db, $table, 'active_plugins', $new );
					foreach ( $removed as $file ) {
						/* translators: %s: plugin file */
						$done[] = sprintf( __( 'Deactivated %s.', 'estasoft-migrate' ), $file );
					}
				}
			}
		}
		return $done;
	}

	/**
	 * Write an option row directly.
	 *
	 * @param Database $db    Database.
	 * @param string   $table Options table.
	 * @param string   $name  Option name.
	 * @param string   $value Value.
	 * @return void
	 */
	public static function set_option( Database $db, $table, $name, $value ) {
		$exists = $db->get_var( 'SELECT COUNT(*) FROM ' . Identifier::quote( $table ) . ' WHERE option_name = %s', array( $name ) );
		if ( (int) $exists > 0 ) {
			$db->query( 'UPDATE ' . Identifier::quote( $table ) . ' SET option_value = ' . $db->quote( $value ) . ' WHERE option_name = ' . $db->quote( $name ) );
		} else {
			$db->query( 'INSERT INTO ' . Identifier::quote( $table ) . ' (option_name, option_value, autoload) VALUES (' . $db->quote( $name ) . ', ' . $db->quote( $value ) . ", 'no')" );
		}
	}
}
