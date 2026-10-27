# REST contract for the admin screens

Namespace `estasoft-migrate/v1` · base URL `window.ESTM.rest` (ends with `/`) · every route needs the
`manage_options` capability (filter: `estasoft_migrate_capability`) and the REST nonce, sent as the
`X-WP-Nonce` header (`window.ESTM.nonce`).

Request bodies are JSON. Responses are the plain objects below. Errors are the WordPress REST shape:

```json
{ "code": "estm_busy", "message": "Another job is still in progress: Export. …", "data": { "status": 409, "job_id": 12 } }
```

`message` is always written for people and can be shown as it is. Codes: `estm_invalid` (400),
`estm_password_required` (400), `estm_not_found` (404), `estm_busy` (409 with `data.job_id`),
`estm_connection` (400 with `data.remote_code`), `estm_error` (500).

The logic lives in `includes/Rest/Api.php` (unit-tested with arrays in and out). `Rest\Router` only maps routes.

## Overview, tables, pre-flight

| Method | Route | Notes |
| --- | --- | --- |
| GET | `/overview` | `site{url,name,prefix,wp_version,php,db,multisite}`, `database{tables,rows,bytes,largest{name,rows,bytes}\|null}`, `last` (last completed export/import/push/pull as a job summary, or null), `current` (running job view or null), `recent[]` (job summaries, 8), `packages{exports,snapshots,received}` (each `{count,bytes,newest}`) plus `sanitized` and `free`, `connection{accepting,https,key_id}`, `health` (see Settings → environment) |
| GET | `/tables` | `tables[{name,logical,rows,bytes}]`, `totals{tables,rows,bytes}`, `prefix` |
| POST | `/preflight` | Body: the same fields as the job you plan, plus `mode` = `export\|sanitize\|import\|search_replace\|push\|pull`. Returns `status` (`ready\|warnings\|blocked`), `counts{pass,warn,block}`, `checks[{id,status,title,detail}]` (blocks first), `summary{tables,rows,bytes,package,seconds[lo,hi],remote}`, `safety{snapshot,snapshot_bytes,free,remote_free}` |

## Jobs

| Method | Route | Notes |
| --- | --- | --- |
| GET | `/jobs?status=&type=&limit=` | `{jobs:[job summary]}` |
| POST | `/jobs` | Body `{type, …fields}` → **201** job view. `type`: `export`, `import`, `search_replace`, `push`, `pull`, `verify`. |
| GET | `/jobs/{id}?state=1` | Job view (`state` adds the technical state, for support) |
| DELETE | `/jobs/{id}` | Only finished jobs |
| POST | `/jobs/{id}/tick` | Body `{after_log}`. Does one slice of work **in the browser's request** when no background runner holds the job. Returns the job view plus `ran`, `locked`, `logs[]` (new log lines, oldest first) and `log_cursor` (send it back as `after_log`). Poll every 1–2 s while the job is `queued`/`running`. |
| POST | `/jobs/{id}/pause\|resume\|cancel\|retry` | Job view |

### Job summary

`id, type, kind, status, title, progress (0–100), message, created, updated, started, finished, remote, dry_run, package`

`kind` refines the type: `export`, `sanitize`, `import`, `restore`, `snapshot`, `push`, `pull`, `search_replace`, `verify`.
`status`: `queued, running, paused, failed, completed, cancelled`.

### Job view (summary plus)

`params` (secrets replaced by `key_enc_set: true` …), `steps[{id,label,status: done|current|pending}]`,
`tables[{name,done,total,status: done|running|waiting|skipped, matches?, rows?}]` (≤ 500) and `tables_total`,
`can{pause,resume,cancel,retry,delete}`,
`stats{rows_done,rows_total,elapsed,rows_per_sec,eta (seconds or null),batch,tables{done,running,waiting}}`,
`warning` (`{message,attempt,max}` while a temporary problem is being retried, else null),
`error` (`{message,details[]}` when failed, else null),
`result` (only when completed, shape depends on `type`).

### Result shapes

- `export`: `package, size, rows, tables, masked{…}, notes[], sanitized, protection (password|site-key|none)`
- `import`: `tables, rows, snapshot (package id or ""), notes[], restore (bool)`
- `push`: `remote, rows, tables, size, notes[]` · `pull`: `remote, rows, tables, size, snapshot, notes[]`
- `search_replace`: `dry_run, totals{scanned,changed,matches,nested}, tables[{name,done,total,status,matches,rows}], samples[{table,column,key,before,after}], snapshot, notes[]`
- `verify`: `package, blocks, size`

### Input fields by job type

Booleans accept `true/false/"1"/"0"`.

- **export**: `label`, `compress` (true), `tables[]`, `exclude_tables[]`, `password` (encrypts the package), `protect_with_site_key`, `replace{pairs:[[from,to]], regex, case_sensitive}`, `sanitize{profile, overrides{rules{id:bool}, safety{id:bool}, keep_users[], keep_admins, skip_tables[], skip_columns[]}}` (presence makes it a *sanitized copy*). A `password` must have at least 8 characters. When Settings → “Encrypt packages” is on, `password` or `protect_with_site_key` is required (`estm_password_required`). `keep_admins` defaults to true for the `staging` profile and false for `dev` and `gdpr`.
- **import**: `package_id`, `password` (if the package has one), `snapshot` (true), `rewrite` (true: change the old site address to this one), `replace{pairs…}`, `safety{mail,seo,pay,smtp: bool}`. A package of type `snapshot` is a **restore**.
- **search_replace**: `pairs[[from,to]]`, `regex`, `case_sensitive` (true), `replace_keys` (true), `skip_guid` (true), `dry_run` (**true by default**), `snapshot` (true; only used when not a dry run), `sample_limit` (20), `skip_columns[]`, `tables[]`, `exclude_tables[]`
- **push** / **pull**: `remote_url`, `remote_key` (starts with `esm_`), `snapshot` (true), `rewrite` (true), `keep_package`, `label`, `safety{…}`, `sanitize{…}`; push also `tables[]`, `exclude_tables[]`, `replace{…}`
- **verify**: `package_id`

