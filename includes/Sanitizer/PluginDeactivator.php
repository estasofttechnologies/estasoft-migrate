<?php
/**
 * Removes payment, SMTP and analytics plugins from the active plugin list.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Works on the stored value of the active_plugins option, so it also works on a database that
 * is not the one WordPress is currently running on.
 */
final class PluginDeactivator {

	/**
	 * Plugin folders of payment gateways.
	 */
	const PAYMENT = array(
		'woocommerce-gateway-stripe',
		'woocommerce-payments',
		'woocommerce-paypal-payments',
		'woocommerce-gateway-paypal-express-checkout',
		'woocommerce-gateway-paypal-powered-by-braintree',
		'woocommerce-square',
		'woocommerce-gateway-authorize-net-cim',
		'woocommerce-gateway-amazon-payments-advanced',
		'woocommerce-paypal-pro',
		'klarna-payments-for-woocommerce',
		'klarna-checkout-for-woocommerce',
		'mollie-payments-for-woocommerce',
		'razorpay-for-woocommerce',
		'paypal-for-woocommerce',
		'mercadopago',
		'woo-mercado-pago-module',
		'payfast-gateway-for-woocommerce',
		'woocommerce-gateway-payfast',
		'paystack-payments',
		'flutterwave-payments',
		'wp-simple-pay-lite-for-stripe',
		'stripe-payments',
		'easy-digital-downloads-stripe',
		'give',
		'paid-memberships-pro',
		'restrict-content-pro',
	);

	/**
	 * Plugin folders of mail relays and analytics or tracking tools.
	 */
	const SMTP_ANALYTICS = array(
		'wp-mail-smtp',
		'wp-mail-smtp-pro',
		'post-smtp',
		'fluent-smtp',
		'easy-wp-smtp',
		'wp-smtp',
		'smtp-mailer',
		'mailgun',
		'sendgrid-email-delivery-simplified',
		'mailin',
		'google-site-kit',
		'google-analytics-for-wordpress',
		'google-analytics-dashboard-for-wp',
		'ga-google-analytics',
		'matomo',
		'wp-statistics',
		'pixelyoursite',
		'official-facebook-pixel',
		'facebook-for-woocommerce',
		'hotjar',
		'duracelltomi-google-tag-manager',
		'gtm4wp',
		'monsterinsights-pro',
		'exactmetrics-premium',
	);

	/**
	 * Remove plugins from an active_plugins value.
	 *
	 * @param string   $stored  Stored option value (serialized array of plugin files).
	 * @param string[] $folders Plugin folders to deactivate.
	 * @return array{0:string, 1:string[]} New stored value and the plugin files that were removed.
	 */
	public static function filter( $stored, array $folders ) {
		$list = @unserialize( $stored, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions
		if ( ! is_array( $list ) ) {
			return array( $stored, array() );
		}
		$removed = array();
		$kept    = array();
		foreach ( $list as $file ) {
			if ( is_string( $file ) && self::matches( $file, $folders ) ) {
				$removed[] = $file;
			} else {
				$kept[] = $file;
			}
		}
		if ( ! $removed ) {
			return array( $stored, array() );
		}
		return array( serialize( array_values( $kept ) ), $removed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Does a plugin file belong to one of the folders?
	 *
	 * @param string   $file    Plugin file such as "wp-mail-smtp/wp_mail_smtp.php".
	 * @param string[] $folders Folders.
	 * @return bool
	 */
	private static function matches( $file, array $folders ) {
		$folder = false === strpos( $file, '/' ) ? preg_replace( '/\.php$/', '', $file ) : substr( $file, 0, (int) strpos( $file, '/' ) );
		foreach ( $folders as $f ) {
			if ( 0 === strcasecmp( $folder, $f ) ) {
				return true;
			}
		}
		return false;
	}
}
