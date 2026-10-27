<?php
/**
 * Writes a package file frame by frame.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Package;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Package file layout
 * -------------------
 * Header (40 bytes): "ESTM", version, flags (1 = encrypted, 2 = compressed), key mode,
 * reserved, 16-byte file id, 16-byte key salt.
 *
 * Then any number of frames: type (1 byte), sequence number (4 bytes), payload length
 * (4 bytes), payload, and a 32-byte SHA-256 of the three header fields plus the payload.
 *
 * Frame types: M manifest, T table start, R rows, U table end, E end of package.
 * A payload is JSON, optionally compressed (raw deflate) and then encrypted
 * (XChaCha20-Poly1305, with the file id, type and sequence number as authenticated data).
 *
 * Every frame checks itself, so a damaged package is caught at the exact frame, and a job can
 * resume from the byte offset after the last good frame.
 */
class PackageWriter {

	const MAGIC       = 'ESTM';
	const VERSION     = 1;
	const HEADER_SIZE = 40;
	const FLAG_ENC    = 1;
	const FLAG_GZ     = 2;

	/**
	 * File handle.
	 *
	 * @var resource|null
	 */
	private $fh;

	/**
	 * Next sequence number.
	 *
	 * @var int
	 */
	private $seq = 0;

	/**
	 * Current end of file.
	 *
	 * @var int
	 */
	private $offset = 0;

	/**
	 * Header flags.
	 *
	 * @var int
	 */
	private $flags = 0;

	/**
	 * File id (raw bytes).
	 *
	 * @var string
	 */
	private $file_id = '';

	/**
	 * Encryption key (raw bytes) or empty.
	 *
	 * @var string
	 */
	private $key = '';

