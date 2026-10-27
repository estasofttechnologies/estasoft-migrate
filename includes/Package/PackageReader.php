<?php
/**
 * Reads a package file frame by frame.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Package;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Counterpart of PackageWriter. Verifies every frame before it hands the data over.
 */
class PackageReader {

	/**
	 * File handle.
	 *
	 * @var resource|null
	 */
	private $fh;

	/**
	 * Parsed header.
	 *
	 * @var array
	 */
	private $header;

	/**
	 * Encryption key or empty.
	 *
	 * @var string
	 */
	private $key = '';

	/**
	 * Offset of the next frame.
	 *
	 * @var int
	 */
	private $offset;

	/**
	 * File size.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Read and check the 40-byte header.
	 *
	 * @param string $path File path.
	 * @return array flags, kdf, file_id, salt, version, encrypted (bool), compressed (bool).
	 * @throws PackageException When the file is not a package.
	 */
	public static function read_header( $path ) {
		if ( ! is_readable( $path ) ) {
			throw new PackageException( 'The package file cannot be read.', PackageException::IO );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new PackageException( 'The package file cannot be opened.', PackageException::IO );
		}
		$raw = fread( $fh, PackageWriter::HEADER_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $raw || strlen( $raw ) < PackageWriter::HEADER_SIZE || PackageWriter::MAGIC !== substr( $raw, 0, 4 ) ) {
			throw new PackageException( 'This file is not an Estasoft Migrate package.', PackageException::CORRUPT );
		}
		$version = ord( $raw[4] );
		if ( $version > PackageWriter::VERSION ) {
			throw new PackageException( 'This package was made by a newer version of the plugin. Update the plugin on this site and try again.', PackageException::UNSUPPORTED );
		}
		$flags = ord( $raw[5] );
		return array(
			'version'    => $version,
			'flags'      => $flags,
			'kdf'        => ord( $raw[6] ),
			'file_id'    => substr( $raw, 8, 16 ),
			'salt'       => substr( $raw, 24, 16 ),
			'encrypted'  => (bool) ( $flags & PackageWriter::FLAG_ENC ),
			'compressed' => (bool) ( $flags & PackageWriter::FLAG_GZ ),
		);
	}

	/**
	 * Work out the decryption key.
	 *
	 * @param array $header  From read_header().
	 * @param array $options passphrase or secret.
	 * @return string
	 * @throws PackageException When no suitable key material was given.
	 */
	public static function derive_key( array $header, array $options ) {
		if ( ! Crypto::available() ) {
			throw new PackageException( 'This package is encrypted, and the PHP sodium extension is not available on this server.', PackageException::UNSUPPORTED );
		}
		$passphrase = isset( $options['passphrase'] ) ? (string) $options['passphrase'] : '';
		$secret     = isset( $options['secret'] ) ? (string) $options['secret'] : '';
		if ( Crypto::KDF_PASSPHRASE === $header['kdf'] ) {
			if ( '' === $passphrase ) {
				throw new PackageException( 'This package is protected with a password. Enter the password to continue.', PackageException::NEEDS_KEY );
			}
			return Crypto::key_from_passphrase( $passphrase, $header['salt'] );
		}
		if ( Crypto::KDF_SECRET === $header['kdf'] ) {
			if ( '' === $secret ) {
				throw new PackageException( 'This package was protected with the connection key of the site that made it, and that key is not available here.', PackageException::NEEDS_KEY );
			}
			return Crypto::key_from_secret( $secret, $header['salt'] );
		}
		throw new PackageException( 'The package uses an unknown protection method.', PackageException::UNSUPPORTED );
	}

