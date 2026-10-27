<?php
/**
 * The Estasoft Migrate screens inside wp-admin: menu, assets and the page shell.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Admin;

use Estasoft\Migrate\Plugin;
use Estasoft\Migrate\Rest\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Every screen is a single page that is drawn by JavaScript from the REST API. This class only
 * adds the menu, loads the files that belong to the screen that is open, and prints the shell.
 */
class Admin {

	const SLUG = 'estasoft-migrate';

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
	 * Connect to WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_head', array( $this, 'menu_style' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ESTASOFT_MIGRATE_FILE ), array( $this, 'action_links' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ), 20 );
		add_action( 'admin_post_estm_download_package', array( new Downloads( $this->plugin ), 'package' ) );
		add_action( 'admin_post_estm_download_log', array( new Downloads( $this->plugin ), 'log' ) );
	}

	/**
	 * Screens: id => title, menu label (empty = not in the menu), file name of the script.
	 *
	 * @return array<string,array{title:string,menu:string,slug:string}>
	 */
	public static function screens() {
		return array(
			'overview'  => array(
				'title' => __( 'Overview', 'estasoft-migrate' ),
				'menu'  => __( 'Overview', 'estasoft-migrate' ),
				'slug'  => self::SLUG,
			),
			'new'       => array(
				'title' => __( 'New migration', 'estasoft-migrate' ),
				'menu'  => __( 'New migration', 'estasoft-migrate' ),
				'slug'  => self::SLUG . '-new',
			),
			'preflight' => array(
				'title' => __( 'Pre-flight check', 'estasoft-migrate' ),
				'menu'  => '',
				'slug'  => self::SLUG . '-preflight',
			),
			'progress'  => array(
				'title' => __( 'Progress', 'estasoft-migrate' ),
				'menu'  => '',
				'slug'  => self::SLUG . '-progress',
			),
			'sanitizer' => array(
				'title' => __( 'Sanitizer', 'estasoft-migrate' ),
				'menu'  => __( 'Sanitizer', 'estasoft-migrate' ),
				'slug'  => self::SLUG . '-sanitizer',
			),
			'replace'   => array(
				'title' => __( 'Search & replace', 'estasoft-migrate' ),
				'menu'  => __( 'Search & replace', 'estasoft-migrate' ),
				'slug'  => self::SLUG . '-search-replace',
			),
			'packages'  => array(
				'title' => __( 'Packages & snapshots', 'estasoft-migrate' ),
				'menu'  => __( 'Packages', 'estasoft-migrate' ),
				'slug'  => self::SLUG . '-packages',
			),
			'logs'      => array(
				'title' => __( 'Logs', 'estasoft-migrate' ),
				'menu'  => __( 'Logs', 'estasoft-migrate' ),
				'slug'  => self::SLUG . '-logs',
			),
			'settings'  => array(
				'title' => __( 'Settings', 'estasoft-migrate' ),
				'menu'  => __( 'Settings', 'estasoft-migrate' ),
				'slug'  => self::SLUG . '-settings',
			),
		);
	}

	/**
	 * Screen id for a page slug.
	 *
	 * @param string $slug Page slug from the query string.
	 * @return string Screen id, or an empty string when the page is not ours.
	 */
	public static function screen_for_slug( $slug ) {
		foreach ( self::screens() as $id => $screen ) {
			if ( $screen['slug'] === $slug ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Screen that is open now, if it is ours.
	 *
	 * @return string
	 */
	private function current_screen_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return self::screen_for_slug( $page );
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public function menu() {
		$cap     = Router::capability();
		$screens = self::screens();
		$first   = $screens['overview'];

		add_menu_page(
			$first['title'] . ' · ' . __( 'Estasoft Migrate', 'estasoft-migrate' ),
			__( 'Estasoft Migrate', 'estasoft-migrate' ),
			$cap,
			self::SLUG,
			array( $this, 'render' ),
			self::icon(),
			76
		);
		foreach ( $screens as $screen ) {
			add_submenu_page(
				self::SLUG,
				$screen['title'] . ' · ' . __( 'Estasoft Migrate', 'estasoft-migrate' ),
				'' === $screen['menu'] ? $screen['title'] : $screen['menu'],
				$cap,
				$screen['slug'],
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Pre-flight and Progress belong to the flow, not to the menu, so they are hidden there
	 * unless one of them is open. They stay registered, so WordPress keeps the menu open on them.
	 *
	 * @return void
	 */
	public function menu_style() {
		$hide = array();
		foreach ( self::screens() as $screen ) {
			if ( '' === $screen['menu'] ) {
				$hide[] = '#adminmenu .wp-submenu li:not(.current) > a[href$="page=' . $screen['slug'] . '"]';
			}
		}
		if ( $hide ) {
			// Selectors are built from fixed slugs above.
			echo '<style id="estm-menu">' . implode( ',', $hide ) . '{display:none}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Menu icon: the Estasoft "E". A plain black shape, so WordPress can color it like its own icons.
	 *
	 * @return string Data URI.
	 */
	private static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" transform="translate(2.675 2) scale(.015091) translate(-277.1 -232.25)" d="M277.1 232.25H1248.1V412.5H457.2V712.05A335 335 0 0 1 613.7 673.25H1181.4V851.75H613.7A156.5 156.5 0 0 0 457.2 1008.25V1112.25H1248.1V1292.5H277.1Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Mark the body of our pages, so the style sheet can tune the WordPress chrome around them.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		return '' !== $this->current_screen_id() ? $classes . ' estm-admin' : $classes;
	}

	/**
	 * Style sheet and scripts, on our own pages only.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		unset( $hook );
		$screen = $this->current_screen_id();
		if ( '' === $screen ) {
			return;
		}
		$ver  = ESTASOFT_MIGRATE_VERSION;
		$base = ESTASOFT_MIGRATE_URL . 'assets/';

		wp_enqueue_style( 'estasoft-migrate', $base . 'css/admin.css', array(), $ver );
		if ( is_readable( ESTASOFT_MIGRATE_DIR . 'assets/css/screens/' . $screen . '.css' ) ) {
			wp_enqueue_style( 'estasoft-migrate-screen', $base . 'css/screens/' . $screen . '.css', array( 'estasoft-migrate' ), $ver );
		}
		wp_enqueue_script( 'estasoft-migrate', $base . 'js/core.js', array( 'wp-i18n' ), $ver, true );
		wp_enqueue_script( 'estasoft-migrate-screen', $base . 'js/screens/' . $screen . '.js', array( 'estasoft-migrate' ), $ver, true );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'estasoft-migrate', 'estasoft-migrate', ESTASOFT_MIGRATE_DIR . 'languages' );
			wp_set_script_translations( 'estasoft-migrate-screen', 'estasoft-migrate', ESTASOFT_MIGRATE_DIR . 'languages' );
		}
		wp_add_inline_script( 'estasoft-migrate', 'window.ESTM = ' . wp_json_encode( $this->boot_data( $screen ) ) . ';', 'before' );
	}

	/**
	 * What the scripts need to know before they start.
	 *
	 * @param string $screen Screen id.
	 * @return array
	 */
	private function boot_data( $screen ) {
		$pages = array();
		foreach ( self::screens() as $id => $s ) {
			$pages[ $id ] = admin_url( 'admin.php?page=' . $s['slug'] );
		}
		$download = wp_create_nonce( 'estm_download' );
		return array(
			'screenId'   => $screen,
			'version'    => ESTASOFT_MIGRATE_VERSION,
			'rest'       => esc_url_raw( rest_url( Router::NAMESPACE_ . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'pages'      => $pages,
			'downloads'  => array(
				'package' => admin_url( 'admin-post.php?action=estm_download_package&_wpnonce=' . $download . '&package=' ),
				'log'     => admin_url( 'admin-post.php?action=estm_download_log&_wpnonce=' . $download . '&job_id=' ),
			),
			'site'       => array(
				'url'       => home_url(),
				'host'      => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'multisite' => is_multisite(),
			),
			'rtl'        => is_rtl(),
			'timezone'   => (string) wp_timezone_string(),
			'dateFormat' => (string) get_option( 'date_format' ),
			'timeFormat' => (string) get_option( 'time_format' ),
			'links'      => self::links(),
		);
	}

	/**
	 * Help links shown in the page header and the footer card.
	 *
	 * @return array{docs:string,support:string,source:string}
	 */
	public static function links() {
		/**
		 * Links to documentation, support and source code.
		 *
		 * @param array $links docs, support, source (an empty string hides the link).
		 */
		return (array) apply_filters(
			'estasoft_migrate_links',
			array(
				'docs'    => 'https://estasoft.com/',
				'support' => 'https://wordpress.org/support/plugin/estasoft-migrate/',
				'source'  => '',
			)
		);
	}

	/**
	 * Page shell: the header, an empty area the screen script fills, and a message for visitors without JavaScript.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Router::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to use Estasoft Migrate.', 'estasoft-migrate' ), 403 );
		}
		$id      = $this->current_screen_id();
		$screens = self::screens();
		$screen  = $screens[ '' === $id ? 'overview' : $id ];
		$links   = self::links();
		include ESTASOFT_MIGRATE_DIR . 'views/app.php';
	}

	/**
	 * Link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$screens = self::screens();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . $screens['settings']['slug'] ) ) . '">' . esc_html__( 'Settings', 'estasoft-migrate' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open', 'estasoft-migrate' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Footer line of wp-admin on our pages.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function footer_text( $text ) {
		return '' !== $this->current_screen_id() ? '' : $text;
	}
}
