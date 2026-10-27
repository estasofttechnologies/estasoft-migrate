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
			return sprintf( _n( '%1$d to %2$d second', '%1$d to %2$d seconds', hi, 'estasoft-migrate' ), lo, hi );
		}
		var a = Math.max( 1, Math.round( lo / 60 ) );
		var b = Math.max( a, Math.round( hi / 60 ) );
		if ( b < 90 ) {
			if ( a === b ) {
				/* translators: %d: minutes */
				return sprintf( _n( 'About %d minute', 'About %d minutes', b, 'estasoft-migrate' ), b );
			}
			/* translators: 1: shortest time in minutes, 2: longest time in minutes */
			return sprintf( _n( '%1$d to %2$d minute', '%1$d to %2$d minutes', b, 'estasoft-migrate' ), a, b );
		}
		/* translators: 1: shortest time, 2: longest time, each with its unit */
		return sprintf( __( '%1$s to %2$s', 'estasoft-migrate' ), fmt.duration( lo ), fmt.duration( hi ) );
	}

	function backLabel( screen ) {
		var labels = {
			'new': __( 'Back to options', 'estasoft-migrate' ),
			packages: __( 'Back to packages', 'estasoft-migrate' ),
			replace: __( 'Back to search & replace', 'estasoft-migrate' ),
			sanitizer: __( 'Back to sanitizer', 'estasoft-migrate' ),
			overview: __( 'Back to overview', 'estasoft-migrate' ),
		};
		return labels[ screen ] || __( 'Back', 'estasoft-migrate' );
	}

	/** Words on the Start button. */
	function startLabel( plan ) {
		switch ( plan.mode ) {
			case 'import':
				return __( 'Start import', 'estasoft-migrate' );
			case 'search_replace':
				return isOff( plan.input.dry_run ) ? __( 'Apply changes', 'estasoft-migrate' ) : __( 'Run preview', 'estasoft-migrate' );
			case 'export':
			case 'sanitize':
			case 'push':
			case 'pull':
				return __( 'Start migration', 'estasoft-migrate' );
		}
		return __( 'Start', 'estasoft-migrate' );
	}

	/** What the plan does, in plain words, when the screen that made it gave no lines. */
	function defaultLines( plan ) {
		var snap = ! isOff( plan.input.snapshot );
		switch ( plan.mode ) {
			case 'export':
				return [ __( 'The tables of this site are saved in one package file on this site.', 'estasoft-migrate' ), __( 'Nothing on this site is changed.', 'estasoft-migrate' ) ];
			case 'sanitize':
				return [ __( 'A sanitized copy of this site’s database is saved as a package file.', 'estasoft-migrate' ), __( 'Nothing on this site is changed.', 'estasoft-migrate' ) ];
			case 'import':
				return [ __( 'The package is read and its tables replace the tables of this site.', 'estasoft-migrate' ), snap ? __( 'A rollback snapshot of this site is saved first.', 'estasoft-migrate' ) : __( 'No rollback snapshot is saved.', 'estasoft-migrate' ) ];
			case 'search_replace':
				return isOff( plan.input.dry_run )
					? [ __( 'The text is changed in this site’s database.', 'estasoft-migrate' ), snap ? __( 'A rollback snapshot of this site is saved first.', 'estasoft-migrate' ) : __( 'No rollback snapshot is saved.', 'estasoft-migrate' ) ]
					: [ __( 'The database is searched and the changes are listed. Nothing is changed.', 'estasoft-migrate' ) ];
			case 'push':
				return [ __( 'This site’s database is sent to the other site, which replaces its own.', 'estasoft-migrate' ) ];
			case 'pull':
				return [ __( 'The database of the other site is copied here and replaces this site’s database.', 'estasoft-migrate' ) ];
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
					title: __( 'Export package', 'estasoft-migrate' ),
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

		var rerun = ui.btn( __( 'Run checks again', 'estasoft-migrate' ), { onClick: function () { run(); } } );
		var pageHead = ui.pageHead( __( 'Pre-flight check', 'estasoft-migrate' ), '', [ rerun ] );
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
			h( 'h2', { style: 'margin-bottom:8px' }, __( 'Ready to run?', 'estasoft-migrate' ) ),
			readyText,
			startNote,
			h( 'div.stack', { style: 'gap:10px' }, startBtn, ui.btn( backLabel( plan.back ), { href: backUrl } ) )
		);

		var steps = 'new' === plan.back
			? [ __( 'Method', 'estasoft-migrate' ), __( 'Destination and options', 'estasoft-migrate' ), __( 'Pre-flight check', 'estasoft-migrate' ), __( 'Run', 'estasoft-migrate' ) ]
			: [ __( 'Choose', 'estasoft-migrate' ), __( 'Pre-flight check', 'estasoft-migrate' ), __( 'Run', 'estasoft-migrate' ) ];
		var current = steps.length - 2;
		var stepsEl = h( 'ol.steps', { 'aria-label': __( 'Progress', 'estasoft-migrate' ) }, steps.map( function ( label, i ) {
			var cls = i < current ? 'done' : ( i === current ? 'cur' : '' );
			return h( 'li', { class: cls, 'aria-current': i === current ? 'step' : null }, h( 'span.n', { 'aria-hidden': 'true' }, i < current ? '✓' : String( i + 1 ) ), label );
		} ) );

		var planCard = lines.length
			? ui.card( { title: __( 'What will happen', 'estasoft-migrate' ) }, h( 'ul.checklist.stack', { style: 'gap:10px;font-size:13.5px' }, lines.map( function ( l ) { return h( 'li', l ); } ) ) )
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
			var title = String( plan.title || __( 'Pre-flight check', 'estasoft-migrate' ) ).replace( /[.:\s]+$/, '' );
			if ( 'failed' === when ) {
				/* translators: %s: what is planned, for example "Push to staging.example.com" */
				return sprintf( __( '%s. The checks could not run.', 'estasoft-migrate' ), title );
			}
			if ( ! when ) {
				/* translators: %s: what is planned, for example "Push to staging.example.com" */
				return sprintf( __( '%s. Checking now.', 'estasoft-migrate' ), title );
			}
			/* translators: 1: what is planned, for example "Push to staging.example.com", 2: date and time */
			return sprintf( __( '%1$s. Checked %2$s.', 'estasoft-migrate' ), title, when );
		}

		function setLoading() {
			result = null;
			lede.textContent = ledeText( '' );
			ESTM.replace(
				statusBox,
				h(
					'div.note',
					{ role: 'status' },
					h( 'span', { style: 'flex:none;padding-top:1px' }, ui.spinner( __( 'Checking', 'estasoft-migrate' ) ) ),
					h( 'div', h( 'b', __( 'Checking…', 'estasoft-migrate' ) ), ' ', 'push' === mode || 'pull' === mode ? __( 'This site and the other site are being checked. Nothing is changed.', 'estasoft-migrate' ) : __( 'This takes a few seconds. Nothing is changed.', 'estasoft-migrate' ) )
				)
			);
			ESTM.replace( tilesBox, [ 1, 2, 3 ].map( function () { return h( 'div.tile', ui.skeleton( 2 ) ); } ) );
			ESTM.replace( listsBox, h( 'section.card', ui.skeleton( 5 ) ) );
			ESTM.replace( safetyBox, h( 'section.card.hi', ui.skeleton( 3 ) ) );
			readyText.textContent = __( 'The checks are running. Start is available when they are done.', 'estasoft-migrate' );
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
				h( 'div.stack', ui.note( 'err', ESTM.errorMessage( err ), { title: __( 'The checks could not run.', 'estasoft-migrate' ) } ), h( 'div.row', ui.btn( __( 'Try again', 'estasoft-migrate' ), { onClick: run } ), ui.btn( backLabel( plan.back ), { href: backUrl } ) ) )
			);
			ESTM.replace( tilesBox );
			ESTM.replace( listsBox );
			ESTM.replace( safetyBox );
			readyText.textContent = __( 'Start is switched off because the checks could not run. Try them again, or go back.', 'estasoft-migrate' );
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
				return ui.note( 'err', __( 'Fix what is listed under Problems, then run the checks again. The Start button stays off until nothing is blocked.', 'estasoft-migrate' ), {
					/* translators: %d: number of blocking problems */
					title: sprintf( _n( 'Blocked by %d problem.', 'Blocked by %d problems.', c.block, 'estasoft-migrate' ), c.block ),
				} );
			}
			if ( 'warnings' === r.status ) {
				return ui.note( 'warn', __( 'Nothing blocks this run. Each warning says what Estasoft Migrate will do about it, so you can start now or fix it first.', 'estasoft-migrate' ), {
					/* translators: %d: number of warnings */
					title: sprintf( _n( 'Ready, with %d warning.', 'Ready, with %d warnings.', c.warn, 'estasoft-migrate' ), c.warn ),
				} );
			}
			return okNote( [
				h( 'b', __( 'Ready.', 'estasoft-migrate' ) ),
				' ',
				/* translators: %d: number of checks that passed */
				sprintf( _n( '%d check passed. Nothing blocks this run.', 'All %d checks passed. Nothing blocks this run.', c.pass, 'estasoft-migrate' ), c.pass ),
			] );
		}

		function remoteHost( r ) {
			return hostOf( ( r.summary && r.summary.remote ) || input.remote_url || '' );
		}

		function tileList( r ) {
			var sm = r.summary || {};
			var out = [];
			var tablesLabel = 'import' === mode ? __( 'This site’s database now', 'estasoft-migrate' ) : ( 'search_replace' === mode ? __( 'Tables searched', 'estasoft-migrate' ) : __( 'Tables to copy', 'estasoft-migrate' ) );
			var n = Number( sm.tables ) || 0;
			var rows = Number( sm.rows ) || 0;
			out.push( ui.tile(
				tablesLabel,
				/* translators: %s: number of tables */
				sprintf( _n( '%s table', '%s tables', n, 'estasoft-migrate' ), fmt.number( n ) ),
				/* translators: 1: number of rows, 2: size */
				sprintf( _n( 'About %1$s row · %2$s', 'About %1$s rows · %2$s', rows, 'estasoft-migrate' ), fmt.number( rows ), fmt.bytes( sm.bytes ) )
			) );

			var time = timeRange( sm.seconds );
			out.push( ui.tile( __( 'Estimated time', 'estasoft-migrate' ), time || '–', __( 'A rough guess. Large tables take longer.', 'estasoft-migrate' ), { small: true } ) );

			if ( 'push' === mode || 'pull' === mode ) {
				var addr = ( sm.remote || input.remote_url || '' );
				out.push( ui.tile( __( 'Other site', 'estasoft-migrate' ), hostOf( addr ) || '–', addr || __( 'Not reached', 'estasoft-migrate' ), { small: true, mono: true } ) );
			} else if ( 'export' === mode || 'sanitize' === mode ) {
				out.push( ui.tile( __( 'Package size', 'estasoft-migrate' ), sprintf( /* translators: %s: size, for example 412 MB */ __( 'About %s', 'estasoft-migrate' ), fmt.bytes( sm.package ) ), __( 'An estimate. The real size depends on the data.', 'estasoft-migrate' ), { small: true } ) );
			} else {
				var on = snapshotOn( r );
				/* translators: %s: size, for example 412 MB */
				out.push( ui.tile( __( 'Rollback snapshot', 'estasoft-migrate' ), on ? sprintf( __( 'About %s', 'estasoft-migrate' ), fmt.bytes( ( r.safety || {} ).snapshot_bytes ) ) : __( 'None', 'estasoft-migrate' ), on ? __( 'Saved before anything changes.', 'estasoft-migrate' ) : __( 'There is nothing to go back to.', 'estasoft-migrate' ), { small: true } ) );
			}
			return out;
		}

		function checkRow( c ) {
			var badge = 'block' === c.status
				? ui.badge( __( 'Blocked', 'estasoft-migrate' ), 'block' )
				: ( 'warn' === c.status ? ui.badge( __( 'Warning', 'estasoft-migrate' ), 'warn' ) : ui.badge( __( 'Passed', 'estasoft-migrate' ), 'ready' ) );
			return h( 'div.check', h( 'div.st', badge ), h( 'div.tx', h( 'b', c.title ), h( 'span', c.detail ) ) );
		}

		function checkCards( checks, c ) {
			var blocks = checks.filter( function ( x ) { return 'block' === x.status; } );
			var warns = checks.filter( function ( x ) { return 'warn' === x.status; } );
			var passes = checks.filter( function ( x ) { return 'block' !== x.status && 'warn' !== x.status; } );
			var cards = [];
			if ( blocks.length ) {
				cards.push( h( 'section.card.flush', h( 'div.card-h', h( 'h2', __( 'Problems', 'estasoft-migrate' ) ), ui.badge( String( blocks.length ), 'block' ) ), blocks.map( checkRow ) ) );
			}
			if ( warns.length ) {
				cards.push( h( 'section.card.flush', h( 'div.card-h', h( 'h2', __( 'Warnings', 'estasoft-migrate' ) ), ui.badge( String( warns.length ), 'warn' ) ), warns.map( checkRow ) ) );
			}
			if ( passes.length ) {
				// With problems or warnings to read, the long list of what is fine starts closed on a small screen.
				var open = ! ( narrow && ( c.block || c.warn ) );
				cards.push( h(
					'details.card.flush.pf-passed',
					{ open: open },
					h( 'summary.card-h', h( 'h2', __( 'Passed', 'estasoft-migrate' ) ), h( 'span.pf-toggle', ui.badge( String( passes.length ), 'ready' ) ) ),
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
					? sprintf( /* translators: 1: address of the other site, 2: size, for example 412 MB */ __( 'A rollback snapshot of %1$s is saved before anything is overwritten. About %2$s.', 'estasoft-migrate' ), remote, size )
					: sprintf( /* translators: %s: size, for example 412 MB */ __( 'A rollback snapshot of this site is saved before anything is overwritten. About %s.', 'estasoft-migrate' ), size ) ) );
			} else if ( s.snapshot ) {
				kids.push( ui.note( 'warn', __( 'No rollback snapshot will be saved, so this change cannot be undone.', 'estasoft-migrate' ) ) );
			} else {
				kids.push( h( 'p.sub', 'search_replace' === mode
					? __( 'This is a preview. Nothing is changed, so no snapshot is needed.', 'estasoft-migrate' )
					: __( 'Nothing on this site is changed, so no snapshot is needed.', 'estasoft-migrate' ) ) );
			}

			pairs.push( [ __( 'Snapshot', 'estasoft-migrate' ), on ? __( 'Saved first', 'estasoft-migrate' ) : ( s.snapshot ? __( 'Off', 'estasoft-migrate' ) : __( 'Not needed', 'estasoft-migrate' ) ) ] );
			pairs.push( [ __( 'Free space here', 'estasoft-migrate' ), null !== s.free && undefined !== s.free && s.free > 0 ? fmt.bytes( s.free ) : __( 'Unknown', 'estasoft-migrate' ) ] );
			if ( 'push' === mode || 'pull' === mode ) {
				pairs.push( [ __( 'Free space on the other site', 'estasoft-migrate' ), s.remote_free > 0 ? fmt.bytes( s.remote_free ) : __( 'Unknown', 'estasoft-migrate' ) ] );
			}
			if ( on ) {
				pairs.push( [ __( 'Restore', 'estasoft-migrate' ), 'push' === mode ? __( 'One click, from Packages on the other site', 'estasoft-migrate' ) : __( 'One click, from Packages', 'estasoft-migrate' ) ] );
			}
			var dl = h( 'dl.kv', { style: 'margin-top:12px' } );
			pairs.forEach( function ( p ) {
				dl.appendChild( h( 'dt', p[ 0 ] ) );
				dl.appendChild( h( 'dd.break', p[ 1 ] ) );
			} );
			return h( 'section.card.hi', h( 'h2', { style: 'margin-bottom:8px' }, __( 'Safety net', 'estasoft-migrate' ) ), kids, dl );
		}

		function readyState( r, c ) {
			var time = timeRange( ( r.summary || {} ).seconds );
			var title = String( plan.title || '' ).replace( /[.:\s]+$/, '' );
			var text;
			if ( 'blocked' === r.status ) {
				/* translators: 1: what is planned, 2: number of blocked checks */
				text = sprintf( _n( '%1$s. Start is switched off because %2$d check is blocked. Fix it, then run the checks again.', '%1$s. Start is switched off because %2$d checks are blocked. Fix them, then run the checks again.', c.block, 'estasoft-migrate' ), title, c.block );
			} else if ( 'warnings' === r.status ) {
				/* translators: 1: what is planned, 2: number of warnings */
				text = sprintf( _n( '%1$s. The %2$d warning does not stop the run. Start now, or fix it first and run the checks again.', '%1$s. The %2$d warnings do not stop the run. Start now, or fix them first and run the checks again.', c.warn, 'estasoft-migrate' ), title, c.warn );
			} else {
				/* translators: %s: what is planned */
				text = sprintf( __( '%s. Everything checks out.', 'estasoft-migrate' ), title );
			}
			if ( time && 'blocked' !== r.status ) {
				/* translators: %s: estimated time, for example "6 to 8 minutes" */
				text += ' ' + sprintf( __( 'Estimated time: %s.', 'estasoft-migrate' ), time );
			}
			readyText.textContent = text;
			startBtn.disabled = started || 'blocked' === r.status;
		}

		/* ---- Starting */

		/** What a plan replaces, so the person is asked first. Null when it only reads or writes a new package. */
		function replacement() {
			var type = plan.type;
			var noSnap = isOff( input.snapshot );
			var other = hostOf( input.remote_url || '' ) || __( 'the other site', 'estasoft-migrate' );
			var kids = [];
			var title;
			var confirm = __( 'Replace and start', 'estasoft-migrate' );

			if ( 'import' === type ) {
				title = __( 'Replace this site’s database?', 'estasoft-migrate' );
				kids.push( h( 'p', __( 'The database of this site will be replaced by the contents of the package.', 'estasoft-migrate' ) ) );
			} else if ( 'pull' === type ) {
				title = __( 'Replace this site’s database?', 'estasoft-migrate' );
				/* translators: %s: address of the other site */
				kids.push( h( 'p', sprintf( __( 'The database of this site will be replaced by the database of %s.', 'estasoft-migrate' ), other ) ) );
			} else if ( 'push' === type ) {
				/* translators: %s: address of the other site */
				title = sprintf( __( 'Replace the database of %s?', 'estasoft-migrate' ), other );
				/* translators: %s: address of the other site */
				kids.push( h( 'p', sprintf( __( 'The database of %s will be replaced by the database of this site.', 'estasoft-migrate' ), other ) ) );
			} else if ( 'search_replace' === type && isOff( input.dry_run ) ) {
				title = __( 'Change this site’s database?', 'estasoft-migrate' );
				confirm = __( 'Apply changes', 'estasoft-migrate' );
				kids.push( h( 'p', __( 'The replacements are written to the database of this site.', 'estasoft-migrate' ) ) );
			} else {
				return null;
			}

			if ( noSnap ) {
				kids.push( h( 'p', h( 'b', __( 'No rollback snapshot is saved, so this cannot be undone.', 'estasoft-migrate' ) ) ) );
			} else if ( 'push' === type ) {
				/* translators: %s: address of the other site */
				kids.push( h( 'p', sprintf( __( '%s saves a rollback snapshot first, so it can go back from its Packages screen. Nothing on this site is changed.', 'estasoft-migrate' ), other ) ) );
			} else {
				kids.push( h( 'p', __( 'A rollback snapshot of the current database is saved first, so you can go back from Packages.', 'estasoft-migrate' ) ) );
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
					jobId ? [ ' ', h( 'a', { href: ESTM.url( 'progress', { job: jobId } ) }, __( 'View its progress', 'estasoft-migrate' ) ) ] : null,
				], { title: __( 'Another job is running.', 'estasoft-migrate' ) } ) );
				return;
			}
			if ( 'estm_password_required' === err.code ) {
				ESTM.replace( startNote, h( 'div.stack.tight', ui.note( 'warn', err.message, { title: __( 'A password is needed.', 'estasoft-migrate' ) } ), h( 'div', back ) ) );
				return;
			}
			ESTM.replace( startNote, h( 'div.stack.tight', ui.note( 'err', err.message, { title: __( 'The job could not be started.', 'estasoft-migrate' ) } ), h( 'div', back ) ) );
		}

		run();
	} );

	/* ---------------------------------------------------------------- No plan */

	function showNoPlan( root, mode ) {
		var targets = {
			'import': { screen: 'packages', label: __( 'Open Packages', 'estasoft-migrate' ), text: __( 'Choose a package on the Packages screen and select Import. The checks run here before anything is replaced.', 'estasoft-migrate' ) },
			search_replace: { screen: 'replace', label: __( 'Open Search & replace', 'estasoft-migrate' ), text: __( 'Enter what to search for on the Search & replace screen. The checks run here before anything is changed.', 'estasoft-migrate' ) },
			sanitize: { screen: 'sanitizer', label: __( 'Open Sanitizer', 'estasoft-migrate' ), text: __( 'Choose a profile on the Sanitizer screen and make a sanitized copy. The checks run here first.', 'estasoft-migrate' ) },
			push: { screen: 'new', params: { method: 'push' }, label: __( 'Plan a push', 'estasoft-migrate' ), text: __( 'Enter the other site and the options on the New migration screen. The checks run here first.', 'estasoft-migrate' ) },
			pull: { screen: 'new', params: { method: 'pull' }, label: __( 'Plan a pull', 'estasoft-migrate' ), text: __( 'Enter the other site and the options on the New migration screen. The checks run here first.', 'estasoft-migrate' ) },
		};
		var target = targets[ mode ] || { screen: 'new', label: __( 'Start a new migration', 'estasoft-migrate' ), text: __( 'A pre-flight check looks at a planned migration before anything runs. Plan one on the New migration screen and you arrive here with the result.', 'estasoft-migrate' ) };
		ESTM.replace(
			root,
			ui.pageHead( __( 'Pre-flight check', 'estasoft-migrate' ), __( 'The last look before anything runs. Nothing is changed by a check.', 'estasoft-migrate' ) ),
			ui.note( '', target.text, { title: __( 'Nothing to check yet.', 'estasoft-migrate' ) } ),
			h(
				'div.row',
				ui.btn( target.label, { kind: 'primary', href: ESTM.url( target.screen, target.params ) } ),
				ui.btn( __( 'Back to overview', 'estasoft-migrate' ), { href: ESTM.url( 'overview' ) } )
			)
		);
	}
}() );
