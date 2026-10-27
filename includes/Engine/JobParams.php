<?php
/**
 * Turns what a person entered (REST request, WP-CLI arguments) into safe job parameters.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Package\Secrets;
use Estasoft\Migrate\Replace\Replacer;
use Estasoft\Migrate\Sanitizer\Profiles;
use Estasoft\Migrate\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Validation lives in one place so the screens, the command line and the tests all follow the same rules.
 * Errors are InvalidArgumentException with a message that can be shown as it is.
 */
final class JobParams {

	const TYPES = array( 'export', 'import', 'search_replace', 'push', 'pull', 'verify' );

	/**
	 * Build the parameters of a job.
	 *
	 * @param string     $type Job type.
	 * @param array      $in   Raw input.
	 * @param JobContext $ctx  Services.
	 * @return array{0:string, 1:array} Title and parameters.
	 * @throws \InvalidArgumentException When the input is not valid.
	 */
	public static function build( $type, array $in, JobContext $ctx ) {
		switch ( $type ) {
			case 'export':
				return self::export( $in, $ctx );
			case 'import':
				return self::import( $in, $ctx );
			case 'search_replace':
				return self::search_replace( $in, $ctx );
			case 'push':
				return self::push( $in, $ctx );
			case 'pull':
				return self::pull( $in, $ctx );
			case 'verify':
				$id = self::package_id( $in, $ctx );
				return array( __( 'Check package', 'estasoft-migrate' ), array( 'package_id' => $id ) );
		}
		throw new \InvalidArgumentException( __( 'Unknown job type.', 'estasoft-migrate' ) );
	}

	/**
	 * Export or sanitized copy.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 */
	private static function export( array $in, JobContext $ctx ) {
		$params = array(
			'kind'     => 'export',
			'label'    => self::label( $in, 'export' ),
			'compress' => ! isset( $in['compress'] ) || self::truthy( $in['compress'] ),
		);
		$params = array_merge( $params, self::table_choice( $in, $ctx ), self::protection( $in, $ctx ), self::replace( $in ) );
		$san    = self::sanitize( $in );
		if ( $san ) {
			$params['sanitize'] = $san;
			$params['kind']     = 'sanitize';
			$params['label']    = self::label( $in, 'sanitized' );
		}
		return array( $san ? __( 'Sanitized copy', 'estasoft-migrate' ) : __( 'Export', 'estasoft-migrate' ), $params );
	}

	/**
	 * Import a package that is already in storage.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 */
	private static function import( array $in, JobContext $ctx ) {
		$id     = self::package_id( $in, $ctx );
		$meta   = (array) $ctx->storage->read_meta( $id );
		$params = array(
			'package_id' => $id,
			'snapshot'   => ! isset( $in['snapshot'] ) || self::truthy( $in['snapshot'] ),
			'rewrite'    => ! isset( $in['rewrite'] ) || self::truthy( $in['rewrite'] ),
			'kind'       => 'snapshot' === ( $meta['type'] ?? '' ) ? 'restore' : 'import',
		);
		if ( isset( $in['password'] ) && '' !== (string) $in['password'] ) {
			$params['passphrase_enc'] = Secrets::seal( (string) $in['password'], $ctx->secret );
		}
		if ( isset( $in['safety'] ) && is_array( $in['safety'] ) ) {
			$params['safety'] = self::safety( $in['safety'] );
		}
		$extra = self::replace( $in );
		if ( $extra ) {
			$params['replace'] = array( 'pairs' => $extra['replace']['pairs'] );
		}
		$title = 'restore' === $params['kind'] ? __( 'Restore snapshot', 'estasoft-migrate' ) : __( 'Import package', 'estasoft-migrate' );
		return array( $title, $params );
	}

	/**
	 * Search and replace.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 * @throws \InvalidArgumentException When nothing to search for.
	 */
	private static function search_replace( array $in, JobContext $ctx ) {
		$pairs = self::pairs( isset( $in['pairs'] ) ? $in['pairs'] : array() );
		if ( ! $pairs ) {
			throw new \InvalidArgumentException( __( 'Enter the text to search for.', 'estasoft-migrate' ) );
		}
		$regex = ! empty( $in['regex'] ) && self::truthy( $in['regex'] );
		if ( $regex ) {
			$pairs = array( $pairs[0] );
			try {
				new Replacer( $pairs, array( 'regex' => true ) );
			} catch ( \InvalidArgumentException $e ) {
				throw new \InvalidArgumentException( __( 'The search pattern is not a valid regular expression.', 'estasoft-migrate' ) );
			}
		}
		$dry    = ! isset( $in['dry_run'] ) || self::truthy( $in['dry_run'] );
		$params = array(
			'pairs'          => $pairs,
			'regex'          => $regex,
			'case_sensitive' => ! isset( $in['case_sensitive'] ) || self::truthy( $in['case_sensitive'] ),
			'replace_keys'   => ! isset( $in['replace_keys'] ) || self::truthy( $in['replace_keys'] ),
			'skip_guid'      => ! isset( $in['skip_guid'] ) || self::truthy( $in['skip_guid'] ),
			'dry_run'        => $dry,
			'snapshot'       => ! $dry && ( ! isset( $in['snapshot'] ) || self::truthy( $in['snapshot'] ) ),
			'sample_limit'   => max( 0, min( 100, (int) ( $in['sample_limit'] ?? 20 ) ) ),
			'skip_columns'   => self::string_list( $in['skip_columns'] ?? array() ),
		);
		$params = array_merge( $params, self::table_choice( $in, $ctx ) );
		return array( $dry ? __( 'Search and replace preview', 'estasoft-migrate' ) : __( 'Search and replace', 'estasoft-migrate' ), $params );
	}

