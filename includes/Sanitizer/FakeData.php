<?php
/**
 * Deterministic fake personal data.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Same real value in, same fake value out (within one salt), so joins between tables keep working:
 * a customer who appears in users, comments and orders gets the same fake name and email everywhere.
 *
 * The salt is secret and random per job, so the fake data cannot be reversed by guessing real values.
 */
final class FakeData {

	const FIRST = array(
		'Alex',
		'Sam',
		'Jordan',
		'Taylor',
		'Morgan',
		'Casey',
		'Riley',
		'Avery',
		'Quinn',
		'Rowan',
		'Maya',
		'Noah',
		'Lina',
		'Omar',
		'Elena',
		'Kai',
		'Nora',
		'Leo',
		'Zara',
		'Ivan',
		'Amara',
		'Hugo',
		'Yuki',
		'Sofia',
		'Mateo',
		'Aisha',
		'Felix',
		'Priya',
		'Liam',
		'Mina',
		'Tariq',
		'Ines',
		'Jonas',
		'Chloe',
		'Dara',
		'Emil',
		'Farah',
		'Gabe',
		'Hana',
		'Idris',
	);

	const LAST = array(
		'Moreno',
		'Whitfield',
		'Nakamura',
		'Okafor',
		'Lindqvist',
		'Haddad',
		'Petrov',
		'Silva',
		'Novak',
		'Brennan',
		'Castillo',
		'Dubois',
		'Eriksen',
		'Fischer',
		'Gallagher',
		'Hartmann',
		'Ivanova',
		'Jensen',
		'Kowalski',
		'Laurent',
		'Mbeki',
		'Navarro',
		'Olsen',
		'Pereira',
		'Quintero',
		'Rossi',
		'Santos',
		'Tanaka',
		'Umar',
		'Vargas',
		'Weber',
		'Xu',
		'Yilmaz',
		'Zielinski',
		'Abbasi',
		'Baker',
		'Clarke',
		'Dalton',
		'Evans',
		'Fraser',
	);

	const STREETS = array(
		'Oak',
		'Pine',
		'Maple',
		'Cedar',
		'Elm',
		'Willow',
		'Birch',
		'Hill',
		'River',
		'Lake',
		'Park',
		'Church',
		'Mill',
		'Station',
		'Garden',
		'Meadow',
		'Orchard',
		'Bridge',
		'Market',
		'Spring',
	);

	const SUFFIXES = array( 'Road', 'Lane', 'Street', 'Avenue', 'Close', 'Drive', 'Way', 'Court' );

	/**
	 * Secret salt.
	 *
	 * @var string
	 */
	private $salt;

	/**
	 * Constructor.
	 *
	 * @param string $salt Secret salt.
	 */
	public function __construct( $salt ) {
		$this->salt = (string) $salt;
	}

	/**
	 * Keyed hash of a value (hex).
	 *
	 * @param string $kind Namespace so different kinds of data do not share fake values.
	 * @param string $real Real value.
	 * @return string
	 */
	public function digest( $kind, $real ) {
		return hash_hmac( 'sha256', $kind . "\0" . mb_strtolower( trim( (string) $real ), 'UTF-8' ), $this->salt );
	}

	/**
	 * Pick an item from a list by hash position.
	 *
	 * @param string[] $list   List.
	 * @param string   $digest Hex digest.
	 * @param int      $offset Which 8-hex-digit slice of the digest to use.
	 * @return string
	 */
	private function pick( array $list, $digest, $offset = 0 ) {
		return $list[ hexdec( substr( $digest, $offset * 8, 8 ) ) % count( $list ) ];
	}

	/**
	 * Fake first name.
	 *
	 * @param string $real Real name.
	 * @return string
	 */
	public function first_name( $real ) {
		return $this->pick( self::FIRST, $this->digest( 'first', $real ) );
	}

	/**
	 * Fake last name.
	 *
	 * @param string $real Real name.
	 * @return string
	 */
	public function last_name( $real ) {
		return $this->pick( self::LAST, $this->digest( 'last', $real ) );
	}

	/**
	 * Fake full name.
	 *
	 * @param string $real Real full name.
	 * @return string
	 */
	public function full_name( $real ) {
		$d = $this->digest( 'full', $real );
		return $this->pick( self::FIRST, $d, 0 ) . ' ' . $this->pick( self::LAST, $d, 1 );
	}

