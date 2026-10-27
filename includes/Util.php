<?php
/**
 * Small helpers.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate;

defined( 'ABSPATH' ) || exit;

/**
 * Formatting and parsing helpers that do not need WordPress.
 */
final class Util {

	/**
	 * Convert a php.ini size such as 256M to bytes. Returns 0 for "unlimited" (-1).
	 *
	 * @param string|int $value Size.
	 * @return int
	 */
	public static function bytes_from_ini( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}
		$number = (float) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}
		return (int) $number;
	}

	/**
	 * Human-readable size, such as "412 MB".
	 *
	 * @param int|float $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		$last  = count( $units ) - 1;
		while ( $bytes >= 1024 && $i < $last ) {
			$bytes /= 1024;
			++$i;
		}
		$decimals = ( $i >= 2 && $bytes < 10 ) ? 1 : 0;
		if ( $i >= 3 && $bytes < 100 ) {
			$decimals = 1;
		}
		return number_format( $bytes, $decimals, '.', ',' ) . ' ' . $units[ $i ];
	}

	/**
	 * Format a whole number with thousands separators (locale aware when WordPress is loaded).
	 *
	 * @param int|float $n Number.
	 * @return string
	 */
	public static function number( $n ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( $n ) : number_format( $n );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Remove a table prefix from a name, or return the name if it does not start with it.
	 *
	 * @param string $table  Table name.
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public static function unprefix( $table, $prefix ) {
		return ( '' !== $prefix && 0 === strpos( $table, $prefix ) ) ? substr( $table, strlen( $prefix ) ) : $table;
	}

	/**
	 * Turn a value into a list of unique non-empty strings.
	 *
	 * @param mixed $value Array or newline/comma separated text.
	 * @return string[]
	 */
	public static function to_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out = array();
		foreach ( (array) $value as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$out[ $item ] = $item;
			}
		}
		return array_values( $out );
	}
}
