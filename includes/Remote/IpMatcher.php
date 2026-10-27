<?php
/**
 * IP address allow list matching (IPv4, IPv6 and CIDR ranges).
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Remote;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Pure functions, no WordPress needed.
 */
final class IpMatcher {

	/**
	 * Is this a valid address or range?
	 *
	 * @param string $entry For example 203.0.113.7, 203.0.113.0/24 or 2001:db8::/32.
	 * @return bool
	 */
	public static function is_valid_entry( $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			return false;
		}
		if ( false === strpos( $entry, '/' ) ) {
			return false !== filter_var( $entry, FILTER_VALIDATE_IP );
		}
		list( $ip, $bits ) = explode( '/', $entry, 2 );
		if ( ! ctype_digit( $bits ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$max = false !== strpos( $ip, ':' ) ? 128 : 32;
		return (int) $bits <= $max;
	}

	/**
	 * Does the address match any entry of the list? An empty list matches everything.
	 *
	 * @param string          $ip   Visitor address.
	 * @param string|string[] $list Entries, as an array or one per line.
	 * @return bool
	 */
	public static function matches( $ip, $list ) {
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\s,]+/', trim( $list ), -1, PREG_SPLIT_NO_EMPTY );
		}
		$list = array_values( array_filter( (array) $list, array( __CLASS__, 'is_valid_entry' ) ) );
		if ( ! $list ) {
			return true;
		}
		$packed = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $packed ) {
			return false;
		}
		foreach ( $list as $entry ) {
			if ( self::in_range( $packed, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compare one packed address with one entry.
	 *
	 * @param string $packed Packed address.
	 * @param string $entry  Entry.
	 * @return bool
	 */
	private static function in_range( $packed, $entry ) {
		$bits = null;
		if ( false !== strpos( $entry, '/' ) ) {
			list( $entry, $bits ) = explode( '/', $entry, 2 );
			$bits                 = (int) $bits;
		}
		$net = inet_pton( $entry );
		if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
			// An IPv4 address may arrive as an IPv4-mapped IPv6 address.
			if ( 16 === strlen( $packed ) && 4 === strlen( (string) $net ) && 0 === strncmp( $packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12 ) ) {
				return self::in_range( substr( $packed, 12 ), $entry . ( null === $bits ? '' : '/' . $bits ) );
			}
			return false;
		}
		if ( null === $bits ) {
			return $net === $packed;
		}
		$bytes = intdiv( $bits, 8 );
		if ( $bytes > 0 && 0 !== strncmp( $net, $packed, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $net[ $bytes ] ) & $mask ) === ( ord( $packed[ $bytes ] ) & $mask );
	}
}
