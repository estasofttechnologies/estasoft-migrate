<?php
/**
 * SQL identifier helpers.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Db;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Table and column names cannot be bound as parameters, so every name is checked and quoted here.
 */
final class Identifier {

	/**
	 * Is this a name we are willing to put into SQL?
	 *
	 * @param string $name Table or column name.
	 * @return bool
	 */
	public static function is_safe( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9_$\-]{1,128}$/', $name );
	}

	/**
	 * Quote a name with backticks.
	 *
	 * @param string $name Table or column name.
	 * @return string
	 * @throws \InvalidArgumentException When the name holds unexpected characters.
	 */
	public static function quote( $name ) {
		if ( ! self::is_safe( $name ) ) {
			throw new \InvalidArgumentException( 'Unsupported database name: ' . substr( (string) $name, 0, 80 ) );
		}
		return '`' . $name . '`';
	}

	/**
	 * Quote a list of names and join them with commas.
	 *
	 * @param string[] $names Names.
	 * @return string
	 */
	public static function quote_list( array $names ) {
		return implode( ',', array_map( array( self::class, 'quote' ), $names ) );
	}
}
