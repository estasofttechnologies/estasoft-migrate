<?php
/**
 * The position of a job in a table, kept safe for storage as JSON.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * A table is read in key order, and the last key read is saved with the job. Keys can be raw bytes
 * (a BINARY(16) UUID, for example), which JSON cannot hold, so those are saved as hex and turned
 * back into bytes before they are used in a query.
 */
final class Cursor {

	/**
	 * Key values of a row, in a form that survives JSON.
	 *
	 * @param array    $row     A row as read from the database.
	 * @param string[] $columns Key columns.
	 * @return array
	 */
	public static function pack( array $row, array $columns ) {
		$out = array();
		foreach ( $columns as $column ) {
			$value = (string) $row[ $column ];
			$out[] = self::is_text( $value ) ? $value : array( 'x' => bin2hex( $value ) );
		}
		return $out;
	}

	/**
	 * The key values as the database returned them.
	 *
	 * @param array|null $cursor A saved cursor.
	 * @return string[]|null Null at the start of a table.
	 */
	public static function unpack( $cursor ) {
		if ( ! is_array( $cursor ) || ! $cursor ) {
			return null;
		}
		$out = array();
		foreach ( array_values( $cursor ) as $value ) {
			if ( is_array( $value ) ) {
				$hex   = isset( $value['x'] ) ? (string) $value['x'] : '';
				$bytes = 1 === preg_match( '/^(?:[0-9a-f]{2})*$/i', $hex ) ? hex2bin( $hex ) : false;
				$out[] = false === $bytes ? '' : $bytes;
			} else {
				$out[] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Is this value safe to save as JSON text?
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_text( $value ) {
		return false === strpos( $value, "\0" ) && 1 === preg_match( '//u', $value );
	}
}
