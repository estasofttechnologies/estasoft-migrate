/**
 * Logs screen: the plain-language log of every job, newest first, with filters, search and an explanation of errors.
 * Data: GET logs (lines and the 50 latest jobs), DELETE logs, GET jobs/{id} (only to explain a failed job whose error
 * line is gone), POST jobs/{id}/retry. The .txt download is a plain link (ESTM.downloadUrl).
 *
 * The filters and the table are built once. A reload replaces only the rows; auto-refresh only adds the new lines at the
 * top, so a row the person has opened stays open and nothing they are using is re-created.
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

	var STEPS = [ 200, 500, 1000 ];
	var REFRESH_MS = 5000;

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

	function levelLabel( level ) {
		switch ( level ) {
			case 'error': return __( 'Error', 'estasoft-migrate' );
			case 'warning': return __( 'Warning', 'estasoft-migrate' );
			case 'debug': return __( 'Debug', 'estasoft-migrate' );
		}
		return __( 'Info', 'estasoft-migrate' );
	}

	function statusWord( status ) {
		switch ( status ) {
			case 'queued': return __( 'queued', 'estasoft-migrate' );
			case 'running': return __( 'running', 'estasoft-migrate' );
			case 'paused': return __( 'paused', 'estasoft-migrate' );
			case 'failed': return __( 'failed', 'estasoft-migrate' );
			case 'cancelled': return __( 'cancelled', 'estasoft-migrate' );
		}
		return '';
	}

	/** A context value as text. Everything the log holds is shown as text, never as markup. */
	function valueText( v ) {
		if ( null === v || undefined === v ) {
			return '–';
		}
		if ( 'object' === typeof v ) {
			try {
				return JSON.stringify( v ).slice( 0, 2000 );
			} catch ( e ) {
				return '';
			}
		}
		return String( v ).slice( 0, 2000 );
	}

	function contextKeys( ctx ) {
		return ctx && 'object' === typeof ctx && ! Array.isArray( ctx ) ? Object.keys( ctx ) : [];
	}

	ESTM.screen( 'logs', function ( root ) {
		var query = ESTM.params();
		var state = {
			job: parseInt( query.job, 10 ) > 0 ? String( parseInt( query.job, 10 ) ) : '',
			level: 'all',
			search: '',
			limit: STEPS[ 0 ],
			auto: false,
			autoByHand: false,
		};
		var jobs = [];
		var lines = [];
		var token = 0;
		var timer = null;
		var loading = false;
		var jobStatus = '';
		var explainToken = 0;

		/* ------------------------------------------------------------ Page */

		var downloadBtn = ui.btn( __( 'Download log (.txt)', 'estasoft-migrate' ), { href: ESTM.downloadUrl( 'log', state.job ), download: true } );
		var clearBtn = ui.btn( __( 'Clear log', 'estasoft-migrate' ), { kind: 'danger', onClick: onClear } );

		var jobSelect = h( 'select.select', { id: 'lg-job', onchange: function () {
			state.job = jobSelect.value;
			state.limit = STEPS[ 0 ];
			jobChanged();
		} } );
		var levelSeg = ui.seg(
			[
				{ value: 'all', label: __( 'All', 'estasoft-migrate' ) },
				{ value: 'error', label: __( 'Errors', 'estasoft-migrate' ) },
				{ value: 'warning', label: __( 'Warnings', 'estasoft-migrate' ) },
				{ value: 'info', label: __( 'Info', 'estasoft-migrate' ) },
			],
			state.level,
			function ( value ) {
				state.level = value;
				state.limit = STEPS[ 0 ];
				reload();
			},
			{ label: __( 'Log level', 'estasoft-migrate' ) }
		);
		var searchInput = ui.input( {
			type: 'search',
			id: 'lg-q',
			placeholder: __( 'Table name or message', 'estasoft-migrate' ),
			onInput: ESTM.debounce( function () {
				var value = searchInput.value.trim();
				if ( value !== state.search ) {
					state.search = value;
					state.limit = STEPS[ 0 ];
					reload();
				}
			}, 350 ),
			onKeydown: function ( ev ) {
				if ( 'Enter' === ev.key ) {
					ev.preventDefault();
				}
			},
		} );
		var autoSwitch = ui.switch( { id: 'lg-auto', onChange: function () {
			state.auto = autoSwitch.checked;
			state.autoByHand = true;
			schedule();
			if ( state.auto ) {
				refresh();
			}
		} } );

		var filters = h(
			'section.card',
			h(
				'div.row',
				{ style: 'align-items:flex-end;gap:14px 16px' },
				h( 'div.field', { style: 'flex:1 1 260px' }, h( 'label', { for: 'lg-job' }, __( 'Job', 'estasoft-migrate' ) ), jobSelect ),
				h( 'div.field', h( 'span.lbl', __( 'Show', 'estasoft-migrate' ) ), levelSeg ),
				h( 'div.field', { style: 'flex:1 1 200px' }, h( 'label', { for: 'lg-q' }, __( 'Search the log', 'estasoft-migrate' ) ), searchInput ),
				h(
					'div.field',
					h( 'span.lbl', __( 'Live updates', 'estasoft-migrate' ) ),
					h( 'label.row', { for: 'lg-auto', style: 'gap:10px;min-height:40px;font-weight:400' }, autoSwitch, h( 'span', __( 'Refresh every 5 seconds', 'estasoft-migrate' ) ) )
				)
			)
		);

		var status = h( 'div.sr-only', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' } );
		var explainSlot = h( 'div', { hidden: true } );

		var cardTitle = h( 'h2', { tabindex: '-1' }, __( 'All jobs', 'estasoft-migrate' ) );
		var hint = h( 'span.sub', { hidden: true }, __( 'Could not refresh. Trying again…', 'estasoft-migrate' ) );
		var cardMeta = h( 'span.sub' );
		var body = h( 'div' );
		var tbody = h( 'tbody', { role: 'rowgroup' } );
		var table = h(
			'div.scroll',
			h(
				'table.tbl.logs-tbl',
				{ role: 'table' },
				h( 'caption.sr-only', __( 'Log lines, newest first', 'estasoft-migrate' ) ),
				h(
					'thead',
					{ role: 'rowgroup' },
					h(
						'tr',
						{ role: 'row' },
						h( 'th', { scope: 'col', role: 'columnheader' }, __( 'Time', 'estasoft-migrate' ) ),
						h( 'th', { scope: 'col', role: 'columnheader' }, __( 'Level', 'estasoft-migrate' ) ),
						h( 'th', { scope: 'col', role: 'columnheader' }, __( 'Job', 'estasoft-migrate' ) ),
						h( 'th', { scope: 'col', role: 'columnheader' }, __( 'Message', 'estasoft-migrate' ) )
					)
				),
				tbody
			)
		);
		var moreBtn = ui.btn( __( 'Show more', 'estasoft-migrate' ), { size: 'sm', onClick: function () {
			var next = STEPS.filter( function ( n ) { return n > state.limit; } )[ 0 ];
			if ( next ) {
				state.limit = next;
				ui.busy( moreBtn, reload() );
			}
		} } );
		moreBtn.hidden = true;
		var limitNote = h( 'span.sub', { hidden: true } );
		var card = h(
			'section.card.flush',
			{ 'aria-live': 'off' },
			h( 'div.card-h', cardTitle, h( 'div.row', hint, cardMeta ) ),
			body,
			h(
				'div.card-f',
				h( 'span.sub.grow', __( 'Logs are kept on this site only and never sent to ESTASOFT TECHNOLOGIES. Passwords, keys and personal data are never written to a log.', 'estasoft-migrate' ) ),
				limitNote,
				moreBtn
			)
		);

		ESTM.replace(
			root,
			ui.pageHead(
				__( 'Logs', 'estasoft-migrate' ),
				__( 'Every job writes a plain-language log. Errors say what happened, what was not changed and what to do next.', 'estasoft-migrate' ),
				[ downloadBtn, clearBtn ]
			),
			filters,
			status,
			explainSlot,
			card
		);
		ESTM.replace( body, h( 'div', { style: 'padding:20px' }, ui.skeleton( 4 ) ) );

		/* ------------------------------------------------------------ Loading */

		function baseQuery() {
			var q = { limit: state.limit };
			if ( state.job ) {
				q.job_id = state.job;
			}
			if ( 'error' === state.level ) {
				q.levels = 'error';
			} else if ( 'warning' === state.level ) {
				q.levels = 'warning';
			} else if ( 'info' === state.level ) {
				q.levels = 'info,debug';
			}
			if ( state.search ) {
				q.search = state.search;
			}
			return q;
		}

		function filtered() {
			return 'all' !== state.level || '' !== state.search;
		}

		/** Full load with the current filters. Returns the promise. */
		function reload() {
			var mine = ++token;
			loading = true;
			card.setAttribute( 'aria-busy', 'true' );
			return ESTM.get( 'logs', baseQuery() ).then( function ( data ) {
				if ( mine !== token ) {
					return;
				}
				loading = false;
				card.removeAttribute( 'aria-busy' );
				lines = data.lines || [];
				takeJobs( data.jobs || [] );
				drawList();
				defaultAuto();
				loadExplanation();
			}, function ( err ) {
				if ( mine !== token ) {
					return;
				}
				loading = false;
				card.removeAttribute( 'aria-busy' );
				ESTM.replace( body, h( 'div', { style: 'padding:20px' }, ui.loadError( err, reload ) ) );
				moreBtn.hidden = true;
				limitNote.hidden = true;
				setText( cardMeta, '' );
			} );
		}

		/** Add the lines that arrived since the last load, without touching the rows already shown. */
		function refresh() {
			if ( loading || document.hidden ) {
				return Promise.resolve();
			}
			if ( ! lines.length ) {
				return reload();
			}
			var mine = token;
			var q = baseQuery();
			q.after_id = lines[ 0 ].id;
			loading = true;
			return ESTM.get( 'logs', q ).then( function ( data ) {
				loading = false;
				if ( mine !== token ) {
					return;
				}
				var changed = takeJobs( data.jobs || [] );
				var top = lines[ 0 ].id;
				var fresh = ( data.lines || [] ).filter( function ( l ) { return l.id > top; } );
				if ( fresh.length >= state.limit ) {
					// More arrived than one page holds: a gap would show, so start from the top again.
					reload();
					return;
				}
				if ( fresh.length ) {
					lines = fresh.concat( lines ).slice( 0, 1000 );
					prepend( fresh );
					/* translators: %d: number of new log lines */
					announce( sprintf( _n( '%d new log line.', '%d new log lines.', fresh.length, 'estasoft-migrate' ), fresh.length ) );
				}
				setText( cardMeta, metaText() );
				hint.hidden = true;
				if ( changed ) {
					loadExplanation();
				}
				defaultAuto();
			}, function () {
				loading = false;
				hint.hidden = false;
			} );
		}

		function setText( el, text ) {
			if ( el.textContent !== text ) {
				el.textContent = text;
			}
		}

		function announce( text ) {
			status.textContent = '';
			setTimeout( function () { status.textContent = text; }, 50 );
		}

		/* ------------------------------------------------------------ Jobs */

		function jobLabel( j ) {
			var word = statusWord( j.status );
			/* translators: 1: job number, 2: job title, 3: date and time */
			var text = sprintf( __( '#%1$s %2$s, %3$s', 'estasoft-migrate' ), j.id, j.title, fmt.date( j.created ) );
			return word ? text + ' (' + word + ')' : text;
		}

		function findJob( id ) {
			for ( var i = 0; i < jobs.length; i += 1 ) {
				if ( String( jobs[ i ].id ) === String( id ) ) {
					return jobs[ i ];
				}
			}
			return null;
		}

		/** Keep the job list and the select in step with the server. Returns true when the shown job changed status. */
		function takeJobs( list ) {
			var ids = function ( a ) { return a.map( function ( j ) { return j.id; } ).join( ',' ); };
			var rebuild = ! jobSelect.options.length || ids( list ) !== ids( jobs );
			jobs = list;
			if ( rebuild ) {
				// A select that is open must not be rebuilt under the person's hand.
				if ( ! jobSelect.options.length || document.activeElement !== jobSelect ) {
					drawJobSelect();
				}
			} else {
				Array.prototype.forEach.call( jobSelect.options, function ( opt ) {
					var j = findJob( opt.value );
					if ( j ) {
						setText( opt, jobLabel( j ) );
					}
				} );
			}
			var current = findJob( state.job );
			var sig = current ? current.status : ( state.job ? 'gone' : '' );
			var changed = sig !== jobStatus;
			jobStatus = sig;
			drawTitle();
			return changed;
		}

		function drawJobSelect() {
			var options = [ h( 'option', { value: '' }, __( 'All jobs', 'estasoft-migrate' ) ) ];
			jobs.forEach( function ( j ) {
				options.push( h( 'option', { value: String( j.id ) }, jobLabel( j ) ) );
			} );
			if ( state.job && ! findJob( state.job ) ) {
				/* translators: %s: job number */
				options.push( h( 'option', { value: state.job }, sprintf( __( 'Job #%s', 'estasoft-migrate' ), state.job ) ) );
			}
			ESTM.replace( jobSelect, options );
			jobSelect.value = state.job;
		}

		function drawTitle() {
			var j = findJob( state.job );
			var text = __( 'All jobs', 'estasoft-migrate' );
			if ( j ) {
				/* translators: 1: job number, 2: job title */
				text = sprintf( __( '#%1$s %2$s', 'estasoft-migrate' ), j.id, j.title );
			} else if ( state.job ) {
				/* translators: %s: job number */
				text = sprintf( __( 'Job #%s', 'estasoft-migrate' ), state.job );
			}
			setText( cardTitle, text );
		}

		function rememberAddress() {
			downloadBtn.href = ESTM.downloadUrl( 'log', state.job );
			try {
				window.history.replaceState( null, '', state.job ? ESTM.url( 'logs', { job: state.job } ) : ESTM.url( 'logs' ) );
			} catch ( e ) {
				return;
			}
		}

		function jobChanged() {
			var j = findJob( state.job );
			jobStatus = j ? j.status : ( state.job ? 'gone' : '' );
			state.autoByHand = false;
			state.auto = !! ( j && ESTM.isActive( j.status ) );
			autoSwitch.checked = state.auto;
			schedule();
			drawTitle();
			rememberAddress();
			clearExplanation();
			reload();
		}

		/** Live updates start on by themselves for a job that is running, and stop by themselves when it ends. */
		function defaultAuto() {
			if ( state.autoByHand ) {
				return;
			}
			var j = findJob( state.job );
			var on = !! ( j && ESTM.isActive( j.status ) );
			if ( on !== state.auto ) {
				state.auto = on;
				autoSwitch.checked = on;
				schedule();
			}
		}

		function schedule() {
			clearInterval( timer );
			timer = null;
			if ( state.auto ) {
				timer = setInterval( refresh, REFRESH_MS );
			}
		}

		/* ------------------------------------------------------------ The list */

		function metaText() {
			var count = lines.length;
			/* translators: %s: number of log lines */
			var text = sprintf( _n( '%s entry shown', '%s entries shown', count, 'estasoft-migrate' ), fmt.number( count ) );
			if ( lines.length ) {
				return fmt.date( lines[ lines.length - 1 ].created_gmt, false ) + ' · ' + text;
			}
			return text;
		}

		function jobCell( l ) {
			if ( ! l.job_id ) {
				return h( 'span.muted', __( 'System', 'estasoft-migrate' ) );
			}
			var j = findJob( l.job_id );
			return [
				h( 'a', { href: ESTM.url( 'progress', { job: l.job_id } ) }, '#' + l.job_id ),
				j ? h( 'span.cell-sub', j.title ) : null,
			];
		}

		function contextBlock( ctx ) {
			var keys = contextKeys( ctx );
			if ( ! keys.length ) {
				return null;
			}
			var pairs = [];
			keys.forEach( function ( k ) {
				pairs.push( h( 'dt.mono', k ), h( 'dd.mono.break', valueText( ctx[ k ] ) ) );
			} );
			return h( 'details.log-ctx', h( 'summary', __( 'Details', 'estasoft-migrate' ) ), h( 'dl.kv.left.log-kv', pairs ) );
		}

		function lineRow( l ) {
			var level = [ 'error', 'warning', 'info', 'debug' ].indexOf( l.level ) >= 0 ? l.level : 'info';
			return h(
				'tr',
				{ class: 'error' === level ? 'is-error' : null, role: 'row', dataset: { id: String( l.id ) } },
				h( 'td.nowrap', { role: 'cell' }, h( 'span.mono', logTime( l.created_gmt ) ), h( 'span.cell-sub', fmt.date( l.created_gmt, false ) ) ),
				h( 'td', { role: 'cell' }, h( 'span', { class: 'lv lv-' + level }, levelLabel( level ) ) ),
				h( 'td.nowrap', { role: 'cell' }, jobCell( l ) ),
				h( 'td.break', { role: 'cell' }, ui.highlight( l.message, state.search ), contextBlock( l.context ) )
			);
		}

		function drawList() {
			setText( cardMeta, metaText() );
			hint.hidden = true;
			if ( ! lines.length ) {
				var title;
				var text;
				if ( filtered() ) {
					title = __( 'Nothing matches', 'estasoft-migrate' );
					text = __( 'No log line fits the level and search you chose. Try “All” or a shorter search.', 'estasoft-migrate' );
				} else if ( state.job ) {
					title = __( 'No log lines for this job', 'estasoft-migrate' );
					text = __( 'The log of this job is empty, or it was cleared.', 'estasoft-migrate' );
				} else {
					title = __( 'No log lines yet', 'estasoft-migrate' );
					text = __( 'Every job writes its steps here as it runs. Start a migration, an export or a search and replace to see them.', 'estasoft-migrate' );
				}
				ESTM.replace( body, ui.empty( title, text ) );
				announce( title );
			} else {
				ESTM.replace( tbody, lines.map( lineRow ) );
				ESTM.replace( body, table );
				/* translators: %s: number of log lines */
				announce( sprintf( _n( '%s log line shown.', '%s log lines shown.', lines.length, 'estasoft-migrate' ), fmt.number( lines.length ) ) );
			}
			var more = lines.length >= state.limit && state.limit < STEPS[ STEPS.length - 1 ];
			if ( ! more && document.activeElement === moreBtn ) {
				// The button the person just used is going away: keep focus on the list's heading, not on the page.
				cardTitle.focus();
			}
			moreBtn.hidden = ! more;
			var atMax = lines.length >= state.limit && ! more;
			limitNote.hidden = ! atMax;
			if ( atMax ) {
				/* translators: %s: number of log lines */
				setText( limitNote, sprintf( _n( 'Showing the newest %s line. Narrow the job, level or search to see older ones.', 'Showing the newest %s lines. Narrow the job, level or search to see older ones.', state.limit, 'estasoft-migrate' ), fmt.number( state.limit ) ) );
			}
		}

		function prepend( fresh ) {
			if ( ! tbody.parentNode || ! table.parentNode ) {
				drawList();
				return;
			}
			fresh.slice().reverse().forEach( function ( l ) {
				tbody.insertBefore( lineRow( l ), tbody.firstChild );
			} );
			while ( tbody.children.length > 1000 ) {
				tbody.removeChild( tbody.lastChild );
			}
		}

		/* ------------------------------------------------------------ Explaining an error */

		function clearExplanation() {
			ESTM.replace( explainSlot );
			explainSlot.hidden = true;
		}

		function loadExplanation() {
			var mine = ++explainToken;
			if ( ! state.job ) {
				clearExplanation();
				return;
			}
			ESTM.get( 'logs', { job_id: state.job, levels: 'error', order: 'asc', limit: 200 } ).then( function ( data ) {
				if ( mine !== explainToken ) {
					return null;
				}
				var errors = data.lines || [];
				if ( errors.length ) {
					drawExplanation( errors[ 0 ].message, errors[ 0 ].context, errors.length, errors[ 0 ].created_gmt );
					return null;
				}
				var j = findJob( state.job );
				if ( j && 'failed' === j.status ) {
					// The job failed but its error line is gone (the log was cleared): the job itself still knows.
					return ESTM.get( 'jobs/' + state.job ).then( function ( view ) {
						if ( mine === explainToken ) {
							if ( view.error ) {
								drawExplanation( view.error.message, Array.isArray( view.error.details ) ? {} : view.error.details, 1, view.finished );
							} else {
								clearExplanation();
							}
						}
					} );
				}
				clearExplanation();
				return null;
			} ).then( null, function () {
				if ( mine === explainToken ) {
					clearExplanation();
				}
			} );
		}

		function drawExplanation( message, ctx, count, when ) {
			ctx = ctx && 'object' === typeof ctx && ! Array.isArray( ctx ) ? ctx : {};
			var j = findJob( state.job );
			var paras = [];
			paras.push( h( 'p.log-ex', h( 'b', __( 'What happened:', 'estasoft-migrate' ) ), ' ', String( message ) ) );
			if ( ctx.table ) {
				var where = String( ctx.table );
				if ( ctx.chunk ) {
					/* translators: 1: table name, 2: chunk number */
					where = sprintf( __( '%1$s, chunk %2$s', 'estasoft-migrate' ), where, String( ctx.chunk ) );
				}
				paras.push( h( 'p.log-ex', h( 'b', __( 'Where:', 'estasoft-migrate' ) ), ' ', h( 'code', where ) ) );
			}
			if ( ctx.untouched ) {
				paras.push( h( 'p.log-ex', h( 'b', __( 'What was not changed:', 'estasoft-migrate' ) ), ' ', String( ctx.untouched ) ) );
			}
			if ( ctx.advice ) {
				paras.push( h( 'p.log-ex', h( 'b', __( 'What to do:', 'estasoft-migrate' ) ), ' ', String( ctx.advice ) ) );
			} else if ( ! ctx.untouched ) {
				paras.push( h( 'p.log-ex', __( 'The log has no further advice for this error. Open the job to see its state, or download the log to share it with support.', 'estasoft-migrate' ) ) );
			}
			if ( count > 1 ) {
				/* translators: %d: number of errors */
				paras.push( h( 'p.log-ex.sub', sprintf( _n( 'This job logged %d error. This is the first one.', 'This job logged %d errors. This is the first one.', count, 'estasoft-migrate' ), count ) ) );
			}
			var buttons = [];
			if ( j && 'failed' === j.status ) {
				buttons.push( ui.btn( __( 'Retry the job', 'estasoft-migrate' ), { size: 'sm', kind: 'danger', onClick: function ( ev, el ) {
					ui.busy( el, ESTM.post( 'jobs/' + state.job + '/retry' ).then( function () {
						ESTM.go( ESTM.url( 'progress', { job: state.job } ) );
					}, function ( err ) {
						ui.toast( ESTM.errorMessage( err ), 'err' );
					} ) );
				} } ) );
			}
			buttons.push( ui.btn( __( 'Open the job', 'estasoft-migrate' ), { size: 'sm', kind: 'quiet', href: ESTM.url( 'progress', { job: state.job } ) } ) );
			if ( 'error' !== state.level ) {
				buttons.push( ui.btn( __( 'Show only errors', 'estasoft-migrate' ), { size: 'sm', kind: 'quiet', onClick: function () {
					state.level = 'error';
					state.limit = STEPS[ 0 ];
					levelSeg.set( 'error' );
					reload();
				} } ) );
			}
			var stamp = when ? ' · ' + fmt.date( when ) : '';
			ESTM.replace( explainSlot, ui.note( 'err', [ paras, h( 'div.row', { style: 'margin-top:12px' }, buttons ) ], { title: __( 'This job hit an error.', 'estasoft-migrate' ) + stamp } ) );
			explainSlot.hidden = false;
		}

		/* ------------------------------------------------------------ Clearing */

		function onClear() {
			var j = findJob( state.job );
			var message = state.job ?
				__( 'The log lines of this job are deleted for good. The job, its package and its result are not affected.', 'estasoft-migrate' ) :
				__( 'Every log line of every job is deleted for good. Jobs, packages and snapshots are not affected.', 'estasoft-migrate' );
			ui.confirm( {
				title: state.job ? __( 'Clear the log of this job?', 'estasoft-migrate' ) : __( 'Clear the whole log?', 'estasoft-migrate' ),
				message: j ? [ h( 'p', sprintf( /* translators: 1: job number, 2: job title */ __( '%1$s %2$s', 'estasoft-migrate' ), '#' + j.id, j.title ) ), h( 'p', message ) ] : message,
				confirm: __( 'Clear log', 'estasoft-migrate' ),
				cancel: __( 'Keep it', 'estasoft-migrate' ),
				danger: true,
			} ).then( function ( yes ) {
				if ( ! yes ) {
					return;
				}
				ui.busy( clearBtn, ESTM.del( 'logs', state.job ? { job_id: state.job } : {} ).then( function () {
					ui.toast( __( 'Log cleared.', 'estasoft-migrate' ) );
					state.limit = STEPS[ 0 ];
					return reload();
				}, function ( err ) {
					ui.toast( ESTM.errorMessage( err ), 'err' );
				} ) );
			} );
		}

		/* ------------------------------------------------------------ Start */

		jobStatus = '';
		rememberAddress();
		reload();
		window.addEventListener( 'pagehide', function () {
			clearInterval( timer );
		} );
	} );
}() );
