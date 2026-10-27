/**
 * Search & replace screen: build the replacement, run a dry run in place, review the numbers, then hand the real run to Pre-flight.
 * Data: GET tables, GET jobs/{id} (for ?job=), POST jobs (a dry run), jobs/{id}/tick (through ESTM.watchJob), jobs/{id}/cancel.
 * The real run is a plan handed to Pre-flight (docs/UI.md): this screen never starts a job that writes.
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

	var SAMPLES_SHOWN = 6;

	/* ---------------------------------------------------------------- Small helpers */

	/** Switch drawn as a button (as in the design). el.setChecked(bool). */
	function sw( o ) {
		var el = h( 'button', {
			type: 'button',
			class: 'sw',
			role: 'switch',
			'aria-checked': o.checked ? 'true' : 'false',
			'aria-labelledby': o.labelledBy,
			'aria-describedby': o.describedBy,
			'aria-disabled': o.locked ? 'true' : null,
		} );
		el.addEventListener( 'click', function () {
			if ( 'true' === el.getAttribute( 'aria-disabled' ) ) {
				return;
			}
			o.onToggle( 'true' !== el.getAttribute( 'aria-checked' ) );
		} );
		el.setChecked = function ( on ) {
			el.setAttribute( 'aria-checked', on ? 'true' : 'false' );
		};
		return el;
	}

	/** Label, input and error message tied together (like ui.field, but for an input inside a wrapper too). */
	function inputField( label, input, node ) {
		var id = input.id || ESTM.uid( 'sr' );
		input.id = id;
		var err = h( 'div.field-error', { id: id + '-err', hidden: true } );
		input.setAttribute( 'aria-describedby', id + '-err' );
		var el = h( 'div.field', h( 'label', { for: id }, label ), node || input, err );
		el.setError = function ( message ) {
			el.classList.toggle( 'has-error', !! message );
			err.hidden = ! message;
			err.textContent = message || '';
			if ( message ) {
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				input.removeAttribute( 'aria-invalid' );
			}
		};
		return el;
	}

	/** Did the person arrive through the Back link of the Pre-flight screen? */
	function cameFromPreflight() {
		try {
			return !! document.referrer && !! ESTM.pages.preflight && 0 === document.referrer.indexOf( ESTM.pages.preflight );
		} catch ( e ) {
			return false;
		}
	}

	function utf8Length( text ) {
		try {
			return new TextEncoder().encode( text ).length;
		} catch ( e ) {
			return text.length;
		}
	}

	function code( text ) {
		return h( 'code', text );
	}

	/** Does the text contain the needle (honouring the case rule), also in its JSON-escaped form? Returns the form found or ''. */
	function foundForm( text, needle, caseSensitive ) {
		if ( ! needle ) {
			return '';
		}
		var forms = [ needle, needle.replace( /\//g, '\\/' ) ];
		var hay = caseSensitive ? text : text.toLowerCase();
		for ( var i = 0; i < forms.length; i += 1 ) {
			if ( hay.indexOf( caseSensitive ? forms[ i ] : forms[ i ].toLowerCase() ) >= 0 ) {
				return forms[ i ];
			}
		}
		return '';
	}

	function escapeForm( original, form, value ) {
		return form !== original ? value.replace( /\//g, '\\/' ) : value;
	}

	function shortList( names, max ) {
		if ( names.length <= max ) {
			return names.join( ', ' );
		}
		return sprintf(
			/* translators: 1: comma-separated list of table names, 2: number of further tables */
			__( '%1$s and %2$d more', TD ),
			names.slice( 0, max ).join( ', ' ),
			names.length - max
		);
	}

	/* ---------------------------------------------------------------- Screen */

	ESTM.screen( 'replace', function ( root ) {
		var title = __( 'Search & replace', TD );
		var lede = __( 'Change addresses and text across the whole database. Serialized data and JSON are rewritten safely, so nothing breaks.', TD );
		var jobId = parseInt( ESTM.params().job, 10 ) || 0;

		function load() {
			ESTM.replace( root, ui.pageHead( title, lede ), h( 'div.stack', ui.skeleton( 4 ), ui.skeleton( 6 ) ) );
			var jobRequest = jobId
				? ESTM.get( 'jobs/' + jobId ).then( function ( job ) { return { job: job }; }, function ( err ) { return { error: err }; } )
				: Promise.resolve( null );
			Promise.all( [ ESTM.get( 'tables' ), jobRequest ] ).then( function ( res ) {
				build( root, title, lede, res[ 0 ], res[ 1 ] );
			}, function ( err ) {
				ESTM.replace( root, ui.pageHead( title, lede ), ui.loadError( err, load ) );
			} );
		}
		load();
	} );

	function build( root, title, lede, tablesData, jobRes ) {
		var allTables = tablesData.tables || [];
		var byFull = {};
		var logicalSet = {};
		allTables.forEach( function ( t ) {
			byFull[ t.name ] = t.logical;
			logicalSet[ t.logical ] = true;
		} );

		var opt = { dry: true, guid: true, cs: true, regex: false, keys: true, snap: true };
		var optControls = {};
		var tablesMode = 'all';
		var selected = {};
		var current = null; // The dry run on screen: { id, input, sig }.
		var watcher = null;
		var running = false;
		var result = null; // The result card on screen: { staleEl, applyBtn }.

		/* ---------------- Pairs */

		var pairs = [];
		var pairsBox = h( 'div.stack' );
		var pairsHint = h( 'span.sub' );
		var addPairBtn = ui.btn( __( 'Add another pair', TD ), { kind: 'quiet', size: 'sm', onClick: function () {
			addPair( '', '' ).fromInput.focus();
		} } );

		function addPair( from, to ) {
			var pair = {};
			pair.fromInput = ui.input( { mono: true, placeholder: 'https://example.com', onInput: onFormChange } );
			pair.toInput = ui.input( { mono: true, placeholder: 'https://staging.example.com', onInput: onFormChange } );
			pair.fromInput.value = from || '';
			pair.toInput.value = to || '';
			pair.fromField = inputField( __( 'Search for', TD ), pair.fromInput );
			pair.toField = inputField( __( 'Replace with', TD ), pair.toInput );
			pair.removeBtn = ui.btn( __( 'Remove', TD ), { kind: 'quiet', size: 'sm', onClick: function () { removePair( pair ); } } );
			pair.off = h( 'span.sub.sr-off', { hidden: true }, __( 'Not used in regular expression mode: only the first pair counts.', TD ) );
			pair.el = h( 'div.sr-pair', { role: 'group' }, pair.fromField, pair.toField, pair.removeBtn, pair.off );
			pairs.push( pair );
			pairsBox.appendChild( pair.el );
			syncPairs();
			return pair;
		}

		function removePair( pair ) {
			var at = pairs.indexOf( pair );
			if ( at < 0 || pairs.length < 2 ) {
				return;
			}
			pairs.splice( at, 1 );
			pair.el.remove();
			syncPairs();
			pairs[ Math.min( at, pairs.length - 1 ) ].fromInput.focus();
			onFormChange();
		}

		function syncPairs() {
			pairs.forEach( function ( p, i ) {
				p.el.setAttribute( 'aria-label', sprintf( /* translators: %d: position of the pair */ __( 'Pair %d', TD ), i + 1 ) );
				p.removeBtn.hidden = pairs.length < 2;
				p.removeBtn.setAttribute( 'aria-label', sprintf( /* translators: %d: position of the pair */ __( 'Remove pair %d', TD ), i + 1 ) );
				var off = opt.regex && i > 0;
				p.el.classList.toggle( 'is-off', off );
				p.fromInput.disabled = off;
				p.toInput.disabled = off;
				p.off.hidden = ! off;
			} );
			addPairBtn.disabled = opt.regex;
			ESTM.replace( pairsHint, opt.regex
				? __( 'A regular expression uses one pair.', TD )
				: __( 'Pairs run in order, in a single pass.', TD ) );
		}

		var explainBox = h( 'p.sub.sr-explain', { 'aria-live': 'off' } );

		/** What the pairs will match, in plain words. */
		function drawExplain() {
			var input = buildInput();
			var list = input.pairs;
			if ( ! list.length ) {
				ESTM.replace( explainBox, __( 'Type the text you want to find and this line will say exactly what is matched.', TD ) );
				return;
			}
			var bits = [];
			function replaced( to ) {
				return to ? [ __( 'changes it to', TD ), ' ', code( to ) ] : [ __( 'removes it', TD ) ];
			}
			if ( opt.regex ) {
				bits.push(
					__( 'Matches every part of the text that fits the pattern', TD ), ' ', code( list[ 0 ][ 0 ] ), ', ',
					list[ 0 ][ 1 ] ? [ __( 'and replaces each match with', TD ), ' ', code( list[ 0 ][ 1 ] ), '. ' ] : [ __( 'and removes each match. ', TD ) ],
					__( 'Write the pattern without slashes or flags. In the replacement, $1 and $2 stand for the parts in brackets.', TD ), ' '
				);
			} else if ( 1 === list.length ) {
				bits.push(
					opt.cs ? __( 'Matches the exact text', TD ) : __( 'Matches the text', TD ), ' ', code( list[ 0 ][ 0 ] ),
					opt.cs ? [ ' (', __( 'upper and lower case count as different', TD ), ')' ] : [ ' (', __( 'upper and lower case do not matter', TD ), ')' ],
					', ', __( 'wherever it is stored, also inside serialized data and JSON, and', TD ), ' ', replaced( list[ 0 ][ 1 ] ), '. '
				);
			} else {
				bits.push( __( 'Matches each of these texts, wherever it is stored, also inside serialized data and JSON:', TD ) );
				bits.push( h( 'ul.sr-explain-list', list.map( function ( pair ) {
					return h( 'li', code( pair[ 0 ] ), ' → ', pair[ 1 ] ? code( pair[ 1 ] ) : h( 'em', __( '(removed)', TD ) ) );
				} ) ) );
				bits.push( opt.cs ? __( 'Upper and lower case count as different.', TD ) : __( 'Upper and lower case do not matter.', TD ), ' ' );
			}
			if ( list.some( function ( pair ) { return /^\s|\s$/.test( pair[ 0 ] ); } ) ) {
				bits.push( __( 'Spaces at the start or end of the search text count.', TD ), ' ' );
			}
			ESTM.replace( explainBox, bits );
		}

		/* ---------------- Tables */

		var tableSelect = ui.select(
			[
				{ value: 'all', label: sprintf(
					/* translators: %s: number of tables */
					_n( 'All %s table', 'All %s tables', allTables.length, TD ),
					fmt.number( allTables.length )
				) },
				{ value: 'choose', label: __( 'Choose tables', TD ) },
			],
			{ id: ESTM.uid( 'sr-tables' ), onChange: function () {
				tablesMode = tableSelect.value;
				tablesPanel.hidden = 'choose' !== tablesMode;
				tablesField.setError( '' );
				syncTables();
				onFormChange();
			} }
		);
		var tablesField = ui.field( { label: __( 'Tables', TD ), id: tableSelect.id }, tableSelect );

		var tableFilter = ui.input( { placeholder: __( 'Type part of a table name', TD ), onInput: function () {
			var q = tableFilter.value.trim().toLowerCase();
			tableRows.forEach( function ( r ) {
				r.row.hidden = !! q && r.t.name.toLowerCase().indexOf( q ) < 0;
			} );
			syncTables();
		} } );
		var tableCount = h( 'span.sub', { role: 'status', 'aria-live': 'polite' } );
		var tableRows = allTables.map( function ( t ) {
			var cb = ui.checkbox( { onChange: function () {
				if ( cb.checked ) {
					selected[ t.name ] = true;
				} else {
					delete selected[ t.name ];
				}
				syncTables();
				onFormChange();
			} } );
			var row = h(
				'label.sr-trow',
				cb,
				h( 'span.mono.break', t.name ),
				h( 'span.sub.num', sprintf(
					/* translators: %s: number of rows */
					_n( '%s row', '%s rows', t.rows, TD ),
					fmt.number( t.rows )
				) )
			);
			return { t: t, cb: cb, row: row };
		} );

		function setVisibleSelected( on ) {
			tableRows.forEach( function ( r ) {
				if ( r.row.hidden ) {
					return;
				}
				if ( on ) {
					selected[ r.t.name ] = true;
				} else {
					delete selected[ r.t.name ];
				}
			} );
			syncTables();
			onFormChange();
		}

		var tablesPanel = h(
			'div.stack.sr-tpanel',
			{ hidden: true },
			inputField( __( 'Find a table', TD ), tableFilter ),
			h(
				'div.row',
				ui.btn( __( 'Select all', TD ), { size: 'sm', kind: 'quiet', onClick: function () { setVisibleSelected( true ); } } ),
				ui.btn( __( 'Select none', TD ), { size: 'sm', kind: 'quiet', onClick: function () { setVisibleSelected( false ); } } ),
				tableCount
			),
			h( 'div.sr-tlist', { role: 'group', 'aria-label': __( 'Tables to search', TD ) }, tableRows.map( function ( r ) { return r.row; } ) )
		);

		function selectedNames() {
			return allTables.filter( function ( t ) { return selected[ t.name ]; } ).map( function ( t ) { return t.name; } );
		}

		function syncTables() {
			tableRows.forEach( function ( r ) { r.cb.checked = !! selected[ r.t.name ]; } );
			var shown = tableRows.filter( function ( r ) { return ! r.row.hidden; } ).length;
			ESTM.replace( tableCount, sprintf(
				/* translators: 1: number of tables chosen, 2: number of tables */
				__( '%1$s of %2$s tables chosen', TD ),
				fmt.number( selectedNames().length ),
				fmt.number( allTables.length )
			) + ( shown < allTables.length ? ' · ' + sprintf(
				/* translators: %s: number of tables that match the filter */
				__( '%s shown', TD ),
				fmt.number( shown )
			) : '' ) );
		}

		var skipArea = ui.textarea( { rows: 2, mono: true, placeholder: 'posts.post_excerpt', onInput: onFormChange } );
		var skipHint = h( 'span.hint', __( 'Optional. One per line or separated by commas, written as table.column, for example posts.post_excerpt. Write the table without the prefix.', TD ) );
		var skipField = inputField( __( 'Also skip these columns', TD ), skipArea, h( 'div.stack.tight', skipArea, skipHint ) );

		function normColumn( path ) {
			var dot = path.indexOf( '.' );
			if ( dot > 0 && byFull[ path.slice( 0, dot ) ] ) {
				return byFull[ path.slice( 0, dot ) ] + path.slice( dot );
			}
			return path;
		}

		function skipColumns() {
			return ESTM.toList( skipArea.value ).map( normColumn ).filter( function ( v, i, a ) { return a.indexOf( v ) === i; } );
		}

		/* ---------------- The request, built from the form */

		function buildInput() {
			var list = [];
			pairs.forEach( function ( p, i ) {
				if ( opt.regex && i > 0 ) {
					return;
				}
				if ( '' !== p.fromInput.value ) {
					list.push( [ p.fromInput.value, p.toInput.value ] );
				}
			} );
			var input = {
				pairs: list,
				regex: opt.regex,
				case_sensitive: opt.cs,
				replace_keys: opt.keys,
				skip_guid: opt.guid,
				skip_columns: skipColumns(),
			};
			if ( 'choose' === tablesMode ) {
				var names = selectedNames();
				if ( names.length && names.length < allTables.length ) {
					if ( allTables.length - names.length < names.length ) {
						input.exclude_tables = allTables.filter( function ( t ) { return ! selected[ t.name ]; } ).map( function ( t ) { return t.name; } );
					} else {
						input.tables = names;
					}
				} else if ( ! names.length ) {
					input.tables = [];
				}
			}
			return input;
		}

		function inputSig( input ) {
			return JSON.stringify( [ input.pairs, input.regex, input.case_sensitive, input.replace_keys, input.skip_guid, input.skip_columns, input.tables || null, input.exclude_tables || null ] );
		}

		function clearErrors() {
			pairs.forEach( function ( p ) {
				p.fromField.setError( '' );
				p.toField.setError( '' );
			} );
			tablesField.setError( '' );
		}

		/** Show what is wrong, next to the field it is about. Returns true when the form can be used. */
		function validate( input ) {
			clearErrors();
			var first = null;
			function fail( field, control, message ) {
				field.setError( message );
				first = first || control;
			}
			var any = false;
			var same = true;
			pairs.forEach( function ( p, i ) {
				if ( opt.regex && i > 0 ) {
					return;
				}
				var from = p.fromInput.value;
				var to = p.toInput.value;
				if ( '' !== from ) {
					any = true;
					if ( from !== to ) {
						same = false;
					}
				} else if ( '' !== to ) {
					fail( p.fromField, p.fromInput, __( 'Enter the text to search for, or clear the replacement.', TD ) );
				}
			} );
			if ( ! any && ! first ) {
				fail( pairs[ 0 ].fromField, pairs[ 0 ].fromInput, __( 'Enter the text to search for.', TD ) );
			} else if ( any && same && ! opt.regex ) {
				fail( pairs[ 0 ].toField, pairs[ 0 ].toInput, __( 'The search text and the replacement are the same, so nothing would change.', TD ) );
			}
			if ( 'choose' === tablesMode && ! selectedNames().length ) {
				fail( tablesField, tableSelect, __( 'Choose at least one table, or search all tables.', TD ) );
			}
			if ( first ) {
				first.focus();
			}
			return ! first;
		}

		/* ---------------- Options */

		function optionRow( key, titleNodes, text, o ) {
			o = o || {};
			var id = ESTM.uid( 'sr-opt' );
			var control = sw( { checked: opt[ key ], locked: o.locked, labelledBy: id, describedBy: id + '-d', onToggle: function ( on ) {
				setOpt( key, on );
				onOption( key );
			} } );
			optControls[ key ] = control;
			return h( 'div.set', h( 'div.tx', h( 'b', { id: id }, titleNodes ), h( 'span', { id: id + '-d' }, text ) ), h( 'div.ctl', control ) );
		}

		function setOpt( key, on ) {
			opt[ key ] = on;
			if ( optControls[ key ] ) {
				optControls[ key ].setChecked( on );
			}
		}

		function onOption( key ) {
			if ( 'regex' === key ) {
				syncPairs();
			}
			if ( 'dry' === key ) {
				syncRunBtn();
			}
			onFormChange();
		}

		var serialId = ESTM.uid( 'sr-opt' );
		var serialRow = h(
			'div.set',
			h( 'div.tx', h( 'b', { id: serialId }, __( 'Read serialized data and JSON', TD ) ), h( 'span', { id: serialId + '-d' }, __( 'Values inside serialized PHP, JSON and block comments are decoded, replaced and encoded again with the right lengths. Always on.', TD ) ) ),
			h( 'div.ctl', sw( { checked: true, locked: true, labelledBy: serialId, describedBy: serialId + '-d', onToggle: function () {} } ) )
		);

		/* ---------------- Run, progress and result */

		var runNote = h( 'div', { 'aria-live': 'polite' } );
		var runBox = h( 'div.sr-slot', { 'aria-live': 'off' } );
		var runBtn = ui.btn( '', { kind: 'primary', onClick: startRun } );
		var runHint = h( 'span.sub' );

		function syncRunBtn() {
			runBtn.textContent = running
				? __( 'Dry run is running…', TD )
				: ( opt.dry ? __( 'Run dry run', TD ) : __( 'Review and apply', TD ) );
			runBtn.disabled = running;
			ESTM.replace( runHint, opt.dry
				? __( 'Counts the matches and shows examples. Nothing is written.', TD )
				: __( 'Goes to the pre-flight check, where you confirm before anything is written.', TD ) );
		}

		function setRunning( on ) {
			running = on;
			syncRunBtn();
		}

		function startError( err ) {
			var kids = [];
			if ( 'estm_busy' === err.code ) {
				kids = ui.note(
					'warn',
					[
						err.message,
						' ',
						err.data && err.data.job_id ? h( 'a', { href: ESTM.url( 'progress', { job: err.data.job_id } ) }, __( 'View progress', TD ) ) : null,
					],
					{ title: __( 'Another job is running.', TD ) }
				);
			} else if ( 'estm_invalid' === err.code && opt.regex ) {
				pairs[ 0 ].fromField.setError( err.message );
				pairs[ 0 ].fromInput.focus();
			} else {
				kids = ui.note( 'err', ESTM.errorMessage( err ), { title: __( 'The dry run could not start.', TD ) } );
			}
			ESTM.replace( runNote, kids );
		}

		function startRun() {
			ESTM.replace( runNote );
			var input = buildInput();
			if ( ! validate( input ) ) {
				return;
			}
			if ( ! opt.dry ) {
				goToPlan( input, null );
				return;
			}
			var body = Object.assign( { type: 'search_replace' }, input, { dry_run: true } );
			var request = ESTM.post( 'jobs', body ).then( function ( view ) {
				attach( view, input );
			}, startError );
			ui.busy( runBtn, request );
		}

		/** Follow a dry run that is queued or running. */
		function attach( view, input ) {
			stopWatching();
			result = null;
			current = { id: view.id, input: input, sig: inputSig( input ) };
			setRunning( true );
			var bar = ui.bar( view.progress || 0, { large: true, live: true, label: __( 'Dry run progress', TD ) } );
			var status = h( 'p', { 'aria-live': 'off' }, __( 'Starting…', TD ) );
			var detail = h( 'p.sub', { 'aria-live': 'off' } );
			var cancelBtn = ui.btn( __( 'Cancel dry run', TD ), { kind: 'quiet', size: 'sm', onClick: function () {
				ui.confirm( {
					title: __( 'Stop the dry run?', TD ),
					message: __( 'Nothing has been changed, so there is nothing to undo. You can run it again at any time.', TD ),
					confirm: __( 'Stop dry run', TD ),
					cancel: __( 'Keep running', TD ),
				} ).then( function ( ok ) {
					if ( ! ok ) {
						return;
					}
					ui.busy( cancelBtn, ESTM.post( 'jobs/' + view.id + '/cancel' ).then( function ( done ) {
						stopWatching();
						finish( done );
					}, function ( err ) {
						ui.toast( ESTM.errorMessage( err ), 'err' );
					} ) );
				} );
			} } );
			var warn = h( 'div', { 'aria-live': 'polite' } );
			ESTM.replace( runBox, h(
				'section.card',
				{ 'aria-labelledby': 'sr-prog-h' },
				h( 'div.card-h', h( 'h2', { id: 'sr-prog-h' }, __( 'Dry run in progress', TD ) ), cancelBtn ),
				h( 'div.stack', bar, status, detail, warn ),
				h( 'p.sub', { style: 'margin-top:12px' }, __( 'The form stays usable. Keep this page open: the work is done in small steps while it is open.', TD ) )
			) );

			function update( v ) {
				ui.setBar( bar, v.progress );
				var tables = v.tables || [];
				var now = tables.filter( function ( t ) { return 'running' === t.status; } )[ 0 ];
				var matches = tables.reduce( function ( n, t ) { return n + ( t.matches || 0 ); }, 0 );
				ESTM.replace( status, now
					? sprintf( /* translators: 1: table name, 2: percent done */ __( 'Searching %1$s · %2$s', TD ), now.name, fmt.percent( v.progress ) )
					: sprintf( /* translators: %s: percent done */ __( 'Searching · %s', TD ), fmt.percent( v.progress ) ) );
				var st = v.stats || {};
				ESTM.replace( detail, [
					sprintf(
						/* translators: 1: number of matches found so far, 2: number of tables done, 3: number of tables */
						__( '%1$s matches so far · %2$s of %3$s tables done', TD ),
						fmt.number( matches ),
						fmt.number( tables.filter( function ( t ) { return 'done' === t.status || 'skipped' === t.status; } ).length ),
						fmt.number( v.tables_total || tables.length )
					),
					st.elapsed ? ' · ' + sprintf( /* translators: %s: time elapsed */ __( '%s elapsed', TD ), fmt.duration( st.elapsed ) ) : '',
				] );
				ESTM.replace( warn, v.warning
					? ui.note( 'warn', v.warning.message || __( 'A temporary problem came up. Trying again.', TD ) )
					: null );
			}
			update( view );
			watcher = ESTM.watchJob( view.id, {
				onUpdate: update,
				onDone: function ( v ) {
					watcher = null;
					finish( v );
				},
				onError: function ( err ) {
					ESTM.replace( warn, ui.note( 'warn', ESTM.errorMessage( err ) ) );
				},
			} );
		}

		function stopWatching() {
			if ( watcher ) {
				watcher.stop();
				watcher = null;
			}
		}

		function finish( view ) {
			setRunning( false );
			if ( 'completed' === view.status && view.result ) {
				showResult( view, specFromInput( current ? current.input : inputFromParams( view.params || {} ) ) );
				return;
			}
			var note;
			if ( 'failed' === view.status ) {
				var details = view.error && view.error.details && view.error.details.length
					? h( 'ul', view.error.details.map( function ( d ) { return h( 'li', typeof d === 'string' ? d : JSON.stringify( d ) ); } ) )
					: null;
				note = ui.note( 'err', [ view.error && view.error.message ? view.error.message : __( 'The job stopped before it finished.', TD ), details, ' ', h( 'a', { href: ESTM.url( 'logs', { job: view.id } ) }, __( 'View log', TD ) ) ], { title: __( 'The dry run failed. Nothing was changed.', TD ) } );
			} else if ( 'cancelled' === view.status ) {
				note = ui.note( 'tint', __( 'You stopped the dry run. Nothing was changed. Run it again when you are ready.', TD ), { title: __( 'Dry run stopped.', TD ) } );
			} else {
				note = ui.note( 'warn', [ __( 'The job is paused.', TD ), ' ', h( 'a', { href: ESTM.url( 'progress', { job: view.id } ) }, __( 'Open progress', TD ) ) ] );
			}
			ESTM.replace( runBox, note );
		}

		/* ---------------- Result */

		function specFromInput( input ) {
			return { pairs: input.pairs || [], regex: !! input.regex, cs: false !== input.case_sensitive };
		}

		/** Highlight where a search text sits in a snippet. Plain text uses ui.highlight; a pattern is tried as a JavaScript one. */
		function markBefore( text, spec ) {
			if ( spec.regex ) {
				try {
					var m = new RegExp( spec.pairs[ 0 ][ 0 ], spec.cs ? 'u' : 'iu' ).exec( text );
					if ( m && m[ 0 ] ) {
						return [ text.slice( 0, m.index ), h( 'mark', m[ 0 ] ), text.slice( m.index + m[ 0 ].length ) ];
					}
				} catch ( e ) {
					return text;
				}
				return text;
			}
			for ( var i = 0; i < spec.pairs.length; i += 1 ) {
				var form = foundForm( text, spec.pairs[ i ][ 0 ], spec.cs );
				if ( form ) {
					return { nodes: ui.highlight( text, form ), index: i, form: form };
				}
			}
			return text;
		}

		function sampleBlock( s, spec ) {
			var before = String( s.before );
			var after = String( s.after );
			var beforeNodes = before;
			var afterNodes = after;
			var marked = markBefore( before, spec );
			if ( marked && marked.nodes ) {
				beforeNodes = marked.nodes;
				var to = spec.pairs[ marked.index ][ 1 ];
				if ( to ) {
					afterNodes = ui.highlight( after, escapeForm( spec.pairs[ marked.index ][ 0 ], marked.form, to ) );
				}
			} else if ( marked && Array.isArray( marked ) ) {
				beforeNodes = marked;
			}
			return h(
				'div.sr-sample',
				h( 'div.row', h( 'span.mono.break', { style: 'font-weight:600' }, s.table + '.' + s.column ), h( 'span.sub', sprintf( /* translators: %s: primary key of the row */ __( 'row %s', TD ), s.key ) ) ),
				h( 'div.diff', h( 'del', beforeNodes ), h( 'ins', afterNodes ) )
			);
		}

		function resultTable( rows, dry ) {
			return ui.table(
				[
					{ label: __( 'Table', TD ), className: 'mono', render: function ( t ) { return t.name; } },
					{ label: __( 'Matches', TD ), numeric: true, render: function ( t ) {
						return 'skipped' === t.status ? '–' : fmt.number( t.matches );
					} },
					{ label: dry ? __( 'Rows to change', TD ) : __( 'Rows changed', TD ), numeric: true, render: function ( t ) {
						return 'skipped' === t.status ? ui.badge( __( 'Skipped', TD ), 'idle' ) : fmt.number( t.rows );
					} },
				],
				rows,
				{ caption: dry ? __( 'Matches per table in the dry run', TD ) : __( 'Rows changed per table', TD ) }
			);
		}

		function showResult( view, spec ) {
			var r = view.result;
			var dry = !! r.dry_run;
			var totals = r.totals || {};
			var tables = r.tables || [];
			var withMatches = tables.filter( function ( t ) { return t.matches > 0; } ).sort( function ( a, b ) { return b.matches - a.matches; } );
			var skipped = tables.filter( function ( t ) { return 'skipped' === t.status; } );
			var quiet = tables.filter( function ( t ) { return ! t.matches && 'skipped' !== t.status; } );
			var notes = ( r.notes || [] ).filter( Boolean );
			var clean = ! notes.length && ! skipped.length;

			var staleEl = ui.note( 'warn', __( 'You changed the form after this dry run, so these numbers are out of date. Run the dry run again to see what the new settings would do.', TD ), { title: __( 'This result no longer matches the form.', TD ) } );
			staleEl.hidden = true;

			var kids = [
				h(
					'div.g-auto',
					{ style: 'grid-template-columns:repeat(auto-fit,minmax(130px,1fr))' },
					ui.tile( __( 'Matches', TD ), fmt.number( totals.matches ), sprintf( /* translators: %s: number of tables */ _n( 'in %s table', 'in %s tables', withMatches.length, TD ), fmt.number( withMatches.length ) ) ),
					ui.tile( dry ? __( 'Rows that would change', TD ) : __( 'Rows changed', TD ), fmt.number( totals.changed ), __( 'rows with at least one match', TD ) ),
					ui.tile( __( 'Rows scanned', TD ), fmt.number( totals.scanned ), sprintf( /* translators: %s: number of tables */ _n( 'in %s table', 'in %s tables', tables.length, TD ), fmt.number( tables.length ) ) ),
					ui.tile( __( 'Inside serialized or JSON data', TD ), fmt.number( totals.nested ), __( 'matches rewritten safely', TD ) )
				),
			];

			if ( dry ) {
				kids.push( ui.note(
					'tint',
					totals.matches
						? __( 'This was only a dry run: the database is exactly as it was. Apply these changes to make the replacements for real.', TD )
						: __( 'This was only a dry run: the database is exactly as it was.', TD ),
					{ title: __( 'Nothing was written.', TD ) }
				) );
			} else if ( r.snapshot ) {
				kids.push( ui.note(
					'tint',
					[ __( 'The tables were saved before anything changed, so you can undo this from Packages.', TD ), ' ', h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Open Packages', TD ) ) ],
					{ title: __( 'A rollback snapshot was saved.', TD ) }
				) );
			} else {
				kids.push( ui.note( 'warn', __( 'This run did not take a snapshot, so it cannot be undone from Packages.', TD ) ) );
			}
			if ( notes.length ) {
				kids.push( ui.note( 'warn', h( 'ul', notes.map( function ( n ) { return h( 'li', n ); } ) ), { title: __( 'Notes', TD ) } ) );
			}
			if ( skipped.length ) {
				kids.push( ui.note(
					'warn',
					sprintf(
						/* translators: 1: number of tables, 2: list of table names */
						_n( '%1$s table was skipped because it has no primary key, so its rows cannot be updated safely: %2$s.', '%1$s tables were skipped because they have no primary key, so their rows cannot be updated safely: %2$s.', skipped.length, TD ),
						fmt.number( skipped.length ),
						shortList( skipped.map( function ( t ) { return t.name; } ), 5 )
					)
				) );
			}

			var body = [ h( 'div.stack', { style: 'padding:20px' }, kids ) ];
			if ( ! totals.matches ) {
				body.push( ui.empty(
					__( 'No matches found', TD ),
					opt.regex
						? __( 'The pattern matched nothing. Check it, and whether upper and lower case should count.', TD )
						: __( 'The text was not found in the tables you chose. Check the spelling, whether upper and lower case should count, and which tables are searched.', TD )
				) );
			} else {
				body.push( resultTable( withMatches.concat( skipped ), dry ) );
				if ( quiet.length ) {
					var quietTable = resultTable( quiet, dry );
					quietTable.hidden = true;
					var toggle = ui.btn( '', { kind: 'link', onClick: function () {
						quietTable.hidden = ! quietTable.hidden;
						toggle.textContent = quietLabel( quietTable.hidden );
						toggle.setAttribute( 'aria-expanded', quietTable.hidden ? 'false' : 'true' );
					} } );
					toggle.textContent = quietLabel( true );
					toggle.setAttribute( 'aria-expanded', 'false' );
					body.push( h( 'div', { style: 'padding:12px 20px;border-top:1px solid var(--line)' }, toggle ), quietTable );
				}
			}

			var samples = ( r.samples || [] ).filter( function ( s ) { return s && undefined !== s.before; } );
			if ( samples.length ) {
				body.push( h(
					'div.stack.sr-samples',
					h( 'div', h( 'h3', __( 'Examples', TD ) ), h( 'p.sub', sprintf(
						/* translators: %s: number of examples */
						_n( 'The first %s match, shortened around the change.', 'The first %s matches, shortened around the change.', samples.length, TD ),
						fmt.number( samples.length )
					) ) ),
					samples.map( function ( s, i ) {
						var block = sampleBlock( s, spec );
						block.hidden = i >= SAMPLES_SHOWN;
						block.classList.toggle( 'sr-extra', i >= SAMPLES_SHOWN );
						return block;
					} ),
					samples.length > SAMPLES_SHOWN ? moreSamples( samples.length ) : null
				) );
			}

			var applyBtn = null;
			var footer = [];
			if ( dry && totals.matches ) {
				applyBtn = ui.btn( __( 'Apply these changes', TD ), { kind: 'primary', onClick: function () {
					goToPlan( current ? current.input : inputFromParams( view.params || {} ), totals );
				} } );
				footer.push( applyBtn, h( 'span.sub', __( 'You check and confirm on the next screen before anything is written.', TD ) ) );
			}
			footer.push( h( 'span.sp' ), ui.btn( __( 'View log', TD ), { kind: 'quiet', size: 'sm', href: ESTM.url( 'logs', { job: view.id } ) } ) );

			if ( dry ) {
				body.push( staleEl );
			}
			body.push( h( 'div.card-f', footer ) );

			var heading = h( 'h2', { tabindex: '-1' }, dry ? __( 'Dry run result', TD ) : __( 'What was changed', TD ) );
			ESTM.replace( runBox, h(
				'section.card.flush',
				h( 'div.card-h', heading, clean
					? ui.badge( dry ? __( 'No problems found', TD ) : __( 'Completed', TD ), 'ready' )
					: ui.badge( __( 'Finished with notes', TD ), 'warn' ) ),
				body
			) );
			result = dry ? { staleEl: staleEl, applyBtn: applyBtn } : null;
			heading.focus();
			runBox.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			checkStale();
		}

		function moreSamples( total ) {
			var btn = ui.btn( sprintf(
				/* translators: %s: number of examples */
				__( 'Show all %s examples', TD ),
				fmt.number( total )
			), { kind: 'link', onClick: function () {
				Array.prototype.forEach.call( runBox.querySelectorAll( '.sr-extra' ), function ( el ) { el.hidden = false; } );
				btn.hidden = true;
			} } );
			return h( 'div', btn );
		}

		function quietLabel( hidden ) {
			return hidden ? __( 'Show the tables without matches', TD ) : __( 'Hide the tables without matches', TD );
		}

		/** The dry run on screen is only good for the settings it ran with. */
		function checkStale() {
			if ( ! result || ! current ) {
				return;
			}
			var stale = current.sig !== inputSig( buildInput() );
			result.staleEl.hidden = ! stale;
			if ( result.applyBtn ) {
				result.applyBtn.disabled = stale;
			}
		}

		/* ---------------- Hand the real run to Pre-flight */

		function planLines( input, totals ) {
			var lines = [];
			if ( input.regex ) {
				lines.push( sprintf(
					/* translators: 1: pattern, 2: replacement text */
					__( 'Replace matches of the pattern “%1$s” with “%2$s”', TD ),
					input.pairs[ 0 ][ 0 ],
					input.pairs[ 0 ][ 1 ]
				) );
			} else {
				input.pairs.forEach( function ( pair ) {
					lines.push( pair[ 1 ]
						? sprintf(
							/* translators: 1: text searched for, 2: replacement text */
							__( 'Replace “%1$s” with “%2$s”', TD ),
							pair[ 0 ],
							pair[ 1 ]
						)
						: sprintf(
							/* translators: %s: text searched for */
							__( 'Remove “%s”', TD ),
							pair[ 0 ]
						) );
				} );
			}
			if ( input.tables ) {
				lines.push( sprintf(
					/* translators: 1: number of tables, 2: list of table names */
					_n( 'Only this table (%1$s): %2$s', 'Only these %1$s tables: %2$s', input.tables.length, TD ),
					fmt.number( input.tables.length ),
					shortList( input.tables, 5 )
				) );
			} else if ( input.exclude_tables ) {
				lines.push( sprintf(
					/* translators: %s: list of table names */
					__( 'All tables except: %s', TD ),
					shortList( input.exclude_tables, 5 )
				) );
			} else {
				lines.push( sprintf(
					/* translators: %s: number of tables */
					_n( 'All %s table', 'All %s tables', allTables.length, TD ),
					fmt.number( allTables.length )
				) );
			}
			lines.push( input.case_sensitive ? __( 'Upper and lower case count as different', TD ) : __( 'Upper and lower case do not matter', TD ) );
			lines.push( input.skip_guid ? __( 'The guid column is left alone', TD ) : __( 'The guid column is changed too', TD ) );
			if ( input.skip_columns && input.skip_columns.length ) {
				lines.push( sprintf(
					/* translators: %s: list of columns */
					__( 'Columns left alone: %s', TD ),
					shortList( input.skip_columns, 5 )
				) );
			}
			lines.push( input.snapshot
				? __( 'A rollback snapshot is saved first, so you can undo it from Packages', TD )
				: __( 'No rollback snapshot: this cannot be undone', TD ) );
			if ( totals ) {
				lines.push( sprintf(
					/* translators: 1: number of matches, 2: number of rows */
					__( 'The dry run found %1$s matches in %2$s rows', TD ),
					fmt.number( totals.matches ),
					fmt.number( totals.changed )
				) );
			}
			return lines;
		}

		function goToPlan( input, totals ) {
			var live = Object.assign( {}, input, { dry_run: false, snapshot: opt.snap } );
			var plan = {
				mode: 'search_replace',
				type: 'search_replace',
				input: live,
				title: __( 'Search & replace', TD ),
				lines: planLines( live, totals ),
				back: 'replace',
			};
			ESTM.draft.set( 'replace-form', { input: live, dry: opt.dry } );
			if ( ! ESTM.draft.set( 'plan', plan ) ) {
				ESTM.replace( runNote, ui.note( 'err', __( 'Your browser would not keep the plan for the next step. Allow site data for this page and try again.', TD ) ) );
				return;
			}
			ESTM.go( ESTM.url( 'preflight' ) );
		}

		/* ---------------- Open an earlier job (?job=) */

		function inputFromParams( params ) {
			var input = {
				pairs: ( params.pairs || [] ).map( function ( p ) { return [ String( p[ 0 ] ), String( p[ 1 ] || '' ) ]; } ),
				regex: !! params.regex,
				case_sensitive: false !== params.case_sensitive,
				replace_keys: false !== params.replace_keys,
				skip_guid: false !== params.skip_guid,
				skip_columns: params.skip_columns || [],
			};
			if ( params.tables && params.tables.length ) {
				input.tables = params.tables;
			} else if ( params.exclude_tables && params.exclude_tables.length ) {
				input.exclude_tables = params.exclude_tables;
			}
			return input;
		}

		function fillForm( params ) {
			pairs.slice().forEach( function ( p ) { p.el.remove(); } );
			pairs.length = 0;
			( params.pairs && params.pairs.length ? params.pairs : [ [ '', '' ] ] ).forEach( function ( pair ) {
				addPair( String( pair[ 0 ] ), String( pair[ 1 ] || '' ) );
			} );
			setOpt( 'regex', !! params.regex );
			setOpt( 'cs', false !== params.case_sensitive );
			setOpt( 'keys', false !== params.replace_keys );
			setOpt( 'guid', false !== params.skip_guid );
			skipArea.value = ( params.skip_columns || [] ).join( '\n' );
			selected = {};
			tablesMode = 'all';
			if ( params.tables && params.tables.length ) {
				params.tables.forEach( function ( n ) { if ( byFull[ n ] ) { selected[ n ] = true; } } );
				tablesMode = 'choose';
			} else if ( params.exclude_tables && params.exclude_tables.length ) {
				allTables.forEach( function ( t ) { selected[ t.name ] = true; } );
				params.exclude_tables.forEach( function ( n ) { delete selected[ n ]; } );
				tablesMode = 'choose';
			}
			tableSelect.value = tablesMode;
			tablesPanel.hidden = 'choose' !== tablesMode;
			syncPairs();
			syncTables();
			onFormChange();
		}

		var introBox = h( 'div.sr-slot' );

		function adopt( job ) {
			if ( 'search_replace' !== job.type ) {
				ESTM.replace( introBox, ui.note( 'warn', [ __( 'That job is not a search and replace, so it cannot be shown here.', TD ), ' ', h( 'a', { href: ESTM.url( 'progress', { job: job.id } ) }, __( 'Open it in Progress', TD ) ) ] ) );
				return;
			}
			var params = job.params || {};
			fillForm( params );
			var when = fmt.date( job.finished || job.updated || job.created );
			var dry = !! params.dry_run;
			var message;
			if ( ESTM.isActive( job.status ) ) {
				message = __( 'This dry run is still running. The form shows its settings.', TD );
			} else if ( 'completed' === job.status ) {
				message = dry
					? sprintf( /* translators: %s: date */ __( 'This is the dry run from %s. The form has the same settings, so you can change them and run it again.', TD ), when )
					: sprintf( /* translators: %s: date */ __( 'This replacement was applied on %s. The form has the same settings; they start as a dry run if you want to check them again.', TD ), when );
			} else if ( 'failed' === job.status ) {
				message = sprintf( /* translators: %s: date */ __( 'This job failed on %s. The form has its settings.', TD ), when );
			} else {
				message = sprintf( /* translators: 1: status, 2: date */ __( 'This job ended as “%1$s” on %2$s. The form has its settings.', TD ), job.status, when );
			}
			ESTM.replace( introBox, ui.note( 'tint', message, { title: dry ? __( 'Earlier dry run', TD ) : __( 'Earlier job', TD ) } ) );

			var input = buildInput();
			if ( ESTM.isActive( job.status ) ) {
				attach( job, input );
			} else if ( 'completed' === job.status && job.result ) {
				current = { id: job.id, input: input, sig: inputSig( input ) };
				showResult( job, specFromInput( input ) );
			} else {
				current = { id: job.id, input: input, sig: inputSig( input ) };
				finish( job );
			}
		}

		/* ---------------- Change handler */

		function onFormChange() {
			drawExplain();
			drawExamples();
			checkStale();
		}

		/* ---------------- Page */

		addPair( '', '' );
		syncPairs();
		syncTables();

		var whatCard = h(
			'section.card',
			h( 'h2', { style: 'margin-bottom:14px' }, __( 'What to replace', TD ) ),
			h(
				'div.stack',
				{ style: 'gap:16px' },
				pairsBox,
				h( 'div.row', addPairBtn, pairsHint ),
				explainBox,
				tablesField,
				tablesPanel,
				skipField
			)
		);

		var optionsCard = h(
			'section.card',
			h( 'h2', { style: 'margin-bottom:14px' }, __( 'Options', TD ) ),
			optionRow( 'dry', __( 'Dry run first', TD ), __( 'Counts matches and writes nothing. When this is off, the button goes straight to the review step and then applies the change.', TD ) ),
			serialRow,
			optionRow( 'guid', __( 'Skip the guid column', TD ), [ __( 'WordPress uses', TD ), ' ', code( 'guid' ), ' ', __( 'as a permanent identifier, so it should not change. Recommended.', TD ) ] ),
			optionRow( 'cs', __( 'Match case', TD ), [ __( 'Treat', TD ), ' ', code( 'Example.com' ), ' ', __( 'and', TD ), ' ', code( 'example.com' ), ' ', __( 'as different.', TD ) ] ),
			optionRow( 'regex', __( 'Regular expression', TD ), __( 'The search text is a pattern, for example https?://(www\\.)?example\\.com. Only one pair can be used. Leave off for plain text.', TD ) ),
			optionRow( 'keys', __( 'Also replace inside array keys', TD ), __( 'In serialized and JSON data, text used as a key is replaced too, not only the values.', TD ) ),
			optionRow( 'snap', __( 'Take a rollback snapshot before applying', TD ), __( 'Lets you undo the whole replacement from Packages. Only used when you apply; a dry run never writes anything.', TD ) ),
			runNote,
			h( 'div.card-f', { style: 'margin:16px -20px -20px' }, runBtn, runHint )
		);

		/* Side cards: examples that follow what is typed. */
		var serialCode = h( 'div.code', { style: 'background:#2b2b2b', 'aria-live': 'off' } );
		var jsonCode = h( 'div.code', { style: 'background:#2b2b2b', 'aria-live': 'off' } );

		function drawExamples() {
			var first = null;
			if ( ! opt.regex ) {
				pairs.some( function ( p ) {
					var from = p.fromInput.value;
					if ( from && from.length <= 60 && p.toInput.value.length <= 60 ) {
						first = [ from, p.toInput.value ];
						return true;
					}
					return false;
				} );
			}
			var from = first ? first[ 0 ] : 'https://example.com';
			var to = first ? first[ 1 ] : 'https://staging.example.com';
			function ser( text ) {
				return [ 'a:1:{s:4:"home";s:', h( 'mark', String( utf8Length( text ) ) ), ':"', h( 'mark', text ), '";}' ];
			}
			ESTM.replace( serialCode, h( 'span.dim', __( 'Before', TD ) ), '\n', ser( from ), '\n\n', h( 'span.dim', __( 'After', TD ) ), '\n', ser( to ) );
			var shown = to || 'https://staging.example.com';
			ESTM.replace( jsonCode, '<!-- wp:image {"url":"', h( 'mark', shown ), '/uploads/a.jpg"} -->' );
		}

		var sideCards = [
			h(
				'section.card.dark',
				h( 'h2', { style: 'margin-bottom:6px' }, __( 'Why serialized data needs care', TD ) ),
				h( 'p.sub', { style: 'margin-bottom:14px' }, __( 'PHP stores the length of every string. A plain text replace changes the text but not the length, and the setting is lost. Estasoft Migrate updates both.', TD ) ),
				serialCode
			),
			h(
				'section.card',
				h( 'h3', { style: 'margin-bottom:6px' }, __( 'JSON and block comments', TD ) ),
				h( 'p.sub', { style: 'margin-bottom:12px' }, __( 'Gutenberg and page-builder settings are read as JSON, not as plain text.', TD ) ),
				jsonCode
			),
			h(
				'section.card',
				h( 'h3', { style: 'margin-bottom:6px' }, __( 'Safe by design', TD ) ),
				h(
					'ul.stack.checklist',
					{ style: 'gap:8px;font-size:13.5px' },
					h( 'li', __( 'Objects inside serialized data are never created or run, only read as plain values.', TD ) ),
					h( 'li', __( 'Large tables are processed in batches, so there is no timeout.', TD ) ),
					h( 'li', h( 'span', __( 'Every run is saved in', TD ), ' ', h( 'a', { href: ESTM.url( 'logs' ) }, __( 'Logs', TD ) ), '.' ) )
				)
			),
		];

		ESTM.replace(
			root,
			ui.pageHead( title, lede ),
			h(
				'div.cols',
				h( 'div.main.stack', introBox, whatCard, optionsCard, runBox ),
				h( 'div.side.stack', sideCards )
			)
		);

		syncRunBtn();
		onFormChange();

		var kept = ESTM.draft.get( 'replace-form' );
		ESTM.draft.clear( 'replace-form' );
		if ( kept && kept.input && ! jobRes && cameFromPreflight() ) {
			fillForm( kept.input );
			setOpt( 'snap', false !== kept.input.snapshot );
			setOpt( 'dry', false !== kept.dry );
			syncRunBtn();
		}

		if ( jobRes && jobRes.error ) {
			ESTM.replace( introBox, ui.note( 'err', ESTM.errorMessage( jobRes.error ), { title: __( 'That job could not be opened.', TD ) } ) );
		} else if ( jobRes && jobRes.job ) {
			adopt( jobRes.job );
		}

		window.addEventListener( 'pagehide', stopWatching );
	}
}() );
