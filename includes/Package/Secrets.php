<?php
/**
 * Seals short secrets (such as a package password) for storage in a job.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Package;

defined( 'ABSPATH' ) || exit;

/**
 * A package password has to survive between job slices, so it is kept with the job. It is
 * encrypted with the site's connection key first, so it is not readable in a database dump or a log.
 */
final class Secrets {

	/**
	 * Encrypt.
	 *
	 * @param string $plain  Secret text.
	 * @param string $secret Site secret.
	 * @return string
	 */
	public static function seal( $plain, $secret ) {
		if ( ! Crypto::available() || '' === $secret ) {
			return 'p:' . base64_encode( $plain );
		}
		return 's:' . base64_encode( Crypto::encrypt( $plain, self::key( $secret ), 'job-secret' ) );
	}

	/**
	 * Decrypt.
	 *
	 * @param string $sealed Output of seal().
	 * @param string $secret Site secret.
	 * @return string Empty string when it cannot be opened.
	 */
	public static function open( $sealed, $secret ) {
		if ( 0 === strpos( $sealed, 'p:' ) ) {
			return (string) base64_decode( substr( $sealed, 2 ), true );
		}
		if ( 0 === strpos( $sealed, 's:' ) && Crypto::available() ) {
			$plain = Crypto::decrypt( (string) base64_decode( substr( $sealed, 2 ), true ), self::key( $secret ), 'job-secret' );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	/**
	 * Derive the key.
	 *
	 * @param string $secret Site secret.
	 * @return string
	 */
	private static function key( $secret ) {
		return sodium_crypto_generichash( 'estasoft-migrate:seal', hash( 'sha256', $secret, true ), SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
	}
}
