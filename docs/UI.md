# Writing a screen

Every screen is one WordPress admin page. PHP (`includes/Admin/Admin.php`, `views/app.php`) prints an empty shell and loads
`assets/js/core.js` plus `assets/js/screens/<id>.js`; the screen script draws everything from the REST API
(contract: [`REST.md`](REST.md)). There is no build step: the files you edit are the files that run.

| Screen id | Page slug (`?page=`) | In menu | File |
| --- | --- | --- | --- |
| `overview` | `estasoft-migrate` | yes | `screens/overview.js` (**finished: use it as the reference**) |
| `new` | `estasoft-migrate-new` | yes | `screens/new.js` |
| `preflight` | `estasoft-migrate-preflight` | no (flow page) | `screens/preflight.js` |
| `progress` | `estasoft-migrate-progress` | no (flow page) | `screens/progress.js` |
| `sanitizer` | `estasoft-migrate-sanitizer` | yes | `screens/sanitizer.js` |
| `replace` | `estasoft-migrate-search-replace` | yes | `screens/replace.js` |
| `packages` | `estasoft-migrate-packages` | yes | `screens/packages.js` |
| `logs` | `estasoft-migrate-logs` | yes | `screens/logs.js` |
| `settings` | `estasoft-migrate-settings` | yes | `screens/settings.js` |

The approved design of each screen (the look, the words, the states to cover) is in
`/tmp/claude-0/-home-claude/425f695c-32b7-5aa5-a482-0b14a84f01bb/scratchpad/estasoft-ui/project/`
(`Main`=Overview, `NewMigration`, `Preflight`, `Progress`, `Sanitizer`, `SearchReplace`, `Packages`, `Logs`, `Settings` `.dc.html`;
`StyleGuide.dc.html` shows every component; `estasoft-ui.css` is the original of `assets/css/admin.css`). Read the `.dc.html` file as
source: the markup between `<x-dc>` and `</x-dc>` is the design, and the class names are the ones in `admin.css`.
**Build what the design shows** (same structure, wording and states), connected to real data. Where the design and the REST
contract disagree, the REST contract is the truth: keep the design's look and adapt the content, and say so in your report.

## Skeleton of a screen

```js
( function () {
	'use strict';
	var ESTM = window.ESTM, h = ESTM.h, ui = ESTM.ui, fmt = ESTM.fmt;
	var __ = ESTM.i18n.__, _n = ESTM.i18n._n, sprintf = ESTM.i18n.sprintf;
	var TD = 'estasoft-migrate';

	ESTM.screen( 'logs', function ( root ) {
		// Build the static page once. Keep references to the regions that change.
		var table = h( 'div' );
		ESTM.replace( root, ui.pageHead( __( 'Logs', TD ), __( 'What happened…', TD ) ), table );

		ESTM.get( 'logs', { limit: 200 } ).then( function ( data ) {
			ESTM.replace( table, /* nodes */ );
		}, function ( err ) {
			ESTM.replace( table, ui.loadError( err, /* retry */ ) );
		} );
	} );
}() );
```

`root` is `#estm-app` (class `est-page`: flex column with a 20 px gap, padding already applied). Put the page head first
(`ui.pageHead`), then your sections. For two-column pages use `h('div.cols', h('div.main.stack', …), h('div.side.stack', …))`.

### Rules

1. **No `innerHTML`, no `insertAdjacentHTML`, no string-built markup.** Use `ESTM.h()`. Text from the database, from log
   lines, from another site (names, URLs, messages) must only ever reach the page as text nodes. This is a security rule.
2. **Never re-render a region the person is typing in or tabbing through.** Build forms once; update only the regions that
   show changing data (progress, a result table). Polling must not steal focus or reset a field.
3. **Every string** is `__( 'Text', TD )` / `_n()` / `sprintf()` with the literal text domain `'estasoft-migrate'`
   (string literals only, so `wp i18n make-pot` finds them). Put `/* translators: … */` before strings with placeholders.
   Words are plain, short and specific; say what happened and what to do next. No jargon in messages for people.