	/**
	 * Create a new package file.
	 *
	 * @param string $path    File path.
	 * @param array  $options compress (bool, default true), passphrase (string), secret (string).
	 *                        With a passphrase or secret the package is encrypted.
	 * @return PackageWriter
	 * @throws PackageException On failure.
	 */
	public static function create( $path, array $options = array() ) {
		$w          = new self();
		$compress   = ! isset( $options['compress'] ) || $options['compress'];
		$w->flags   = ( $compress && function_exists( 'gzdeflate' ) ) ? self::FLAG_GZ : 0;
		$w->file_id = random_bytes( 16 );
		$salt       = str_repeat( "\0", 16 );
		$mode       = Crypto::KDF_NONE;

		$passphrase = isset( $options['passphrase'] ) ? (string) $options['passphrase'] : '';
		$secret     = isset( $options['secret'] ) ? (string) $options['secret'] : '';
		if ( '' !== $passphrase || '' !== $secret ) {
			if ( ! Crypto::available() ) {
				throw new PackageException( 'Encryption needs the PHP sodium extension, which is not available on this server.', PackageException::UNSUPPORTED );
			}
			$salt = random_bytes( 16 );
			if ( '' !== $passphrase ) {
				$mode   = Crypto::KDF_PASSPHRASE;
				$w->key = Crypto::key_from_passphrase( $passphrase, $salt );
			} else {
				$mode   = Crypto::KDF_SECRET;
				$w->key = Crypto::key_from_secret( $secret, $salt );
			}
			$w->flags |= self::FLAG_ENC;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked on the next line.
		$w->fh = @fopen( $path, 'wb' );
		if ( ! $w->fh ) {
			throw new PackageException( 'The package file could not be created. Check that the storage folder is writable.', PackageException::IO );
		}
		$header = self::MAGIC . chr( self::VERSION ) . chr( $w->flags ) . chr( $mode ) . "\0" . $w->file_id . $salt;
		$w->put( $header );
		$w->offset = self::HEADER_SIZE;
		return $w;
	}

	/**
	 * Continue writing a package that was interrupted. Everything after $offset is discarded.
	 *
	 * @param string $path    File path.
	 * @param int    $offset  Byte offset just after the last frame that was fully written and recorded.
	 * @param int    $seq     Sequence number of the next frame.
	 * @param array  $options passphrase or secret, as used when the file was created.
	 * @return PackageWriter
	 * @throws PackageException On failure.
	 */
	public static function resume( $path, $offset, $seq, array $options = array() ) {
		$header = PackageReader::read_header( $path );
		$w      = new self();

		$w->flags   = $header['flags'];
		$w->file_id = $header['file_id'];
		if ( $header['flags'] & self::FLAG_ENC ) {
			$w->key = PackageReader::derive_key( $header, $options );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked on the next line.
		$w->fh = @fopen( $path, 'r+b' );
		if ( ! $w->fh ) {
			throw new PackageException( 'The package file could not be reopened.', PackageException::IO );
		}
		clearstatcache( true, $path );
		if ( $offset < self::HEADER_SIZE || $offset > filesize( $path ) ) {
			throw new PackageException( 'The package file is shorter than the job expected, so the job cannot continue from where it stopped.', PackageException::TRUNCATED );
		}
		ftruncate( $w->fh, $offset );
		fseek( $w->fh, $offset );
		$w->offset = (int) $offset;
		$w->seq    = (int) $seq;
		return $w;
	}

	/**
	 * Write one frame.
	 *
	 * @param string $type Frame type, one character.
	 * @param array  $data Payload, encoded as JSON.
	 * @return int Offset after the frame.
	 * @throws PackageException On failure.
	 */
	public function frame( $type, array $data ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$json = json_encode( $data, RowCodec::FLAGS );
		if ( false === $json ) {
			throw new PackageException( 'A package block could not be encoded: ' . json_last_error_msg() );
		}
		return $this->raw_frame( $type, $json );
	}

	/**
	 * Write a frame whose payload is already JSON text.
	 *
	 * @param string $type Frame type.
	 * @param string $json JSON text.
	 * @return int Offset after the frame.
	 * @throws PackageException On failure.
	 */
	public function raw_frame( $type, $json ) {
		$seq     = $this->seq;
		$payload = $json;
		if ( $this->flags & self::FLAG_GZ ) {
			$payload = gzdeflate( $payload, 6 );
		}
		if ( $this->flags & self::FLAG_ENC ) {
			$payload = Crypto::encrypt( $payload, $this->key, $this->aad( $type, $seq ) );
		}
		$head = $type . pack( 'N', $seq ) . pack( 'N', strlen( $payload ) );
		$this->put( $head . $payload . hash( 'sha256', $head . $payload, true ) );
		$this->offset += strlen( $head ) + strlen( $payload ) + 32;
		++$this->seq;
		return $this->offset;
	}

	/**
	 * Associated data for encryption.
	 *
	 * @param string $type Frame type.
	 * @param int    $seq  Sequence number.
	 * @return string
	 */
	private function aad( $type, $seq ) {
		return $this->file_id . $type . pack( 'N', $seq );
	}

	/**
	 * Write bytes completely or fail.
	 *
	 * @param string $bytes Bytes.
	 * @return void
	 * @throws PackageException When the disk is full or the write fails.
	 */
	private function put( $bytes ) {
		$len     = strlen( $bytes );
		$written = 0;
		while ( $written < $len ) {
			$n = fwrite( $this->fh, substr( $bytes, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $n || 0 === $n ) {
				throw new PackageException( 'Writing the package failed. The disk may be full.', PackageException::IO );
			}
			$written += $n;
		}
	}

	/**
	 * Byte offset after the last frame.
	 *
	 * @return int
	 */
	public function offset() {
		return $this->offset;
	}

	/**
	 * Sequence number of the next frame.
	 *
	 * @return int
	 */
	public function seq() {
		return $this->seq;
	}

	/**
	 * Make sure everything reached the disk.
	 *
	 * @return void
	 */
	public function flush() {
		if ( $this->fh ) {
			fflush( $this->fh );
		}
	}

	/**
	 * Close the file.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->fh ) {
			fflush( $this->fh );
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
