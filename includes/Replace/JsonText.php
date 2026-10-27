<?php
/**
 * Rewrites string values inside JSON text and leaves everything else byte-for-byte unchanged.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Replace;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Decoding and re-encoding a whole JSON document would change spacing, number formats and
 * escaping. This class only touches the string literals whose content actually changes, and
 * encodes them in the same style the document already uses.
 */
final class JsonText {

	/**
	 * Does the text look like a JSON object or array?
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function looks_json( $s ) {
		$t = trim( $s );
		$n = strlen( $t );
		if ( $n < 2 ) {
			return false;
		}
		$first = $t[0];
		$last  = $t[ $n - 1 ];
		return ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last );
	}

	/**
	 * Is the text valid JSON?
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function is_valid( $s ) {
		json_decode( $s );
		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * Rewrite string values (and, if wanted, object keys).
	 *
	 * @param string        $s        Valid JSON text.
	 * @param callable      $leaf     Receives a decoded string value, returns the new string.
	 * @param callable|null $key_leaf Receives a decoded key, returns the new key. Null leaves keys alone.
	 * @return string
	 */
	public static function transform( $s, callable $leaf, $key_leaf = null ) {
		$flags = JSON_PRESERVE_ZERO_FRACTION;
		if ( false === strpos( $s, '\\/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( 1 !== preg_match( '/\\\\u(?!00[0-7][0-9a-f])[0-9a-f]{4}/i', $s ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}

		$result = preg_replace_callback(
			'/"(?:[^"\\\\]++|\\\\.)*+"(\s*:)?/s',
			static function ( $m ) use ( $leaf, $key_leaf, $flags ) {
				$is_key  = isset( $m[1] ) && '' !== $m[1];
				$literal = $is_key ? substr( $m[0], 0, -strlen( $m[1] ) ) : $m[0];
				$colon   = $is_key ? $m[1] : '';

				$callback = $is_key ? $key_leaf : $leaf;
				if ( null === $callback ) {
					return $m[0];
				}
				$decoded = json_decode( $literal );
				if ( ! is_string( $decoded ) ) {
					return $m[0];
				}
				$new = (string) call_user_func( $callback, $decoded );
				if ( $new === $decoded ) {
					return $m[0];
				}
				$encoded = json_encode( $new, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Needs the exact flags of the original text, and must work without WordPress.
				if ( false === $encoded ) {
					return $m[0];
				}
				return $encoded . $colon;
			},
			$s
		);

		return null === $result ? $s : $result;
	}
}