4. **Colour never carries meaning alone.** Use `ui.badge` / `ui.note` (they come with an icon glyph and a word).
5. **Accessibility:** a visible `<label>` for every control (`ui.field`), `aria-live` on regions that update by themselves,
   real `<button>`s for actions and `<a href>` for navigation, focus visible (already styled), tables with `scope="col"`
   headers (`ui.table`). Dialogs use `ui.confirm` / `ui.dialog` (native `<dialog>`: focus is trapped and Esc closes).
   Everything must be usable with the keyboard and at 360 px width.
6. **Destructive or irreversible actions ask first** (`ui.confirm({ danger: true, … })`): delete, restore, import, apply,
   cancel a running job, clear logs. Say what will be lost and what is kept (the rollback snapshot).
7. **Every request can fail, and every list can be empty.** Show `ui.loadError(err, retry)` for a failed load, an `ui.empty`
   state for no data, and a disabled/busy state on buttons while a request runs (`ui.busy(button, promise)`).
   Messages in `err.message` are already written for people; show them as they are.
8. **Right-to-left:** use logical CSS properties (`margin-inline-start`, `padding-inline`, `inset-inline-end`, `text-align: start/end`).
   Never `left`/`right` in your own CSS.
9. Own CSS goes in `assets/css/screens/<id>.css` (loaded automatically when present), scoped under `.estm`. Reuse the classes of
   `admin.css` first (`card`, `tile`, `note`, `badge b-*`, `btn btn-*`, `tbl`, `field`, `input`, `select`, `switch`, `chip`, `seg`,
   `opt`, `drop`, `bar`, `steps`, `check`, `rrow`, `set`, `kv`, `code`, `diff`, `chips`, `copy`, `empty` …; `StyleGuide.dc.html` shows them).
   Do not edit `core.js`, `admin.css` or any PHP file unless you hit a real bug; if you do, keep the edit minimal and list it in your report.
10. Colours: only the palette (`--ink`, `--yellow`, `--white`, tints, `--danger` for errors and destructive actions). White text never on yellow.

## `ESTM` toolbox (`assets/js/core.js`)

Boot data: `ESTM.rest`, `ESTM.nonce`, `ESTM.pages` (screen id → URL), `ESTM.downloads`, `ESTM.site` (`url`, `host`, `multisite`), `ESTM.links`, `ESTM.rtl`, `ESTM.version`.

| Function | What it does |
| --- | --- |
| `h(tag, attrs, …children)` | Element builder. `tag` may carry classes: `h('div.card.hi')`. `attrs`: `class`, `style` (string/object), `dataset`, `on<event>`, `value/checked/disabled` (as properties), anything else as an attribute (`true` = present, `false/null` = omitted). Children: nodes, strings, numbers, arrays (flattened), `null/false` (skipped). |
| `ESTM.replace(el, …children)` | Empty a node and fill it. |
| `ESTM.get(path, query)` / `post(path, body)` / `del(path, query)` / `api(method, path, body, {raw, query})` | REST calls → Promise. Rejects with `ESTM.ApiError` (`message` for people, `code`, `status`, `data`). `ESTM.errorMessage(err)`. |
| `ESTM.watchJob(id, {onUpdate, onLogs, onDone, onError, cursor})` | Drives a job: calls `jobs/{id}/tick` in a loop (each call does one slice of work unless a background runner has the job). Returns `{stop(), poke()}`. `onDone` fires when the job finished **or paused**. Stop it (`stop()`) when leaving the screen or when you take over (`poke()` restarts it, e.g. after Resume). |
| `ESTM.isFinished(status)`, `ESTM.isActive(status)` | `completed/failed/cancelled`; `queued/running`. |
| `ESTM.jobLabel(job)`, `ESTM.jobBadge(job)` | “Export”, “Search & replace”… and the status badge (shows “Dry run” for a completed dry run). |
| `ESTM.url(screen, params)` | URL of another screen: `ESTM.url('progress', { job: 12 })`. `ESTM.go(url)` navigates. `ESTM.params()` = this page's query string as an object. |
| `ESTM.downloadUrl('package'\|'log', id)` | Nonce-protected download links (`<a href download>`). For a log of all jobs pass `''`. |
| `ESTM.draft.get/set/clear(key)` | Hand data to the next screen of a flow (sessionStorage; survives reload, not a closed tab). |
| `ESTM.fmt.*` | `number`, `bytes` (412 MB), `duration(sec)`, `date(gmt[, withTime])`, `time`, `clock` (hh:mm), `ago`, `percent`. Dates from the API are UTC strings. |
| `ESTM.toList(textOrArray)`, `ESTM.debounce(fn, ms)`, `ESTM.uid(prefix)` | Helpers. |
| `ESTM.ui.*` | `btn`, `busy`, `card`, `tile`, `note`, `badge`, `bar`/`setBar`, `field`, `input`, `password`, `textarea`, `select`, `switch`, `checkbox`, `setting`, `seg`, `spinner`, `skeleton`, `empty`, `pageHead`, `table`, `cell`, `loadError`, `dialog`, `confirm`, `toast`, `copy`, `copyField`, `highlight`, `logLine`. Read their doc comments in `core.js` for options. |

