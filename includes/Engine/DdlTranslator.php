<?php
/**
 * Adapts CREATE TABLE statements to the destination server.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\Database;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Handles the usual MySQL / MariaDB differences: collations the destination does not know
 * (utf8mb4_0900_ai_ci on MariaDB, for example), foreign keys, and engine-specific options.
 */
final class DdlTranslator {

	/**
	 * Ordered fallbacks for collations that older or different servers lack.
	 */
	const FALLBACKS = array(
		'utf8mb4_0900_ai_ci'     => array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ),
		'utf8mb4_0900_as_cs'     => array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ),
		'utf8mb4_0900_bin'       => array( 'utf8mb4_bin' ),
		'utf8mb4_uca1400_ai_ci'  => array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ),
		'utf8mb4_uca1400_as_cs'  => array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ),
		'utf8mb4_unicode_520_ci' => array( 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ),
		'utf8mb4_unicode_ci'     => array( 'utf8mb4_general_ci' ),
		'utf8mb3_general_ci'     => array( 'utf8_general_ci' ),
		'utf8mb3_unicode_ci'     => array( 'utf8_unicode_ci', 'utf8_general_ci' ),
		'utf8mb3_bin'            => array( 'utf8_bin' ),
		'utf8_general_ci'        => array( 'utf8mb3_general_ci' ),
		'utf8_unicode_ci'        => array( 'utf8mb3_unicode_ci' ),
		'utf8_bin'               => array( 'utf8mb3_bin' ),
	);

	/**
	 * Translate a statement.
	 *
	 * @param string   $ddl       CREATE TABLE statement from the source.
	 * @param string   $new_name  Name of the table to create.
	 * @param Database $target    Destination database.
	 * @param string   $force     Collation to use everywhere, empty to keep the source collations where possible.
	 * @return array{0:string, 1:string[]} New statement and notes about what was changed.
	 */
	public static function translate( $ddl, $new_name, Database $target, $force = '' ) {
		$notes = array();
		$type  = $target->server_info()['type'];

		// Table name.
		$ddl = preg_replace( '/^CREATE TABLE (?:IF NOT EXISTS )?`[^`]+`/i', 'CREATE TABLE `' . $new_name . '`', $ddl, 1 );

		if ( 'sqlite' === $type ) {
			return array( (string) $ddl, $notes );
		}

		// Foreign keys point at tables that are staged under other names, so they are left out.
		$count = 0;
		$ddl   = preg_replace( '/,?\s*\n\s*CONSTRAINT `[^`]+` FOREIGN KEY[^\n]*/i', '', (string) $ddl, -1, $count );
		if ( $count ) {
			$notes[] = 'Foreign key constraints were left out.';
		}
		$ddl = preg_replace( '/,(\s*\n\s*\)\s*(ENGINE|DEFAULT|AUTO_INCREMENT|CHARSET|COLLATE|ROW_FORMAT|COMMENT))/i', '$1', (string) $ddl );

		// Options other servers do not understand.
		$ddl = preg_replace( '/\s+(PAGE_CHECKSUM|TRANSACTIONAL)=\d+/i', '', (string) $ddl );
		if ( 'mariadb' !== $type ) {
			$ddl = preg_replace( '/\s+CHECK\s*\(\s*json_valid\(`[^`]+`\)\s*\)/i', '', (string) $ddl );
		}

		// Collations.
		preg_match_all( '/COLLATE[= ]+([a-z0-9_]+)/i', (string) $ddl, $m );
		foreach ( array_unique( $m[1] ) as $collation ) {
			$replacement = '' !== $force ? $force : self::supported( $collation, $target );
			if ( $replacement !== $collation ) {
				$ddl     = preg_replace( '/(COLLATE[= ]+)' . preg_quote( $collation, '/' ) . '\b/i', '${1}' . $replacement, (string) $ddl );
				$notes[] = sprintf( 'Collation %1$s became %2$s.', $collation, $replacement );
			}
		}
		if ( '' !== $force ) {
			$charset = (string) strtok( $force, '_' );
			$ddl     = preg_replace( '/(CHARSET=|CHARACTER SET )[a-z0-9_]+/i', '${1}' . $charset, (string) $ddl );
		}

		return array( (string) $ddl, array_values( array_unique( $notes ) ) );
	}

	/**
	 * The collation itself if the target knows it, otherwise the first fallback that exists.
	 *
	 * @param string   $collation Source collation.
	 * @param Database $target    Destination.
	 * @return string
	 */
	public static function supported( $collation, Database $target ) {
		if ( $target->has_collation( $collation ) ) {
			return $collation;
		}
		foreach ( self::FALLBACKS[ strtolower( $collation ) ] ?? array() as $candidate ) {
			if ( $target->has_collation( $candidate ) ) {
				return $candidate;
			}
		}
		return $collation;
	}
}
