<?php
/**
 * Serialization-safe search and replace for one value.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Replace;

defined( 'ABSPATH' ) || exit;

/**
 * Applies search and replace pairs to a database value.
 *
 * The value can be plain text, HTML (including Gutenberg block comments), JSON, PHP-serialized
 * data, or any mix of those nested inside each other (for example serialized data that holds a
 * JSON string that holds a URL). Serialized string lengths are recalculated. Nothing is ever
 * unserialized, so no object is created and no code can run.
 */
class Replacer {

	const MAX_DEPTH = 8;

	/**
	 * Search => replace map (plain mode), including JSON-escaped variants.
	 *
	 * @var array<string,string>
	 */
	private $map = array();

	/**
	 * Original pairs, in the order given.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private $pairs = array();

	/**
	 * Regular expression (regex mode) or null.
	 *
	 * @var string|null
	 */
	private $regex = null;

	/**
	 * Replacement for regex mode.
	 *
	 * @var string
	 */
	private $regex_replacement = '';

	/**
	 * Compiled pattern for case-insensitive plain mode.
	 *
	 * @var string|null
	 */
	private $ci_pattern = null;

	/**
	 * Lower-cased map for case-insensitive plain mode.
	 *
	 * @var array<string,string>
	 */
	private $ci_map = array();

	/**
	 * Case sensitive?
	 *
	 * @var bool
	 */
	private $case_sensitive;

	/**
	 * Also rewrite array and object keys?
	 *
	 * @var bool
	 */
	private $replace_keys;

	/**
	 * Pattern used when some pairs must match whole addresses only, or null.
	 *
	 * @var string|null
	 */
	private $bound_pattern = null;

	/**
	 * Replacement for each text the pattern above can match (lower case keys when case is ignored).
	 *
	 * @var array<string,string>
	 */
	private $bound_map = array();

	/**
	 * Search texts that only match whole addresses (the character after the match is not part of a name).
	 *
	 * @var array<string,bool>
	 */
	private $whole = array();