	/**
	 * Fake login name.
	 *
	 * @param string $real Real login.
	 * @return string
	 */
	public function username( $real ) {
		return 'user' . substr( $this->digest( 'login', $real ), 0, 10 );
	}

	/**
	 * Fake email address on the reserved .invalid test domain.
	 *
	 * @param string $real Real address.
	 * @return string
	 */
	public function email( $real ) {
		$real = trim( (string) $real );
		if ( 1 === preg_match( '/@example\.invalid$/i', $real ) ) {
			return $real;
		}
		// Digits only, so the result looks like user0000004821@example.invalid. Ten digits keep two people from
		// getting the same address (five digits gave about one clash in a hundred customers).
		$n = hexdec( substr( $this->digest( 'email', $real ), 0, 12 ) ) % 10000000000;
		return 'user' . str_pad( (string) $n, 10, '0', STR_PAD_LEFT ) . '@example.invalid';
	}

	/**
	 * Fake phone number: the country code and the format stay, other digits change.
	 *
	 * @param string $real Real number.
	 * @return string
	 */
	public function phone( $real ) {
		$real   = (string) $real;
		$stream = $this->digest( 'phone', $real );
		$keep   = 0;
		if ( 1 === preg_match( '/^\s*\+\s*(\d{1,3})/', $real, $m ) ) {
			$keep = strlen( $m[1] );
		}
		$i      = 0;
		$seen   = 0;
		$result = '';
		foreach ( str_split( $real ) as $ch ) {
			if ( ctype_digit( $ch ) ) {
				if ( $seen < $keep ) {
					$result .= $ch;
				} else {
					$result .= (string) ( hexdec( $stream[ $i % 64 ] ) % 10 );
					++$i;
				}
				++$seen;
			} else {
				$result .= $ch;
			}
		}
		return $result;
	}

	/**
	 * Fake street line such as "48 Pine Road".
	 *
	 * @param string $real Real street line.
	 * @return string
	 */
	public function street( $real ) {
		$d = $this->digest( 'street', $real );
		return ( 1 + hexdec( substr( $d, 0, 4 ) ) % 199 ) . ' ' . $this->pick( self::STREETS, $d, 1 ) . ' ' . $this->pick( self::SUFFIXES, $d, 2 );
	}

	/**
	 * Fake second address line.
	 *
	 * @param string $real Real value.
	 * @return string
	 */
	public function address_line_2( $real ) {
		$d = $this->digest( 'addr2', $real );
		return 'Flat ' . ( 1 + hexdec( substr( $d, 0, 4 ) ) % 60 );
	}

	/**
	 * Fake postcode with the same shape (digits stay digits, letters stay letters).
	 *
	 * @param string $real Real postcode.
	 * @return string
	 */
	public function postcode( $real ) {
		$stream = $this->digest( 'postcode', $real );
		$i      = 0;
		$result = '';
		foreach ( str_split( (string) $real ) as $ch ) {
			$n = hexdec( $stream[ $i % 64 ] );
			++$i;
			if ( ctype_digit( $ch ) ) {
				$result .= (string) ( $n % 10 );
			} elseif ( ctype_upper( $ch ) ) {
				$result .= chr( 65 + ( $n + hexdec( $stream[ $i % 64 ] ) ) % 26 );
			} elseif ( ctype_lower( $ch ) ) {
				$result .= chr( 97 + ( $n + hexdec( $stream[ $i % 64 ] ) ) % 26 );
			} else {
				$result .= $ch;
			}
		}
		return $result;
	}

	/**
	 * Fake company name.
	 *
	 * @param string $real Real name.
	 * @return string
	 */
	public function company( $real ) {
		return 'Company ' . strtoupper( substr( $this->digest( 'company', $real ), 0, 5 ) );
	}

	/**
	 * Anonymize an IP address: IPv4 keeps three octets, IPv6 keeps the first 48 bits.
	 *
	 * @param string $real IP address.
	 * @return string
	 */
	public static function ip( $real ) {
		$real = trim( (string) $real );
		if ( filter_var( $real, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $real );
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		if ( filter_var( $real, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $real );
			if ( false !== $bin ) {
				return inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\0", 10 ) );
			}
		}
		return '';
	}
}
