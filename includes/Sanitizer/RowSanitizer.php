<?php
/**
 * Masks personal data in rows as they stream past.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Applies the sanitizer rules to one row at a time. Rows are masked in the stream, so the
 * original values never reach a package, a remote site or a staging database.
 *
 * Table names are logical (without the prefix): users, usermeta, comments, options, postmeta ...
 */
class RowSanitizer {

	const REMOVED = '(removed)';

	/**
	 * Rules that are switched on.
	 *
	 * @var array<string,bool>
	 */
	private $rules;

	/**
	 * Fake data source.
	 *
	 * @var FakeData
	 */
	private $fake;

	/**
	 * User ids that stay unchanged.
	 *
	 * @var array<int,bool>
	 */
	private $keep = array();

	/**
	 * Hash written to every reset password.
	 *
	 * @var string
	 */
	private $password_hash;

	/**
	 * Logical tables left alone.
	 *
	 * @var string[]
	 */
	private $skip_tables;

	/**
	 * "table.column" entries left alone.
	 *
	 * @var string[]
	 */
	private $skip_columns;

	/**
	 * Extra columns: "table.column" => rule id.
	 *
	 * @var array<string,string>
	 */
	private $extra;

	/**
	 * Counts of masked values by rule.
	 *
	 * @var array<string,int>
	 */
	private $counts = array();

	/**
	 * Constructor.
	 *
	 * @param array  $config        From Profiles::build_config().
	 * @param string $salt          Secret salt for deterministic fakes.
	 * @param string $password_hash Hash to store for reset passwords.
	 * @param int[]  $keep_user_ids Users whose personal data stays unchanged.
	 */
	public function __construct( array $config, $salt, $password_hash, array $keep_user_ids = array() ) {
		$this->rules         = (array) ( $config['rules'] ?? array() );
		$this->fake          = new FakeData( $salt );
		$this->password_hash = (string) $password_hash;
		$this->skip_tables   = array_map( 'strval', (array) ( $config['skip_tables'] ?? array() ) );
		$this->skip_columns  = array_map( 'strval', (array) ( $config['skip_columns'] ?? array() ) );
		$this->extra         = array_map( 'strval', (array) ( $config['columns'] ?? array() ) );
		foreach ( $keep_user_ids as $id ) {
			$this->keep[ (int) $id ] = true;
		}
	}

	/**
	 * Is a rule on?
	 *
	 * @param string $rule Rule id.
	 * @return bool
	 */
	private function on( $rule ) {
		return ! empty( $this->rules[ $rule ] );
	}

