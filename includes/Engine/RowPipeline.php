<?php
/**
 * What happens to a row between the source and the destination.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Replace\Replacer;
use Estasoft\Migrate\Sanitizer\RowSanitizer;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * In order: leave out the plugin's own state, mask personal data, map the table prefix in
 * option and user meta names, then search and replace in every text column.
 */
class RowPipeline {

	/**
	 * Replacer or null.
	 *
	 * @var Replacer|null
	 */
	private $replacer;

	/**
	 * Sanitizer or null.
	 *
	 * @var RowSanitizer|null
	 */
	private $sanitizer;

	/**
	 * Source prefix.
	 *
	 * @var string
	 */
	private $from_prefix;

	/**
	 * Destination prefix.
	 *
	 * @var string
	 */
	private $to_prefix;

	/**
	 * "table.column" entries never searched.
	 *
	 * @var string[]
	 */
	private $skip_columns;

	/**
	 * Logical table now being processed.
	 *
	 * @var string
	 */
	private $logical = '';

	/**
	 * Columns skipped by the replacer in the current table.
	 *
	 * @var array<string,bool>
	 */
	private $skip = array();

	/**
	 * Constructor.
	 *
	 * @param Replacer|null     $replacer     Replacer, or null.
	 * @param RowSanitizer|null $sanitizer    Sanitizer, or null.
	 * @param string            $from_prefix  Source table prefix.
	 * @param string            $to_prefix    Destination table prefix.
	 * @param string[]          $skip_columns "table.column" entries to leave alone when replacing.
	 */
	public function __construct( $replacer, $sanitizer, $from_prefix = '', $to_prefix = '', array $skip_columns = array() ) {
		$this->replacer     = $replacer && $replacer->is_active() ? $replacer : null;
		$this->sanitizer    = $sanitizer;
		$this->from_prefix  = (string) $from_prefix;
		$this->to_prefix    = (string) $to_prefix;
		$this->skip_columns = $skip_columns;
	}

	/**
	 * Prepare for a table.
	 *
	 * @param string   $logical Table name without prefix.
	 * @param array    $columns Column details from Database::columns().
	 * @param string[] $pk      Primary key columns.
	 * @return void
	 */
	public function begin_table( $logical, array $columns, array $pk ) {
		$this->logical = $logical;
		$this->skip    = array();
		foreach ( $columns as $name => $spec ) {
			if ( ! empty( $spec['binary'] ) || ! empty( $spec['numeric'] ) ) {
				$this->skip[ $name ] = true;
			}
		}
		foreach ( $pk as $name ) {
			$this->skip[ $name ] = true;
		}
		if ( 'posts' === $logical ) {
			$this->skip['guid'] = true;
		}
		foreach ( $this->skip_columns as $path ) {
			if ( 0 === strpos( $path, $logical . '.' ) ) {
				$this->skip[ substr( $path, strlen( $logical ) + 1 ) ] = true;
			}
		}
	}

	/**
	 * Is the whole table emptied by the sanitizer?
	 *
	 * @return bool
	 */
	public function empties_table() {
		return $this->sanitizer && $this->sanitizer->empties_table( $this->logical );
	}

	/**
	 * Process one row.
	 *
	 * @param array $row Row as column => value.
	 * @return array|null Row to write, or null to leave it out.
	 */
	public function row( array $row ) {
		if ( 'options' === $this->logical && isset( $row['option_name'] ) && self::is_plugin_option( (string) $row['option_name'] ) ) {
			return null;
		}

		if ( $this->sanitizer ) {
			$row = $this->sanitizer->process( $this->logical, $row );
			if ( null === $row ) {
				return null;
			}
		}

		if ( '' !== $this->from_prefix && $this->from_prefix !== $this->to_prefix ) {
			if ( 'options' === $this->logical ) {
				$row = $this->map_prefix( $row, 'option_name' );
			} elseif ( 'usermeta' === $this->logical ) {
				$row = $this->map_prefix( $row, 'meta_key' );
			}
		}

		if ( $this->replacer ) {
			foreach ( $row as $col => $value ) {
				if ( null === $value || isset( $this->skip[ $col ] ) ) {
					continue;
				}
				$row[ $col ] = $this->replacer->replace( (string) $value );
			}
		}
		return $row;
	}

	/**
	 * Values masked so far by rule.
	 *
	 * @return array<string,int>
	 */
	public function sanitizer_counts() {
		return $this->sanitizer ? $this->sanitizer->counts() : array();
	}

	/**
	 * Search and replace counters.
	 *
	 * @return array|null
	 */
	public function replace_stats() {
		return $this->replacer ? $this->replacer->stats() : null;
	}

	/**
	 * Options that belong to this plugin on this site, and never travel.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_plugin_option( $name ) {
		return 0 === strpos( $name, 'estasoft_migrate_' )
			|| 0 === strpos( $name, '_transient_estasoft_migrate_' )
			|| 0 === strpos( $name, '_transient_timeout_estasoft_migrate_' );
	}

	/**
	 * Rename a key that starts with the source prefix.
	 *
	 * @param array  $row Row.
	 * @param string $col Column holding the key.
	 * @return array
	 */
	private function map_prefix( array $row, $col ) {
		if ( isset( $row[ $col ] ) && 0 === strpos( (string) $row[ $col ], $this->from_prefix ) ) {
			$row[ $col ] = $this->to_prefix . substr( (string) $row[ $col ], strlen( $this->from_prefix ) );
		}
		return $row;
	}
}
