<?php
/**
 * Adapts CREATE TABLE statements to the destination server.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\Database;

defined( 'ABSPATH' ) || exit;

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
		$ddl = self::without_foreign_keys( (string) $ddl, $count );
		if ( $count ) {
			$notes[] = 'Foreign key constraints were left out.';
		}

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
	 * Is this exactly one CREATE TABLE statement for the named table and nothing else?
	 *
	 * A package comes from somewhere else, so its table definition is checked before it is run: it has to start with
	 * CREATE TABLE for the staging name, hold a single statement and not copy rows from another table. Quoted text and
	 * comments are skipped while looking, so a semicolon inside a default value is fine.
	 *
	 * @param string $ddl  Statement after translation.
	 * @param string $name Name the table must have.
	 * @return bool
	 */
	public static function is_single_create_table( $ddl, $name ) {
		$ddl = (string) $ddl;
		if ( '' === $ddl || strlen( $ddl ) > 1048576 || 1 !== preg_match( '//u', $ddl ) ) {
			return false;
		}
		$head = 'CREATE TABLE `' . $name . '` (';
		if ( 0 !== strpos( $ddl, $head ) ) {
			return false;
		}
		$bare = '';
		$len  = strlen( $ddl );
		$i    = 0;
		while ( $i < $len ) {
			$c = $ddl[ $i ];
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				// Skip a quoted string; a doubled quote or a backslash escapes the next character.
				for ( $j = $i + 1; $j < $len; $j++ ) {
					if ( '\\' === $ddl[ $j ] && '`' !== $c ) {
						++$j;
						continue;
					}
					if ( $ddl[ $j ] === $c ) {
						if ( $j + 1 < $len && $ddl[ $j + 1 ] === $c ) {
							++$j;
							continue;
						}
						break;
					}
				}
				if ( $j >= $len ) {
					return false; // Quote never closed.
				}
				$bare .= ' ';
				$i     = $j + 1;
				continue;
			}
			if ( '#' === $c || ( '-' === $c && '-' === ( $ddl[ $i + 1 ] ?? '' ) ) ) {
				return false; // SHOW CREATE TABLE never prints a line comment.
			}
			if ( '/' === $c && '*' === ( $ddl[ $i + 1 ] ?? '' ) ) {
				// Version comments (/*!50100 ... */, /*M!100001 ... */) are run by the server, so their content is looked at too.
				if ( 1 === preg_match( '/\G\/\*(?:M?!\d*)/A', $ddl, $m, 0, $i ) ) {
					$i += strlen( $m[0] );
					continue;
				}
				$end = strpos( $ddl, '*/', $i + 2 );
				if ( false === $end ) {
					return false;
				}
				$bare .= ' ';
				$i     = $end + 2;
				continue;
			}
			$bare .= $c;
			++$i;
		}
		if ( false !== strpos( $bare, ';' ) || false !== strpos( $bare, '\\' ) ) {
			return false;
		}
		// A table definition does not copy rows from another table (UPDATE, DELETE and SET are normal words in column definitions).
		if ( 1 === preg_match( '/\bSELECT\b/i', $bare ) ) {
			return false;
		}
		$depth = 0;
		$seen  = false;
		foreach ( str_split( $bare ) as $ch ) {
			if ( '(' === $ch ) {
				++$depth;
				$seen = true;
			} elseif ( ')' === $ch ) {
				--$depth;
				if ( $depth < 0 ) {
					return false;
				}
			}
		}
		return $seen && 0 === $depth;
	}

	/**
	 * Take the foreign key lines out of a CREATE TABLE statement and keep the commas between the remaining
	 * definitions right (a key or CHECK line may follow the one that was removed).
	 *
	 * @param string $ddl   Statement as SHOW CREATE TABLE prints it: one definition per line.
	 * @param int    $count Number of lines removed.
	 * @return string
	 */
	public static function without_foreign_keys( $ddl, &$count = 0 ) {
		$lines = explode( "\n", $ddl );
		$kept  = array();
		$count = 0;
		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^\s*CONSTRAINT `[^`]+` FOREIGN KEY/i', $line ) ) {
				++$count;
				continue;
			}
			$kept[] = $line;
		}
		if ( ! $count ) {
			return $ddl;
		}
		$close = 0;
		for ( $i = count( $kept ) - 1; $i > 0; $i-- ) {
			if ( 1 === preg_match( '/^\s*\)/', $kept[ $i ] ) ) {
				$close = $i;
				break;
			}
		}
		if ( $close > 1 ) {
			// Definitions sit on lines 1 .. close-1: all but the last end with a comma.
			for ( $j = 1; $j < $close - 1; $j++ ) {
				if ( ',' !== substr( rtrim( $kept[ $j ] ), -1 ) ) {
					$kept[ $j ] = rtrim( $kept[ $j ] ) . ',';
				}
			}
			$kept[ $close - 1 ] = rtrim( rtrim( $kept[ $close - 1 ] ), ',' );
		}
		return implode( "\n", $kept );
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