Only one job that changes the database can run at a time (`JobConflicts`); starting a conflicting one returns `estm_busy`.

## Logs

| Method | Route | Notes |
| --- | --- | --- |
| GET | `/logs?job_id=&level=&levels=&search=&after_id=&limit=&order=` | `level` = this level and worse; `levels=error,warning` = exactly these. Returns `lines[{id,job_id,level,message,context{},created_gmt}]` and `jobs[{id,title,status,created}]` (the 50 latest, for the job select) |
| DELETE | `/logs?job_id=` | Without `job_id` clears everything |

Downloads are not REST: see **Downloads**.

## Packages and snapshots

| Method | Route | Notes |
| --- | --- | --- |
| GET | `/packages?type=export\|snapshot\|received` | `packages[{id,type,kind,status,label,file,size,created,encrypted,protection,sanitized,source_url,tables,rows,sha256,verified{ok,at,message}\|null,in_use,origin{job_id,type,kind,title,remote}\|null}]`, `totals{count,bytes,free}` |
| DELETE | `/packages/{id}` | 409 while a job uses it |
| POST | `/packages/upload` | `{name,size}` → `{package_id, chunk (bytes per piece, 1 MiB), received: 0}` starts a chunked upload. A `size` above the limit (64 GB, change it with the filter `estasoft_migrate_max_package_size`) gives 413, and so does a piece that runs past `size` |
| POST | `/packages/upload/{id}?offset=N` | Raw bytes of one piece as the body (`Content-Type: application/octet-stream`) → `{received}`. Sending a piece twice is harmless. If a piece is missing: 409 `estm_offset` with `data.received` — resume from there |
| POST | `/packages/upload/{id}/done` | `{size}` → `{package}` (a package view; 422 when the file is not a package). Then start a `verify` job and, if the person confirms, an `import` job. |

`origin` is what the snapshot list shows in its “Before” column (the job the snapshot was taken for).

## Settings

| Method | Route | Notes |
| --- | --- | --- |
| GET | `/settings` | `values{accept_incoming,require_https,ip_allowlist,runner,max_batch,slice_seconds,encrypt_packages,delete_packages_days,keep_snapshots,delete_snapshots_days,delete_on_uninstall}`, `defaults{…same keys}`, `connection{key,key_id,endpoint,previous_key_until}`, `environment{php,wp,db,memory,max_exec,cron_off,action_scheduler,runner,sodium,zlib,multisite,storage{writable,protected,free,used}}` (`protected` is true when a test file in the package folder could not be fetched from the web, false when it could, null when the server could not try), `runners[]` |
| POST | `/settings` | Any subset of `values`. Values are validated and clamped; returns the same object as GET. An `ip_allowlist` in which no line is a valid address or range is refused (400 `estm_invalid`) instead of being saved as an empty list |
| POST | `/settings/key` | Replace the connection key (the old one keeps working for a short while: `previous_key_until`) |
| POST | `/settings/test-connection` | `{remote_url, remote_key}` → `ok, took_ms, https, skew, site{url,name,wp_version,php,prefix,db,multisite}, version, writable, free_bytes, stats` |

## Sanitizer

| Method | Route | Notes |
| --- | --- | --- |
| GET | `/sanitizer` | `profiles[{id,name,text,rules{id:bool},safety{id:bool},keep_admins}]` (ids: `staging`, `dev`, `gdpr`), `rules[{id,what,where,how,example}]`, `safety[{id,title,text}]`, `admins[{id,login,email}]`, `saved{profile,rules,safety,keep_users,keep_admins,skip_tables,skip_columns,on_export}` |
| POST | `/sanitizer/preview` | `{profile, overrides{…}, per_table}` → `profile`, `tables[{table,logical,examples[{changed:[column], before:{column:value}, after:{column:value}}],dropped}]`, `kept`. Reads a few rows, **changes nothing** |
| POST | `/sanitizer/save` | The `saved` object → `{saved}`. Used by the New migration and Packages screens too: “Sanitize while sending” starts from it |

To use the saved settings in a job, send `sanitize: {profile, overrides: {rules, safety, keep_users, keep_admins, skip_tables, skip_columns}}`
(the same fields as `saved`, with `rules`/`safety` under `overrides`).

## Downloads

Not REST, because they stream a file. Use `window.ESTM.downloads` (built with nonces):

- Package: `admin-post.php?action=estm_download_package&package=<id>&_wpnonce=…`
- Log as text: `admin-post.php?action=estm_download_log&job_id=<id or empty>&_wpnonce=…`

Build the URLs with `ESTM.downloadUrl('package', id)` / `ESTM.downloadUrl('log', jobId)` from `assets/js/core.js`.

## Remote protocol (site to site)

Not for screens. `POST {site}/?estm_remote=<action>` with a signed body (see `includes/Remote/`). Actions:
`hello, put_init, put_part, put_done, import_start, export_start, job_tick, job_cancel, get_part, drop_package`.
