<?php
/**
 * REST routes for the admin screens. Thin: every route calls Api.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Rest;

use Estasoft\Migrate\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers estasoft-migrate/v1. Only administrators can use it, and WordPress checks the REST nonce.
 */
class Router {

	const NAMESPACE_ = 'estasoft-migrate/v1';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Capability needed for every route.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Capability needed to use Estasoft Migrate. Administrators by default.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'estasoft_migrate_capability', 'manage_options' );
	}

	/**
	 * Is the current user allowed?
	 *
	 * @return bool
	 */
	public function allowed() {
		return current_user_can( self::capability() );
	}

	/**
	 * Add a route.
	 *
	 * @param string   $route    Route pattern.
	 * @param string   $methods  HTTP methods.
	 * @param callable $handler  function( Api, WP_REST_Request ): array.
	 * @return void
	 */
	private function add( $route, $methods, callable $handler ) {
		register_rest_route(
			self::NAMESPACE_,
			$route,
			array(
				'methods'             => $methods,
				'permission_callback' => array( $this, 'allowed' ),
				'callback'            => function ( \WP_REST_Request $request ) use ( $handler ) {
					return $this->respond( $handler, $request );
				},
			)
		);
	}

	/**
	 * Run a handler and turn failures into REST errors.
	 *
	 * @param callable         $handler Handler.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond( callable $handler, \WP_REST_Request $request ) {
		try {
			$result = call_user_func( $handler, $this->plugin->api(), $request );
			return new \WP_REST_Response( $result, isset( $result['_status'] ) ? (int) $result['_status'] : 200 );
		} catch ( ApiException $e ) {
			return new \WP_Error( $e->error_code, $e->getMessage(), array_merge( $e->data, array( 'status' => $e->status ) ) );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'REST error: ' . $e->getMessage(), 0, array( 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ) );
			return new \WP_Error(
				'estm_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Something went wrong: %s', 'estasoft-migrate' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Values sent by the screen: JSON body first, then the query string.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	private static function input( \WP_REST_Request $r ) {
		$json = $r->get_json_params();
		return array_merge( (array) $r->get_query_params(), is_array( $json ) ? $json : (array) $r->get_body_params() );
	}

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public function register() {
		$read   = \WP_REST_Server::READABLE;
		$create = \WP_REST_Server::CREATABLE;
		$remove = \WP_REST_Server::DELETABLE;

		$this->add(
			'/overview',
			$read,
			static function ( Api $api ) {
				return $api->overview();
			}
		);
		$this->add(
			'/tables',
			$read,
			static function ( Api $api ) {
				return $api->tables();
			}
		);
		$this->add(
			'/preflight',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->preflight( self::input( $r ) );
			}
		);

		$this->add(
			'/jobs',
			$read,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->jobs( self::input( $r ) );
			}
		);
		$this->add(
			'/jobs',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				$in   = self::input( $r );
				$type = (string) ( $in['type'] ?? '' );
				unset( $in['type'] );
				$view            = $api->create_job( $type, $in );
				$view['_status'] = 201;
				return $view;
			}
		);
		$this->add(
			'/jobs/(?P<id>\d+)',
			$read,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->job( (int) $r['id'], ! empty( $r->get_param( 'state' ) ) );
			}
		);
		$this->add(
			'/jobs/(?P<id>\d+)',
			$remove,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->delete_job( (int) $r['id'] );
			}
		);
		$this->add(
			'/jobs/(?P<id>\d+)/tick',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->tick( (int) $r['id'], (int) $r->get_param( 'after_log' ) );
			}
		);
		$this->add(
			'/jobs/(?P<id>\d+)/(?P<action>pause|resume|cancel|retry)',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->job_action( (int) $r['id'], (string) $r['action'] );
			}
		);

		$this->add(
			'/logs',
			$read,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->logs( self::input( $r ) );
			}
		);
		$this->add(
			'/logs',
			$remove,
			static function ( Api $api, \WP_REST_Request $r ) {
				$job = $r->get_param( 'job_id' );
				return $api->clear_logs( null === $job || '' === $job ? null : (int) $job );
			}
		);

		$this->add(
			'/packages',
			$read,
			static function ( Api $api, \WP_REST_Request $r ) {
				$type = (string) $r->get_param( 'type' );
				return $api->packages( in_array( $type, array( 'export', 'snapshot', 'received' ), true ) ? $type : null );
			}
		);
		$this->add(
			'/packages/(?P<id>[A-Za-z0-9._-]+)',
			$remove,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->delete_package( (string) $r['id'] );
			}
		);
		$this->add(
			'/packages/upload',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				$in = self::input( $r );
				return $api->upload_init( (string) ( $in['name'] ?? 'package.estm' ), (int) ( $in['size'] ?? 0 ) );
			}
		);
		$this->add(
			'/packages/upload/(?P<id>[A-Za-z0-9._-]+)',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->upload_chunk( (string) $r['id'], (int) $r->get_param( 'offset' ), (string) $r->get_body() );
			}
		);
		$this->add(
			'/packages/upload/(?P<id>[A-Za-z0-9._-]+)/done',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				$in = self::input( $r );
				return $api->upload_done( (string) $r['id'], (int) ( $in['size'] ?? 0 ) );
			}
		);

		$this->add(
			'/settings',
			$read,
			static function ( Api $api ) {
				return $api->settings();
			}
		);
		$this->add(
			'/settings',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->save_settings( self::input( $r ) );
			}
		);
		$this->add(
			'/settings/key',
			$create,
			static function ( Api $api ) {
				return $api->regenerate_key();
			}
		);
		$this->add(
			'/settings/test-connection',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				$in = self::input( $r );
				return $api->test_connection( (string) ( $in['remote_url'] ?? '' ), (string) ( $in['remote_key'] ?? '' ) );
			}
		);

		$this->add(
			'/sanitizer',
			$read,
			static function ( Api $api ) {
				return $api->sanitizer_info();
			}
		);
		$this->add(
			'/sanitizer/preview',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->sanitizer_preview( self::input( $r ) );
			}
		);
		$this->add(
			'/sanitizer/save',
			$create,
			static function ( Api $api, \WP_REST_Request $r ) {
				return $api->save_sanitizer( self::input( $r ) );
			}
		);
	}
}