	/**
	 * Open a package for reading.
	 *
	 * @param string $path    File path.
	 * @param array  $options passphrase or secret, needed for encrypted packages.
	 * @return PackageReader
	 * @throws PackageException On failure.
	 */
	public static function open( $path, array $options = array() ) {
		$r         = new self();
		$r->header = self::read_header( $path );
		if ( $r->header['encrypted'] ) {
			$r->key = self::derive_key( $r->header, $options );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$r->fh = fopen( $path, 'rb' );
		if ( ! $r->fh ) {
			throw new PackageException( 'The package file cannot be opened.', PackageException::IO );
		}
		clearstatcache( true, $path );
		$r->size   = (int) filesize( $path );
		$r->offset = PackageWriter::HEADER_SIZE;
		fseek( $r->fh, $r->offset );
		return $r;
	}

	/**
	 * Open a package only to check its blocks. No password is needed, because the blocks are
	 * checked as stored (encrypted), not decoded.
	 *
	 * @param string $path File path.
	 * @return PackageReader
	 * @throws PackageException On failure.
	 */
	public static function open_for_check( $path ) {
		$r         = new self();
		$r->header = self::read_header( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$r->fh = fopen( $path, 'rb' );
		if ( ! $r->fh ) {
			throw new PackageException( 'The package file cannot be opened.', PackageException::IO );
		}
		clearstatcache( true, $path );
		$r->size   = (int) filesize( $path );
		$r->offset = PackageWriter::HEADER_SIZE;
		fseek( $r->fh, $r->offset );
		return $r;
	}

	/**
	 * Header details.
	 *
	 * @return array
	 */
	public function header() {
		return $this->header;
	}

	/**
	 * Jump to a frame boundary saved earlier.
	 *
	 * @param int $offset Byte offset.
	 * @return void
	 */
	public function seek( $offset ) {
		$this->offset = max( PackageWriter::HEADER_SIZE, (int) $offset );
		fseek( $this->fh, $this->offset );
	}

	/**
	 * Offset of the next frame.
	 *
	 * @return int
	 */
	public function offset() {
		return $this->offset;
	}

	/**
	 * File size in bytes.
	 *
	 * @return int
	 */
	public function size() {
		return $this->size;
	}

	/**
	 * Read the next frame.
	 *
	 * @param bool $decode False to check the frame hash only (works without the decryption key and is faster).
	 * @return array|null type, seq, data (array|null), offset (frame start), end (offset after frame); null at the end of the file.
	 * @throws PackageException When the frame is damaged.
	 */
	public function next( $decode = true ) {
		if ( $this->offset >= $this->size ) {
			return null;
		}
		$head = fread( $this->fh, 9 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( false === $head || 9 !== strlen( $head ) ) {
			throw new PackageException( 'The package ends in the middle of a block. The file may not have finished uploading or downloading.', PackageException::TRUNCATED );
		}
		$type = $head[0];
		$seq  = unpack( 'N', substr( $head, 1, 4 ) )[1];
		$len  = unpack( 'N', substr( $head, 5, 4 ) )[1];
		if ( $this->offset + 9 + $len + 32 > $this->size ) {
			throw new PackageException( 'The package ends in the middle of a block. The file may not have finished uploading or downloading.', PackageException::TRUNCATED );
		}
		$payload = $len > 0 ? fread( $this->fh, $len ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$hash    = fread( $this->fh, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( (string) $payload ) !== $len || 32 !== strlen( (string) $hash ) ) {
			throw new PackageException( 'The package could not be read to the end of a block.', PackageException::TRUNCATED );
		}
		if ( ! hash_equals( hash( 'sha256', $head . $payload, true ), $hash ) ) {
			throw new PackageException( sprintf( 'Block %d of the package is damaged (checksum mismatch).', $seq ), PackageException::CORRUPT );
		}
		$start        = $this->offset;
		$this->offset = $start + 9 + $len + 32;

		$data = null;
		if ( $decode ) {
			if ( $this->header['encrypted'] ) {
				$plain = Crypto::decrypt( $payload, $this->key, $this->header['file_id'] . $type . pack( 'N', $seq ) );
				if ( false === $plain ) {
					if ( 0 === $seq ) {
						throw new PackageException( 'The password is not correct.', PackageException::BAD_PASSPHRASE );
					}
					throw new PackageException( sprintf( 'Block %d of the package could not be decrypted. It may have been changed.', $seq ), PackageException::CORRUPT );
				}
				$payload = $plain;
			}
			if ( $this->header['compressed'] ) {
				$payload = @gzinflate( $payload, 512 * 1024 * 1024 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false === $payload ) {
					throw new PackageException( sprintf( 'Block %d of the package could not be unpacked.', $seq ), PackageException::CORRUPT );
				}
			}
			$data = json_decode( $payload, true );
			if ( ! is_array( $data ) ) {
				throw new PackageException( sprintf( 'Block %d of the package holds unreadable data.', $seq ), PackageException::CORRUPT );
			}
		}

		return array(
			'type'   => $type,
			'seq'    => $seq,
			'data'   => $data,
			'offset' => $start,
			'end'    => $this->offset,
		);
	}

	/**
	 * Check every block of a package. Works for encrypted packages without the password.
	 *
	 * @param string        $path     File path.
	 * @param callable|null $progress Called with (bytes_done, total_bytes) now and then.
	 * @return array frames (int), complete (bool: an end block was found).
	 * @throws PackageException When a block is damaged or the file is cut short.
	 */
	public static function verify( $path, $progress = null ) {
		$r         = new self();
		$r->header = self::read_header( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$r->fh = fopen( $path, 'rb' );
		clearstatcache( true, $path );
		$r->size   = (int) filesize( $path );
		$r->offset = PackageWriter::HEADER_SIZE;
		fseek( $r->fh, $r->offset );

		$frames   = 0;
		$complete = false;
		$expect   = 0;
		$f        = $r->next( false );
		while ( null !== $f ) {
			if ( $f['seq'] !== $expect ) {
				throw new PackageException( sprintf( 'Block %1$d is missing or out of order (found %2$d).', $expect, $f['seq'] ), PackageException::CORRUPT );
			}
			++$expect;
			++$frames;
			if ( 'E' === $f['type'] ) {
				$complete = true;
			}
			if ( $progress && 0 === $frames % 50 ) {
				call_user_func( $progress, $r->offset, $r->size );
			}
			$f = $r->next( false );
		}
		if ( $progress ) {
			call_user_func( $progress, $r->size, $r->size );
		}
		return array(
			'frames'   => $frames,
			'complete' => $complete,
		);
	}

	/**
	 * Close the file.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->fh = null;
		}
	}

	/**
	 * Close on destruction.
	 */
	public function __destruct() {
		$this->close();
	}
}
