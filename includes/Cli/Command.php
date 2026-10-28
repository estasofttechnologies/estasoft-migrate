<?php
/**
 * WP-CLI commands. They do what the screens do, through the same Api class.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Cli;

use Estasoft\Migrate\Engine\Job;
use Estasoft\Migrate\Plugin;
use Estasoft\Migrate\Rest\ApiException;
use Estasoft\Migrate\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Move, copy and clean a WordPress database from the command line.
 *
 * Jobs run in the foreground without time limits and can be stopped with Ctrl+C and continued later
 * with "wp estasoft-migrate resume <id>". Package passwords and connection keys can be given with
 * --password-file / --key-file or the ESTM_PASSWORD / ESTM_REMOTE_KEY environment variables, which keeps
 * them out of the process list.
 */
class Command {

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
	 * Create a package (.estm) with the database of this site.
	 *
	 * ## OPTIONS
	 *
	 * [--label=<label>]
	 * : Short name, used in the file name.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated tables to include. Default: all tables of this site.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated tables to leave out.
	 *
	 * [--search=<text>]
	 * : Change this text in the package (serialization-safe). Use with --replace.
	 *
	 * [--replace=<text>]
	 * : Text that takes its place.
	 *
	 * [--password=<password>]
	 * : Encrypt the package with a password. Prefer --password-file or ESTM_PASSWORD.
	 *
	 * [--password-file=<file>]
	 * : Read the password from a file.
	 *
	 * [--site-key]
	 * : Encrypt with this site's own key instead (only this site can open it).
	 *
	 * [--compress]
	 * : Compress the package. On by default; turn it off with --no-compress.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-migrate export --label=before-redesign --password-file=/root/.estm-pass
	 *     wp estasoft-migrate export --search=https://example.com --replace=https://staging.example.com
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function export( $args, $assoc ) {
		unset( $args );
		$in = array_merge( $this->table_input( $assoc ), $this->protection_input( $assoc ), $this->replace_input( $assoc ) );
		if ( isset( $assoc['label'] ) ) {
			$in['label'] = (string) $assoc['label'];
		}
		if ( self::turned_off( $assoc, 'compress' ) ) {
			$in['compress'] = false;
		}
		$view = $this->run_job( 'export', $in );
		$this->say_result( $view );
	}

