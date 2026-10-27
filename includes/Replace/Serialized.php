<?php
/**
 * Rewrites strings inside PHP-serialized data without ever unserializing it.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Replace;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * A small tokenizer for the PHP serialize() format.
 *
 * Why not call unserialize()? Because unserialize() can create objects and run code
 * (__wakeup, __destruct), and classes from deactivated plugins are not available during a
 * migration. This class walks the text instead: every string value (and, optionally, every
 * string array key) goes through a callback, and the new string length is written back.
 * Structure, references (r: and R:), object class names and property names stay untouched.
 */
final class Serialized {

	const MAX_DEPTH = 256;

	/**
	 * Cheap test: does the text start like serialized data and end like it?
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function looks_serialized( $s ) {
		$s = rtrim( $s );
		$n = strlen( $s );
		if ( $n < 4 ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[aOsCE]:\d+:/', $s ) ) {
			return false;
		}
		$last = $s[ $n - 1 ];
		return ';' === $last || '}' === $last;
	}

	/**
	 * Rewrite all strings in a serialized value.
	 *
	 * @param string        $s        Serialized data.
	 * @param callable      $leaf     Receives a string value, returns the new string.
	 * @param callable|null $key_leaf Receives a string array key, returns the new key. Null leaves keys alone.
	 * @return string|null New serialized data, or null when $s is not valid serialized data.
	 */
	public static function transform( $s, callable $leaf, $key_leaf = null ) {
		$trimmed = rtrim( $s );
		$tail    = substr( $s, strlen( $trimmed ) );
		$len     = strlen( $trimmed );
		$i       = 0;
		try {
			$out = self::value( $trimmed, $i, $len, $leaf, $key_leaf, 0 );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		if ( $i !== $len ) {
			return null;
		}
		return $out . $tail;
	}

	/**
	 * Is this text valid serialized data? (Structure check only.)
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function is_valid( $s ) {
		return null !== self::transform(
			$s,
			static function ( $v ) {
				return $v;
			}
		);
	}

	/**
	 * Parse one value starting at $i and return its (rewritten) text.
	 *
	 * @param string        $s        Text.
	 * @param int           $i        Position, advanced past the value.
	 * @param int           $len      Text length.
	 * @param callable      $leaf     String callback.
	 * @param callable|null $key_leaf Key callback.
	 * @param int           $depth    Nesting depth.
	 * @return string
	 * @throws \UnexpectedValueException When the data is malformed.
	 */
	private static function value( $s, &$i, $len, $leaf, $key_leaf, $depth ) {
		if ( $i >= $len || $depth > self::MAX_DEPTH ) {
			throw new \UnexpectedValueException( 'end' );
		}
		$start = $i;
		$type  = $s[ $i ];

		switch ( $type ) {
			case 'N':
				if ( ';' !== ( $s[ $i + 1 ] ?? '' ) ) {
					throw new \UnexpectedValueException( 'N' );
				}
				$i += 2;
				return 'N;';

			case 'b':
				return self::token( $s, $i, '/\Gb:[01];/' );

			case 'i':
				return self::token( $s, $i, '/\Gi:[+-]?\d+;/' );

			case 'd':
				return self::token( $s, $i, '/\Gd:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|NAN|-?INF);/' );

			case 'r':
			case 'R':
				return self::token( $s, $i, '/\G[rR]:\d+;/' );

			case 's':
				$str = self::raw_string( $s, $i, $len );
				$new = (string) call_user_func( $leaf, $str );
				if ( $new === $str ) {
					return substr( $s, $start, $i - $start );
				}
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'a':
				if ( 1 !== preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $i ) ) {
					throw new \UnexpectedValueException( 'a' );
				}
				$count = (int) $m[1];
				$i    += strlen( $m[0] );
				$out   = 'a:' . $count . ':{';
				for ( $n = 0; $n < $count; $n++ ) {
					$out .= self::key( $s, $i, $len, $key_leaf );
					$out .= self::value( $s, $i, $len, $leaf, $key_leaf, $depth + 1 );
				}
				self::expect( $s, $i, '}' );
				return $out . '}';

			case 'O':
				if ( 1 !== preg_match( '/\GO:(\d+):"/', $s, $m, 0, $i ) ) {
					throw new \UnexpectedValueException( 'O' );
				}
				$name_len = (int) $m[1];
				$pos      = $i + strlen( $m[0] );
				if ( $pos + $name_len + 2 > $len || '":' !== substr( $s, $pos + $name_len, 2 ) ) {
					throw new \UnexpectedValueException( 'O name' );
				}
				$name = substr( $s, $pos, $name_len );
				$i    = $pos + $name_len + 2;
				if ( 1 !== preg_match( '/\G(\d+):\{/', $s, $m2, 0, $i ) ) {
					throw new \UnexpectedValueException( 'O count' );
				}
				$count = (int) $m2[1];
				$i    += strlen( $m2[0] );
				$out   = 'O:' . $name_len . ':"' . $name . '":' . $count . ':{';
				for ( $n = 0; $n < $count; $n++ ) {
					// Property names are never rewritten.
					$out .= self::key( $s, $i, $len, null );
					$out .= self::value( $s, $i, $len, $leaf, $key_leaf, $depth + 1 );
				}
				self::expect( $s, $i, '}' );
				return $out . '}';

			case 'E':
				$str = self::raw_string( $s, $i, $len );
				unset( $str );
				return substr( $s, $start, $i - $start );

			case 'C':
				if ( 1 !== preg_match( '/\GC:(\d+):"/', $s, $m, 0, $i ) ) {
					throw new \UnexpectedValueException( 'C' );
				}
				$name_len = (int) $m[1];
				$pos      = $i + strlen( $m[0] );
				if ( $pos + $name_len + 2 > $len || '":' !== substr( $s, $pos + $name_len, 2 ) ) {
					throw new \UnexpectedValueException( 'C name' );
				}
				$i = $pos + $name_len + 2;
				if ( 1 !== preg_match( '/\G(\d+):\{/', $s, $m2, 0, $i ) ) {
					throw new \UnexpectedValueException( 'C data' );
				}
				$data_len = (int) $m2[1];
				$i       += strlen( $m2[0] );
				if ( $i + $data_len + 1 > $len || '}' !== $s[ $i + $data_len ] ) {
					throw new \UnexpectedValueException( 'C end' );
				}
				$i += $data_len + 1;
				// Custom-serialized data is opaque: copy it as it is.
				return substr( $s, $start, $i - $start );
		}

		throw new \UnexpectedValueException( 'type' );
	}

