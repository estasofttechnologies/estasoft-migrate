# Pre-release review

Before the first submission the plugin went through three independent reviews: the migration engine (data integrity), the PHP surface (security) and the admin screens (accessibility and translation). Each finding was reproduced with a test before it was fixed. The regression tests are in `tests/Unit/EngineReviewTest.php`, `SanitizerCoverageTest.php`, `SavedConfigTest.php`, `ApiTest.php` and `JobParamsTest.php`.

No critical or high security issue was found. One data-integrity problem was critical and four were high; all are fixed.

## Fixed

### Data integrity

| Finding | Fix |
| --- | --- |
| A table whose key holds binary or non-UTF-8 bytes (UUIDs as `BINARY(16)`) was cut short without any error. | Cursors are stored as hex when they are not valid text, and encoding errors now stop the job instead of saving `null`. |
| A partial import of a sanitized package changed the live site's options (search engine visibility, mail blocking, payment plugins). | Safety switches from the package are applied only when the options table was really imported. |
| Cancelling after the tables were swapped left a half-finished site and a wrong message. | Cancel is refused once the swap happened, and the import finishes. |
| A batch that ran out of PHP memory repeated forever. | An in-flight marker halves the batch size and counts a failure, so the job stops with a clear message. |
| An export could be written without encryption when the sealed password could not be read. | The job now fails instead. |
| Cancel ignored the lock of a slice that was still running. | The lock is kept; the running slice does the clean-up. |
| A slice that started with little memory did no work, ever. | Every slice does at least one unit of work. |
| The automatic address rewrite also changed `acme.com.au` and `/var/www/old2`. | Addresses and paths are matched as whole addresses. |
| Changing the table prefix renamed settings that only look like they carry it (`wp_page_for_privacy_policy`, `wp_rocket_*`). | Only the keys WordPress builds from the prefix are renamed. |
| The secret scrubber rewrote every JSON setting (`{}` became `[]`, large numbers changed). | A value without secrets is returned untouched. |
| Tables and columns with accents or spaces were skipped or broke the import. | Any name that can sit inside backticks is accepted. |
| A table without a key could be duplicated when an import was retried after a lost connection. | The table is staged again from its first block. |
| Staging tables stayed behind when a failed import was deleted. | They are removed with the job, and the daily clean-up removes any that no job owns. |
| Masked emails and logins could collide. | Longer fake identifiers. |
| Removing foreign keys could leave a missing comma before a `CHECK` line. | Definition lines are re-joined correctly. |
| A table without a key could skip a row that was deleted between two slices. | Cannot be avoided without a key; a warning is written to the log. |

### Security

| Finding | Fix |
| --- | --- |
| A package's table definition was run as SQL as it came. A changed package could run any statement. | Only one plain `CREATE TABLE` for the staging name is run. Anything else stops the import before the site is touched. |
| A package could replace the plugin's own tables (jobs, log). | The plugin's tables are never imported. |
| A "sanitized" Developer or GDPR copy still held every administrator's email and password hash. | Administrators are kept by default only in the Staging profile. The switch names "password hashes". |
| The sanitizer left protected-post passwords, privacy-request emails and option-stored security keys and salts. | All three are masked. |
| The storage folder was reported as protected when guard files existed, which does nothing on nginx. | The plugin now requests a throw-away file from the folder and reports what happened: blocked, reachable, or could not be checked. |
| Incoming requests were not traceable. | The caller's address and the key used are logged for every action that starts something, and refused requests are logged. |
| An IP allow-list with only invalid lines let every address in. | Such a list is refused when saved and matches nothing if it is found stored. |
| No minimum password length for packages in the REST API or the command line. | At least 8 characters. |
| Declared sizes of uploads and received parts were not enforced. | A part cannot run past the announced size; an upload larger than 64 GB is refused (filter `estasoft_migrate_max_package_size`). |
| `?estm_remote[]=x` raised a PHP notice. | Ignored. |
| The settings were read from the database on every page view. | The version check runs only in the admin, in cron, on the command line and for REST requests. |

### Screens