	/**
	 * Create a sanitized copy: fake personal data, no passwords or keys, safe to share.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : staging, dev or gdpr.
	 * ---
	 * default: staging
	 * options:
	 *   - staging
	 *   - dev
	 *   - gdpr
	 * ---
	 *
	 * [--saved]
	 * : Start from the settings saved on the Sanitizer screen instead of the profile defaults.
	 *
	 * [--keep-users=<users>]
	 * : Comma-separated logins or emails that stay unchanged.
	 *
	 * [--preview]
	 * : Show a few before/after examples and change nothing.
	 *
	 * [--label=<label>]
	 * : Short name for the file.
	 *
	 * [--password=<password>] [--password-file=<file>] [--site-key]
	 * : Package protection, as for export.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-migrate sanitize --preview
	 *     wp estasoft-migrate sanitize --profile=gdpr --password-file=/root/.estm-pass
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function sanitize( $args, $assoc ) {
		unset( $args );
		$overrides = array();
		$profile   = (string) ( $assoc['profile'] ?? 'staging' );
		if ( isset( $assoc['saved'] ) ) {
			$saved     = $this->plugin->settings()->sanitizer();
			$input     = \Estasoft\Migrate\Sanitizer\SavedConfig::to_job_input( $saved );
			$profile   = isset( $assoc['profile'] ) ? $profile : $input['profile'];
			$overrides = $input['overrides'];
		}
		if ( isset( $assoc['keep-users'] ) ) {
			$overrides['keep_users'] = Util::to_list( $assoc['keep-users'] );
		}

		if ( isset( $assoc['preview'] ) ) {
			try {
				$preview = $this->plugin->api( false )->sanitizer_preview(
					array(
						'profile'   => $profile,
						'overrides' => $overrides,
					)
				);
			} catch ( ApiException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			foreach ( $preview['tables'] as $t ) {
				\WP_CLI::log( '' );
				\WP_CLI::log( \WP_CLI::colorize( '%G' . $t['table'] . '%n' . ( $t['dropped'] ? sprintf( ' (%d rows would be left out)', $t['dropped'] ) : '' ) ) );
				foreach ( $t['examples'] as $ex ) {
					foreach ( (array) $ex['changed'] as $col ) {
						\WP_CLI::log( '  ' . $col );
						\WP_CLI::log( '    - ' . self::short( $ex['before'][ $col ] ?? '' ) );
						\WP_CLI::log( '    + ' . self::short( $ex['after'][ $col ] ?? '' ) );
					}
				}
			}
			\WP_CLI::success( __( 'Nothing was changed. This is only a preview.', 'estasoft-migrate' ) );
			return;
		}

		$in = array_merge(
			$this->protection_input( $assoc ),
			array(
				'sanitize' => array(
					'profile'   => $profile,
					'overrides' => $overrides,
				),
			)
		);
		if ( isset( $assoc['label'] ) ) {
			$in['label'] = (string) $assoc['label'];
		}
		$this->say_result( $this->run_job( 'export', $in ) );
	}

	/**
	 * Import a package into this site. The current site is saved as a rollback snapshot first.
	 *
	 * ## OPTIONS
	 *
	 * <package>
	 * : Package id (see "packages") or the path of a .estm file.
	 *
	 * [--password=<password>] [--password-file=<file>]
	 * : Password of the package, if it has one.
	 *
	 * [--snapshot]
	 * : Save a rollback snapshot first. On by default; --no-snapshot skips it. Not recommended.
	 *
	 * [--rewrite]
	 * : Change the addresses in the package to this site's address. On by default; --no-rewrite keeps them as they are.
	 *
	 * [--search=<text>] [--replace=<text>]
	 * : Also change text while importing.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-migrate import 20261025-1406-export-ab12cd --yes
	 *     wp estasoft-migrate import ./site.estm --password-file=/root/.estm-pass
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function import( $args, $assoc ) {
		$id = $this->package_arg( (string) $args[0] );
		\WP_CLI::confirm( __( 'This replaces the database of this site with the contents of the package. Continue?', 'estasoft-migrate' ), $assoc );
		$in = array_merge( array( 'package_id' => $id ), $this->replace_input( $assoc ) );
		$pw = $this->password( $assoc );
		if ( '' !== $pw ) {
			$in['password'] = $pw;
		}
		if ( self::turned_off( $assoc, 'snapshot' ) ) {
			$in['snapshot'] = false;
		}
		if ( self::turned_off( $assoc, 'rewrite' ) ) {
			$in['rewrite'] = false;
		}
		$this->say_result( $this->run_job( 'import', $in ) );
	}

	/**
	 * Go back to a rollback snapshot.
	 *
	 * ## OPTIONS
	 *
	 * <snapshot>
	 * : Snapshot id (see "snapshots").
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function restore( $args, $assoc ) {
		$id   = (string) $args[0];
		$meta = $this->plugin->storage()->read_meta( $id );
		if ( ! is_array( $meta ) || 'snapshot' !== ( $meta['type'] ?? '' ) ) {
			\WP_CLI::error( __( 'That is not a rollback snapshot. Run “wp estasoft-migrate snapshots” for the list.', 'estasoft-migrate' ) );
		}
		\WP_CLI::confirm( __( 'This puts the database back to the state in the snapshot. Changes made since then are lost. Continue?', 'estasoft-migrate' ), $assoc );
		$this->say_result(
			$this->run_job(
				'import',
				array(
					'package_id' => $id,
					'snapshot'   => false,
				)
			)
		);
	}

	/**
	 * Search and replace in the whole database, safe for serialized and JSON data. A dry run unless --apply is given.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : Text to find.
	 *
	 * <replace>
	 * : Text to put in its place.
	 *
	 * [--apply]
	 * : Write the changes. Without it nothing is written and the result is only a report.
	 *
	 * [--regex]
	 * : Treat <search> as a regular expression (PCRE, without delimiters).
	 *
	 * [--ignore-case]
	 * : Ignore upper and lower case.
	 *
	 * [--tables=<tables>] [--exclude-tables=<tables>]
	 * : Limit to, or leave out, comma-separated tables.
	 *
	 * [--skip-columns=<columns>]
	 * : Comma-separated columns to leave alone, as table.column.
	 *
	 * [--include-guid]
	 * : Also change the guid column of posts (WordPress advises against it).
	 *
	 * [--snapshot]
	 * : With --apply: save a rollback snapshot first. On by default; --no-snapshot skips it.
	 *
	 * [--yes]
	 * : With --apply: do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-migrate search-replace https://old.example.com https://example.com
	 *     wp estasoft-migrate search-replace https://old.example.com https://example.com --apply --yes
	 *
	 * @subcommand search-replace
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function search_replace( $args, $assoc ) {
		$apply = isset( $assoc['apply'] );
		if ( $apply ) {
			\WP_CLI::confirm( __( 'This changes the database. A rollback snapshot is saved first. Continue?', 'estasoft-migrate' ), $assoc );
		}
		$in = array_merge(
			$this->table_input( $assoc ),
			array(
				'pairs'          => array( array( (string) $args[0], (string) $args[1] ) ),
				'regex'          => isset( $assoc['regex'] ),
				'case_sensitive' => ! isset( $assoc['ignore-case'] ),
				'skip_guid'      => ! isset( $assoc['include-guid'] ),
				'dry_run'        => ! $apply,
			)
		);
		if ( isset( $assoc['skip-columns'] ) ) {
			$in['skip_columns'] = Util::to_list( $assoc['skip-columns'] );
		}
		if ( self::turned_off( $assoc, 'snapshot' ) ) {
			$in['snapshot'] = false;
		}
		$view = $this->run_job( 'search_replace', $in );
		$this->say_result( $view );
		if ( ! $apply ) {
			\WP_CLI::log( __( 'This was a dry run. Add --apply to write the changes.', 'estasoft-migrate' ) );
		}
	}

	/**
	 * Check this site (and optionally another site or a package) before a job.
	 *
	 * ## OPTIONS
	 *
	 * [<mode>]
	 * : export, sanitize, import, search_replace, push or pull.
	 * ---
	 * default: export
	 * ---
	 *
	 * [--package=<id>]
	 * : For import: the package to check.
	 *
	 * [--url=<url>] [--key=<key>] [--key-file=<file>]
	 * : For push and pull: the other site.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function preflight( $args, $assoc ) {
		$in = array( 'mode' => (string) ( $args[0] ?? 'export' ) );
		if ( isset( $assoc['package'] ) ) {
			$in['package_id'] = $this->package_arg( (string) $assoc['package'] );
		}
		if ( isset( $assoc['url'] ) ) {
			$in['remote_url'] = (string) $assoc['url'];
			$in['remote_key'] = $this->remote_key( $assoc );
		}
		try {
			$r = $this->plugin->api( false )->preflight( $in );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		foreach ( $r['checks'] as $c ) {
			$tag = 'block' === $c['status'] ? '%RBLOCKED%n' : ( 'warn' === $c['status'] ? '%YWARNING%n' : '%GPASSED %n' );
			\WP_CLI::log( \WP_CLI::colorize( $tag ) . '  ' . $c['title'] . ( '' !== $c['detail'] && 'pass' !== $c['status'] ? ' – ' . $c['detail'] : '' ) );
		}
		\WP_CLI::log( '' );
		if ( 'blocked' === $r['status'] ) {
			\WP_CLI::error( __( 'Blocked: fix the items marked BLOCKED first.', 'estasoft-migrate' ) );
		}
		\WP_CLI::success( 'warnings' === $r['status'] ? __( 'Ready, with warnings.', 'estasoft-migrate' ) : __( 'Ready.', 'estasoft-migrate' ) );
	}

	/**
	 * Push this site's database to another site that has Estasoft Migrate (accepting incoming migrations).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Address of the other site.
	 *
	 * [--key=<key>] [--key-file=<file>]
	 * : Connection key shown in the other site's Settings. Or set ESTM_REMOTE_KEY.
	 *
	 * [--tables=<tables>] [--exclude-tables=<tables>]
	 * : Limit the tables.
	 *
	 * [--snapshot]
	 * : Save a rollback snapshot on the other site first. On by default; --no-snapshot skips it. Not recommended.
	 *
	 * [--rewrite]
	 * : Change this site's address to the other site's address. On by default; --no-rewrite keeps the addresses.
	 *
	 * [--sanitize=<profile>]
	 * : Mask personal data while sending: staging, dev or gdpr.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function push( $args, $assoc ) {
		\WP_CLI::confirm( sprintf( /* translators: %s: address */ __( 'This replaces the database of %s with this site\'s database. Continue?', 'estasoft-migrate' ), $args[0] ), $assoc );
		$this->say_result( $this->run_job( 'push', $this->remote_input( $args[0], $assoc, true ) ) );
	}

	/**
	 * Pull the database of another site into this site. A rollback snapshot of this site is saved first.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Address of the other site.
	 *
	 * [--key=<key>] [--key-file=<file>]
	 * : Connection key of the other site (its Settings screen). Or set ESTM_REMOTE_KEY.
	 *
	 * [--snapshot] [--rewrite]
	 * : As for push; turn either off with --no-snapshot or --no-rewrite.
	 *
	 * [--sanitize=<profile>]
	 * : The other site masks personal data before anything leaves it.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function pull( $args, $assoc ) {
		\WP_CLI::confirm( __( 'This replaces the database of this site with the other site\'s database. Continue?', 'estasoft-migrate' ), $assoc );
		$this->say_result( $this->run_job( 'pull', $this->remote_input( $args[0], $assoc, false ) ) );
	}

	/**
	 * Check a package: every block, without a password.
	 *
	 * ## OPTIONS
	 *
	 * <package>
	 * : Package id or file path.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function verify( $args, $assoc ) {
		unset( $assoc );
		$id = $this->package_arg( (string) $args[0] );
		$this->run_job( 'verify', array( 'package_id' => $id ) );
		\WP_CLI::success( __( 'The package is complete and undamaged.', 'estasoft-migrate' ) );
	}

	/**
	 * List jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : queued, running, paused, failed, completed or cancelled.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function jobs( $args, $assoc ) {
		unset( $args );
		$q    = array( 'limit' => 100 );
		$list = $this->plugin->api( false )->jobs( $q );
		$rows = array();
		foreach ( $list['jobs'] as $j ) {
			if ( isset( $assoc['status'] ) && $j['status'] !== $assoc['status'] ) {
				continue;
			}
			$rows[] = array(
				'id'       => $j['id'],
				'type'     => $j['kind'] ? $j['kind'] : $j['type'],
				'status'   => $j['status'],
				'progress' => $j['progress'] . '%',
				'title'    => $j['title'],
				'created'  => $j['created'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'id', 'type', 'status', 'progress', 'title', 'created' ) );
	}

	/**
	 * Show one job and its latest log lines.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function job( $args, $assoc ) {
		unset( $assoc );
		$api = $this->plugin->api( false );
		try {
			$v = $api->job( (int) $args[0] );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::log( sprintf( '#%d  %s  [%s, %s%%]', $v['id'], $v['title'], $v['status'], $v['progress'] ) );
		if ( '' !== $v['message'] ) {
			\WP_CLI::log( $v['message'] );
		}
		if ( ! empty( $v['error'] ) ) {
			\WP_CLI::warning( $v['error']['message'] );
		}
		foreach ( array_slice(
			$api->logs(
				array(
					'job_id' => $v['id'],
					'order'  => 'desc',
					'limit'  => 15,
				)
			)['lines'],
			0,
			15
		) as $l ) {
			\WP_CLI::log( sprintf( '%s  %-7s  %s', $l['created_gmt'], strtoupper( $l['level'] ), $l['message'] ) );
		}
		if ( ! empty( $v['result'] ) ) {
			$this->say_result( $v );
		}
	}

	/**
	 * Continue a paused, interrupted or failed job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function resume( $args, $assoc ) {
		unset( $assoc );
		$api = $this->plugin->api( false );
		try {
			$view = $api->job( (int) $args[0] );
			if ( Job::FAILED === $view['status'] ) {
				$api->job_action( $view['id'], 'retry' );
			} elseif ( Job::PAUSED === $view['status'] ) {
				$api->job_action( $view['id'], 'resume' );
			}
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$this->say_result( $this->follow( (int) $args[0] ) );
	}

	/**
	 * Cancel a job that has not finished. Temporary tables are removed.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function cancel( $args, $assoc ) {
		unset( $assoc );
		try {
			$this->plugin->api( false )->job_action( (int) $args[0], 'cancel' );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( __( 'The job was cancelled.', 'estasoft-migrate' ) );
	}

	/**
	 * Show log lines.
	 *
	 * ## OPTIONS
	 *
	 * [<job-id>]
	 * : Only lines of this job.
	 *
	 * [--level=<level>]
	 * : This level and worse: debug, info, warning, error.
	 *
	 * [--search=<text>]
	 * : Only lines that contain the text.
	 *
	 * [--limit=<n>]
	 * : Most lines to show.
	 * ---
	 * default: 100
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function log( $args, $assoc ) {
		$q = array(
			'order'  => 'asc',
			'limit'  => (int) ( $assoc['limit'] ?? 100 ),
			'level'  => (string) ( $assoc['level'] ?? '' ),
			'search' => (string) ( $assoc['search'] ?? '' ),
		);
		if ( isset( $args[0] ) ) {
			$q['job_id'] = (int) $args[0];
		}
		foreach ( $this->plugin->api( false )->logs( $q )['lines'] as $l ) {
			\WP_CLI::log( sprintf( '%s  %-7s  job %d  %s', $l['created_gmt'], strtoupper( $l['level'] ), $l['job_id'], $l['message'] ) );
		}
	}

	/**
	 * List packages (exports, received packages, sanitized copies).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function packages( $args, $assoc ) {
		unset( $args );
		$this->list_packages( null, $assoc );
	}

	/**
	 * List rollback snapshots.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function snapshots( $args, $assoc ) {
		unset( $args );
		$this->list_packages( 'snapshot', $assoc );
	}

	/**
	 * Delete a package or snapshot.
	 *
	 * ## OPTIONS
	 *
	 * <package>
	 * : Package id.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function delete( $args, $assoc ) {
		\WP_CLI::confirm( __( 'Delete this package? It cannot be brought back.', 'estasoft-migrate' ), $assoc );
		try {
			$this->plugin->api( false )->delete_package( (string) $args[0] );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( __( 'Deleted.', 'estasoft-migrate' ) );
	}

	/**
	 * Show the connection key of this site, or replace it.
	 *
	 * ## OPTIONS
	 *
	 * [--regenerate]
	 * : Make a new key. The old one keeps working for a few minutes.
	 *
	 * [--stop-old]
	 * : With --regenerate, stop the old key at once instead of after a few minutes.
	 *
	 * [--revoke-old]
	 * : Stop the previous key now, if it still works.
	 *
	 * [--accept-incoming=<on|off>]
	 * : Allow or refuse migrations sent to this site by other sites.
	 *
	 * [--accept-hours=<hours>]
	 * : How long incoming migrations stay on without a migration: 1, 24, 168 (7 days) or 0 (until switched off). Starts again from now.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function key( $args, $assoc ) {
		unset( $args );
		$api = $this->plugin->api( false );

		$change = array();
		if ( isset( $assoc['accept-incoming'] ) ) {
			$change['accept_incoming'] = in_array( strtolower( (string) $assoc['accept-incoming'] ), array( 'on', '1', 'true', 'yes' ), true );
		}
		if ( isset( $assoc['accept-hours'] ) ) {
			$change['accept_hours'] = (int) $assoc['accept-hours'];
		}
		if ( $change ) {
			$api->save_settings( $change );
		}

		if ( isset( $assoc['regenerate'] ) ) {
			$s = $api->regenerate_key( ! isset( $assoc['stop-old'] ) );
		} elseif ( isset( $assoc['revoke-old'] ) ) {
			$s = $api->revoke_previous_key();
		} else {
			$s = $api->settings();
		}

		\WP_CLI::log( $s['connection']['key'] );
		\WP_CLI::log( sprintf( /* translators: %s: address */ __( 'Address: %s', 'estasoft-migrate' ), $s['connection']['endpoint'] ) );
		if ( empty( $s['values']['accept_incoming'] ) ) {
			\WP_CLI::warning( __( 'This site does not accept incoming migrations yet. Turn it on in Settings, or run: wp estasoft-migrate key --accept-incoming=on', 'estasoft-migrate' ) );
		} elseif ( (int) $s['connection']['accept_until'] > 0 ) {
			\WP_CLI::log(
				sprintf(
					/* translators: %s: date and time */
					__( 'Incoming migrations switch themselves off on %s unless a migration arrives before then.', 'estasoft-migrate' ),
					wp_date( 'Y-m-d H:i', (int) $s['connection']['accept_until'] )
				)
			);
		}
	}

	/* ------------------------------------------------------------------ Helpers */

	/**
	 * A value on one line, cut to a readable length.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function short( $value ) {
		$text = trim( preg_replace( '/\s+/', ' ', is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ) );
		return strlen( $text ) > 110 ? substr( $text, 0, 107 ) . '…' : $text;
	}

	/**
	 * Print a package list.
	 *
	 * @param string|null $type  Type filter.
	 * @param array       $assoc Named arguments.
	 * @return void
	 */
	private function list_packages( $type, array $assoc ) {
		$rows = array();
		foreach ( $this->plugin->api( false )->packages( $type )['packages'] as $p ) {
			$rows[] = array(
				'id'      => $p['id'],
				'type'    => $p['type'],
				'size'    => Util::format_bytes( $p['size'] ),
				'tables'  => $p['tables'],
				'protect' => $p['protection'],
				'created' => $p['created'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'id', 'type', 'size', 'tables', 'protect', 'created' ) );
	}

	/**
	 * Create a job and follow it to its end.
	 *
	 * @param string $type Job type.
	 * @param array  $in   Input.
	 * @return array Job view.
	 */
	private function run_job( $type, array $in ) {
		try {
			$view = $this->plugin->api( false )->create_job( $type, $in );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::log( sprintf( '#%d  %s', $view['id'], $view['title'] ) );
		return $this->follow( $view['id'] );
	}

	/**
	 * Was an on-by-default option switched off? WP-CLI turns "--no-snapshot" into snapshot=false.
	 *
	 * @param array  $assoc Named arguments.
	 * @param string $name  Option name without the "no-" prefix.
	 * @return bool
	 */
	private static function turned_off( array $assoc, $name ) {
		return ( isset( $assoc[ $name ] ) && ! filter_var( $assoc[ $name ], FILTER_VALIDATE_BOOLEAN ) ) || isset( $assoc[ 'no-' . $name ] );
	}

	/**
	 * Run a job until it ends, showing progress, and stop with an error if it did not complete.
	 *
	 * @param int $id Job id.
	 * @return array Job view of a completed job.
	 */
	private function follow( $id ) {
		$last = -1;
		$job  = $this->plugin->runner()->run_to_end(
			$id,
			function ( Job $job ) use ( &$last ) {
				$step = (int) floor( $job->progress / 5 ) * 5;
				if ( $step !== $last ) {
					$last = $step;
					\WP_CLI::log( sprintf( '%3d%%  %s', (int) $job->progress, $job->message ) );
				}
			}
		);
		$view = $this->plugin->api( false )->job( $id );
		if ( ! $job || Job::COMPLETED !== $job->status ) {
			$status = $job ? $job->status : 'missing';
			if ( Job::PAUSED === $status ) {
				\WP_CLI::error( sprintf( /* translators: %d: job id */ __( 'The job is paused. Continue with: wp estasoft-migrate resume %d', 'estasoft-migrate' ), $id ) );
			}
			$why = ! empty( $view['error']['message'] ) ? $view['error']['message'] : ( $job ? $job->message : '' );
			\WP_CLI::error( sprintf( /* translators: 1: job id, 2: reason */ __( 'The job did not finish (#%1$d). %2$s Fix the cause, then run: wp estasoft-migrate resume %1$d', 'estasoft-migrate' ), $id, $why ) );
		}
		return $view;
	}

	/**
	 * Print what a completed job achieved.
	 *
	 * @param array $view Job view.
	 * @return void
	 */
	private function say_result( array $view ) {
		$r = isset( $view['result'] ) ? (array) $view['result'] : array();
		if ( ! $r ) {
			return;
		}
		switch ( $r['type'] ) {
			case 'export':
				$path = $this->plugin->storage()->package_path( $r['package'] );
				\WP_CLI::success( sprintf( /* translators: 1: tables, 2: rows, 3: size */ __( 'Package created: %1$d tables, %2$s rows, %3$s.', 'estasoft-migrate' ), $r['tables'], Util::number( $r['rows'] ), Util::format_bytes( $r['size'] ) ) );
				\WP_CLI::log( $r['package'] . '  →  ' . $path );
				if ( 'none' !== $r['protection'] ) {
					\WP_CLI::log( 'password' === $r['protection'] ? __( 'Protected with the password you gave.', 'estasoft-migrate' ) : __( 'Protected with this site\'s key.', 'estasoft-migrate' ) );
				}
				break;
			case 'import':
			case 'pull':
				$text = ! empty( $r['restore'] )
					/* translators: 1: tables, 2: rows */
					? __( 'Restored %1$d tables, %2$s rows.', 'estasoft-migrate' )
					/* translators: 1: tables, 2: rows */
					: __( 'Imported %1$d tables, %2$s rows.', 'estasoft-migrate' );
				\WP_CLI::success( sprintf( $text, $r['tables'], Util::number( $r['rows'] ) ) );
				if ( ! empty( $r['snapshot'] ) ) {
					\WP_CLI::log( sprintf( /* translators: %s: snapshot id */ __( 'Rollback snapshot: %s (restore with: wp estasoft-migrate restore <id>)', 'estasoft-migrate' ), $r['snapshot'] ) );
				}
				break;
			case 'push':
				\WP_CLI::success( sprintf( /* translators: 1: address, 2: tables, 3: rows */ __( 'Pushed to %1$s: %2$d tables, %3$s rows.', 'estasoft-migrate' ), $r['remote'], $r['tables'], Util::number( $r['rows'] ) ) );
				break;
			case 'search_replace':
				$t = (array) $r['totals'];
				\WP_CLI::success( sprintf( /* translators: 1: matches, 2: rows, 3: rows scanned */ __( '%1$s matches in %2$s rows (of %3$s scanned).', 'estasoft-migrate' ), Util::number( $t['matches'] ?? 0 ), Util::number( $t['changed'] ?? 0 ), Util::number( $t['scanned'] ?? 0 ) ) );
				$rows = array();
				foreach ( (array) $r['tables'] as $tb ) {
					if ( ! empty( $tb['matches'] ) ) {
						$rows[] = array(
							'table'   => $tb['name'],
							'matches' => $tb['matches'],
							'rows'    => $tb['rows'],
						);
					}
				}
				if ( $rows ) {
					\WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'matches', 'rows' ) );
				}
				break;
		}
		foreach ( (array) ( $r['notes'] ?? array() ) as $note ) {
			\WP_CLI::log( '• ' . ( is_array( $note ) ? ( $note['text'] ?? wp_json_encode( $note ) ) : $note ) );
		}
	}

	/**
	 * Turn a package id or a file path into a package id in storage.
	 *
	 * @param string $arg Id or path.
	 * @return string
	 */
	private function package_arg( $arg ) {
		if ( ! is_file( $arg ) ) {
			return $arg;
		}
		$api = $this->plugin->api( false );
		$fh  = fopen( $arg, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			\WP_CLI::error( __( 'The file cannot be read.', 'estasoft-migrate' ) );
		}
		$size = (int) filesize( $arg );
		try {
			$up     = $api->upload_init( basename( $arg ), $size );
			$offset = 0;
			while ( ! feof( $fh ) ) {
				$piece = fread( $fh, (int) $up['chunk'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $piece || '' === $piece ) {
					break;
				}
				$api->upload_chunk( $up['package_id'], $offset, $piece );
				$offset += strlen( $piece );
			}
			$done = $api->upload_done( $up['package_id'], $size );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return (string) $done['package']['id'];
	}

	/**
	 * Table choice.
	 *
	 * @param array $assoc Named arguments.
	 * @return array
	 */
	private function table_input( array $assoc ) {
		$out = array();
		if ( isset( $assoc['tables'] ) ) {
			$out['tables'] = Util::to_list( $assoc['tables'] );
		}
		if ( isset( $assoc['exclude-tables'] ) ) {
			$out['exclude_tables'] = Util::to_list( $assoc['exclude-tables'] );
		}
		return $out;
	}

	/**
	 * One replacement pair applied while exporting or importing.
	 *
	 * @param array $assoc Named arguments.
	 * @return array
	 */
	private function replace_input( array $assoc ) {
		if ( isset( $assoc['search'], $assoc['replace'] ) && '' !== (string) $assoc['search'] ) {
			return array( 'replace' => array( 'pairs' => array( array( (string) $assoc['search'], (string) $assoc['replace'] ) ) ) );
		}
		return array();
	}

	/**
	 * Password or site-key protection.
	 *
	 * @param array $assoc Named arguments.
	 * @return array
	 */
	private function protection_input( array $assoc ) {
		$pw = $this->password( $assoc );
		if ( '' !== $pw ) {
			return array( 'password' => $pw );
		}
		return isset( $assoc['site-key'] ) ? array( 'protect_with_site_key' => true ) : array();
	}

	/**
	 * Password from --password, --password-file or ESTM_PASSWORD.
	 *
	 * @param array $assoc Named arguments.
	 * @return string
	 */
	private function password( array $assoc ) {
		return $this->secret_from( $assoc, 'password', 'password-file', 'ESTM_PASSWORD' );
	}

	/**
	 * Connection key from --key, --key-file or ESTM_REMOTE_KEY.
	 *
	 * @param array $assoc Named arguments.
	 * @return string
	 */
	private function remote_key( array $assoc ) {
		return $this->secret_from( $assoc, 'key', 'key-file', 'ESTM_REMOTE_KEY' );
	}

	/**
	 * Read a secret from an option, a file or the environment.
	 *
	 * @param array  $assoc Named arguments.
	 * @param string $opt   Option name.
	 * @param string $file  File option name.
	 * @param string $env   Environment variable.
	 * @return string
	 */
	private function secret_from( array $assoc, $opt, $file, $env ) {
		if ( isset( $assoc[ $opt ] ) && '' !== (string) $assoc[ $opt ] ) {
			return (string) $assoc[ $opt ];
		}
		if ( isset( $assoc[ $file ] ) ) {
			if ( ! is_readable( (string) $assoc[ $file ] ) ) {
				\WP_CLI::error( sprintf( /* translators: %s: path */ __( 'Cannot read %s', 'estasoft-migrate' ), $assoc[ $file ] ) );
			}
			return trim( (string) file_get_contents( (string) $assoc[ $file ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		$value = getenv( $env );
		return false === $value ? '' : trim( (string) $value );
	}

	/**
	 * Input of a push or a pull.
	 *
	 * @param string $url    Other site.
	 * @param array  $assoc  Named arguments.
	 * @param bool   $is_push True for a push (tables can be chosen).
	 * @return array
	 */
	private function remote_input( $url, array $assoc, $is_push ) {
		$in = array(
			'remote_url' => (string) $url,
			'remote_key' => $this->remote_key( $assoc ),
		);
		if ( $is_push ) {
			$in = array_merge( $in, $this->table_input( $assoc ) );
		}
		if ( self::turned_off( $assoc, 'snapshot' ) ) {
			$in['snapshot'] = false;
		}
		if ( self::turned_off( $assoc, 'rewrite' ) ) {
			$in['rewrite'] = false;
		}
		if ( isset( $assoc['sanitize'] ) ) {
			$in['sanitize'] = array( 'profile' => (string) $assoc['sanitize'] );
		}
		return $in;
	}
}
