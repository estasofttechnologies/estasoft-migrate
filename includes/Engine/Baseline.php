<?php
/**
 * Checksums kept after each migration so a later delta sync knows what changed.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\Database;
use Estasoft\Migrate\Db\Identifier;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Phase 1 only records the data (delta sync itself comes in Phase 2). For every table we keep a
 * row count, the highest key and an order-independent checksum, plus a checksum per block of 1000
 * ids for tables with a single integer key. Comparing two baselines later shows which blocks changed.
 */
final class Baseline {

	const BUCKET = 1000;

	/**
	 * Start tracking a table.
	 *
	 * @param string[] $pk Primary key columns.
	 * @return array
	 */
	public static function start( array $pk ) {
		return array(
			'n'   => 0,
			'sum' => 0,
			'max' => null,
			'pk'  => 1 === count( $pk ) ? $pk[0] : null,
			'bs'  => self::BUCKET,
			'b'   => array(),
		);
	}

	/**
	 * Add one source row (before any masking or rewriting).
	 *
	 * @param array $st  Tracker from start(), updated in place.
	 * @param array $row Row.
	 * @return void
	 */
	public static function add( array &$st, array $row ) {
		$crc = crc32( implode( "\x1f", array_map( array( __CLASS__, 'cell' ), $row ) ) );
		++$st['n'];
		$st['sum'] = ( $st['sum'] + $crc ) % 4294967296;

		$bucket = 0;
		if ( null !== $st['pk'] && isset( $row[ $st['pk'] ] ) ) {
			$key = (string) $row[ $st['pk'] ];
			if ( ctype_digit( $key ) ) {
				$bucket = intdiv( (int) $key, (int) $st['bs'] );
				if ( null === $st['max'] || (int) $key > (int) $st['max'] ) {
					$st['max'] = $key;
				}
			} elseif ( null === $st['max'] || strcmp( $key, (string) $st['max'] ) > 0 ) {
				$st['max'] = $key;
			}
		}
		if ( ! isset( $st['b'][ $bucket ] ) ) {
			$st['b'][ $bucket ] = array( 0, 0 );
		}
		++$st['b'][ $bucket ][0];
		$st['b'][ $bucket ][1] = ( $st['b'][ $bucket ][1] + $crc ) % 4294967296;

		// Keep the block list small on very large tables by merging neighbouring blocks.
		if ( count( $st['b'] ) > 2000 ) {
			$merged = array();
			foreach ( $st['b'] as $k => $v ) {
				$m = intdiv( (int) $k, 2 );
				if ( ! isset( $merged[ $m ] ) ) {
					$merged[ $m ] = array( 0, 0 );
				}
				$merged[ $m ][0] += $v[0];
				$merged[ $m ][1]  = ( $merged[ $m ][1] + $v[1] ) % 4294967296;
			}
			$st['b']   = $merged;
			$st['bs'] *= 2;
		}
	}

	/**
	 * Cell to text.
	 *
	 * @param mixed $v Cell.
	 * @return string
	 */
	private static function cell( $v ) {
		return null === $v ? "\0" : (string) $v;
	}

	/**
	 * Final numbers for a table.
	 *
	 * @param array $st Tracker.
	 * @return array rows, checksum, max, buckets.
	 */
	public static function result( array $st ) {
		return array(
			'rows'     => $st['n'],
			'checksum' => sprintf( '%08x', $st['sum'] ),
			'max'      => $st['max'],
			'buckets'  => array(
				'size'   => $st['bs'],
				'blocks' => $st['b'],
			),
		);
	}

	/**
	 * Store the result for a table.
	 *
	 * @param Database $db     Database.
	 * @param string   $table  Baselines table.
	 * @param string   $peer   Peer id (the other site, or "export").
	 * @param string   $name   Source table name.
	 * @param array    $result From result().
	 * @param int      $job_id Job id.
	 * @return void
	 */
	public static function save( Database $db, $table, $peer, $name, array $result, $job_id ) {
		$db->query(
			'DELETE FROM ' . Identifier::quote( $table ) . ' WHERE peer = ' . $db->quote( $peer ) . ' AND table_name = ' . $db->quote( $name )
		);
		$db->insert_rows(
			$table,
			array( 'peer', 'table_name', 'row_count', 'max_key', 'checksum', 'buckets', 'watermark', 'job_id', 'created_gmt' ),
			array(
				array(
					$peer,
					$name,
					(string) $result['rows'],
					null === $result['max'] ? null : (string) $result['max'],
					$result['checksum'],
					json_encode( $result['buckets'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
					json_encode( array( 'max_key' => $result['max'] ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
					(string) $job_id,
					Util::now(),
				),
			),
			'insert'
		);
	}

	/**
	 * Move provisional baselines to their final peer once the job succeeded.
	 *
	 * @param Database $db    Database.
	 * @param string   $table Baselines table.
	 * @param string   $from  Provisional peer.
	 * @param string   $to    Final peer.
	 * @return void
	 */
	public static function promote( Database $db, $table, $from, $to ) {
		$t = Identifier::quote( $table );
		$db->query(
			'DELETE FROM ' . $t . ' WHERE peer = ' . $db->quote( $to ) . ' AND table_name IN (SELECT table_name FROM (SELECT table_name FROM ' . $t . ' WHERE peer = ' . $db->quote( $from ) . ') AS x)'
		);
		$db->query( 'UPDATE ' . $t . ' SET peer = ' . $db->quote( $to ) . ' WHERE peer = ' . $db->quote( $from ) );
	}

	/**
	 * Drop provisional baselines (job failed or was cancelled).
	 *
	 * @param Database $db    Database.
	 * @param string   $table Baselines table.
	 * @param string   $peer  Provisional peer.
	 * @return void
	 */
	public static function discard( Database $db, $table, $peer ) {
		$db->query( 'DELETE FROM ' . Identifier::quote( $table ) . ' WHERE peer = ' . $db->quote( $peer ) );
	}
}