	/**
	 * Counters since the last reset.
	 *
	 * @var array{matches:int, nested:int, unparsed:int}
	 */
	private $stats = array(
		'matches'  => 0,
		'nested'   => 0,
		'unparsed' => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param array $pairs   List of [search, replace] pairs, or search => replace map. In regex mode only the first pair is used.
	 *                       A third element set to true makes the pair match whole addresses only: acme.com does not match inside acme.com.au or acme.community.
	 * @param array $options regex (bool), case_sensitive (bool, default true), replace_keys (bool, default true).
	 * @throws \InvalidArgumentException When the regular expression is invalid.
	 */
	public function __construct( array $pairs, array $options = array() ) {
		$options = array_merge(
			array(
				'regex'          => false,
				'case_sensitive' => true,
				'replace_keys'   => true,
			),
			$options
		);

		$this->case_sensitive = (bool) $options['case_sensitive'];
		$this->replace_keys   = (bool) $options['replace_keys'];

		foreach ( $pairs as $k => $v ) {
			if ( is_array( $v ) ) {
				$this->pairs[] = array( (string) ( $v[0] ?? '' ), (string) ( $v[1] ?? '' ), ! empty( $v[2] ) );
			} else {
				$this->pairs[] = array( (string) $k, (string) $v );
			}
		}
		$this->pairs = array_values(
			array_filter(
				$this->pairs,
				static function ( $pair ) {
					return '' !== $pair[0];
				}
			)
		);

		if ( $options['regex'] ) {
			$this->init_regex();
		} else {
			$this->init_plain();
		}
	}

	/**
	 * Prepare regex mode.
	 *
	 * @return void
	 * @throws \InvalidArgumentException When the pattern does not compile.
	 */
	private function init_regex() {
		if ( ! $this->pairs ) {
			throw new \InvalidArgumentException( 'A search pattern is required.' );
		}
		$pattern = $this->pairs[0][0];
		$delim   = '~';
		$pattern = $delim . str_replace( $delim, '\\' . $delim, $pattern ) . $delim . 'u' . ( $this->case_sensitive ? '' : 'i' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === @preg_match( $pattern, '' ) ) {
			throw new \InvalidArgumentException( 'The search pattern is not a valid regular expression.' );
		}
		$this->regex             = $pattern;
		$this->regex_replacement = $this->pairs[0][1];
	}

	/**
	 * Prepare plain mode: add JSON-escaped variants so URLs inside JSON text are found too.
	 *
	 * @return void
	 */
	private function init_plain() {
		foreach ( $this->pairs as $pair ) {
			$search                 = $pair[0];
			$replace                = $pair[1];
			$this->map[ $search ]   = $replace;
			$this->whole[ $search ] = ! empty( $pair[2] );

			$esc_search  = self::json_escape( $search );
			$esc_replace = self::json_escape( $replace );
			if ( $esc_search !== $search && '' !== $esc_search && ! isset( $this->map[ $esc_search ] ) ) {
				$this->map[ $esc_search ]   = $esc_replace;
				$this->whole[ $esc_search ] = ! empty( $pair[2] );
			}
		}
		if ( in_array( true, $this->whole, true ) ) {
			$this->init_bounded();
			return;
		}
		if ( ! $this->case_sensitive && $this->map ) {
			$keys = array_keys( $this->map );
			usort(
				$keys,
				static function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);
			foreach ( $keys as $key ) {
				$this->ci_map[ strtolower( $key ) ] = $this->map[ $key ];
			}
			$this->ci_pattern = '/' . implode(
				'|',
				array_map(
					static function ( $k ) {
						return preg_quote( $k, '/' );
					},
					$keys
				)
			) . '/i';
		}
	}

	/**
	 * One pattern for all pairs when some of them must match whole addresses only.
	 *
	 * @return void
	 */
	private function init_bounded() {
		$keys = array_map( 'strval', array_keys( $this->map ) );
		usort(
			$keys,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		$parts = array();
		foreach ( $keys as $key ) {
			$lookahead = ! empty( $this->whole[ $key ] ) ? '(?![A-Za-z0-9_-]|\.[A-Za-z0-9])' : '';
			$parts[]   = preg_quote( $key, '/' ) . $lookahead;
			$this->bound_map[ $this->case_sensitive ? $key : strtolower( $key ) ] = $this->map[ $key ];
		}
		$this->bound_pattern = '/' . implode( '|', $parts ) . '/' . ( $this->case_sensitive ? '' : 'i' );
	}

	/**
	 * Escape text the way json_encode does inside a string, without the quotes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function json_escape( $text ) {
		$encoded = json_encode( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( false === $encoded ) {
			return $text;
		}
		return substr( $encoded, 1, -1 );
	}

	/**
	 * Does this replacer have anything to do?
	 *
	 * @return bool
	 */
	public function is_active() {
		return null !== $this->regex || ! empty( $this->map );
	}

	/**
	 * Counters.
	 *
	 * @return array{matches:int, nested:int, unparsed:int}
	 */
	public function stats() {
		return $this->stats;
	}

	/**
	 * Reset the counters.
	 *
	 * @return void
	 */
	public function reset_stats() {
		$this->stats = array(
			'matches'  => 0,
			'nested'   => 0,
			'unparsed' => 0,
		);
	}

	/**
	 * Apply the replacements to a value.
	 *
	 * @param string $value Database value.
	 * @return string The new value (identical to the input when nothing matched).
	 */
	public function replace( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->is_active() ) {
			return $value;
		}
		return $this->process( $value, 0 );
	}

	/**
	 * Recursive worker.
	 *
	 * @param string $value Text.
	 * @param int    $depth Nesting depth.
	 * @return string
	 */
	private function process( $value, $depth ) {
		if ( ! $this->might_match( $value ) ) {
			return $value;
		}

		if ( $depth < self::MAX_DEPTH ) {
			if ( Serialized::looks_serialized( $value ) ) {
				$leaf = function ( $s ) use ( $depth ) {
					return $this->process( $s, $depth + 1 );
				};
				$out  = Serialized::transform( $value, $leaf, $this->replace_keys ? $leaf : null );
				if ( null !== $out ) {
					return $out;
				}
				++$this->stats['unparsed'];
			} elseif ( JsonText::looks_json( $value ) && JsonText::is_valid( $value ) ) {
				$leaf = function ( $s ) use ( $depth ) {
					return $this->process( $s, $depth + 1 );
				};
				return JsonText::transform( $value, $leaf, $this->replace_keys ? $leaf : null );
			}
		}

		return $this->replace_text( $value, $depth > 0 );
	}

	/**
	 * Quick test used to skip values that cannot change.
	 *
	 * @param string $value Text.
	 * @return bool
	 */
	private function might_match( $value ) {
		if ( null !== $this->regex ) {
			// JSON may hide matches behind escapes, so look inside every JSON document.
			return JsonText::looks_json( $value ) || 1 === preg_match( $this->regex, $value );
		}
		if ( null !== $this->bound_pattern ) {
			return 1 === preg_match( $this->bound_pattern, $value );
		}
		if ( $this->case_sensitive ) {
			foreach ( $this->map as $search => $unused ) {
				if ( false !== strpos( $value, (string) $search ) ) {
					return true;
				}
			}
			return false;
		}
		return 1 === preg_match( (string) $this->ci_pattern, $value );
	}

	/**
	 * Replace inside a plain string.
	 *
	 * @param string $value  Text.
	 * @param bool   $nested True when the text came from inside serialized or JSON data.
	 * @return string
	 */
	private function replace_text( $value, $nested ) {
		if ( null !== $this->regex ) {
			$count = 0;
			$out   = preg_replace( $this->regex, $this->regex_replacement, $value, -1, $count );
			if ( null === $out ) {
				return $value;
			}
			$this->count( $count, $nested );
			return $out;
		}

		if ( null !== $this->bound_pattern ) {
			$count = 0;
			$out   = preg_replace_callback(
				$this->bound_pattern,
				function ( $m ) use ( &$count ) {
					++$count;
					return $this->bound_map[ $this->case_sensitive ? $m[0] : strtolower( $m[0] ) ] ?? $m[0];
				},
				$value
			);
			if ( null === $out ) {
				return $value;
			}
			$this->count( $count, $nested );
			return $out;
		}

		if ( $this->case_sensitive ) {
			$count = 0;
			foreach ( $this->map as $search => $unused ) {
				$count += substr_count( $value, (string) $search );
			}
			if ( 0 === $count ) {
				return $value;
			}
			$this->count( $count, $nested );
			return strtr( $value, $this->map );
		}

		$count = 0;
		$out   = preg_replace_callback(
			(string) $this->ci_pattern,
			function ( $m ) use ( &$count ) {
				++$count;
				return $this->ci_map[ strtolower( $m[0] ) ] ?? $m[0];
			},
			$value
		);
		if ( null === $out ) {
			return $value;
		}
		$this->count( $count, $nested );
		return $out;
	}

	/**
	 * Add to the counters.
	 *
	 * @param int  $count  Matches found.
	 * @param bool $nested Inside serialized or JSON data.
	 * @return void
	 */
	private function count( $count, $nested ) {
		$this->stats['matches'] += $count;
		if ( $nested ) {
			$this->stats['nested'] += $count;
		}
	}
}
