<?php
/**
 * Facts about the current WordPress site.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

use Estasoft\Migrate\Db\Database;

defined( 'ABSPATH' ) || defined( 'ESTASOFT_MIGRATE_TESTING' ) || exit;

/**
 * Collected into the package manifest, and compared when a package is imported on another site.
 */
final class SiteInfo {

	/**
	 * Collect the facts.
	 *
	 * @param Database $db Database.
	 * @return array
	 */
	public static function collect( Database $db ) {
		return array(
			'url'            => untrailingslashit( home_url() ),
			'siteurl'        => untrailingslashit( site_url() ),
			'prefix'         => $db->prefix(),
			'abspath'        => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'name'           => (string) get_bloginfo( 'name' ),
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'db'             => $db->server_info(),
			'multisite'      => is_multisite(),
			'plugin_version' => defined( 'ESTASOFT_MIGRATE_VERSION' ) ? ESTASOFT_MIGRATE_VERSION : '',
		);
	}

	/**
	 * Search and replace pairs that point a package's addresses at this site.
	 *
	 * Covers the home and site URL in both schemes, the protocol-relative form, and the file path.
	 *
	 * @param array $source Facts from the package manifest.
	 * @param array $local  Facts of this site.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function rewrite_pairs( array $source, array $local ) {
		$pairs = array();
		$add   = function ( $from, $to ) use ( &$pairs ) {
			$from = (string) $from;
			$to   = (string) $to;
			if ( '' !== $from && $from !== $to ) {
				$pairs[ $from ] = $to;
			}
		};

		$targets = array( 'url' => (string) ( $local['url'] ?? '' ) );
		foreach ( array( 'url', 'siteurl' ) as $key ) {
			$from = rtrim( (string) ( $source[ $key ] ?? '' ), '/' );
			if ( '' === $from ) {
				continue;
			}
			$to = rtrim( (string) ( $local[ $key ] ?? $targets['url'] ), '/' );
			$add( $from, $to );
			// The other scheme of the same address.
			if ( 0 === strpos( $from, 'https://' ) ) {
				$add( 'http://' . substr( $from, 8 ), $to );
			} elseif ( 0 === strpos( $from, 'http://' ) ) {
				$add( 'https://' . substr( $from, 7 ), $to );
			}
			// Protocol-relative form, only when the host part differs.
			$from_host = preg_replace( '#^https?:#', '', $from );
			$to_host   = preg_replace( '#^https?:#', '', $to );
			$add( $from_host, $to_host );
		}

		$from_path = rtrim( (string) ( $source['abspath'] ?? '' ), '/' );
		$to_path   = rtrim( (string) ( $local['abspath'] ?? '' ), '/' );
		if ( '' !== $from_path && '' !== $to_path ) {
			$add( $from_path, $to_path );
		}

		$out = array();
		foreach ( $pairs as $from => $to ) {
			$out[] = array( $from, $to );
		}
		return $out;
	}
}
