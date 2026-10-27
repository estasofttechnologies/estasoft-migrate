<?php
/**
 * Sanitizer profiles and rule lists.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * The three ready-made profiles from the Sanitizer screen, and the rule and safety ids they switch on.
 */
final class Profiles {

	const RULES  = array( 'names', 'emails', 'phones', 'addr', 'ips', 'pw', 'secrets', 'notes' );
	const SAFETY = array( 'mail', 'seo', 'pay', 'smtp' );

	/**
	 * All profiles.
	 *
	 * @return array<string,array{name:string,text:string,rules:array<string,bool>,safety:array<string,bool>,keep_admins:bool}>
	 */
	public static function all() {
		return array(
			'staging' => array(
				'name'        => __( 'Staging', 'estasoft-migrate' ),
				'text'        => __( 'A safe copy for testing. Fake personal data, passwords reset, outgoing email blocked.', 'estasoft-migrate' ),
				'rules'       => self::flags(
					self::RULES,
					array( 'names', 'emails', 'phones', 'addr', 'ips', 'pw', 'secrets' )
				),
				'safety'      => self::flags( self::SAFETY, array( 'mail', 'seo', 'pay', 'smtp' ) ),
				// You usually sign in to a staging copy with your own account, so administrators are kept as they are.
				'keep_admins' => true,
			),
			'dev'     => array(
				'name'        => __( 'Developer handoff', 'estasoft-migrate' ),
				'text'        => __( 'Keeps content and structure. Removes customer data, free text and secrets.', 'estasoft-migrate' ),
				'rules'       => self::flags( self::RULES, self::RULES ),
				'safety'      => self::flags( self::SAFETY, array( 'mail', 'pay' ) ),
				// The copy leaves your hands, so administrator names, emails and password hashes are masked too.
				'keep_admins' => false,
			),
			'gdpr'    => array(
				'name'        => __( 'GDPR-safe copy', 'estasoft-migrate' ),
				'text'        => __( 'Anonymizes everything that could identify a person, including free text.', 'estasoft-migrate' ),
				'rules'       => self::flags( self::RULES, self::RULES ),
				'safety'      => self::flags( self::SAFETY, array() ),
				'keep_admins' => false,
			),
		);
	}

	/**
	 * Settings of one profile, or the staging profile for an unknown id.
	 *
	 * @param string $id Profile id.
	 * @return array
	 */
	public static function get( $id ) {
		$all = self::all();
		return $all[ $id ] ?? $all['staging'];
	}

	/**
	 * Build a sanitizer configuration from a profile and optional overrides.
	 *
	 * @param string $profile   Profile id.
	 * @param array  $overrides rules (id => bool), safety (id => bool), keep_users (list), keep_admins (bool), skip_tables, skip_columns, columns, seed, password.
	 * @return array
	 */
	public static function build_config( $profile, array $overrides = array() ) {
		$base  = self::get( $profile );
		$rules = $base['rules'];
		foreach ( (array) ( $overrides['rules'] ?? array() ) as $id => $on ) {
			if ( in_array( $id, self::RULES, true ) ) {
				$rules[ $id ] = (bool) $on;
			}
		}
		$safety = $base['safety'];
		foreach ( (array) ( $overrides['safety'] ?? array() ) as $id => $on ) {
			if ( in_array( $id, self::SAFETY, true ) ) {
				$safety[ $id ] = (bool) $on;
			}
		}
		return array(
			'profile'      => isset( self::all()[ $profile ] ) ? $profile : 'staging',
			'rules'        => $rules,
			'safety'       => $safety,
			'keep_users'   => \Estasoft\Migrate\Util::to_list( $overrides['keep_users'] ?? array() ),
			'keep_admins'  => isset( $overrides['keep_admins'] ) ? (bool) $overrides['keep_admins'] : (bool) $base['keep_admins'],
			'skip_tables'  => \Estasoft\Migrate\Util::to_list( $overrides['skip_tables'] ?? array() ),
			'skip_columns' => \Estasoft\Migrate\Util::to_list( $overrides['skip_columns'] ?? array() ),
			'columns'      => (array) ( $overrides['columns'] ?? array() ),
			'seed'         => (string) ( $overrides['seed'] ?? '' ),
			'password'     => (string) ( $overrides['password'] ?? '' ),
		);
	}

