<?php
/**
 * Empties secrets inside settings values.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Finds secret-looking keys (API keys, tokens, passwords) in serialized or JSON option values and empties them.
 */
final class SecretScrubber {

	const KEY_PATTERN = '/(api[_\-]?key|secret|token|passw(or)?d|passwd|license|licence|client[_\-]?id|access[_\-]?key|private[_\-]?key|webhook|auth[_\-]?key|signing|credentials?|bearer|smtp[_\-]?pass|salt|logged[_\-]?in[_\-]?key|nonce[_\-]?key)/i';

	/**
	 * Option names that hold payment, SMTP or analytics settings. The whole value is emptied.
	 */
	const OPTION_PATTERN = '/^(woocommerce_(stripe|paypal|ppcp|square|amazon|klarna|braintree|authorize|mollie|payfast)[\w\-]*(_settings|_gateway)?|wp_mail_smtp|postman_options|postman_auth_token|fluentmail-settings|easy_wp_smtp|swpsmtp_options|mailserver_(login|pass)|googlesitekit_[\w]*(credentials|oauth|secret)[\w]*|monsterinsights_[\w]*(auth|key)[\w]*|jetpack_private_options|mailgun|sendgrid[\w\-]*)$/i';

	/**
	 * Does this option name hold secrets by its name alone?
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	public static function is_secret_option( $option_name ) {
		return 1 === preg_match( self::OPTION_PATTERN, $option_name )
			|| 1 === preg_match( self::KEY_PATTERN, $option_name );
	}

	/**
	 * Empty secret keys inside a value.
	 *
	 * @param string $value Option value (plain, serialized or JSON).
	 * @return string The scrubbed value, or the input when nothing needed to change.
	 */
	public static function scrub( $value ) {
		if ( '' === $value ) {
			return $value;
		}
		if ( \Estasoft\Migrate\Replace\Serialized::looks_serialized( $value ) ) {
			// Objects are not created: classes are not allowed, so no code can run.
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions
			if ( is_array( $data ) ) {
				$changed = false;
				$data    = self::walk( $data, $changed );
				return $changed ? serialize( $data ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
			return $value;
		}
		if ( \Estasoft\Migrate\Replace\JsonText::looks_json( $value ) ) {
			// Objects stay objects ({} must not become []), and a value with no secret is returned exactly as it was.
			$data = json_decode( $value );
			if ( is_array( $data ) || is_object( $data ) ) {
				$changed = false;
				$data    = self::walk( $data, $changed );
				if ( ! $changed ) {
					return $value;
				}
				$out = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				return false === $out ? $value : $out;
			}
		}
		return $value;
	}

	/**
	 * Walk an array or object and empty the secrets in it.
	 *
	 * @param array|object $data    Data.
	 * @param bool         $changed Set to true when something was emptied.
	 * @return array|object
	 */
	private static function walk( $data, &$changed ) {
		foreach ( $data as $key => $item ) {
			if ( is_array( $item ) || is_object( $item ) ) {
				$item = self::walk( $item, $changed );
			} elseif ( is_string( $key ) && 1 === preg_match( self::KEY_PATTERN, $key ) && is_string( $item ) && '' !== $item ) {
				$item    = '';
				$changed = true;
			} else {
				continue;
			}
			if ( is_object( $data ) ) {
				$data->$key = $item;
			} else {
				$data[ $key ] = $item;
			}
		}
		return $data;
	}
}