## The plan: how screens hand work to Pre-flight

Starting anything that changes data goes **screen → Pre-flight → Progress**. The screen that collects the choices does not create the
job. It saves a *plan* and opens Pre-flight, which runs the checks, shows what will happen, and (only there) creates the job:

```js
ESTM.draft.set( 'plan', {
	mode:   'export',            // export | sanitize | import | search_replace | push | pull   (what Pre-flight checks)
	type:   'export',            // job type for POST /jobs: export | import | search_replace | push | pull
	input:  { /* body for POST /jobs and POST /preflight, without "type" and "mode" */ },
	title:  'Export package',    // heading for the plan in Pre-flight
	lines:  [ 'All 63 tables', 'Encrypted with a password' ],   // optional: plain-language summary lines, shown in “What will happen”
	back:   'new',               // screen id for the Back link
} );
ESTM.go( ESTM.url( 'preflight' ) );
```

Examples of `input`: see "Input fields by job type" in `REST.md`. A `mode` of `sanitize` is an `export` job whose `input.sanitize` is set.
Pre-flight starts the job with `POST jobs {type, …input}`, clears the plan and goes to `ESTM.url('progress', { job: id })`. Secrets in
`input` (`password`, `remote_key`) live only in this tab's sessionStorage until the job has been created; Pre-flight clears the plan
as soon as it has. Screens that need a quick look without a job (a *dry run* search, a sanitizer *preview*) call the API directly
and show the result in place.

## Testing your screen in a browser

```bash
bin/dev-site.sh up <name> <port>        # own WordPress + database, login admin / admin, plugin linked in (see the script's header)
bin/dev-seed.sh <name> 300000           # optional: many rows, so jobs take many slices
node -e "const {open}=require('./bin/pw'); (async()=>{ const s=await open(8111); await s.go('estasoft-migrate-logs'); await s.shot('/tmp/x.png'); console.log(s.problems()); await s.close(); })()"
```

Look at the screenshots (the Read tool shows images). Check: the happy path with real data, an empty state, a failed request
(stop the job, wrong key, no permission…), keyboard use, 360 px width (`open(port, { width: 360, height: 800 })`), right-to-left
(in the test: `await s.page.evaluate(() => { document.documentElement.dir = 'rtl'; document.body.classList.add('rtl'); })`, then screenshot),
and that `s.problems()` is empty (no console errors, no failed requests). PHP errors go to `~/estm-dev-sites/<name>/wp-content/debug.log`.
Run each screen's flows through the real engine (create jobs, wait for them, check the results in the database or with
`wp --path=~/estm-dev-sites/<name> --allow-root estasoft-migrate jobs`).

Syntax check before you finish: `node --check assets/js/screens/<id>.js`.
