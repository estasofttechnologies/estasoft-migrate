# Submitting to WordPress.org

Everything the review team asks for is in the repository. What is left needs a person with a WordPress.org account.

## Before you upload

| Check | State |
| --- | --- |
| `readme.txt` `Contributors` | **You must change it.** It lists `estasofttechnologies`, which has to be a real WordPress.org username (the account that will own the plugin). Several usernames can be listed, comma separated. |
| `Tested up to` | 7.1, the newest stable WordPress when this was written. Raise it only after testing on a newer release. |
| Minimum versions | WordPress 5.8, PHP 7.4. The CI runs the integration tests on exactly that combination (WordPress 5.8, PHP 7.4, MySQL 5.7) as well as the newest. |
| License | GPL v2 or later, stated in the plugin header, `readme.txt` and `license.txt`. Every file is original or MIT/GPL-compatible; there are no bundled libraries. |
| Plugin Check | `wp plugin check estasoft-migrate --include-experimental` reports no errors and no warnings on the zip. Run it again after any change (install the Plugin Check plugin on a throw-away site). |
| Tests | `composer check` and the integration tests (`phpunit-integration.xml.dist`) pass on MySQL and MariaDB. |
| Review | Three independent reviews (engine, security, screens) were done and their findings fixed; what remains is listed in [REVIEW.md](REVIEW.md). |
| Zip | `bin/build-zip.sh` makes `dist/estasoft-migrate-<version>.zip` with one top-level folder, `estasoft-migrate`. |

## Slug and text domain

The text domain is `estasoft-migrate` everywhere and the plugin folder is `estasoft-migrate`. WordPress.org builds the slug from the plugin name when you upload, so it may offer `estasoft-migration-database-sanitizer`. If that happens, reply to the review email and ask for the slug `estasoft-migrate` so that the folder, the text domain and the slug stay the same. If they insist on another slug, the text domain must be changed to match (search and replace `estasoft-migrate` in `includes/`, `views/`, `assets/js/`, `languages/` and the plugin header, then rebuild the `.pot`).

## What the review team will ask, and the answers

- **Does it call external servers?** No third-party services. The only request the plugin makes on its own is to the site's own uploads address, to test that the package folder cannot be downloaded from directly. Push and pull connect only to a WordPress site the person types in, signed with a key that only the two sites know. Nothing is sent to Estasoft. No tracking, no ads, no upsell, no license key.
- **Why does it use direct database queries?** It is a database migration tool. All table and column names pass through `Identifier::quote()`; values are escaped with `$wpdb->prepare()` or the engine's own typed quoting. Each query that Plugin Check could not prove safe carries a comment saying why.
- **Why `unserialize`?** Search and replace does not use it: `includes/Replace/Serialized.php` parses serialized values itself, so no object can be created and no code can run. `unserialize()` appears only where the sanitizer reads a stored list (secrets and the `active_plugins` option), and always with `allowed_classes` set to `false`.
- **Why `base64`?** To carry binary values inside the JSON of the signed site-to-site protocol. Nothing is hidden or executed.
- **Why files in uploads?** Packages and snapshots are the product of the plugin. They live in a folder with a random name, with `.htaccess`, `web.config` and `index.php` protection, and are served only through an authenticated admin request. The plugin tests the protection by requesting a throw-away file from that folder and tells the person when it can be fetched (on nginx the `.htaccess` file has no effect).
- **What does it do on the front end?** Nothing, except answer a signed request when "Accept incoming migrations" is switched on in Settings (off by default).
- **What happens on uninstall?** Nothing is deleted unless "Delete all plugin data when the plugin is deleted" is switched on, because packages can be the only copy of someone's data. With it on, tables, options, transients, scheduled events and the storage folder are removed.

## Upload

1. Sign in at https://wordpress.org/plugins/developers/add/ with the account named in `Contributors`.
2. Upload `dist/estasoft-migrate-<version>.zip`.
3. Answer the review email within 7 days. Reviews are done by volunteers; the first answer can take several weeks.
4. After approval you get an SVN repository. Put the plugin files in `trunk/`, a copy in `tags/<version>/`, and the contents of `.wordpress-org/` in `assets/` (not in `trunk/`). `bin/build-zip.sh` shows exactly which files belong in the plugin.

## Assets in `.wordpress-org/`

`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` and `screenshot-1.png` to `screenshot-7.png`. The screenshot order matches the numbered list under "Screenshots" in `readme.txt`; change both together.
