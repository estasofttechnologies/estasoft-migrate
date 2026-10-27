<?php
/**
 * Binary-safe row encoding.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Package;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Packs a batch of database rows into JSON.
 *
 * Every cell is a string or null. Text that is not valid UTF-8 (BLOB data, for example) is
 * stored as {"b": "<base64>"}, so any value survives the trip unchanged.
 */
final class RowCodec {

	const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

	/**
	 * Encode rows.
	 *
	 * @param string   $table   Table the rows belong to.
	 * @param string[] $columns Column order.
	 * @param array[]  $rows    Rows as column => value maps.
	 * @return string JSON.
	 * @throws PackageException When the data cannot be encoded.
	 */
	public static function encode( $table, array $columns, array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $columns as $col ) {
				$v = $row[ $col ] ?? null;
				if ( null !== $v && 1 !== preg_match( '//u', (string) $v ) ) {
					$v = array( 'b' => base64_encode( (string) $v ) );
				} elseif ( null !== $v ) {
					$v = (string) $v;
				}
				$line[] = $v;
			}
			$out[] = $line;
		}
		$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				't' => $table,
				'c' => array_values( $columns ),
				'r' => $out,
			),
			self::FLAGS
		);
		if ( false === $json ) {
			throw new PackageException( 'Rows of ' . $table . ' could not be encoded: ' . json_last_error_msg() );
		}
		return $json;
	}

	/**
	 * Decode rows.
	 *
	 * @param array $data Decoded JSON of an R frame.
	 * @return array{table:string, columns:string[], rows:array[]} Rows as lists in column order.
	 * @throws PackageException When the data is malformed.
	 */
	public static function decode( array $data ) {
		if ( ! isset( $data['t'], $data['c'], $data['r'] ) || ! is_array( $data['r'] ) ) {
			throw new PackageException( 'A rows block is malformed.' );
		}
		$rows = array();
		foreach ( $data['r'] as $line ) {
			foreach ( $line as $i => $v ) {
				if ( is_array( $v ) ) {
					$line[ $i ] = isset( $v['b'] ) ? (string) base64_decode( (string) $v['b'], true ) : '';
				}
			}
			$rows[] = $line;
		}
		return array(
			'table'   => (string) $data['t'],
			'columns' => array_map( 'strval', (array) $data['c'] ),
			'rows'    => $rows,
		);
	}
}