	/**
	 * Push to another site.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 */
	private static function push( array $in, JobContext $ctx ) {
		$params = array_merge(
			self::remote( $in, $ctx ),
			array(
				'label'        => self::label( $in, 'push' ),
				'snapshot'     => ! isset( $in['snapshot'] ) || self::truthy( $in['snapshot'] ),
				'rewrite'      => ! isset( $in['rewrite'] ) || self::truthy( $in['rewrite'] ),
				'keep_package' => ! empty( $in['keep_package'] ) && self::truthy( $in['keep_package'] ),
			),
			self::table_choice( $in, $ctx ),
			self::replace( $in )
		);
		$san    = self::sanitize( $in );
		if ( $san ) {
			$params['sanitize'] = $san;
		}
		if ( isset( $in['safety'] ) && is_array( $in['safety'] ) ) {
			$params['safety'] = self::safety( $in['safety'] );
		}
		return array( __( 'Push to another site', 'estasoft-migrate' ), $params );
	}

	/**
	 * Pull from another site. The sanitizer settings are sent to the other site, which applies them before any data leaves it.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 */
	private static function pull( array $in, JobContext $ctx ) {
		$params = array_merge(
			self::remote( $in, $ctx ),
			array(
				'label'        => self::label( $in, 'pull' ),
				'snapshot'     => ! isset( $in['snapshot'] ) || self::truthy( $in['snapshot'] ),
				'rewrite'      => ! isset( $in['rewrite'] ) || self::truthy( $in['rewrite'] ),
				'keep_package' => ! empty( $in['keep_package'] ) && self::truthy( $in['keep_package'] ),
			)
		);
		// Tables are named as on the other site, so they are checked there, not here.
		foreach ( array( 'tables', 'exclude_tables' ) as $key ) {
			$list = self::string_list( $in[ $key ] ?? array() );
			if ( $list ) {
				$params[ $key ] = $list;
			}
		}
		$params = array_merge( $params, self::replace( $in ) );
		if ( ! empty( $in['sanitize'] ) && is_array( $in['sanitize'] ) ) {
			$profile = (string) ( $in['sanitize']['profile'] ?? 'staging' );
			if ( ! isset( Profiles::all()[ $profile ] ) ) {
				throw new \InvalidArgumentException( __( 'Choose one of the sanitizer profiles.', 'estasoft-migrate' ) );
			}
			$params['sanitize'] = array(
				'profile'   => $profile,
				'overrides' => (array) ( $in['sanitize']['overrides'] ?? array() ),
			);
		}
		if ( isset( $in['safety'] ) && is_array( $in['safety'] ) ) {
			$params['safety'] = self::safety( $in['safety'] );
		}
		return array( __( 'Pull from another site', 'estasoft-migrate' ), $params );
	}

	/**
	 * Address and key of the other site, with the key sealed.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 * @throws \InvalidArgumentException When missing or malformed.
	 */
	private static function remote( array $in, JobContext $ctx ) {
		$url = rtrim( trim( (string) ( $in['remote_url'] ?? '' ) ), '/' );
		$key = trim( (string) ( $in['remote_key'] ?? '' ) );
		if ( ! preg_match( '#^https?://[^\s/]+(?:/[^\s?\#]*)?$#i', $url ) ) {
			throw new \InvalidArgumentException( __( 'Enter the full address of the other site, for example https://example.com', 'estasoft-migrate' ) );
		}
		if ( '' === $key ) {
			throw new \InvalidArgumentException( __( 'Enter the connection key from the other site’s Settings screen.', 'estasoft-migrate' ) );
		}
		if ( 0 !== strpos( $key, 'esm_' ) ) {
			throw new \InvalidArgumentException( __( 'That does not look like a connection key. Keys start with esm_.', 'estasoft-migrate' ) );
		}
		return array(
			'remote_url' => $url,
			'key_enc'    => Secrets::seal( $key, $ctx->secret ),
		);
	}

