<?php
/**
 * The sanitizer settings a person saved on the Sanitizer screen.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans and completes what the screen sends, so stored values are always safe to use.
 */
final class SavedConfig {

	/**
	 * Settings of a profile with nothing changed.
	 *
	 * @param string $profile Profile id.
	 * @return array
	 */
	public static function defaults( $profile = 'staging' ) {
		$base = Profiles::get( $profile );
		return array(
			'profile'      => isset( Profiles::all()[ $profile ] ) ? $profile : 'staging',
			'rules'        => $base['rules'],
			'safety'       => $base['safety'],
			'keep_users'   => array(),
			'keep_admins'  => (bool) $base['keep_admins'],
			'skip_tables'  => array(),
			'skip_columns' => array(),
			'on_export'    => false,
		);
	}

	/**
	 * Clean input from the screen.
	 *
	 * @param array $in Raw values.
	 * @return array
	 */
	public static function clean( array $in ) {
		$profile = (string) ( $in['profile'] ?? 'staging' );
		$out     = self::defaults( $profile );
		foreach ( (array) ( $in['rules'] ?? array() ) as $id => $on ) {
			if ( in_array( $id, Profiles::RULES, true ) ) {
				$out['rules'][ $id ] = self::truthy( $on );
			}
		}
		foreach ( (array) ( $in['safety'] ?? array() ) as $id => $on ) {
			if ( in_array( $id, Profiles::SAFETY, true ) ) {
				$out['safety'][ $id ] = self::truthy( $on );
			}
		}
		$out['keep_users']   = self::strings( $in['keep_users'] ?? array(), 50 );
		$out['skip_tables']  = self::strings( $in['skip_tables'] ?? array(), 200 );
		$out['skip_columns'] = self::strings( $in['skip_columns'] ?? array(), 200 );
		if ( array_key_exists( 'keep_admins', $in ) ) {
			$out['keep_admins'] = self::truthy( $in['keep_admins'] );
		}
		$out['on_export'] = ! empty( $in['on_export'] ) && self::truthy( $in['on_export'] );
		return $out;
	}

	/**
	 * Input for a job that sanitizes with these settings.
	 *
	 * @param array $saved From clean().
	 * @return array profile and overrides, as JobParams expects them.
	 */
	public static function to_job_input( array $saved ) {
		return array(
			'profile'   => $saved['profile'],
			'overrides' => array(
				'rules'        => $saved['rules'],
				'safety'       => $saved['safety'],
				'keep_users'   => $saved['keep_users'],
				'keep_admins'  => $saved['keep_admins'],
				'skip_tables'  => $saved['skip_tables'],
				'skip_columns' => $saved['skip_columns'],
			),
		);
	}

	/**
	 * Loose boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * A short list of short strings.
	 *
	 * @param mixed $raw   Array or text separated by commas and new lines.
	 * @param int   $limit Most items kept.
	 * @return string[]
	 */
	private static function strings( $raw, $limit ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out = array();
		foreach ( (array) $raw as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item && strlen( $item ) <= 200 ) {
				$out[] = $item;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, $limit );
	}
}