| Finding | Fix |
| --- | --- |
| About 62% of the screen text was never extracted for translation. | The text domain is written out literally everywhere. The template covers every screen. |
| Two WordPress locales (`pt_PT_ao90`, `ca@valencia`) made date formatting throw. | The locale is normalised once; every formatter falls back. |
| A failing handler silently stopped the progress updates. | Handlers are isolated, and polling always continues. |
| The Overview rebuilt its content every 4 seconds, lost keyboard focus, re-announced to screen readers and stopped for good after one failed request. | It updates in place, leaves unchanged parts alone and tries again. |
| The page said "you can leave" when the job would not run in the background (browser runner, WP-Cron switched off). | The wording follows how this server runs jobs. |
| Sentences in Search & replace were built from fragments. | Whole sentences with placeholders. |
| The Sanitizer save bar was announced on every keystroke. | It changes only when the saved state changes. |
| Error messages disappeared after 8 seconds and were not reliably announced. | Errors stay until dismissed and are announced as alerts. |
| Buttons in the package lists had identical names. | The date is part of each name. |
| The Overview made the whole page scroll sideways on a phone. | Text meant for screen readers in a wide table was positioned outside its scroll box. It is now clipped with the table. |
| Two sentences on Search & replace showed a raw `%s` (“Every run is saved in %s.”). | The helper that puts links into translated sentences understood only numbered placeholders. It now handles both, and every screen was scanned for leftover placeholders. |
| A masked email example was cut off in the Sanitizer table. | The example column is wider and long examples wrap. |
| The Settings “Show key” button announced its state twice. | One state only, like the other password fields. |
| The pre-flight check repeated an old answer about the package folder for hours after the host fixed it. | A pre-flight check always tests again. A “can be downloaded” answer is kept for 10 minutes, a good one for 6 hours. |
| Smaller points | Copy failure is reported, a password toggle announces one state, one `h1` per page, numbers and sizes follow the locale, no focus stealing or smooth scrolling for people who asked for less motion, 24 px close buttons, a correct tick in right-to-left languages. |

## Known limitations

These were found and are not fixed in 1.0.0. None puts data at risk without an administrator's key or permission.

- **A connection key gives full access while "Accept incoming migrations" is on.** That is what the key is for. The switch is off by default, requests are signed, HTTPS is required by default, every action that starts something is logged with the caller's address, and an IP allow-list can narrow it. There is no automatic switch-off timer; the Settings screen asks to switch it off when not migrating.
- **Push and pull use `wp_remote_post`, not `wp_safe_remote_post`.** An administrator can therefore point a connection test at an internal address. It needs the `manage_options` capability, and migrating to or from a local development site (`localhost`) is a normal use of the plugin. Redirects are not followed.
- **Replay protection depends on the object cache and the options table.** A signed request that was captured can be replayed within five minutes (the clock tolerance) if the cache was flushed, or an import replaced the options table, in between. With HTTPS required, capturing a request needs access to the connection itself. Two identical requests at the same moment could both be accepted; a second import cannot start while another job is writing to the tables.
- **Failed attempts with a bad key are counted per IP address in transients.** Behind a proxy that hides the real address, all callers share one counter. The filter `estasoft_migrate_remote_ip` exists for hosts that pass the real address in a trusted header.
- **A replaced connection key keeps working for 10 minutes** so that two sites can be updated one after the other. There is no "revoke now".
- **The block on outgoing email on a staging copy lives in the plugin.** If the plugin is deactivated on that copy, email flows again. Deactivate it only after you have changed the addresses.
- **Some data is not masked:** arguments of scheduled events (`cron` option), custom user meta that holds IP addresses, and the file-system path of the source site in the package manifest (needed to rewrite paths). The sanitizer shows what it masked, and a column list can be added in the filter `estasoft_migrate_sanitizer_columns`.
- **Tables without a primary key** are read with an offset, so a row that is deleted while the export runs can be missed; a warning is logged. Tables with only a `UNIQUE` key take the same path.
- **Generated columns** are not skipped on import; such a table fails loudly rather than losing data.
- **Time zones:** `TIMESTAMP` columns are copied as they are. If the two servers use different session time zones, such values can shift.
- **A statement that failed because the connection dropped** is repeated once after reconnecting. Imports write with `REPLACE`, which gives the same result when repeated.
