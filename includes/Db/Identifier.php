<?php
/**
 * SQL identifier helpers.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Db;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

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
		// Any letters (café_log), spaces ("Order Number") and the usual punctuation are fine: the name is always
		// wrapped in backticks. What is refused is what cannot be part of a name inside backticks: the backtick itself,
		// a backslash, control characters, invalid UTF-8, and a trailing space (MySQL does not allow it).
		return is_string( $name )
			&& strlen( $name ) >= 1
			&& strlen( $name ) <= 192
			&& 1 === preg_match( '/\A[^\x00-\x1f\x7f`\\\\]+\z/u', $name )
			&& ' ' !== substr( $name, -1 );
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
