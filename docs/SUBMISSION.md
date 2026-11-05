# Submitting to WordPress.org

Everything the review team asks for is in the repository. What is left needs a person with a WordPress.org account.

## Before you upload

| Check | State |
| --- | --- |
| `readme.txt` `Contributors` | `estasoft`, the WordPress.org username of the account that owns the plugin. It must stay a real WordPress.org username; several can be listed, comma separated. |
| `Tested up to` | 7.1, the newest stable WordPress when this was written. Raise it only after testing on a newer release. |
| Minimum versions | WordPress 5.8, PHP 7.4. The CI runs the integration tests on exactly that combination (WordPress 5.8, PHP 7.4, MySQL 5.7) as well as the newest. |
| License | GPL v2 or later, stated in the plugin header, `readme.txt` and `license.txt`. Every file is original or MIT/GPL-compatible; there are no bundled libraries. |
| Plugin Check | `wp plugin check estasoft-migration-database-sanitizer --include-experimental` reports no errors and no warnings on the zip. Run it again after any change (install the Plugin Check plugin on a throw-away site). |
| Tests | `composer check` and the integration tests (`phpunit-integration.xml.dist`) pass on MySQL and MariaDB. |
| Review | Three independent reviews (engine, security, screens) were done and their findings fixed; what remains is listed in [REVIEW.md](REVIEW.md). |
| Zip | `bin/build-zip.sh` makes `dist/estasoft-migration-database-sanitizer-<version>.zip` with one top-level folder of the same name, the WordPress.org slug. |

## Slug and text domain

The slug WordPress.org assigned on 1 Nov 2026 is `estasoft-migration-database-sanitizer`, so the text domain (in the plugin header and in every translation call), the plugin folder inside the zip, the `.pot` file and the support link all use it. Plugin Check compares the text domain with the slug, and the automated scan of the first upload reported 479 errors and 1 warning that were all this mismatch while the text domain was still `estasoft-migrate`. Names that are not translation labels keep the short form and must not be changed: the main file `estasoft-migrate.php`, the `estasoft_migrate_*` options and hooks, the admin page slugs (`estasoft-migrate`, `estasoft-migrate-settings`, ...), the REST namespace `estasoft-migrate/v1`, the WP-CLI command `wp estasoft-migrate`, the script and style handles, the `estasoft-migrate-<random>` folder in uploads, and the strings that feed key and package hashes (changing those would invalidate saved keys and encrypted packages). If WordPress.org ever grants a different slug, replace the text domain only in the last argument of translation calls (`__()`, `_n()`, `esc_html__()` and so on, in PHP and JavaScript), the `Text Domain` header and `wp_set_script_translations()`, rename the `.pot`, regenerate it with the new `--slug` and `--domain`, and rebuild.

## What the review team will ask, and the answers

- **Does it call external servers?** No third-party services. The only request the plugin makes on its own is to the site's own uploads address, to test that the package folder cannot be downloaded from directly. Push and pull connect only to a WordPress site the person types in, signed with a key that only the two sites know. Nothing is sent to Estasoft. No tracking, no ads, no upsell, no license key.
- **Why does it use direct database queries?** It is a database migration tool. All table and column names pass through `Identifier::quote()`; values are escaped with `$wpdb->prepare()` or the engine's own typed quoting. Each query that Plugin Check could not prove safe carries a comment saying why.
- **Why `unserialize`?** Search and replace does not use it: `includes/Replace/Serialized.php` parses serialized values itself, so no object can be created and no code can run. `unserialize()` appears only where the sanitizer reads a stored list (secrets and the `active_plugins` option), and always with `allowed_classes` set to `false`.
- **Why `base64`?** To carry binary values inside the JSON of the signed site-to-site protocol. Nothing is hidden or executed.
- **Why files in uploads?** Packages and snapshots are the product of the plugin. They live in a folder with a random name, with `.htaccess`, `web.config` and `index.php` protection, and are served only through an authenticated admin request. The plugin tests the protection by requesting a throw-away file from that folder and tells the person when it can be fetched (on nginx the `.htaccess` file has no effect).
- **What does it do on the front end?** Nothing, except answer a signed request when "Accept incoming migrations" is switched on in Settings (off by default).
- **Does it print its own `<script>` or `<style>` tags?** No. Style sheets and scripts are enqueued on the plugin's own screens only (`wp_enqueue_style()`, `wp_enqueue_script()`). The two inline pieces use `wp_add_inline_script()` (start-up data for the screens) and `wp_add_inline_style()` (one rule that keeps Pre-flight and Progress out of the menu).
- **Does it show notices in wp-admin?** One, and only when the person has switched on "block outgoing email" for a staging copy: a warning on the Dashboard and the Plugins list, for administrators. It is not shown on other screens, carries no promotion and no upgrade text. If the server is too old for the plugin, administrators who can activate plugins see one error saying so. There are no dashboard widgets, admin-bar items or banners.
- **Which request data does it read?** `$_GET['estm_remote']` (cleaned with `sanitize_key()`; the request is authenticated by its signature, not by a nonce), five `HTTP_X_ESTM_*` headers and `REMOTE_ADDR` (all cleaned with `sanitize_text_field( wp_unslash() )`, and the address again after the `estasoft_migrate_remote_ip` filter), `$_SERVER['REQUEST_METHOD']` and `HTTP_RANGE`, and on its own screens `$_GET['page']`, `package`, `job_id` and `_wpnonce`. Everything else comes through REST routes with a capability check, a nonce and per-argument sanitizing.
- **What happens on uninstall?** Nothing is deleted unless "Delete all plugin data when the plugin is deleted" is switched on, because packages can be the only copy of someone's data. With it on, tables, options, transients, scheduled events and the storage folder are removed.

## Upload

1. Sign in at https://wordpress.org/plugins/developers/add/ with the account named in `Contributors`.
2. Upload `dist/estasoft-migration-database-sanitizer-<version>.zip`.
3. Answer the review email within 7 days. Reviews are done by volunteers; the first answer can take several weeks.
4. After approval you get an SVN repository. Put the plugin files in `trunk/`, a copy in `tags/<version>/`, and the contents of `.wordpress-org/` in `assets/` (not in `trunk/`). `bin/build-zip.sh` shows exactly which files belong in the plugin.

## Assets in `.wordpress-org/`

`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` and `screenshot-1.png` to `screenshot-7.png`. The screenshot order matches the numbered list under "Screenshots" in `readme.txt`; change both together.
