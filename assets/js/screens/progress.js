/**
 * Progress screen: watches one job from start to finish and drives it while the page is open.
 * Data: GET jobs/{id}, POST jobs/{id}/tick (through ESTM.watchJob), POST jobs/{id}/pause|resume|cancel|retry,
 * GET overview and GET jobs (only to find a job when the address has no ?job=), GET settings (which runner is used),
 * POST jobs (verify a package after an export).
 *
 * The page is built once. Updates only change text, attributes and table rows in place, so nothing the person is
 * pointing at or tabbing through is ever re-created while the job runs.
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

	ESTM.screen( 'progress', function ( root ) {
		var wanted = parseInt( ESTM.params().job, 10 ) || 0;

		function begin() {
			ESTM.replace(
				root,
				ui.pageHead( __( 'Progress', 'estasoft-migrate' ), __( 'Loading the job…', 'estasoft-migrate' ) ),
				h( 'section.card', ui.skeleton( 4 ) )
			);
			findJob( wanted ).then( function ( id ) {
				if ( ! id ) {
					showNoJob();
					return null;
				}
				return Promise.all( [
					ESTM.get( 'jobs/' + id ),
					ESTM.get( 'settings' ).then( function ( s ) { return s; }, function () { return null; } ),
				] ).then( function ( r ) {
					if ( ! wanted ) {
						rememberAddress( id );
					}
					mount( root, r[ 0 ], r[ 1 ] );
				} );
			} ).then( null, function ( err ) {
				if ( err && 404 === err.status ) {
					showGone();
				} else {
					ESTM.replace(
						root,
						ui.pageHead( __( 'Progress', 'estasoft-migrate' ) ),
						ui.loadError( err, begin )
					);
				}
			} );
		}

		function showNoJob() {
			ESTM.replace(
				root,
				ui.pageHead( __( 'Progress', 'estasoft-migrate' ), __( 'Live progress of the job that is running now.', 'estasoft-migrate' ) ),
				h(
					'section.card',
					ui.empty(
						__( 'No job to show', 'estasoft-migrate' ),
						__( 'When you start a migration, an export, a sanitized copy or a search and replace, you can follow it here.', 'estasoft-migrate' ),
						h( 'div', { style: 'margin-top:16px' }, ui.btn( __( 'Start a migration', 'estasoft-migrate' ), { kind: 'primary', href: ESTM.url( 'new' ) } ) )
					)
				)
			);
		}

		function showGone() {
			ESTM.replace(
				root,
				ui.pageHead( __( 'Progress', 'estasoft-migrate' ) ),
				h(
					'section.card',
					ui.empty(
						__( 'That job no longer exists', 'estasoft-migrate' ),
						__( 'It may have been removed. Open the logs to see what ran before, or start a new job.', 'estasoft-migrate' ),
						h(
							'div.row',
							{ style: 'margin-top:16px;justify-content:center' },
							ui.btn( __( 'Open logs', 'estasoft-migrate' ), { href: ESTM.url( 'logs' ) } ),
							ui.btn( __( 'New migration', 'estasoft-migrate' ), { kind: 'primary', href: ESTM.url( 'new' ) } )
						)
					)
				)
			);
		}

		begin();
	} );

	/** The job to show: the one in the address, else the running one, else the latest. 0 when there is none. */
	function findJob( wanted ) {
		if ( wanted ) {
			return Promise.resolve( wanted );
		}
		return ESTM.get( 'overview' ).then( function ( o ) {
			if ( o && o.current && o.current.id ) {
				return o.current.id;
			}
			return ESTM.get( 'jobs', { limit: 1 } ).then( function ( r ) {
				return r.jobs && r.jobs.length ? r.jobs[ 0 ].id : 0;
			} );
		} );
	}

	/** Put the job in the address, so a reload shows the same job. */
	function rememberAddress( id ) {
		try {
			window.history.replaceState( null, '', ESTM.url( 'progress', { job: id } ) );
		} catch ( e ) {
			return;
		}
	}

	/* ---------------------------------------------------------------- Small helpers */

	function setText( el, text ) {
		text = null === text || undefined === text ? '' : String( text );
		if ( el.textContent !== text ) {
			el.textContent = text;
		}
	}

	function hostOf( url ) {
		return String( url || '' ).replace( /^https?:\/\//i, '' ).replace( /\/+$/, '' );
	}

	function remoteOf( v ) {
		return hostOf( v.remote || ( v.params && v.params.remote_url ) || '' );
	}

	/** Percent as text. Never shows 100% before the job is really done. */
	function pctText( p, final ) {
		p = Number( p ) || 0;
		if ( final || p >= 100 ) {
			return fmt.number( 100 ) + '%';
		}
		return fmt.number( p < 10 ? Math.floor( p * 10 ) / 10 : Math.floor( p ) ) + '%';
	}

	function heading( v ) {
		var host = remoteOf( v );
		if ( host && 'push' === v.type ) {
			/* translators: %s: address of the other site */
			return sprintf( __( 'Push to %s', 'estasoft-migrate' ), host );
		}
		if ( host && 'pull' === v.type ) {
			/* translators: %s: address of the other site */
			return sprintf( __( 'Pull from %s', 'estasoft-migrate' ), host );
		}
		return v.title || ESTM.jobLabel( v );
	}

	function profileName( id ) {
		switch ( id ) {
			case 'staging': return __( 'Staging', 'estasoft-migrate' );
			case 'dev': return __( 'Developer handoff', 'estasoft-migrate' );
			case 'gdpr': return __( 'GDPR-safe copy', 'estasoft-migrate' );
		}
		return String( id || '' );
	}

	function ruleName( id ) {
		switch ( id ) {
			case 'names': return __( 'Names', 'estasoft-migrate' );
			case 'emails': return __( 'Email addresses', 'estasoft-migrate' );
			case 'phones': return __( 'Phone numbers', 'estasoft-migrate' );
			case 'addr': return __( 'Street addresses', 'estasoft-migrate' );
			case 'ips': return __( 'IP addresses', 'estasoft-migrate' );
			case 'pw': return __( 'Passwords and sessions', 'estasoft-migrate' );
			case 'secrets': return __( 'Keys and secrets', 'estasoft-migrate' );
			case 'notes': return __( 'Free text', 'estasoft-migrate' );
		}
		return String( id );
	}

	function isMigration( v ) {
		return 'push' === v.type || 'pull' === v.type || 'import' === v.type;
	}

	/* ---------------------------------------------------------------- The screen */

	function mount( root, first, settings ) {
		var jobId = first.id;
		var leaveOk = ESTM.canLeave( settings && settings.environment ? settings.environment : null );
		var last = null;
		var watcher = null;
		var wantStatus = '';
		var wantAt = 0;
		var sig = {};
		var milestone = 0;
		var shownResult = '';
		var tableRows = [];
		var tableNames = '';
		var tableFollow = true;
		var logCount = 0;
		var pending = '';
		var pendingTries = 0;
		var settle = null;
		var ticking = true;

		/* Head */
		var title = h( 'h1', { tabindex: '-1' } );
		var lede = h( 'p.lede' );
		var toggleBtn = ui.btn( __( 'Pause', 'estasoft-migrate' ), { kind: 'dark', onClick: onToggle } );
		var cancelBtn = ui.btn( __( 'Cancel job', 'estasoft-migrate' ), { kind: 'danger', onClick: onCancel } );
		var logBtn = ui.btn( __( 'Open log', 'estasoft-migrate' ), { href: ESTM.url( 'logs', { job: jobId } ) } );
		var actions = h( 'div.row', toggleBtn, cancelBtn, logBtn );
		var head = h( 'div.est-pagehead', h( 'div', title, lede ), actions );

		/* Status line for screen readers: state changes only, never the percent ticking */
		var status = h( 'div.sr-only', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' } );

		/* Overall progress */
		var badgeSlot = h( 'span' );
		var pct = h( 'span.num.prg-pct', '0%' );
		var eta = h( 'span.sub' );
		var bar = ui.bar( 0, { large: true, label: __( 'Overall progress', 'estasoft-migrate' ) } );
		var message = h( 'p.sub.prg-msg' );
		var stepsBox = h( 'div.prg-steps' );
		var stat = {
			rows: statItem( __( 'Rows copied', 'estasoft-migrate' ) ),
			elapsed: statItem( __( 'Elapsed', 'estasoft-migrate' ) ),
			speed: statItem( __( 'Speed', 'estasoft-migrate' ) ),
			batch: statItem( __( 'Batch size', 'estasoft-migrate' ) ),
		};
		var progressCard = h(
			'section.card.hi',
			h( 'div.card-h', h( 'h2', __( 'Overall progress', 'estasoft-migrate' ) ), badgeSlot ),
			h( 'div.row.prg-big', pct, eta ),
			bar,
			message,
			stepsBox,
			h( 'dl.kv.left.prg-kv', stat.rows.wrap, stat.elapsed.wrap, stat.speed.wrap, stat.batch.wrap )
		);

		/* Slots that are filled and emptied as the job changes */
		var connSlot = h( 'div', { hidden: true } );
		var resultSlot = h( 'div', { hidden: true } );
		var alertSlot = h( 'div.stack', { hidden: true } );

		/* Tables */
		var tablesSub = h( 'span.sub' );
		var tbody = h( 'tbody', { role: 'rowgroup' } );
		var matchesTh = h( 'th', { scope: 'col', role: 'columnheader', class: 'n', hidden: true }, __( 'Matches', 'estasoft-migrate' ) );
		var tablesScroll = h(
			'div.scroll.prg-tables',
			{ tabindex: '0', role: 'region', 'aria-label': __( 'Tables', 'estasoft-migrate' ) },
			h(
				'table.tbl',
				{ role: 'table' },
				h(
					'thead',
					{ role: 'rowgroup' },
					h(
						'tr',
						{ role: 'row' },
						h( 'th', { scope: 'col', role: 'columnheader' }, __( 'Table', 'estasoft-migrate' ) ),
						h( 'th', { scope: 'col', role: 'columnheader', class: 'n' }, __( 'Rows', 'estasoft-migrate' ) ),
						h( 'th', { scope: 'col', role: 'columnheader', style: 'min-width:170px' }, __( 'Progress', 'estasoft-migrate' ) ),
						matchesTh,
						h( 'th', { scope: 'col', role: 'columnheader' }, __( 'Status', 'estasoft-migrate' ) )
					)
				),
				tbody
			)
		);
		[ 'wheel', 'touchstart', 'pointerdown', 'keydown' ].forEach( function ( name ) {
			tablesScroll.addEventListener( name, function () { tableFollow = false; }, { passive: true } );
		} );
		var tablesNote = h( 'div.card-f', { hidden: true } );
		var tablesCard = h(
			'section.card.flush',
			{ hidden: true },
			h( 'div.card-h', h( 'h2', __( 'Tables', 'estasoft-migrate' ) ), tablesSub ),
			tablesScroll,
			tablesNote
		);

		/* Live log */
		var logBox = h( 'div.code.log.prg-log', { role: 'log', 'aria-live': 'off', 'aria-label': __( 'Live log', 'estasoft-migrate' ), tabindex: '0' } );
		var logEmpty = h( 'div.dim', __( 'Waiting for the first log line…', 'estasoft-migrate' ) );
		logBox.appendChild( logEmpty );
		var jumpBtn = ui.btn( __( 'Jump to latest', 'estasoft-migrate' ), { size: 'sm', onClick: function () {
			logBox.scrollTop = logBox.scrollHeight;
			jumpBtn.hidden = true;
		} } );
		jumpBtn.hidden = true;
		logBox.addEventListener( 'scroll', function () {
			if ( atBottom( logBox ) ) {
				jumpBtn.hidden = true;
			}
		} );
		var logCard = h(
			'section.card.dark',
			h(
				'div.card-h',
				h( 'h2', __( 'Live log', 'estasoft-migrate' ) ),
				h( 'a.sub', { href: ESTM.url( 'logs', { job: jobId } ), style: 'color:var(--yellow)' }, __( 'Open full log', 'estasoft-migrate' ) )
			),
			logBox,
			h( 'div', { style: 'margin-top:10px' }, jumpBtn )
		);

		/* What protects the data */
		var protectList = h( 'ul.stack', { style: 'gap:10px;font-size:13.5px' } );
		var protectCard = h( 'section.card', h( 'h2', { style: 'margin-bottom:8px' }, __( 'How this run is protected', 'estasoft-migrate' ) ), protectList );

		ESTM.replace(
			root,
			head,
			status,
			h(
				'div.cols',
				{ 'aria-live': 'off' },
				h( 'div.main.stack', connSlot, resultSlot, progressCard, alertSlot, tablesCard ),
				h( 'div.side.stack', logCard, protectCard )
			)
		);

		/* ------------------------------------------------------------ Drawing */

		/** Fill a slot and hide it when it is empty, so an empty slot does not leave a gap in the column. */
		function fill( slot, nodes ) {
			ESTM.replace( slot, nodes );
			slot.hidden = ! slot.firstChild;
		}

		function statItem( label ) {
			var dd = h( 'dd.num' );
			return { wrap: h( 'div', h( 'dt', label ), dd ), dd: dd, label: label };
		}

		function announce( text ) {
			status.textContent = '';
			setTimeout( function () { status.textContent = text; }, 50 );
		}

		function atBottom( el ) {
			return el.scrollHeight - el.scrollTop - el.clientHeight < 32;
		}

		function ledeText( v ) {
			var kind = ESTM.jobLabel( v );
			var when = fmt.date( v.started || v.created );
			switch ( v.status ) {
				case 'completed':
					/* translators: 1: kind of job, 2: date and time, 3: how long it took */
					return sprintf( __( '%1$s · Finished %2$s · took %3$s.', 'estasoft-migrate' ), kind, fmt.date( v.finished || v.updated ), fmt.duration( v.stats.elapsed ) );
				case 'failed':
					/* translators: 1: kind of job, 2: date and time */
					return sprintf( __( '%1$s · Stopped %2$s because of an error.', 'estasoft-migrate' ), kind, fmt.date( v.finished || v.updated ) );
				case 'cancelled':
					/* translators: 1: kind of job, 2: date and time */
					return sprintf( __( '%1$s · Cancelled %2$s.', 'estasoft-migrate' ), kind, fmt.date( v.finished || v.updated ) );
			}
			var tail;
			if ( leaveOk ) {
				tail = __( 'You can leave this page. The job keeps running and resumes from the last saved position if the server restarts.', 'estasoft-migrate' );
			} else {
				tail = __( 'Keep this page open and visible: on this server the job only moves forward while it is open. If you leave, it stays where it is and carries on when you come back.', 'estasoft-migrate' );
			}
			if ( ! v.started ) {
				/* translators: 1: kind of job, 2: date and time, 3: what to expect when leaving the page */
				return sprintf( __( '%1$s · Created %2$s. %3$s', 'estasoft-migrate' ), kind, when, tail );
			}
			/* translators: 1: kind of job, 2: date and time, 3: what to expect when leaving the page */
			return sprintf( __( '%1$s · Started %2$s. %3$s', 'estasoft-migrate' ), kind, when, tail );
		}

		function drawHead( v ) {
			var ae = document.activeElement;
			setText( title, heading( v ) );
			setText( lede, ledeText( v ) );

			var canToggle = v.can.pause || v.can.resume;
			toggleBtn.hidden = ! canToggle;
			setText( toggleBtn, v.can.resume ? __( 'Resume', 'estasoft-migrate' ) : __( 'Pause', 'estasoft-migrate' ) );
			cancelBtn.hidden = ! v.can.cancel;
			setText( cancelBtn, isMigration( v ) ? __( 'Cancel migration', 'estasoft-migrate' ) : __( 'Cancel job', 'estasoft-migrate' ) );
			logBtn.hidden = !! v.can.cancel;

			// A button that disappears must not take the keyboard position with it.
			if ( ae && actions.contains( ae ) && ae.hidden ) {
				title.focus();
			}
		}

		function etaText( v ) {
			if ( pending && ESTM.isActive( v.status ) ) {
				return 'pause' === pending ?
					__( 'Pausing: waiting for the current batch to finish…', 'estasoft-migrate' ) :
					__( 'Cancelling: waiting for the current batch to finish…', 'estasoft-migrate' );
			}
			switch ( v.status ) {
				case 'queued':
					return __( 'Waiting to start…', 'estasoft-migrate' );
				case 'running':
					if ( null !== v.stats.eta && undefined !== v.stats.eta ) {
						/* translators: %s: time left, for example 2 min 35 s */
						return sprintf( __( 'About %s left', 'estasoft-migrate' ), fmt.duration( v.stats.eta ) );
					}
					return __( 'Estimating the time left…', 'estasoft-migrate' );
				case 'paused':
					return __( 'Paused. It continues from the last saved position when you resume.', 'estasoft-migrate' );
				case 'completed':
					return v.dry_run ? __( 'Finished. Nothing was written.', 'estasoft-migrate' ) : __( 'Finished.', 'estasoft-migrate' );
				case 'failed':
					return __( 'Stopped. The problem is explained below.', 'estasoft-migrate' );
				case 'cancelled':
					return __( 'Cancelled before it finished.', 'estasoft-migrate' );
			}
			return '';
		}

		function drawProgress( v ) {
			var s = v.stats;
			var live = 'running' === v.status || 'queued' === v.status;
			setText( pct, pctText( v.progress, 'completed' === v.status ) );
			ui.setBar( bar, 'completed' === v.status ? 100 : v.progress, { live: live, err: 'failed' === v.status } );
			setText( eta, etaText( v ) );
			var msg = ESTM.isFinished( v.status ) || v.warning ? '' : v.message;
			setText( message, msg );
			message.hidden = ! msg;
			progressCard.classList.toggle( 'hi', ! ( 'completed' === v.status && v.result ) );

			var badgeSig = v.status + ( v.dry_run ? 'd' : '' );
			if ( sig.badge !== badgeSig ) {
				sig.badge = badgeSig;
				ESTM.replace( badgeSlot, ESTM.jobBadge( v ) );
			}

			var showRows = s.rows_total > 0 || s.rows_done > 0;
			stat.rows.wrap.hidden = ! showRows;
			stat.speed.wrap.hidden = ! showRows;
			setText( stat.rows.wrap.firstChild, 'search_replace' === v.type ? __( 'Rows scanned', 'estasoft-migrate' ) : __( 'Rows copied', 'estasoft-migrate' ) );
			// The totals are estimates from the database, so a finished job shows what was really handled.
			setText(
				stat.rows.dd,
				'completed' === v.status ?
					fmt.number( s.rows_done ) :
					sprintf( /* translators: 1: how many so far or chosen, 2: how many there are in total */ __( '%1$s of %2$s', 'estasoft-migrate' ), fmt.number( s.rows_done ), fmt.number( Math.max( s.rows_total, s.rows_done ) ) )
			);
			setText( stat.elapsed.dd, s.elapsed > 0 || 'completed' === v.status ? fmt.duration( s.elapsed ) : '–' );
			setText(
				stat.speed.dd,
				s.rows_per_sec > 0 ?
					/* translators: %s: number of rows */
					sprintf( _n( '%s row per second', '%s rows per second', s.rows_per_sec, 'estasoft-migrate' ), fmt.number( s.rows_per_sec ) ) :
					'–'
			);
			stat.batch.wrap.hidden = ! ( s.batch > 0 );
			setText(
				stat.batch.dd,
				/* translators: %s: number of rows */
				sprintf( _n( '%s row, set automatically', '%s rows, set automatically', s.batch, 'estasoft-migrate' ), fmt.number( s.batch ) )
			);
		}

		function drawSteps( v ) {
			var steps = v.steps || [];
			var key = JSON.stringify( [ steps, v.status ] );
			if ( sig.steps === key ) {
				return;
			}
			sig.steps = key;
			stepsBox.hidden = ! steps.length;
			if ( ! steps.length ) {
				ESTM.replace( stepsBox );
				return;
			}
			var stopped = 'running' !== v.status && 'queued' !== v.status && 'completed' !== v.status;
			ESTM.replace(
				stepsBox,
				h(
					'ol.steps',
					{ 'aria-label': __( 'Steps', 'estasoft-migrate' ) },
					steps.map( function ( st, i ) {
						var cls = 'done' === st.status ? 'done' : ( 'current' === st.status ? 'cur' : 'pending' );
						var hint = null;
						if ( 'done' === st.status ) {
							hint = h( 'span.sr-only', ' ' + __( '(done)', 'estasoft-migrate' ) );
						} else if ( 'pending' === st.status ) {
							hint = h( 'span.sr-only', ' ' + __( '(waiting)', 'estasoft-migrate' ) );
						} else if ( stopped ) {
							hint = h( 'span.sub', ' · ' + ( 'paused' === v.status ? __( 'paused here', 'estasoft-migrate' ) : __( 'stopped here', 'estasoft-migrate' ) ) );
						}
						return h(
							'li',
							{ class: cls, 'aria-current': 'current' === st.status ? 'step' : null },
							h( 'span.n', { 'aria-hidden': 'true' }, 'done' === st.status ? '✓' : String( i + 1 ) ),
							h( 'span', st.label, hint )
						);
					} )
				)
			);
		}

		/* Tables ----------------------------------------------------- */

		function tableBadge( t, v ) {
			if ( 'running' === t.status ) {
				if ( 'paused' === v.status ) {
					return ui.badge( __( 'Paused', 'estasoft-migrate' ), 'pause' );
				}
				if ( 'failed' === v.status ) {
					return ui.badge( __( 'Stopped', 'estasoft-migrate' ), 'block' );
				}
				if ( 'cancelled' === v.status ) {
					return ui.badge( __( 'Stopped', 'estasoft-migrate' ), 'idle' );
				}
				return v.warning ? ui.badge( __( 'Retrying', 'estasoft-migrate' ), 'warn' ) : ui.badge( __( 'Running', 'estasoft-migrate' ), 'run' );
			}
			if ( 'done' === t.status ) {
				return ui.badge( __( 'Done', 'estasoft-migrate' ), 'ready' );
			}
			if ( 'skipped' === t.status ) {
				return ui.badge( __( 'Skipped', 'estasoft-migrate' ), 'idle' );
			}
			return ui.badge( __( 'Waiting', 'estasoft-migrate' ), 'idle' );
		}

		function makeRow( name ) {
			var rowsTd = h( 'td.n', { role: 'cell' } );
			var barEl = ui.bar( 0, { label: name } );
			var pctEl = h( 'span.num.prg-tpct' );
			var matchTd = h( 'td.n', { role: 'cell', hidden: true, dataset: { label: __( 'Matches', 'estasoft-migrate' ) } } );
			var statusTd = h( 'td', { role: 'cell' } );
			var tr = h( 'tr', { role: 'row' }, h( 'td.mono.break', { role: 'cell' }, name ), rowsTd, h( 'td', { role: 'cell' }, h( 'div.row.prg-tbar', barEl, pctEl ) ), matchTd, statusTd );
			var rowSig = '';
			return {
				tr: tr,
				matchTd: matchTd,
				update: function ( t, v, withMatches ) {
					var key = [ t.status, t.done, t.total, t.matches, v.status, v.warning ? 1 : 0, withMatches ? 1 : 0 ].join( '|' );
					if ( key === rowSig ) {
						return;
					}
					rowSig = key;
					var done = 'done' === t.status;
					var p = done ? 100 : ( t.total > 0 ? Math.min( 99, t.done / t.total * 100 ) : 0 );
					tr.classList.toggle( 'is-running', 'running' === t.status && ( 'running' === v.status || 'queued' === v.status ) );
					setText( rowsTd, done ?
						fmt.number( t.done ) :
						( ! ( t.done > 0 ) ? fmt.number( t.total ) :
						sprintf( /* translators: 1: how many so far or chosen, 2: how many there are in total */ __( '%1$s of %2$s', 'estasoft-migrate' ), fmt.number( t.done ), fmt.number( t.total ) ) )
					);
					ui.setBar( barEl, p, { live: 'running' === t.status && ( 'running' === v.status || 'queued' === v.status ) } );
					setText( pctEl, pctText( p, done ) );
					matchTd.hidden = ! withMatches;
					setText( matchTd, withMatches ? fmt.number( t.matches || 0 ) : '' );
					ESTM.replace( statusTd, tableBadge( t, v ) );
				},
			};
		}

		function drawTables( v ) {
			var list = v.tables || [];
			tablesCard.hidden = ! list.length;
			if ( ! list.length ) {
				return;
			}
			var withMatches = 'number' === typeof list[ 0 ].matches;
			matchesTh.hidden = ! withMatches;
			var names = list.map( function ( t ) { return t.name; } ).join( '\n' );
			if ( names !== tableNames ) {
				tableNames = names;
				tableRows = list.map( function ( t ) { return makeRow( t.name ); } );
				ESTM.replace( tbody, tableRows.map( function ( r ) { return r.tr; } ) );
			}
			var runningAt = -1;
			list.forEach( function ( t, i ) {
				tableRows[ i ].update( t, v, withMatches );
				if ( 'running' === t.status && runningAt < 0 ) {
					runningAt = i;
				}
			} );

			var c = v.stats.tables || {};
			/* translators: 1: tables done, 2: tables running, 3: tables waiting */
			setText( tablesSub, sprintf( __( '%1$s done · %2$s running · %3$s waiting', 'estasoft-migrate' ), fmt.number( c.done || 0 ), fmt.number( c.running || 0 ), fmt.number( c.waiting || 0 ) ) );

			var more = ( v.tables_total || list.length ) > list.length;
			tablesNote.hidden = ! more;
			if ( more ) {
				/* translators: 1: tables shown, 2: tables in total */
				setText( tablesNote, sprintf( __( 'Showing the first %1$s of %2$s tables.', 'estasoft-migrate' ), fmt.number( list.length ), fmt.number( v.tables_total ) ) );
			}

			// Keep the table that is being worked on in view, until the person scrolls the list themselves.
			if ( tableFollow && runningAt >= 0 ) {
				var tr = tableRows[ runningAt ].tr;
				var top = tr.offsetTop;
				if ( top < tablesScroll.scrollTop + 40 || top + tr.offsetHeight > tablesScroll.scrollTop + tablesScroll.clientHeight ) {
					tablesScroll.scrollTop = Math.max( 0, top - tablesScroll.clientHeight / 2 );
				}
			} else if ( tableFollow && runningAt < 0 && ESTM.isFinished( v.status ) && tablesScroll.scrollTop > 0 ) {
				// Nothing is being worked on any more: show the list from its first table.
				tablesScroll.scrollTop = 0;
			}
		}

		/* Notes ------------------------------------------------------ */

		function logLink() {
			return ui.btn( __( 'View log', 'estasoft-migrate' ), { size: 'sm', kind: 'quiet', href: ESTM.url( 'logs', { job: jobId } ) } );
		}

		function errorNote( v ) {
			var e = v.error;
			var d = e.details;
			var lines = [];
			if ( Array.isArray( d ) ) {
				d.forEach( function ( item ) {
					if ( 'string' === typeof item && item ) {
						lines.push( h( 'p', { style: 'margin-top:4px' }, item ) );
					}
				} );
			} else if ( d && 'object' === typeof d ) {
				if ( d.untouched ) {
					lines.push( h( 'p', { style: 'margin-top:4px' }, h( 'b', __( 'What was not changed:', 'estasoft-migrate' ) ), ' ', String( d.untouched ) ) );
				}
				if ( d.advice ) {
					lines.push( h( 'p', { style: 'margin-top:4px' }, h( 'b', __( 'What to do:', 'estasoft-migrate' ) ), ' ', String( d.advice ) ) );
				}
			}
			var buttons = [];
			if ( v.can.retry ) {
				buttons.push( ui.btn( __( 'Retry', 'estasoft-migrate' ), { size: 'sm', kind: 'danger', onClick: onRetry } ) );
			}
			buttons.push( logLink() );
			return ui.note( 'err', [ lines, h( 'div.row', { style: 'margin-top:10px' }, buttons ) ], { title: e.message || v.message || __( 'The job stopped because of an error.', 'estasoft-migrate' ) } );
		}

		function cancelledText( v ) {
			var p = v.params || {};
			if ( 'search_replace' === v.type ) {
				if ( v.dry_run ) {
					return __( 'Nothing was written, so there is nothing to undo.', 'estasoft-migrate' );
				}
				return p.snapshot ?
					__( 'Rows that were already changed stay changed. The rest were not touched, and the rollback snapshot is kept in Packages.', 'estasoft-migrate' ) :
					__( 'Rows that were already changed stay changed. The rest were not touched.', 'estasoft-migrate' );
			}
			if ( 'export' === v.type ) {
				return __( 'The unfinished package was removed. Your site was not changed.', 'estasoft-migrate' );
			}
			if ( 'verify' === v.type ) {
				return __( 'The package was only read, so nothing was changed.', 'estasoft-migrate' );
			}
			return __( 'Temporary tables and unfinished files were removed. Nothing was half-applied.', 'estasoft-migrate' );
		}

		function drawNotes( v ) {
			var key = [
				v.warning ? 'w' + v.warning.attempt : '',
				v.error ? 'e' + v.error.message + JSON.stringify( v.error.details ) + ( v.can.retry ? 'r' : '' ) : '',
				'paused' === v.status ? 'p' : '',
				'cancelled' === v.status ? 'c' : '',
			].join( '|' );
			if ( sig.notes === key ) {
				return;
			}
			sig.notes = key;
			var nodes = [];
			if ( v.warning ) {
				nodes.push( ui.note(
					'warn',
					[
						sprintf(
							/* translators: 1: attempt number, 2: most attempts */
							__( 'Trying again automatically (attempt %1$d of %2$d). Nothing is lost: the job continues from its last saved position.', 'estasoft-migrate' ),
							v.warning.attempt,
							v.warning.max
						),
						' ',
						h( 'a', { href: ESTM.url( 'logs', { job: jobId } ) }, __( 'See the details in the log', 'estasoft-migrate' ) ),
					],
					{ title: __( 'A temporary problem interrupted the job.', 'estasoft-migrate' ) }
				) );
			}
			if ( v.error ) {
				nodes.push( errorNote( v ) );
			}
			if ( 'paused' === v.status ) {
				nodes.push( ui.note( 'tint', __( 'Nothing is running now. Resume continues from the last saved position, and finished batches are not repeated.', 'estasoft-migrate' ), { title: __( 'This job is paused.', 'estasoft-migrate' ) } ) );
			}
			if ( 'cancelled' === v.status ) {
				nodes.push( ui.note( '', [ cancelledText( v ), ' ', h( 'a', { href: ESTM.url( 'new' ) }, __( 'Start a new migration', 'estasoft-migrate' ) ) ], { title: __( 'This job was cancelled.', 'estasoft-migrate' ) } ) );
			}
			fill( alertSlot, nodes );
		}

		/* Result ----------------------------------------------------- */

		function drawResult( v ) {
			if ( 'completed' !== v.status || ! v.result ) {
				if ( shownResult ) {
					shownResult = '';
					fill( resultSlot );
				}
				return;
			}
			var key = 'r' + v.id + ( v.finished || '' );
			if ( shownResult === key ) {
				return;
			}
			shownResult = key;
			fill( resultSlot, resultCard( v ) );
		}

		function kvList( pairs ) {
			return h(
				'dl.kv.left.res-kv',
				pairs.filter( Boolean ).map( function ( p ) {
					return h( 'div', h( 'dt', p[ 0 ] ), h( 'dd.num', p[ 1 ] ) );
				} )
			);
		}

		function notesList( notes ) {
			if ( ! notes || ! notes.length ) {
				return null;
			}
			return ui.note(
				'',
				h( 'ul.stack.tight', notes.map( function ( n ) { return h( 'li', String( n ) ); } ) ),
				{ title: _n( 'Note', 'Notes', notes.length, 'estasoft-migrate' ) }
			);
		}

		function protectionLabel( p ) {
			if ( 'password' === p ) {
				return __( 'Encrypted with a password', 'estasoft-migrate' );
			}
			if ( 'site-key' === p ) {
				return __( 'Encrypted with this site’s key', 'estasoft-migrate' );
			}
			return __( 'Not encrypted', 'estasoft-migrate' );
		}

		function snapshotBlock( snapshot, v ) {
			if ( snapshot ) {
				return ui.note(
					'tint',
					[
						__( 'Your previous database was saved before anything changed. If something looks wrong, you can go back to it.', 'estasoft-migrate' ),
						h( 'div.row', { style: 'margin-top:10px' }, ui.btn( __( 'Restore previous state', 'estasoft-migrate' ), { size: 'sm', href: ESTM.url( 'packages' ) } ) ),
					],
					{ title: __( 'A rollback snapshot was saved.', 'estasoft-migrate' ) }
				);
			}
			if ( v.params && 'restore' === v.params.kind ) {
				return ui.note( 'tint', __( 'Restoring a snapshot does not save another one. To go back again, restore a different snapshot from Packages.', 'estasoft-migrate' ), { title: __( 'The state before this restore was not saved.', 'estasoft-migrate' ) } );
			}
			if ( v.params && false === v.params.snapshot ) {
				return ui.note( 'warn', __( 'Rollback snapshots were turned off for this run, so the previous state cannot be restored automatically.', 'estasoft-migrate' ), { title: __( 'No rollback snapshot was saved.', 'estasoft-migrate' ) } );
			}
			return null;
		}

		function resultCard( v ) {
			var r = v.result;
			var title;
			var body = [];
			var buttons = [];

			switch ( v.type ) {
				case 'export':
					title = r.sanitized ? __( 'Sanitized package ready', 'estasoft-migrate' ) : __( 'Package ready', 'estasoft-migrate' );
					body.push( kvList( [
						[ __( 'Package', 'estasoft-migrate' ), h( 'span.mono.break', r.package ) ],
						[ __( 'Size', 'estasoft-migrate' ), fmt.bytes( r.size ) ],
						[ __( 'Rows', 'estasoft-migrate' ), fmt.number( r.rows ) ],
						[ __( 'Tables', 'estasoft-migrate' ), fmt.number( r.tables ) ],
						[ __( 'Protection', 'estasoft-migrate' ), protectionLabel( r.protection ) ],
					] ) );
					if ( r.sanitized ) {
						body.push( maskedBlock( r.masked ) );
					}
					body.push( notesList( r.notes ) );
					if ( r.package ) {
						buttons.push( ui.btn( __( 'Download package', 'estasoft-migrate' ), { kind: 'primary', href: ESTM.downloadUrl( 'package', r.package ), download: true } ) );
						buttons.push( ui.btn( __( 'Verify package', 'estasoft-migrate' ), { onClick: function ( ev, el ) { verify( r.package, el ); } } ) );
					}
					buttons.push( ui.btn( __( 'Open Packages', 'estasoft-migrate' ), { kind: 'quiet', href: ESTM.url( 'packages' ) } ) );
					break;

				case 'import':
					title = r.restore ? __( 'Previous state restored', 'estasoft-migrate' ) : __( 'Import finished', 'estasoft-migrate' );
					body.push( kvList( [
						[ __( 'Tables', 'estasoft-migrate' ), fmt.number( r.tables ) ],
						[ __( 'Rows', 'estasoft-migrate' ), fmt.number( r.rows ) ],
					] ) );
					body.push( snapshotBlock( r.snapshot, v ) );
					body.push( notesList( r.notes ) );
					buttons.push( ui.btn( __( 'Open Packages', 'estasoft-migrate' ), { href: ESTM.url( 'packages' ) } ) );
					break;

				case 'pull':
					title = __( 'Pull finished', 'estasoft-migrate' );
					body.push( kvList( [
						[ __( 'Pulled from', 'estasoft-migrate' ), h( 'span.break', hostOf( r.remote ) ) ],
						[ __( 'Tables', 'estasoft-migrate' ), fmt.number( r.tables ) ],
						[ __( 'Rows', 'estasoft-migrate' ), fmt.number( r.rows ) ],
						r.size ? [ __( 'Package size', 'estasoft-migrate' ), fmt.bytes( r.size ) ] : null,
					] ) );
					body.push( snapshotBlock( r.snapshot, v ) );
					body.push( notesList( r.notes ) );
					buttons.push( ui.btn( __( 'Open Packages', 'estasoft-migrate' ), { href: ESTM.url( 'packages' ) } ) );
					break;

				case 'push':
					title = __( 'Push finished', 'estasoft-migrate' );
					body.push( kvList( [
						[ __( 'Sent to', 'estasoft-migrate' ), h( 'span.break', hostOf( r.remote ) ) ],
						[ __( 'Tables', 'estasoft-migrate' ), fmt.number( r.tables ) ],
						[ __( 'Rows', 'estasoft-migrate' ), fmt.number( r.rows ) ],
						r.size ? [ __( 'Package size', 'estasoft-migrate' ), fmt.bytes( r.size ) ] : null,
					] ) );
					body.push( ui.note(
						'tint',
						v.params && false === v.params.snapshot ?
							__( 'This site was only read. Nothing here changed. The other site did not save a rollback snapshot, because it was turned off for this run.', 'estasoft-migrate' ) :
							__( 'This site was only read. Nothing here changed. The other site saved a rollback snapshot of what it had before, which it can restore from its Packages screen.', 'estasoft-migrate' ),
						{ title: __( 'The other site now has this site’s data.', 'estasoft-migrate' ) }
					) );
					body.push( notesList( r.notes ) );
					break;

				case 'search_replace':
					title = r.dry_run ? __( 'Dry run finished', 'estasoft-migrate' ) : __( 'Search and replace finished', 'estasoft-migrate' );
					var t = r.totals || {};
					if ( r.dry_run ) {
						body.push( ui.note( 'tint', __( 'This was a preview. Your data was only read, and the numbers below show what a real run would change.', 'estasoft-migrate' ), { title: __( 'Nothing was written.', 'estasoft-migrate' ) } ) );
					}
					body.push( kvList( [
						[ __( 'Rows scanned', 'estasoft-migrate' ), fmt.number( t.scanned || 0 ) ],
						[ r.dry_run ? __( 'Rows that would change', 'estasoft-migrate' ) : __( 'Rows changed', 'estasoft-migrate' ), fmt.number( t.changed || 0 ) ],
						[ __( 'Matches', 'estasoft-migrate' ), fmt.number( t.matches || 0 ) ],
						[ __( 'Inside serialized or JSON data', 'estasoft-migrate' ), fmt.number( t.nested || 0 ) ],
					] ) );
					if ( ! r.dry_run ) {
						body.push( snapshotBlock( r.snapshot, v ) );
					}
					body.push( notesList( r.notes ) );
					buttons.push( ui.btn( r.dry_run ? __( 'Review the matches', 'estasoft-migrate' ) : __( 'Open search & replace', 'estasoft-migrate' ), { kind: r.dry_run ? 'primary' : null, href: ESTM.url( 'replace', { job: v.id } ) } ) );
					break;

				case 'verify':
					title = __( 'Package checked', 'estasoft-migrate' );
					body.push( ui.note( 'tint', __( 'Every block was read and matched its checksum.', 'estasoft-migrate' ), { title: __( 'The package is complete and undamaged.', 'estasoft-migrate' ) } ) );
					body.push( kvList( [
						[ __( 'Package', 'estasoft-migrate' ), h( 'span.mono.break', r.package ) ],
						[ __( 'Blocks checked', 'estasoft-migrate' ), fmt.number( r.blocks ) ],
						[ __( 'Size', 'estasoft-migrate' ), fmt.bytes( r.size ) ],
					] ) );
					buttons.push( ui.btn( __( 'Open Packages', 'estasoft-migrate' ), { href: ESTM.url( 'packages' ) } ) );
					break;

				default:
					title = __( 'Finished', 'estasoft-migrate' );
			}

			return h(
				'section.card.hi.res',
				h( 'div.card-h', h( 'h2', title ), ESTM.jobBadge( v ) ),
				h( 'div.stack', body, buttons.length ? h( 'div.row', buttons ) : null )
			);
		}

		function maskedBlock( masked ) {
			var ids = masked && ! Array.isArray( masked ) ? Object.keys( masked ) : [];
			if ( ! ids.length ) {
				return ui.note( '', __( 'The sanitizer found nothing to mask with the rules you chose.', 'estasoft-migrate' ), { title: __( 'Nothing was masked.', 'estasoft-migrate' ) } );
			}
			return h(
				'div',
				h( 'h3', { style: 'margin-bottom:4px' }, __( 'What was masked', 'estasoft-migrate' ) ),
				h( 'p.sub', { style: 'margin-bottom:10px' }, __( 'These values were replaced with fake ones in the package. Your live data was not changed.', 'estasoft-migrate' ) ),
				h(
					'div.chips',
					ids.map( function ( id ) {
						return h( 'span.chip', ruleName( id ), h( 'b.num', fmt.number( masked[ id ] ) ) );
					} )
				)
			);
		}

		function verify( packageId, button ) {
			ui.busy(
				button,
				ESTM.post( 'jobs', { type: 'verify', package_id: packageId } ).then( function ( job ) {
					ESTM.go( ESTM.url( 'progress', { job: job.id } ) );
				}, function ( err ) {
					ui.toast( ESTM.errorMessage( err ), 'err' );
				} )
			);
		}

		/* What protects the data -------------------------------------- */

		function drawProtection( v ) {
			var p = v.params || {};
			var snapshot = false !== p.snapshot;
			var items = [];
			var packagesLink = h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Packages', 'estasoft-migrate' ) );

			function add( headline, text ) {
				items.push( h( 'li', h( 'b', headline ), ' ', h( 'span.muted', text ) ) );
			}
			function resumes() {
				add( __( 'Resumes, not restarts.', 'estasoft-migrate' ), __( 'Progress is saved after every batch. After an interruption the job continues from there and finished batches are not repeated.', 'estasoft-migrate' ) );
			}
			function rollback( where ) {
				if ( snapshot ) {
					if ( 'here' === where ) {
						add( __( 'Rollback first.', 'estasoft-migrate' ), [ __( 'Your current tables are saved as a snapshot before anything changes. You can restore it from', 'estasoft-migrate' ), ' ', packagesLink, '.' ] );
					} else {
						add( __( 'Rollback on the other site.', 'estasoft-migrate' ), __( 'It saves a snapshot of its own tables before importing, so it can go back.', 'estasoft-migrate' ) );
					}
				} else if ( 'here' === where ) {
					add( __( 'No rollback snapshot.', 'estasoft-migrate' ), __( 'It was turned off for this run, so the previous state cannot be restored automatically.', 'estasoft-migrate' ) );
				} else {
					add( __( 'No rollback snapshot.', 'estasoft-migrate' ), __( 'It was turned off for this run, so the other site cannot restore its previous state automatically.', 'estasoft-migrate' ) );
				}
			}
			function sanitizing( text ) {
				if ( p.sanitize ) {
					add( __( 'Personal data is masked.', 'estasoft-migrate' ), sprintf( text, profileName( p.sanitize.profile ) ) );
				}
			}

			switch ( v.type ) {
				case 'export':
					add( __( 'Your site is only read.', 'estasoft-migrate' ), __( 'Exporting copies data into a package and never changes your database.', 'estasoft-migrate' ) );
					/* translators: %s: name of the sanitizer profile */
					sanitizing( __( 'The “%s” profile replaces personal data in the package. Your live data is not changed.', 'estasoft-migrate' ) );
					if ( p.passphrase_enc_set ) {
						add( __( 'Encrypted package.', 'estasoft-migrate' ), __( 'The package is encrypted with the password you chose. Without it the package cannot be opened.', 'estasoft-migrate' ) );
					} else if ( p.protect_with_site_key ) {
						add( __( 'Encrypted package.', 'estasoft-migrate' ), __( 'The package is encrypted with this site’s key.', 'estasoft-migrate' ) );
					}
					add( __( 'Short steps.', 'estasoft-migrate' ), __( 'Work is split into batches that fit your server’s time limit, so a slow server does not stop the job halfway.', 'estasoft-migrate' ) );
					resumes();
					add( __( 'Checksum saved.', 'estasoft-migrate' ), __( 'A checksum is stored with the package, so you can verify it later.', 'estasoft-migrate' ) );
					break;

				case 'import':
					add( __( 'The package is checked first.', 'estasoft-migrate' ), __( 'It is read and verified for damage before anything on your site changes.', 'estasoft-migrate' ) );
					rollback( 'here' );
					add( __( 'Imported into temporary tables.', 'estasoft-migrate' ), __( 'Your live tables are replaced only after every row has been staged, in one quick switch at the end.', 'estasoft-migrate' ) );
					resumes();
					break;

				case 'search_replace':
					if ( p.dry_run ) {
						add( __( 'Nothing is written.', 'estasoft-migrate' ), __( 'A dry run only reads your data and counts what would change.', 'estasoft-migrate' ) );
					} else {
						rollback( 'here' );
					}
					add( __( 'Serialized and JSON data stay valid.', 'estasoft-migrate' ), __( 'Text inside serialized values and JSON is replaced together with its length markers.', 'estasoft-migrate' ) );
					resumes();
					break;

				case 'push':
					add( __( 'Your site is only read.', 'estasoft-migrate' ), __( 'Nothing on this site changes. Data is copied into a package and sent to the other site.', 'estasoft-migrate' ) );
					/* translators: %s: name of the sanitizer profile */
					sanitizing( __( 'The “%s” profile is applied before the data leaves this site.', 'estasoft-migrate' ) );
					rollback( 'there' );
					add( __( 'Switched at the end.', 'estasoft-migrate' ), __( 'The other site imports into temporary tables and replaces its live tables only when everything has arrived.', 'estasoft-migrate' ) );
					resumes();
					break;

				case 'pull':
					add( __( 'The other site is only read.', 'estasoft-migrate' ), __( 'It builds a package of its data. Nothing on that site changes.', 'estasoft-migrate' ) );
					/* translators: %s: name of the sanitizer profile */
					sanitizing( __( 'The “%s” profile is applied on the other site, before any data leaves it.', 'estasoft-migrate' ) );
					rollback( 'here' );
					add( __( 'Imported into temporary tables.', 'estasoft-migrate' ), __( 'Your live tables are replaced only after every row has been staged, in one quick switch at the end.', 'estasoft-migrate' ) );
					resumes();
					break;

				case 'verify':
					add( __( 'Nothing is changed.', 'estasoft-migrate' ), __( 'The package is only read and checked against its checksums.', 'estasoft-migrate' ) );
					break;

				default:
					resumes();
			}
			ESTM.replace( protectList, items );
		}

		/* Log -------------------------------------------------------- */

		/** 24-hour time with seconds: short, and the same in every language. */
		function logTime( gmt ) {
			var d = new Date( String( gmt || '' ).replace( ' ', 'T' ) + 'Z' );
			if ( isNaN( d.getTime() ) ) {
				return '';
			}
			try {
				var opts = { hourCycle: 'h23', hour: '2-digit', minute: '2-digit', second: '2-digit' };
				if ( ESTM.timezone ) {
					opts.timeZone = ESTM.timezone;
				}
				return new Intl.DateTimeFormat( 'en-GB', opts ).format( d );
			} catch ( e ) {
				return fmt.time( gmt );
			}
		}

		function logLine( line ) {
			var level = line.level || 'info';
			var tag = null;
			if ( 'error' === level ) {
				tag = h( 'b', __( 'Error:', 'estasoft-migrate' ) + ' ' );
			} else if ( 'warning' === level ) {
				tag = h( 'b', __( 'Warning:', 'estasoft-migrate' ) + ' ' );
			}
			return h( 'div', { class: 'lv-' + level }, h( 'span.t', logTime( line.created_gmt ) ), h( 'span.msg', tag, String( line.message ) ) );
		}

		function addLogs( lines ) {
			var follow = atBottom( logBox );
			if ( logEmpty.parentNode ) {
				logBox.removeChild( logEmpty );
			}
			lines.forEach( function ( l ) {
				logBox.appendChild( logLine( l ) );
				logCount += 1;
			} );
			while ( logCount > 400 ) {
				logBox.removeChild( logBox.firstChild );
				logCount -= 1;
			}
			if ( follow ) {
				logBox.scrollTop = logBox.scrollHeight;
			} else {
				jumpBtn.hidden = false;
			}
		}

		/* Connection ------------------------------------------------- */

		function showConnection( err ) {
			var key = ( err.code || '' ) + ( err.status || '' );
			if ( sig.conn === key ) {
				return;
			}
			sig.conn = key;
			if ( 'rest_cookie_invalid_nonce' === err.code ) {
				fill( connSlot, ui.note( 'err', [ ESTM.errorMessage( err ), ' ', h( 'a', { href: window.location.href }, __( 'Reload this page', 'estasoft-migrate' ) ) ], { title: __( 'This page can no longer talk to the server.', 'estasoft-migrate' ) } ) );
			} else if ( 404 === err.status ) {
				fill( connSlot, ui.note( 'err', [ ESTM.errorMessage( err ), ' ', h( 'a', { href: ESTM.url( 'logs' ) }, __( 'Open logs', 'estasoft-migrate' ) ) ], { title: __( 'This job is gone.', 'estasoft-migrate' ) } ) );
			} else if ( 'estm_network' === err.code || ! err.status ) {
				fill( connSlot, ui.note( 'warn', __( 'The job is not affected: it keeps going on the server, and this page keeps trying to reconnect.', 'estasoft-migrate' ), { title: __( 'Connection lost, retrying…', 'estasoft-migrate' ) } ) );
			} else {
				fill( connSlot, ui.note( 'warn', [ ESTM.errorMessage( err ), ' ', __( 'This page keeps trying.', 'estasoft-migrate' ) ], { title: __( 'The server did not answer properly. Retrying…', 'estasoft-migrate' ) } ) );
			}
		}

		function clearConnection() {
			if ( sig.conn ) {
				sig.conn = '';
				fill( connSlot );
			}
		}

		/* Applying a view -------------------------------------------- */

		function transition( prev, v ) {
			if ( ! prev || prev.status === v.status ) {
				if ( 'running' === v.status ) {
					var q = Math.floor( ( Number( v.progress ) || 0 ) / 25 );
					if ( q > milestone && q < 4 ) {
						milestone = q;
						/* translators: %s: percent done */
						announce( sprintf( __( '%s done.', 'estasoft-migrate' ), fmt.number( q * 25 ) + '%' ) );
					}
				}
				return;
			}
			switch ( v.status ) {
				case 'running':
					announce( __( 'The job is running.', 'estasoft-migrate' ) );
					break;
				case 'paused':
					announce( __( 'The job is paused.', 'estasoft-migrate' ) );
					break;
				case 'completed':
					/* translators: %s: name of the job */
					announce( v.dry_run ? sprintf( /* translators: %s: name of the job */ __( '%s finished. Nothing was written.', 'estasoft-migrate' ), heading( v ) ) : sprintf( /* translators: %s: name of the job */ __( '%s finished.', 'estasoft-migrate' ), heading( v ) ) );
					break;
				case 'failed':
					/* translators: %s: error message */
					announce( sprintf( __( 'The job failed. %s', 'estasoft-migrate' ), v.error && v.error.message ? v.error.message : '' ) );
					break;
				case 'cancelled':
					announce( __( 'The job was cancelled.', 'estasoft-migrate' ) );
					break;
			}
		}

		function apply( v ) {
			var prev = last;
			last = v;
			drawHead( v );
			drawProgress( v );
			drawSteps( v );
			drawTables( v );
			drawNotes( v );
			drawResult( v );
			if ( logEmpty.parentNode ) {
				setText( logEmpty, ESTM.isFinished( v.status ) ? __( 'This job has no log lines.', 'estasoft-migrate' ) : __( 'Waiting for the first log line…', 'estasoft-migrate' ) );
			}
			transition( prev, v );
		}

		/** A view that arrives late must not undo a pause, resume or cancel that was just confirmed. */
		function isStale( v ) {
			return wantStatus && Date.now() - wantAt < 8000 && v.status !== wantStatus && ! ESTM.isFinished( v.status ) && ! ( 'queued' === v.status && 'running' === wantStatus );
		}

		/* ------------------------------------------------------------ Actions */

		function failed( err ) {
			ui.toast( ESTM.errorMessage( err ), 'err' );
		}

		function resume() {
			ticking = true;
			watcher.poke();
		}

		/** Pause, resume, cancel or retry, sent right now. */
		function command( name ) {
			return ESTM.post( 'jobs/' + jobId + '/' + name ).then( function ( view ) {
				wantStatus = view.status;
				wantAt = Date.now();
				clearConnection();
				apply( view );
				resume();
				return view;
			}, failed );
		}

		/*
		 * Pause and cancel are written to the job while a slice may be running in another request, and a running slice saves
		 * its own copy of the job after every batch, which would overwrite them. So they are sent in the gap between two
		 * slices: when a tick has just come back, the watcher is held and the command goes out before the next tick starts.
		 */
		function request( name, button, other ) {
			if ( ! ticking ) {
				ui.busy( button, command( name ) );
				return;
			}
			pending = name;
			pendingTries = 0;
			other.disabled = true;
			ui.busy( button, new Promise( function ( resolve ) { settle = resolve; } ) );
			setText( eta, etaText( last ) );
			announce( 'pause' === name ? __( 'Pausing after the current batch.', 'estasoft-migrate' ) : __( 'Cancelling after the current batch.', 'estasoft-migrate' ) );
		}

		function endPending() {
			pending = '';
			toggleBtn.disabled = false;
			cancelBtn.disabled = false;
			if ( settle ) {
				settle();
				settle = null;
			}
			if ( last ) {
				setText( eta, etaText( last ) );
			}
		}

		function sendPending() {
			pendingTries += 1;
			if ( pendingTries > 6 ) {
				endPending();
				ui.toast( __( 'The job did not react. Try again in a moment.', 'estasoft-migrate' ), 'err' );
				resume();
				return;
			}
			watcher.stop();
			ticking = false;
			ESTM.post( 'jobs/' + jobId + '/' + pending ).then( function ( view ) {
				wantStatus = view.status;
				wantAt = Date.now();
				apply( view );
				resume();
			}, function ( err ) {
				endPending();
				failed( err );
				resume();
			} );
		}

		/** After a tick: is it time to send what the person asked for? */
		function gate( v ) {
			if ( ESTM.isFinished( v.status ) || ( 'pause' === pending && 'paused' === v.status ) ) {
				endPending();
			} else if ( ! v.locked && 'paused' !== v.status ) {
				sendPending();
			}
		}

		function onToggle() {
			if ( ! last || pending ) {
				return;
			}
			if ( last.can.resume ) {
				ui.busy( toggleBtn, command( 'resume' ) );
				return;
			}
			request( 'pause', toggleBtn, cancelBtn );
		}

		function onRetry( ev, el ) {
			ui.busy( el, command( 'retry' ) );
		}

		function cancelMessage( v ) {
			var p = v.params || {};
			if ( 'search_replace' === v.type ) {
				if ( v.dry_run ) {
					return __( 'The job stops after the current batch. This is a dry run, so nothing was written and there is nothing to undo.', 'estasoft-migrate' );
				}
				return p.snapshot ?
					__( 'The job stops after the current batch. Rows that were already changed stay changed, and the rest are left untouched. The rollback snapshot is kept in Packages.', 'estasoft-migrate' ) :
					__( 'The job stops after the current batch. Rows that were already changed stay changed, and the rest are left untouched.', 'estasoft-migrate' );
			}
			if ( 'export' === v.type ) {
				return __( 'The job stops after the current batch. The unfinished package is deleted. Your site is not changed.', 'estasoft-migrate' );
			}
			if ( 'verify' === v.type ) {
				return __( 'The check stops. The package was only read, so nothing is changed.', 'estasoft-migrate' );
			}
			return __( 'The job stops after the current batch. Temporary tables and unfinished files are removed, and nothing is half-applied. Finished work cannot be continued after a cancel.', 'estasoft-migrate' );
		}

		function onCancel() {
			if ( ! last || ! last.can.cancel ) {
				return;
			}
			if ( pending ) {
				return;
			}
			ui.confirm( {
				title: __( 'Cancel this job?', 'estasoft-migrate' ),
				message: cancelMessage( last ),
				confirm: isMigration( last ) ? __( 'Cancel migration', 'estasoft-migrate' ) : __( 'Cancel job', 'estasoft-migrate' ),
				cancel: __( 'Keep it running', 'estasoft-migrate' ),
				danger: true,
			} ).then( function ( yes ) {
				if ( yes && last && last.can.cancel && ! pending ) {
					request( 'cancel', cancelBtn, toggleBtn );
				}
			} );
		}

		/* ------------------------------------------------------------ Start */

		milestone = Math.min( 3, Math.floor( ( Number( first.progress ) || 0 ) / 25 ) );
		drawProtection( first );
		apply( first );

		watcher = ESTM.watchJob( jobId, {
			onUpdate: function ( v ) {
				clearConnection();
				if ( isStale( v ) ) {
					return;
				}
				if ( wantStatus && v.status === wantStatus ) {
					wantStatus = '';
				}
				apply( v );
				if ( pending ) {
					gate( v );
				}
			},
			onLogs: addLogs,
			onDone: function ( v ) {
				ticking = false;
				if ( isStale( v ) ) {
					// The view that stopped the watcher is older than the person's last action: look again.
					setTimeout( resume, 400 );
					return;
				}
				wantStatus = '';
				if ( pending ) {
					if ( ESTM.isFinished( v.status ) || 'pause' === pending ) {
						endPending();
					} else {
						sendPending();
					}
				}
			},
			onError: showConnection,
		} );

		window.addEventListener( 'pagehide', function () {
			if ( watcher ) {
				watcher.stop();
			}
		} );
	}
}() );