	/**
	 * Read an array key (integer or string) and return its text.
	 *
	 * @param string        $s        Text.
	 * @param int           $i        Position.
	 * @param int           $len      Length.
	 * @param callable|null $key_leaf Key callback, null to keep the key.
	 * @return string
	 * @throws \UnexpectedValueException When the key is malformed.
	 */
	private static function key( $s, &$i, $len, $key_leaf ) {
		if ( $i >= $len ) {
			throw new \UnexpectedValueException( 'key' );
		}
		if ( 'i' === $s[ $i ] ) {
			return self::token( $s, $i, '/\Gi:[+-]?\d+;/' );
		}
		if ( 's' !== $s[ $i ] ) {
			throw new \UnexpectedValueException( 'key type' );
		}
		$start = $i;
		$str   = self::raw_string( $s, $i, $len );
		if ( null === $key_leaf ) {
			return substr( $s, $start, $i - $start );
		}
		$new = (string) call_user_func( $key_leaf, $str );
		if ( $new === $str ) {
			return substr( $s, $start, $i - $start );
		}
		return 's:' . strlen( $new ) . ':"' . $new . '";';
	}

	/**
	 * Read a string token such as s:5:"hello"; and return the string content.
	 * Also used for the E: enum token, whose body has the same shape.
	 *
	 * @param string $s   Text.
	 * @param int    $i   Position, advanced past the token.
	 * @param int    $len Text length.
	 * @return string
	 * @throws \UnexpectedValueException When the token is malformed.
	 */
	private static function raw_string( $s, &$i, $len ) {
		if ( 1 !== preg_match( '/\G[sE]:(\d+):"/', $s, $m, 0, $i ) ) {
			throw new \UnexpectedValueException( 's' );
		}
		$n     = (int) $m[1];
		$start = $i + strlen( $m[0] );
		if ( $start + $n + 2 > $len || '";' !== substr( $s, $start + $n, 2 ) ) {
			throw new \UnexpectedValueException( 's length' );
		}
		$i = $start + $n + 2;
		return substr( $s, $start, $n );
	}

	/**
	 * Match a fixed-shape token at the current position.
	 *
	 * @param string $s       Text.
	 * @param int    $i       Position, advanced past the token.
	 * @param string $pattern Regular expression anchored with \G.
	 * @return string
	 * @throws \UnexpectedValueException When the token does not match.
	 */
	private static function token( $s, &$i, $pattern ) {
		if ( 1 !== preg_match( $pattern, $s, $m, 0, $i ) ) {
			throw new \UnexpectedValueException( 'token' );
		}
		$i += strlen( $m[0] );
		return $m[0];
	}

	/**
	 * Require a single character.
	 *
	 * @param string $s    Text.
	 * @param int    $i    Position, advanced.
	 * @param string $char Expected character.
	 * @return void
	 * @throws \UnexpectedValueException When another character is found.
	 */
	private static function expect( $s, &$i, $char ) {
		if ( ( $s[ $i ] ?? '' ) !== $char ) {
			throw new \UnexpectedValueException( 'expected ' . $char );
		}
		++$i;
	}
}
