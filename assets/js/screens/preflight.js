/**
 * Pre-flight screen: the last stop before anything runs. It takes the *plan* a screen saved (docs/UI.md), checks it
 * with POST preflight, shows what will happen, and is the only place that creates the job (POST jobs).
 * Without a plan it accepts ?mode=export (the Overview links there) and otherwise points back to the screen that makes one.
 */
( function () {
	'use strict';

	var ESTM = window.ESTM;
	var h = ESTM.h;
	var ui = ESTM.ui;
	var fmt = ESTM.fmt;
	var __ = ESTM.i18n.__;
	var _n = ESTM.i18n._n;
	var sprintf = ESTM.i18n.sprintf;
	var TD = 'estasoft-migrate';

	/* ---------------------------------------------------------------- Small helpers */

	function hostOf( url ) {
		return String( url || '' ).replace( /^https?:\/\//i, '' ).replace( /\/+$/, '' );
	}

	/** Booleans can arrive as false, 0, "0" or "false". */
	function isOff( v ) {
		return false === v || 0 === v || '0' === v || 'false' === v;
	}

	function okNote( kids ) {
		return h( 'div.note', { role: 'status' }, h( 'span.ic', { 'aria-hidden': 'true' }, '✓' ), h( 'div', kids ) );
	}

	function nowGmt() {
		return new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' );
	}

	/** "6 to 8 minutes" from [low, high] seconds. */
	function timeRange( range ) {
		if ( ! range || ! range.length ) {
			return '';
		}
		var lo = Math.max( 1, Math.round( Number( range[ 0 ] ) || 1 ) );
		var hi = Math.max( lo, Math.round( Number( range[ 1 ] ) || lo ) );
		if ( hi < 90 ) {
			/* translators: 1: shortest time in seconds, 2: longest time in seconds */
			return sprintf( _n( '%1$d to %2$d second', '%1$d to %2$d seconds', hi, TD ), lo, hi );
		}
		var a = Math.max( 1, Math.round( lo / 60 ) );
		var b = Math.max( a, Math.round( hi / 60 ) );
		if ( b < 90 ) {
			if ( a === b ) {
				/* translators: %d: minutes */
				return sprintf( _n( 'About %d minute', 'About %d minutes', b, TD ), b );
			}
			/* translators: 1: shortest time in minutes, 2: longest time in minutes */
			return sprintf( _n( '%1$d to %2$d minute', '%1$d to %2$d minutes', b, TD ), a, b );
		}
		/* translators: 1: shortest time, 2: longest time, each with its unit */
		return sprintf( __( '%1$s to %2$s', TD ), fmt.duration( lo ), fmt.duration( hi ) );
	}

	function backLabel( screen ) {
		var labels = {
			'new': __( 'Back to options', TD ),
			packages: __( 'Back to packages', TD ),
			replace: __( 'Back to search & replace', TD ),
			sanitizer: __( 'Back to sanitizer', TD ),
			overview: __( 'Back to overview', TD ),
		};
		return labels[ screen ] || __( 'Back', TD );
	}

	/** Words on the Start button. */
	function startLabel( plan ) {
		switch ( plan.mode ) {
			case 'import':
				return __( 'Start import', TD );
			case 'search_replace':
				return isOff( plan.input.dry_run ) ? __( 'Apply changes', TD ) : __( 'Run preview', TD );
			case 'export':
			case 'sanitize':
			case 'push':
			case 'pull':
				return __( 'Start migration', TD );
		}
		return __( 'Start', TD );
	}

	/** What the plan does, in plain words, when the screen that made it gave no lines. */
	function defaultLines( plan ) {
		var snap = ! isOff( plan.input.snapshot );
		switch ( plan.mode ) {
			case 'export':
				return [ __( 'The tables of this site are saved in one package file on this site.', TD ), __( 'Nothing on this site is changed.', TD ) ];
			case 'sanitize':
				return [ __( 'A sanitized copy of this site’s database is saved as a package file.', TD ), __( 'Nothing on this site is changed.', TD ) ];
			case 'import':
				return [ __( 'The package is read and its tables replace the tables of this site.', TD ), snap ? __( 'A rollback snapshot of this site is saved first.', TD ) : __( 'No rollback snapshot is saved.', TD ) ];
			case 'search_replace':
				return isOff( plan.input.dry_run )
					? [ __( 'The text is changed in this site’s database.', TD ), snap ? __( 'A rollback snapshot of this site is saved first.', TD ) : __( 'No rollback snapshot is saved.', TD ) ]
					: [ __( 'The database is searched and the changes are listed. Nothing is changed.', TD ) ];
			case 'push':
				return [ __( 'This site’s database is sent to the other site, which replaces its own.', TD ) ];
			case 'pull':
				return [ __( 'The database of the other site is copied here and replaces this site’s database.', TD ) ];
		}
		return [];
	}

	/** The plan to check: the one a screen saved, or a default one for ?mode=export. */
	function resolvePlan() {
		var mode = ESTM.params().mode || '';
		var saved = ESTM.draft.get( 'plan' );
		var valid = saved && 'object' === typeof saved && saved.mode && saved.type && saved.input && 'object' === typeof saved.input;
		if ( valid && ( ! mode || mode === saved.mode ) ) {
			return { plan: saved, mode: saved.mode };
		}
		if ( 'export' === mode ) {
			return {
				plan: {
					mode: 'export',
					type: 'export',
					input: {},
					title: __( 'Export package', TD ),
					lines: null,
					back: 'new',
				},
				mode: mode,
			};
		}
		return { plan: null, mode: mode };
	}

	/* ---------------------------------------------------------------- Screen */

	ESTM.screen( 'preflight', function ( root ) {
		var found = resolvePlan();
		if ( ! found.plan ) {
			showNoPlan( root, found.mode );
			return;
		}
		var plan = found.plan;
		var input = plan.input;
		var mode = plan.mode;
		var started = false;
		var token = 0;
		var result = null;
		var narrow = !! ( window.matchMedia && window.matchMedia( '(max-width: 782px)' ).matches );
		var backUrl = ESTM.url( plan.back || 'overview', 'new' === plan.back ? { edit: 1 } : null );
		var lines = plan.lines && plan.lines.length ? plan.lines : defaultLines( plan );

		/* ---- Static page */

		var rerun = ui.btn( __( 'Run checks again', TD ), { onClick: function () { run(); } } );
		var pageHead = ui.pageHead( __( 'Pre-flight check', TD ), '', [ rerun ] );
		var lede = pageHead.querySelector( '.lede' ) || pageHead.firstChild.appendChild( h( 'p.lede' ) );
		var statusBox = h( 'div', { 'aria-live': 'polite' } );
		var tilesBox = h( 'div.g-auto', { style: 'grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))' } );
		var listsBox = h( 'div.stack' );
		var safetyBox = h( 'div' );
		var readyText = h( 'p.sub', { id: 'estm-pf-ready-text', style: 'margin-bottom:14px' } );
		var startNote = h( 'div.pf-note', { 'aria-live': 'polite' } );
		var startBtn = ui.btn( startLabel( plan ), { kind: 'primary', disabled: true, onClick: onStart } );
		startBtn.setAttribute( 'aria-describedby', 'estm-pf-ready-text' );
		var readyCard = h(
			'section.card',
			h( 'h2', { style: 'margin-bottom:8px' }, __( 'Ready to run?', TD ) ),
			readyText,
			startNote,
			h( 'div.stack', { style: 'gap:10px' }, startBtn, ui.btn( backLabel( plan.back ), { href: backUrl } ) )
		);

		var steps = 'new' === plan.back
			? [ __( 'Method', TD ), __( 'Destination and options', TD ), __( 'Pre-flight check', TD ), __( 'Run', TD ) ]
			: [ __( 'Choose', TD ), __( 'Pre-flight check', TD ), __( 'Run', TD ) ];
		var current = steps.length - 2;
		var stepsEl = h( 'ol.steps', { 'aria-label': __( 'Progress', TD ) }, steps.map( function ( label, i ) {
			var cls = i < current ? 'done' : ( i === current ? 'cur' : '' );
			return h( 'li', { class: cls, 'aria-current': i === current ? 'step' : null }, h( 'span.n', { 'aria-hidden': 'true' }, i < current ? '✓' : String( i + 1 ) ), label );
		} ) );

		var planCard = lines.length
			? ui.card( { title: __( 'What will happen', TD ) }, h( 'ul.checklist.stack', { style: 'gap:10px;font-size:13.5px' }, lines.map( function ( l ) { return h( 'li', l ); } ) ) )
			: null;

		ESTM.replace(
			root,
			pageHead,
			stepsEl,
			statusBox,
			tilesBox,
			h(
				'div.cols',
				h( 'div.main.stack', listsBox ),
				h( 'div.side.stack', planCard, safetyBox, readyCard )
			)
		);

		/* ---- Running the checks */

		function ledeText( when ) {
			var title = String( plan.title || __( 'Pre-flight check', TD ) ).replace( /[.:\s]+$/, '' );
			if ( 'failed' === when ) {
				/* translators: %s: what is planned, for example "Push to staging.example.com" */
				return sprintf( __( '%s. The checks could not run.', TD ), title );
			}
			if ( ! when ) {
				/* translators: %s: what is planned, for example "Push to staging.example.com" */
				return sprintf( __( '%s. Checking now.', TD ), title );
			}
			/* translators: 1: what is planned, for example "Push to staging.example.com", 2: date and time */
			return sprintf( __( '%1$s. Checked %2$s.', TD ), title, when );
		}

		function setLoading() {
			result = null;
			lede.textContent = ledeText( '' );
			ESTM.replace(
				statusBox,
				h(
					'div.note',
					{ role: 'status' },
					h( 'span', { style: 'flex:none;padding-top:1px' }, ui.spinner( __( 'Checking', TD ) ) ),
					h( 'div', h( 'b', __( 'Checking…', TD ) ), ' ', 'push' === mode || 'pull' === mode ? __( 'This site and the other site are being checked. Nothing is changed.', TD ) : __( 'This takes a few seconds. Nothing is changed.', TD ) )
				)
			);
			ESTM.replace( tilesBox, [ 1, 2, 3 ].map( function () { return h( 'div.tile', ui.skeleton( 2 ) ); } ) );
			ESTM.replace( listsBox, h( 'section.card', ui.skeleton( 5 ) ) );
			ESTM.replace( safetyBox, h( 'section.card.hi', ui.skeleton( 3 ) ) );
			readyText.textContent = __( 'The checks are running. Start is available when they are done.', TD );
			startBtn.disabled = true;
		}

		function run() {
			var mine = ++token;
			ESTM.replace( startNote );
			setLoading();
			var body = Object.assign( {}, input, { mode: mode } );
			ui.busy( rerun, ESTM.post( 'preflight', body ).then( function ( r ) {
				if ( mine !== token ) {
					return;
				}
				result = r;
				draw( r );
			}, function ( err ) {
				if ( mine !== token ) {
					return;
				}
				drawFailure( err );
			} ) );
		}

		function drawFailure( err ) {
			lede.textContent = ledeText( 'failed' );
			ESTM.replace(
				statusBox,
				h( 'div.stack', ui.note( 'err', ESTM.errorMessage( err ), { title: __( 'The checks could not run.', TD ) } ), h( 'div.row', ui.btn( __( 'Try again', TD ), { onClick: run } ), ui.btn( backLabel( plan.back ), { href: backUrl } ) ) )
			);
			ESTM.replace( tilesBox );
			ESTM.replace( listsBox );
			ESTM.replace( safetyBox );
			readyText.textContent = __( 'Start is switched off because the checks could not run. Try them again, or go back.', TD );
			startBtn.disabled = true;
		}

		/* ---- Drawing the result */

		function draw( r ) {
			var c = r.counts || { pass: 0, warn: 0, block: 0 };
			lede.textContent = ledeText( fmt.date( nowGmt(), true ) );
			ESTM.replace( statusBox, statusNote( r, c ) );
			ESTM.replace( tilesBox, tileList( r ) );
			ESTM.replace( listsBox, checkCards( r.checks || [], c ) );
			ESTM.replace( safetyBox, safetyCard( r ) );
			readyState( r, c );
		}

		function statusNote( r, c ) {
			if ( 'blocked' === r.status ) {
				return ui.note( 'err', __( 'Fix what is listed under Problems, then run the checks again. The Start button stays off until nothing is blocked.', TD ), {
					/* translators: %d: number of blocking problems */
					title: sprintf( _n( 'Blocked by %d problem.', 'Blocked by %d problems.', c.block, TD ), c.block ),
				} );
			}
			if ( 'warnings' === r.status ) {
				return ui.note( 'warn', __( 'Nothing blocks this run. Each warning says what Estasoft Migrate will do about it, so you can start now or fix it first.', TD ), {
					/* translators: %d: number of warnings */
					title: sprintf( _n( 'Ready, with %d warning.', 'Ready, with %d warnings.', c.warn, TD ), c.warn ),
				} );
			}
			return okNote( [
				h( 'b', __( 'Ready.', TD ) ),
				' ',
				/* translators: %d: number of checks that passed */
				sprintf( _n( '%d check passed. Nothing blocks this run.', 'All %d checks passed. Nothing blocks this run.', c.pass, TD ), c.pass ),
			] );
		}

		function remoteHost( r ) {
			return hostOf( ( r.summary && r.summary.remote ) || input.remote_url || '' );
		}

		function tileList( r ) {
			var sm = r.summary || {};
			var out = [];
			var tablesLabel = 'import' === mode ? __( 'This site’s database now', TD ) : ( 'search_replace' === mode ? __( 'Tables searched', TD ) : __( 'Tables to copy', TD ) );
			var n = Number( sm.tables ) || 0;
			var rows = Number( sm.rows ) || 0;
			out.push( ui.tile(
				tablesLabel,
				/* translators: %s: number of tables */
				sprintf( _n( '%s table', '%s tables', n, TD ), fmt.number( n ) ),
				/* translators: 1: number of rows, 2: size */
				sprintf( _n( 'About %1$s row · %2$s', 'About %1$s rows · %2$s', rows, TD ), fmt.number( rows ), fmt.bytes( sm.bytes ) )
			) );

			var time = timeRange( sm.seconds );
			out.push( ui.tile( __( 'Estimated time', TD ), time || '–', __( 'A rough guess. Large tables take longer.', TD ), { small: true } ) );

			if ( 'push' === mode || 'pull' === mode ) {
				var addr = ( sm.remote || input.remote_url || '' );
				out.push( ui.tile( __( 'Other site', TD ), hostOf( addr ) || '–', addr || __( 'Not reached', TD ), { small: true, mono: true } ) );
			} else if ( 'export' === mode || 'sanitize' === mode ) {
				/* translators: %s: size, for example 412 MB */
				out.push( ui.tile( __( 'Package size', TD ), sprintf( __( 'About %s', TD ), fmt.bytes( sm.package ) ), __( 'An estimate. The real size depends on the data.', TD ), { small: true } ) );
			} else {
				var on = snapshotOn( r );
				/* translators: %s: size, for example 412 MB */
				out.push( ui.tile( __( 'Rollback snapshot', TD ), on ? sprintf( __( 'About %s', TD ), fmt.bytes( ( r.safety || {} ).snapshot_bytes ) ) : __( 'None', TD ), on ? __( 'Saved before anything changes.', TD ) : __( 'There is nothing to go back to.', TD ), { small: true } ) );
			}
			return out;
		}

		function checkRow( c ) {
			var badge = 'block' === c.status
				? ui.badge( __( 'Blocked', TD ), 'block' )
				: ( 'warn' === c.status ? ui.badge( __( 'Warning', TD ), 'warn' ) : ui.badge( __( 'Passed', TD ), 'ready' ) );
			return h( 'div.check', h( 'div.st', badge ), h( 'div.tx', h( 'b', c.title ), h( 'span', c.detail ) ) );
		}

		function checkCards( checks, c ) {
			var blocks = checks.filter( function ( x ) { return 'block' === x.status; } );
			var warns = checks.filter( function ( x ) { return 'warn' === x.status; } );
			var passes = checks.filter( function ( x ) { return 'block' !== x.status && 'warn' !== x.status; } );
			var cards = [];
			if ( blocks.length ) {
				cards.push( h( 'section.card.flush', h( 'div.card-h', h( 'h2', __( 'Problems', TD ) ), ui.badge( String( blocks.length ), 'block' ) ), blocks.map( checkRow ) ) );
			}
			if ( warns.length ) {
				cards.push( h( 'section.card.flush', h( 'div.card-h', h( 'h2', __( 'Warnings', TD ) ), ui.badge( String( warns.length ), 'warn' ) ), warns.map( checkRow ) ) );
			}
			if ( passes.length ) {
				// With problems or warnings to read, the long list of what is fine starts closed on a small screen.
				var open = ! ( narrow && ( c.block || c.warn ) );
				cards.push( h(
					'details.card.flush.pf-passed',
					{ open: open },
					h( 'summary.card-h', h( 'h2', __( 'Passed', TD ) ), h( 'span.pf-toggle', ui.badge( String( passes.length ), 'ready' ) ) ),
					passes.map( checkRow )
				) );
			}
			return cards;
		}

		function snapshotOn( r ) {
			return !! ( r.safety && r.safety.snapshot ) && ! isOff( input.snapshot );
		}

		function safetyCard( r ) {
			var s = r.safety || {};
			var remote = remoteHost( r );
			var on = snapshotOn( r );
			var size = fmt.bytes( s.snapshot_bytes );
			var kids = [];
			var pairs = [];

			if ( on ) {
				kids.push( h( 'p.sub', 'push' === mode
					/* translators: 1: address of the other site, 2: size, for example 412 MB */
					? sprintf( __( 'A rollback snapshot of %1$s is saved before anything is overwritten. About %2$s.', TD ), remote, size )
					/* translators: %s: size, for example 412 MB */
					: sprintf( __( 'A rollback snapshot of this site is saved before anything is overwritten. About %s.', TD ), size ) ) );
			} else if ( s.snapshot ) {
				kids.push( ui.note( 'warn', __( 'No rollback snapshot will be saved, so this change cannot be undone.', TD ) ) );
			} else {
				kids.push( h( 'p.sub', 'search_replace' === mode
					? __( 'This is a preview. Nothing is changed, so no snapshot is needed.', TD )
					: __( 'Nothing on this site is changed, so no snapshot is needed.', TD ) ) );
			}

			pairs.push( [ __( 'Snapshot', TD ), on ? __( 'Saved first', TD ) : ( s.snapshot ? __( 'Off', TD ) : __( 'Not needed', TD ) ) ] );
			pairs.push( [ __( 'Free space here', TD ), null !== s.free && undefined !== s.free && s.free > 0 ? fmt.bytes( s.free ) : __( 'Unknown', TD ) ] );
			if ( 'push' === mode || 'pull' === mode ) {
				pairs.push( [ __( 'Free space on the other site', TD ), s.remote_free > 0 ? fmt.bytes( s.remote_free ) : __( 'Unknown', TD ) ] );
			}
			if ( on ) {
				pairs.push( [ __( 'Restore', TD ), 'push' === mode ? __( 'One click, from Packages on the other site', TD ) : __( 'One click, from Packages', TD ) ] );
			}
			var dl = h( 'dl.kv', { style: 'margin-top:12px' } );
			pairs.forEach( function ( p ) {
				dl.appendChild( h( 'dt', p[ 0 ] ) );
				dl.appendChild( h( 'dd.break', p[ 1 ] ) );
			} );
			return h( 'section.card.hi', h( 'h2', { style: 'margin-bottom:8px' }, __( 'Safety net', TD ) ), kids, dl );
		}

		function readyState( r, c ) {
			var time = timeRange( ( r.summary || {} ).seconds );
			var title = String( plan.title || '' ).replace( /[.:\s]+$/, '' );
			var text;
			if ( 'blocked' === r.status ) {
				/* translators: 1: what is planned, 2: number of blocked checks */
				text = sprintf( _n( '%1$s. Start is switched off because %2$d check is blocked. Fix it, then run the checks again.', '%1$s. Start is switched off because %2$d checks are blocked. Fix them, then run the checks again.', c.block, TD ), title, c.block );
			} else if ( 'warnings' === r.status ) {
				/* translators: 1: what is planned, 2: number of warnings */
				text = sprintf( _n( '%1$s. The %2$d warning does not stop the run. Start now, or fix it first and run the checks again.', '%1$s. The %2$d warnings do not stop the run. Start now, or fix them first and run the checks again.', c.warn, TD ), title, c.warn );
			} else {
				/* translators: %s: what is planned */
				text = sprintf( __( '%s. Everything checks out.', TD ), title );
			}
			if ( time && 'blocked' !== r.status ) {
				/* translators: %s: estimated time, for example "6 to 8 minutes" */
				text += ' ' + sprintf( __( 'Estimated time: %s.', TD ), time );
			}
			readyText.textContent = text;
			startBtn.disabled = started || 'blocked' === r.status;
		}

		/* ---- Starting */

		/** What a plan replaces, so the person is asked first. Null when it only reads or writes a new package. */
		function replacement() {
			var type = plan.type;
			var noSnap = isOff( input.snapshot );
			var other = hostOf( input.remote_url || '' ) || __( 'the other site', TD );
			var kids = [];
			var title;
			var confirm = __( 'Replace and start', TD );

			if ( 'import' === type ) {
				title = __( 'Replace this site’s database?', TD );
				kids.push( h( 'p', __( 'The database of this site will be replaced by the contents of the package.', TD ) ) );
			} else if ( 'pull' === type ) {
				title = __( 'Replace this site’s database?', TD );
				/* translators: %s: address of the other site */
				kids.push( h( 'p', sprintf( __( 'The database of this site will be replaced by the database of %s.', TD ), other ) ) );
			} else if ( 'push' === type ) {
				/* translators: %s: address of the other site */
				title = sprintf( __( 'Replace the database of %s?', TD ), other );
				/* translators: %s: address of the other site */
				kids.push( h( 'p', sprintf( __( 'The database of %s will be replaced by the database of this site.', TD ), other ) ) );
			} else if ( 'search_replace' === type && isOff( input.dry_run ) ) {
				title = __( 'Change this site’s database?', TD );
				confirm = __( 'Apply changes', TD );
				kids.push( h( 'p', __( 'The replacements are written to the database of this site.', TD ) ) );
			} else {
				return null;
			}

			if ( noSnap ) {
				kids.push( h( 'p', h( 'b', __( 'No rollback snapshot is saved, so this cannot be undone.', TD ) ) ) );
			} else if ( 'push' === type ) {
				/* translators: %s: address of the other site */
				kids.push( h( 'p', sprintf( __( '%s saves a rollback snapshot first, so it can go back from its Packages screen. Nothing on this site is changed.', TD ), other ) ) );
			} else {
				kids.push( h( 'p', __( 'A rollback snapshot of the current database is saved first, so you can go back from Packages.', TD ) ) );
			}
			return { title: title, message: kids, confirm: confirm };
		}

		function onStart() {
			ESTM.replace( startNote );
			var ask = replacement();
			if ( ! ask ) {
				create();
				return;
			}
			ui.confirm( { title: ask.title, message: ask.message, confirm: ask.confirm, danger: true } ).then( function ( yes ) {
				if ( yes ) {
					create();
				}
			} );
		}

		function create() {
			var body = Object.assign( {}, input, { type: plan.type } );
			ui.busy( startBtn, ESTM.post( 'jobs', body ).then( function ( job ) {
				started = true;
				startBtn.disabled = true;
				ESTM.draft.clear( 'plan' );
				ESTM.go( ESTM.url( 'progress', { job: job.id } ) );
			}, showStartError ) );
		}

		function showStartError( err ) {
			var back = ui.btn( backLabel( plan.back ), { size: 'sm', href: backUrl } );
			if ( 'estm_busy' === err.code ) {
				var jobId = err.data && err.data.job_id;
				ESTM.replace( startNote, ui.note( 'warn', [
					err.message,
					jobId ? [ ' ', h( 'a', { href: ESTM.url( 'progress', { job: jobId } ) }, __( 'View its progress', TD ) ) ] : null,
				], { title: __( 'Another job is running.', TD ) } ) );
				return;
			}
			if ( 'estm_password_required' === err.code ) {
				ESTM.replace( startNote, h( 'div.stack.tight', ui.note( 'warn', err.message, { title: __( 'A password is needed.', TD ) } ), h( 'div', back ) ) );
				return;
			}
			ESTM.replace( startNote, h( 'div.stack.tight', ui.note( 'err', err.message, { title: __( 'The job could not be started.', TD ) } ), h( 'div', back ) ) );
		}

		run();
	} );

	/* ---------------------------------------------------------------- No plan */

	function showNoPlan( root, mode ) {
		var targets = {
			'import': { screen: 'packages', label: __( 'Open Packages', TD ), text: __( 'Choose a package on the Packages screen and select Import. The checks run here before anything is replaced.', TD ) },
			search_replace: { screen: 'replace', label: __( 'Open Search & replace', TD ), text: __( 'Enter what to search for on the Search & replace screen. The checks run here before anything is changed.', TD ) },
			sanitize: { screen: 'sanitizer', label: __( 'Open Sanitizer', TD ), text: __( 'Choose a profile on the Sanitizer screen and make a sanitized copy. The checks run here first.', TD ) },
			push: { screen: 'new', params: { method: 'push' }, label: __( 'Plan a push', TD ), text: __( 'Enter the other site and the options on the New migration screen. The checks run here first.', TD ) },
			pull: { screen: 'new', params: { method: 'pull' }, label: __( 'Plan a pull', TD ), text: __( 'Enter the other site and the options on the New migration screen. The checks run here first.', TD ) },
		};
		var target = targets[ mode ] || { screen: 'new', label: __( 'Start a new migration', TD ), text: __( 'A pre-flight check looks at a planned migration before anything runs. Plan one on the New migration screen and you arrive here with the result.', TD ) };
		ESTM.replace(
			root,
			ui.pageHead( __( 'Pre-flight check', TD ), __( 'The last look before anything runs. Nothing is changed by a check.', TD ) ),
			ui.note( '', target.text, { title: __( 'Nothing to check yet.', TD ) } ),
			h(
				'div.row',
				ui.btn( target.label, { kind: 'primary', href: ESTM.url( target.screen, target.params ) } ),
				ui.btn( __( 'Back to overview', TD ), { href: ESTM.url( 'overview' ) } )
			)
		);
	}
}() );
