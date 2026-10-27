<?php
/**
 * Package encryption.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption with libsodium (XChaCha20-Poly1305), one box per package frame.
 */
final class Crypto {

	const KDF_NONE       = 0;
	const KDF_PASSPHRASE = 1;
	const KDF_SECRET     = 2;

	/**
	 * Is libsodium available?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && function_exists( 'sodium_crypto_pwhash' );
	}

	/**
	 * Turn a passphrase into a 32-byte key (Argon2id, deliberately slow).
	 *
	 * @param string $passphrase Passphrase.
	 * @param string $salt       16 random bytes.
	 * @return string
	 */
	public static function key_from_passphrase( $passphrase, $salt ) {
		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
			$passphrase,
			$salt,
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
		);
	}

	/**
	 * Turn a high-entropy secret (such as the site's connection key) into a 32-byte key.
	 *
	 * @param string $secret Secret.
	 * @param string $salt   16 random bytes.
	 * @return string
	 */
	public static function key_from_secret( $secret, $salt ) {
		return sodium_crypto_generichash( 'estasoft-migrate:package-key:' . $salt, hash( 'sha256', $secret, true ), SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
	}

	/**
	 * Encrypt.
	 *
	 * @param string $plain Data.
	 * @param string $key   32-byte key.
	 * @param string $aad   Additional authenticated data.
	 * @return string Nonce followed by ciphertext.
	 */
	public static function encrypt( $plain, $key, $aad ) {
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $aad, $nonce, $key );
	}

	/**
	 * Decrypt.
	 *
	 * @param string $blob Nonce followed by ciphertext.
	 * @param string $key  32-byte key.
	 * @param string $aad  Additional authenticated data.
	 * @return string|false Data, or false when the key is wrong or the data was changed.
	 */
	public static function decrypt( $blob, $key, $aad ) {
		$nlen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( strlen( $blob ) < $nlen + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			return false;
		}
		return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $blob, $nlen ), $aad, substr( $blob, 0, $nlen ), $key );
	}
}