	/**
	 * Plain-language description of every rule, for the Sanitizer screen.
	 *
	 * @return array<int,array{id:string,what:string,where:string,how:string,example:string}>
	 */
	public static function rules_info() {
		return array(
			array(
				'id'      => 'names',
				'what'    => __( 'Names', 'estasoft-migrate' ),
				'where'   => __( 'Display names, first and last names', 'estasoft-migrate' ),
				'how'     => __( 'Replaced with a fake name', 'estasoft-migrate' ),
				'example' => __( 'Sara Whitfield → Alex Moreno', 'estasoft-migrate' ),
			),
			array(
				'id'      => 'emails',
				'what'    => __( 'Email addresses', 'estasoft-migrate' ),
				'where'   => __( 'Users, comments, WooCommerce billing', 'estasoft-migrate' ),
				'how'     => __( 'Replaced with an address on a reserved test domain', 'estasoft-migrate' ),
				'example' => __( 'sara.w@mail.test → user0000004821@example.invalid', 'estasoft-migrate' ),
			),
			array(
				'id'      => 'phones',
				'what'    => __( 'Phone numbers', 'estasoft-migrate' ),
				'where'   => __( 'WooCommerce billing and shipping', 'estasoft-migrate' ),
				'how'     => __( 'Digits replaced, format kept', 'estasoft-migrate' ),
				'example' => __( '+44 7700 900123 → +44 7700 900456', 'estasoft-migrate' ),
			),
			array(
				'id'      => 'addr',
				'what'    => __( 'Street addresses', 'estasoft-migrate' ),
				'where'   => __( 'WooCommerce billing and shipping', 'estasoft-migrate' ),
				'how'     => __( 'Fake street, same town and country', 'estasoft-migrate' ),
				'example' => __( '12 Oak Lane, Leeds → 48 Pine Road, Leeds', 'estasoft-migrate' ),
			),
			array(
				'id'      => 'ips',
				'what'    => __( 'IP addresses', 'estasoft-migrate' ),
				'where'   => __( 'Comments and orders', 'estasoft-migrate' ),
				'how'     => __( 'Last part set to zero', 'estasoft-migrate' ),
				'example' => __( '203.0.113.57 → 203.0.113.0', 'estasoft-migrate' ),
			),
			array(
				'id'      => 'pw',
				'what'    => __( 'Passwords and sessions', 'estasoft-migrate' ),
				'where'   => __( 'Users, session tokens, application passwords', 'estasoft-migrate' ),
				'how'     => __( 'Passwords reset to random values, sessions removed', 'estasoft-migrate' ),
				'example' => __( 'old hash → new random hash', 'estasoft-migrate' ),
			),
			array(
				'id'      => 'secrets',
				'what'    => __( 'Keys and secrets', 'estasoft-migrate' ),
				'where'   => __( 'Payment, SMTP and analytics settings, transients', 'estasoft-migrate' ),
				'how'     => __( 'Emptied', 'estasoft-migrate' ),
				'example' => __( 'api_key a81f… → (empty)', 'estasoft-migrate' ),
			),
			array(
				'id'      => 'notes',
				'what'    => __( 'Free text', 'estasoft-migrate' ),
				'where'   => __( 'Order notes and comment text', 'estasoft-migrate' ),
				'how'     => __( 'Removed, because it can hold personal details', 'estasoft-migrate' ),
				'example' => __( 'Call me on 0750… → (removed)', 'estasoft-migrate' ),
			),
		);
	}

	/**
	 * Plain-language description of every safety switch.
	 *
	 * @return array<int,array{id:string,title:string,text:string}>
	 */
	public static function safety_info() {
		return array(
			array(
				'id'    => 'mail',
				'title' => __( 'Block outgoing email', 'estasoft-migrate' ),
				'text'  => __( 'The copy cannot email real customers or users.', 'estasoft-migrate' ),
			),
			array(
				'id'    => 'seo',
				'title' => __( 'Discourage search engines', 'estasoft-migrate' ),
				'text'  => __( 'Turns on the WordPress setting that asks search engines not to index the site.', 'estasoft-migrate' ),
			),
			array(
				'id'    => 'pay',
				'title' => __( 'Deactivate payment gateway plugins', 'estasoft-migrate' ),
				'text'  => __( 'Live payments cannot be taken on a copy.', 'estasoft-migrate' ),
			),
			array(
				'id'    => 'smtp',
				'title' => __( 'Deactivate SMTP and analytics plugins', 'estasoft-migrate' ),
				'text'  => __( 'No mail relay or visitor tracking from a copy.', 'estasoft-migrate' ),
			),
		);
	}

	/**
	 * Map ids to booleans.
	 *
	 * @param string[] $all Every id.
	 * @param string[] $on  Ids that are on.
	 * @return array<string,bool>
	 */
	private static function flags( array $all, array $on ) {
		$out = array();
		foreach ( $all as $id ) {
			$out[ $id ] = in_array( $id, $on, true );
		}
		return $out;
	}
}