	/**
	 * Masked-value counts by rule.
	 *
	 * @return array<string,int>
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * Tables that are emptied completely (sessions, API keys, saved payment tokens).
	 *
	 * @param string $logical Table name without prefix.
	 * @return bool
	 */
	public function empties_table( $logical ) {
		if ( in_array( $logical, $this->skip_tables, true ) ) {
			return false;
		}
		if ( $this->on( 'pw' ) && in_array( $logical, array( 'woocommerce_sessions' ), true ) ) {
			return true;
		}
		if ( $this->on( 'secrets' ) && in_array( $logical, array( 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta' ), true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Mask one row.
	 *
	 * @param string $logical Table name without prefix.
	 * @param array  $row     Row as column => value.
	 * @return array|null The masked row, or null when the row must be dropped.
	 */
	public function process( $logical, array $row ) {
		if ( in_array( $logical, $this->skip_tables, true ) ) {
			return $row;
		}
		if ( $this->empties_table( $logical ) ) {
			return null;
		}

		$before = $row;
		switch ( $logical ) {
			case 'users':
				$row = $this->users( $row );
				break;
			case 'usermeta':
				$row = $this->usermeta( $row );
				break;
			case 'comments':
				$row = $this->comments( $row );
				break;
			case 'options':
				$row = $this->options( $row );
				break;
			case 'postmeta':
			case 'wc_orders_meta':
				$row = $this->order_meta( $row );
				break;
			case 'posts':
				$row = $this->posts( $row );
				break;
			case 'wc_customer_lookup':
				$row = $this->apply(
					$row,
					array(
						'first_name' => 'first',
						'last_name'  => 'last',
						'username'   => 'login',
						'email'      => 'email',
						'postcode'   => 'postcode',
					)
				);
				break;
			case 'wc_order_addresses':
				$row = $this->apply(
					$row,
					array(
						'first_name' => 'first',
						'last_name'  => 'last',
						'company'    => 'company',
						'address_1'  => 'street',
						'address_2'  => 'line2',
						'postcode'   => 'postcode',
						'email'      => 'email',
						'phone'      => 'phone',
					)
				);
				break;
			case 'wc_orders':
				$row = $this->apply(
					$row,
					array(
						'billing_email' => 'email',
						'ip_address'    => 'ip',
						'user_agent'    => 'agent',
						'customer_note' => 'note',
					)
				);
				break;
			case 'woocommerce_downloadable_product_permissions':
				$row = $this->apply(
					$row,
					array(
						'user_email'      => 'email',
						'user_ip_address' => 'ip',
					)
				);
				break;
			case 'wc_download_log':
				$row = $this->apply( $row, array( 'user_ip_address' => 'ip' ) );
				break;
			case 'wc_webhooks':
				$row = $this->apply( $row, array( 'secret' => 'secret' ) );
				break;
		}

		// Columns added by site owners or by the estasoft_migrate_sanitizer_columns filter.
		foreach ( $this->extra as $path => $kind ) {
			if ( 0 === strpos( $path, $logical . '.' ) ) {
				$col = substr( $path, strlen( $logical ) + 1 );
				if ( isset( $row[ $col ] ) && ! $this->skipped( $logical, $col ) ) {
					$row[ $col ] = $this->mask( $kind, (string) $row[ $col ] );
				}
			}
		}

		// Columns the owner asked to keep unchanged.
		foreach ( $this->skip_columns as $path ) {
			if ( 0 === strpos( $path, $logical . '.' ) ) {
				$col = substr( $path, strlen( $logical ) + 1 );
				if ( array_key_exists( $col, $before ) ) {
					$row[ $col ] = $before[ $col ];
				}
			}
		}
		return $row;
	}

	/**
	 * Is a column on the keep-unchanged list?
	 *
	 * @param string $logical Table.
	 * @param string $col     Column.
	 * @return bool
	 */
	private function skipped( $logical, $col ) {
		return in_array( $logical . '.' . $col, $this->skip_columns, true );
	}

	/**
	 * Apply a column => kind map.
	 *
	 * @param array                $row  Row.
	 * @param array<string,string> $map  Column => kind.
	 * @return array
	 */
	private function apply( array $row, array $map ) {
		foreach ( $map as $col => $kind ) {
			if ( isset( $row[ $col ] ) && '' !== $row[ $col ] ) {
				$row[ $col ] = $this->mask_kind( $kind, (string) $row[ $col ] );
			}
		}
		return $row;
	}

	/**
	 * Mask a value by field kind, honoring which rules are on.
	 *
	 * @param string $kind first, last, full, login, email, phone, street, line2, postcode, company, ip, agent, note, secret.
	 * @param string $real Real value.
	 * @return string
	 */
	private function mask_kind( $kind, $real ) {
		switch ( $kind ) {
			case 'first':
				return $this->on( 'names' ) ? $this->count( 'names', $this->fake->first_name( $real ) ) : $real;
			case 'last':
				return $this->on( 'names' ) ? $this->count( 'names', $this->fake->last_name( $real ) ) : $real;
			case 'full':
				return $this->on( 'names' ) ? $this->count( 'names', $this->fake->full_name( $real ) ) : $real;
			case 'login':
				return $this->on( 'names' ) ? $this->count( 'names', $this->fake->username( $real ) ) : $real;
			case 'email':
				return $this->on( 'emails' ) ? $this->count( 'emails', $this->fake->email( $real ) ) : $real;
			case 'phone':
				return $this->on( 'phones' ) ? $this->count( 'phones', $this->fake->phone( $real ) ) : $real;
			case 'street':
				return $this->on( 'addr' ) ? $this->count( 'addr', $this->fake->street( $real ) ) : $real;
			case 'line2':
				return $this->on( 'addr' ) ? $this->count( 'addr', $this->fake->address_line_2( $real ) ) : $real;
			case 'postcode':
				return $this->on( 'addr' ) ? $this->count( 'addr', $this->fake->postcode( $real ) ) : $real;
			case 'company':
				return $this->on( 'addr' ) ? $this->count( 'addr', $this->fake->company( $real ) ) : $real;
			case 'ip':
				return $this->on( 'ips' ) ? $this->count( 'ips', FakeData::ip( $real ) ) : $real;
			case 'agent':
				return $this->on( 'ips' ) ? $this->count( 'ips', '' ) : $real;
			case 'note':
				return $this->on( 'notes' ) ? $this->count( 'notes', self::REMOVED ) : $real;
			case 'secret':
				return $this->on( 'secrets' ) ? $this->count( 'secrets', '' ) : $real;
		}
		return $real;
	}

	/**
	 * Mask by a rule id (used for extra columns).
	 *
	 * @param string $rule Rule id from Profiles::RULES.
	 * @param string $real Real value.
	 * @return string
	 */
	private function mask( $rule, $real ) {
		if ( '' === $real ) {
			return $real;
		}
		$map = array(
			'names'   => 'full',
			'emails'  => 'email',
			'phones'  => 'phone',
			'addr'    => 'street',
			'ips'     => 'ip',
			'notes'   => 'note',
			'secrets' => 'secret',
		);
		return isset( $map[ $rule ] ) ? $this->mask_kind( $map[ $rule ], $real ) : $real;
	}

	/**
	 * Count a masked value.
	 *
	 * @param string $rule  Rule id.
	 * @param string $value Fake value.
	 * @return string
	 */
	private function count( $rule, $value ) {
		$this->counts[ $rule ] = ( $this->counts[ $rule ] ?? 0 ) + 1;
		return $value;
	}

	/**
	 * Users table.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function users( array $row ) {
		$id = (int) ( $row['ID'] ?? 0 );
		if ( $this->on( 'pw' ) && isset( $row['user_activation_key'] ) ) {
			$row['user_activation_key'] = '';
		}
		if ( isset( $this->keep[ $id ] ) ) {
			return $row;
		}
		if ( $this->on( 'pw' ) && isset( $row['user_pass'] ) ) {
			$row['user_pass'] = $this->count( 'pw', $this->password_hash );
		}
		$login = (string) ( $row['user_login'] ?? '' );
		if ( isset( $row['display_name'] ) && '' !== $row['display_name'] ) {
			$row['display_name'] = $this->mask_kind( 'full', (string) $row['display_name'] );
		}
		if ( isset( $row['user_login'] ) ) {
			$row['user_login'] = $this->mask_kind( 'login', $login );
		}
		if ( isset( $row['user_nicename'] ) ) {
			$row['user_nicename'] = $this->on( 'names' ) ? strtolower( $this->fake->username( $login ) ) : $row['user_nicename'];
		}
		if ( isset( $row['user_email'] ) ) {
			$row['user_email'] = $this->mask_kind( 'email', (string) $row['user_email'] );
		}
		if ( isset( $row['user_url'] ) && '' !== $row['user_url'] && $this->on( 'names' ) ) {
			$row['user_url'] = '';
		}
		return $row;
	}

	/**
	 * Usermeta table.
	 *
	 * @param array $row Row.
	 * @return array|null
	 */
	private function usermeta( array $row ) {
		$key = (string) ( $row['meta_key'] ?? '' );
		if ( $this->on( 'pw' ) && in_array( $key, array( 'session_tokens', '_application_passwords', 'password_reset_token' ), true ) ) {
			$this->count( 'pw', '' );
			return null;
		}
		$id = (int) ( $row['user_id'] ?? 0 );
		if ( isset( $this->keep[ $id ] ) ) {
			return $row;
		}
		if ( ! isset( $row['meta_value'] ) || '' === $row['meta_value'] ) {
			return $row;
		}
		$kind = self::meta_kind( $key );
		if ( null === $kind && $this->on( 'secrets' ) && 1 === preg_match( SecretScrubber::KEY_PATTERN, $key ) ) {
			$kind = 'secret';
		}
		if ( 'description' === $key ) {
			$kind = 'note';
		}
		if ( null !== $kind ) {
			$row['meta_value'] = $this->mask_kind( $kind, (string) $row['meta_value'] );
		}
		return $row;
	}

	/**
	 * Postmeta and order meta: WooCommerce billing and shipping fields.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function order_meta( array $row ) {
		$key = (string) ( $row['meta_key'] ?? '' );
		if ( ! isset( $row['meta_value'] ) || '' === $row['meta_value'] ) {
			return $row;
		}
		$kind = self::meta_kind( ltrim( $key, '_' ) );
		if ( null === $kind ) {
			if ( in_array( $key, array( '_customer_ip_address' ), true ) ) {
				$kind = 'ip';
			} elseif ( in_array( $key, array( '_customer_user_agent' ), true ) ) {
				$kind = 'agent';
			} elseif ( in_array( $key, array( '_billing_address_index', '_shipping_address_index' ), true ) ) {
				$kind = 'street';
			} elseif ( 1 === preg_match( '/^_?(stripe|paypal|ppcp|square|mollie|braintree|razorpay)[\w\-]*(customer|source|intent|token|payer|payment_method)[\w\-]*$/i', $key ) ) {
				$kind = 'secret';
			} elseif ( $this->on( 'secrets' ) && 1 === preg_match( SecretScrubber::KEY_PATTERN, $key ) && 0 === strpos( $key, '_' ) ) {
				$kind = 'secret';
			}
		}
		if ( null !== $kind ) {
			$row['meta_value'] = $this->mask_kind( $kind, (string) $row['meta_value'] );
		}
		return $row;
	}

	/**
	 * Which kind of data a meta key holds, if it is one we know.
	 *
	 * @param string $key Meta key, without a leading underscore.
	 * @return string|null
	 */
	private static function meta_kind( $key ) {
		$key = ltrim( $key, '_' );
		if ( 1 === preg_match( '/^(billing|shipping)_(first_name)$/', $key ) || 'first_name' === $key || 'nickname' === $key ) {
			return 'first';
		}
		if ( 1 === preg_match( '/^(billing|shipping)_(last_name)$/', $key ) || 'last_name' === $key ) {
			return 'last';
		}
		if ( 1 === preg_match( '/^(billing|shipping)_email$/', $key ) ) {
			return 'email';
		}
		if ( 1 === preg_match( '/^(billing|shipping)_phone$/', $key ) ) {
			return 'phone';
		}
		if ( 1 === preg_match( '/^(billing|shipping)_address_1$/', $key ) ) {
			return 'street';
		}
		if ( 1 === preg_match( '/^(billing|shipping)_address_2$/', $key ) ) {
			return 'line2';
		}
		if ( 1 === preg_match( '/^(billing|shipping)_postcode$/', $key ) ) {
			return 'postcode';
		}
		if ( 1 === preg_match( '/^(billing|shipping)_company$/', $key ) ) {
			return 'company';
		}
		return null;
	}

	/**
	 * Posts table: order customer notes live in post_excerpt.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function posts( array $row ) {
		if ( isset( $row['post_type'], $row['post_excerpt'] ) && 0 === strpos( (string) $row['post_type'], 'shop_order' ) && '' !== $row['post_excerpt'] ) {
			$row['post_excerpt'] = $this->mask_kind( 'note', (string) $row['post_excerpt'] );
		}
		return $row;
	}

	/**
	 * Comments table.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function comments( array $row ) {
		$user_id = (int) ( $row['user_id'] ?? 0 );
		if ( $user_id > 0 && isset( $this->keep[ $user_id ] ) ) {
			return $row;
		}
		$row = $this->apply(
			$row,
			array(
				'comment_author'       => 'full',
				'comment_author_email' => 'email',
				'comment_author_IP'    => 'ip',
				'comment_agent'        => 'agent',
				'comment_content'      => 'note',
			)
		);
		if ( isset( $row['comment_author_url'] ) && '' !== $row['comment_author_url'] && $this->on( 'names' ) ) {
			$row['comment_author_url'] = '';
		}
		return $row;
	}

	/**
	 * Options table: transients and secrets.
	 *
	 * @param array $row Row.
	 * @return array|null
	 */
	private function options( array $row ) {
		$name = (string) ( $row['option_name'] ?? '' );
		if ( 0 === strpos( $name, 'estasoft_migrate_' ) ) {
			return $row;
		}
		if ( $this->on( 'secrets' ) && 1 === preg_match( '/^_(site_)?transient_/', $name ) ) {
			$this->count( 'secrets', '' );
			return null;
		}
		if ( in_array( $name, array( 'admin_email', 'new_admin_email' ), true ) && isset( $row['option_value'] ) ) {
			$row['option_value'] = $this->mask_kind( 'email', (string) $row['option_value'] );
			return $row;
		}
		if ( $this->on( 'secrets' ) && isset( $row['option_value'] ) && '' !== $row['option_value'] ) {
			if ( SecretScrubber::is_secret_option( $name ) ) {
				$row['option_value'] = $this->count( 'secrets', '' );
			} else {
				$scrubbed = SecretScrubber::scrub( (string) $row['option_value'] );
				if ( $scrubbed !== $row['option_value'] ) {
					$this->count( 'secrets', '' );
					$row['option_value'] = $scrubbed;
				}
			}
		}
		return $row;
	}
}