	/**
	 * Package id from input, which must exist.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return string
	 * @throws \InvalidArgumentException When unknown.
	 */
	private static function package_id( array $in, JobContext $ctx ) {
		$id = (string) ( $in['package_id'] ?? '' );
		if ( ! Storage::is_valid_id( $id ) || ! is_file( $ctx->storage->package_path( $id ) ) ) {
			throw new \InvalidArgumentException( __( 'The package file could not be found.', 'estasoft-migrate' ) );
		}
		return $id;
	}

	/**
	 * Tables chosen by the person; unknown names are an error, not ignored.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 * @throws \InvalidArgumentException When a table does not exist.
	 */
	private static function table_choice( array $in, JobContext $ctx ) {
		$known = array();
		foreach ( TableSelector::select( $ctx->db ) as $t ) {
			$known[ $t['name'] ] = true;
		}
		$out = array();
		foreach ( array( 'tables', 'exclude_tables' ) as $key ) {
			$list = self::string_list( $in[ $key ] ?? array() );
			foreach ( $list as $name ) {
				if ( ! isset( $known[ $name ] ) ) {
					throw new \InvalidArgumentException(
						sprintf(
							/* translators: %s: table name */
							__( 'The table %s does not exist on this site.', 'estasoft-migrate' ),
							$name
						)
					);
				}
			}
			if ( $list ) {
				$out[ $key ] = $list;
			}
		}
		return $out;
	}

	/**
	 * Package password and protection.
	 *
	 * @param array      $in  Input.
	 * @param JobContext $ctx Services.
	 * @return array
	 * @throws \InvalidArgumentException When the password is too short.
	 */
	private static function protection( array $in, JobContext $ctx ) {
		$out = array();
		if ( isset( $in['password'] ) && '' !== (string) $in['password'] ) {
			if ( mb_strlen( (string) $in['password'], 'UTF-8' ) < 8 ) {
				throw new \InvalidArgumentException( __( 'Use at least 8 characters for the package password.', 'estasoft-migrate' ) );
			}
			$out['passphrase_enc'] = Secrets::seal( (string) $in['password'], $ctx->secret );
		} elseif ( ! empty( $in['protect_with_site_key'] ) && self::truthy( $in['protect_with_site_key'] ) ) {
			$out['protect_with_site_key'] = true;
		}
		return $out;
	}

	/**
	 * Replacement pairs applied while exporting.
	 *
	 * @param array $in Input.
	 * @return array
	 */
	private static function replace( array $in ) {
		$pairs = self::pairs( isset( $in['replace']['pairs'] ) ? $in['replace']['pairs'] : array() );
		if ( ! $pairs ) {
			return array();
		}
		return array(
			'replace' => array(
				'pairs'          => $pairs,
				'regex'          => ! empty( $in['replace']['regex'] ) && self::truthy( $in['replace']['regex'] ),
				'case_sensitive' => ! isset( $in['replace']['case_sensitive'] ) || self::truthy( $in['replace']['case_sensitive'] ),
			),
		);
	}

	/**
	 * Sanitizer configuration, or null when the sanitizer is off.
	 *
	 * @param array $in Input.
	 * @return array|null
	 * @throws \InvalidArgumentException For an unknown profile.
	 */
	private static function sanitize( array $in ) {
		if ( empty( $in['sanitize'] ) || ! is_array( $in['sanitize'] ) ) {
			return null;
		}
		$profile = (string) ( $in['sanitize']['profile'] ?? 'staging' );
		if ( ! isset( Profiles::all()[ $profile ] ) ) {
			throw new \InvalidArgumentException( __( 'Choose one of the sanitizer profiles.', 'estasoft-migrate' ) );
		}
		return Profiles::build_config( $profile, (array) ( $in['sanitize']['overrides'] ?? array() ) );
	}

	/**
	 * Safety switches as booleans.
	 *
	 * @param array $in Input.
	 * @return array
	 */
	private static function safety( array $in ) {
		$out = array();
		foreach ( Profiles::SAFETY as $id ) {
			if ( array_key_exists( $id, $in ) ) {
				$out[ $id ] = self::truthy( $in[ $id ] );
			}
		}
		return $out;
	}

	/**
	 * Clean search/replace pairs.
	 *
	 * @param mixed $raw Raw pairs.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function pairs( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( array_slice( $raw, 0, 200 ) as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$pair = array_values( $pair );
			$from = (string) ( $pair[0] ?? '' );
			if ( '' === $from || strlen( $from ) > 4000 ) {
				continue;
			}
			$out[] = array( $from, substr( (string) ( $pair[1] ?? '' ), 0, 4000 ) );
		}
		return $out;
	}

	/**
	 * A list of short strings.
	 *
	 * @param mixed $raw Array or comma/newline separated text.
	 * @return string[]
	 */
	private static function string_list( $raw ) {
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
		return array_values( array_unique( $out ) );
	}

	/**
	 * Short label for file names.
	 *
	 * @param array  $in      Input.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function label( array $in, $default ) {
		$label = trim( (string) ( $in['label'] ?? '' ) );
		return '' === $label ? $default : substr( $label, 0, 80 );
	}

	/**
	 * Loose boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function truthy( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
