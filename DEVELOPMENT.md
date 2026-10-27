# Developing Estasoft Migration & Database Sanitizer

Free, GPL v2 or later, by ESTASOFT TECHNOLOGIES. Works on WordPress 5.8+, PHP 7.4+, MySQL 5.7+/8.0 and MariaDB 10.3+.

## Layout

| Path | What is in it |
| --- | --- |
| `estasoft-migrate.php` | Plugin header, constants, boot. Keep version numbers in sync with `readme.txt` (the build script checks). |
| `includes/` | All PHP, autoloaded from the `Estasoft\Migrate` namespace (`includes/autoload.php`, one class per file). |
| `includes/Db` | Database access behind an interface: keyset pagination, DDL translation, `WpdbDatabase` for real sites. |
| `includes/Engine` | Jobs: `JobRunner`, `JobStore`, `BatchSizer`, `Budget`, and `Tasks/` (export, import, search & replace, push, pull, verify). |
| `includes/Package` | The `.estm` file format: writer, reader, row codec, encryption. |
| `includes/Replace` | Serialization-safe search and replace. |
| `includes/Sanitizer` | Rules, profiles, fake data, safety switches, saved configuration. |
| `includes/Remote` | Signed site-to-site protocol (`POST /?estm_remote=<action>`), client and server. |
| `includes/Preflight` | The checks that run before anything changes. |
| `includes/Rest` | `Api` (plain arrays, easy to test) and `Router` (thin REST layer). |
| `includes/Admin`, `views/`, `assets/` | The nine admin screens: a PHP shell, `core.js` and one script per screen. |
| `includes/Cli` | `wp estasoft-migrate` commands. |
| `.wordpress-org/` | The official icon for the plugin directory (`icon.svg`, 128 and 256 px PNG). Not part of the zip. The same "E" is drawn in `views/app.php` (header) and `Admin::icon()` (menu). |
| `tests/` | `Unit` (no WordPress, SQLite in memory) and `Integration` (real WordPress and MySQL/MariaDB). |
| `docs/REST.md`, `docs/UI.md` | The REST contract and how to write a screen. |

## Principles

* **Nothing runs long in one request.** Work is cut into slices measured by `Budget`; every slice saves a checkpoint.
  Anything a task needs to continue goes into the job state, never into memory.
* **The live site is untouched until the swap.** Imports build `_estm_<job>_<n>` tables, verify, take a rollback snapshot, then
  switch with one `RENAME TABLE`.
* **Secrets never reach logs or the page.** Passwords and connection keys are stripped from job views and log lines.
* **Everything the person sees is translatable** with the text domain `estasoft-migrate` (literal strings only).
* **No build step.** The JavaScript is plain ES2017 and runs as written.

## Setting up

```bash
composer install          # PHPUnit, WordPress coding standards, PHPStan
```

Without Composer, install PHPUnit 9.6 and PHP_CodeSniffer with the `WordPress` standard some other way; the commands below
only need `phpunit` and `phpcs` on your path.

### Checks (run before every commit)

```bash
composer lint     # php -l on every file
composer cs       # WordPress-Extra + WordPress-Docs + PHP 7.4 compatibility (phpcs.xml.dist)
composer stan     # PHPStan level 5
composer test     # unit tests, no WordPress needed
composer check    # all of the above
```

`composer cs:fix` fixes spacing and alignment automatically. The few rules switched off in `phpcs.xml.dist` each have the reason
written next to them; add a reason when you add an exception.

### Integration tests

They run the whole engine on a real WordPress and a real MySQL or MariaDB server, including atomic table swaps and utf8mb4.
Use a throw-away install: the tests create and drop tables with that site's prefix.

```bash
export ESTM_WP_ROOT=/path/to/a/wordpress/install     # has wp-load.php
export ESTM_DEST_DB=wp_dest                           # an empty second database the same user can write to
vendor/bin/phpunit -c phpunit-integration.xml.dist
```

### Trying the plugin in a browser

```bash
bin/dev-site.sh up shop 8081          # own WordPress and database, plugin linked in; login admin / admin
bin/dev-seed.sh shop 300000           # optional: lots of rows so jobs take many slices
bin/dev-site.sh drop shop             # remove it again
```

Or with Docker: `npx @wordpress/env start` uses `.wp-env.json` (WordPress with the plugin active, debug log on).

`bin/pw.js` drives Chromium through Playwright for screenshots and checks; see the end of `docs/UI.md` for an example.
PHP errors go to `wp-content/debug.log` of the dev site.

## Adding or changing a screen

Read `docs/UI.md` first. In short: add `assets/js/screens/<id>.js` (register with `ESTM.screen`), add the id to
`includes/Admin/Admin.php`, build the page with `ESTM.h()` (never `innerHTML`), call the REST API, and add any new route to
`includes/Rest/Router.php`, `Api.php`, `docs/REST.md` and a test in `tests/Unit/ApiTest.php`.

## Adding a job type

1. A class in `includes/Engine/Tasks/` extending `Task`: `prepare()`, `step()` and `progress()` are required; `describe()`, `tables()`,
   `steps()`, `cleanup()` and `complete()` are optional. Throw `TaskException` for a failure that should be retried.
2. Register it in `TaskRegistry`.
3. Describe its parameters in `JobParams` (validation and defaults), and its conflicts in `JobConflicts`.
4. Expose it in `Api::create_job()` and document the input and result in `docs/REST.md`.
5. Test it in `tests/Unit` with `EngineHarness`; add a case to the integration test if it depends on real MySQL behavior.

## Translations

```bash
wp i18n make-pot . languages/estasoft-migrate.pot --slug=estasoft-migrate --domain=estasoft-migrate \
  --exclude=tests,bin,docs,vendor,node_modules,dist
```

JavaScript strings are found through `wp.i18n` calls with the literal text domain; the same command picks them up.

## Release checklist

1. Same version in `estasoft-migrate.php` (header and `ESTASOFT_MIGRATE_VERSION`) and `readme.txt` (`Stable tag`). Add the changelog entry.
2. Set `Tested up to` in `readme.txt` to the newest stable WordPress you tested on, and `Contributors` to real wordpress.org usernames.
3. `composer check`, then the integration tests on both MySQL and MariaDB.
4. Update `languages/estasoft-migrate.pot`.
5. `bin/build-zip.sh` makes `dist/estasoft-migrate-<version>.zip`. Install it on a clean site, activate, run an export and an import.
6. Tag the release; the zip is also built and attached by CI.

## Filters worth knowing

| Filter | Purpose |
| --- | --- |
| `estasoft_migrate_capability` | Capability needed to use the plugin (default `manage_options`). |
| `estasoft_migrate_links` | Documentation, support and source links shown on the Overview. |
| `estasoft_migrate_tables` | Change the list of tables a job works on. |
| `estasoft_migrate_remote_ip` | The caller's address as seen by the incoming-migration endpoint (behind a proxy). |
| `estasoft_migrate_remote_sslverify` | Turn off certificate checks for a connected site (development only). |
