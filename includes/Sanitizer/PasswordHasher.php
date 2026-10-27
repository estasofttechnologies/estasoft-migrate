<?php
/**
 * Password hashing for reset accounts.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Uses WordPress's own hashing when it is loaded, so reset accounts accept the password on any WordPress version.
 */
final class PasswordHasher {

	/**
	 * Hash a password once. The same hash is then written to every reset account.
	 *
	 * @param string $plain Password. Empty means a random one nobody will ever know.
	 * @return string
	 */
	public static function hash( $plain = '' ) {
		if ( '' === $plain ) {
			$plain = bin2hex( random_bytes( 24 ) );
		}
		if ( function_exists( 'wp_hash_password' ) ) {
			return wp_hash_password( $plain );
		}
		return password_hash( $plain, PASSWORD_BCRYPT, array( 'cost' => 4 ) );
	}
}
