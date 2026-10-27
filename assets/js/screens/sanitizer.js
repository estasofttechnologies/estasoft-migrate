/**
 * Sanitizer screen: choose what is masked, preview it on real rows, save the settings and start a sanitized copy.
 * Data: GET sanitizer, GET tables, GET settings (when the copy dialog opens), POST sanitizer/preview, POST sanitizer/save.
 * The copy itself is a plan handed to Pre-flight (docs/UI.md): this screen never creates the job.
 */
( function () {
	'use strict';

	var ESTM = window.ESTM;
	var h = ESTM.h;
	var ui = ESTM.ui;
	var __ = ESTM.i18n.__;
	var _n = ESTM.i18n._n;
	var sprintf = ESTM.i18n.sprintf;

	var MAX_KEPT = 50;

	/* ---------------------------------------------------------------- Small helpers */

	/** Switch drawn as a button (as in the design). Returns the element; el.setChecked(bool). */
	function sw( o ) {
		var el = h( 'button', {
			type: 'button',
			class: 'sw',
			role: 'switch',
			'aria-checked': o.checked ? 'true' : 'false',
			'aria-labelledby': o.labelledBy,
			'aria-describedby': o.describedBy,
			'aria-label': o.labelledBy ? null : o.label,
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

	/**
	 * Label, control and error tied to the input itself. ui.field would label the wrapper of a password
	 * or a button group, so this one takes the input and the node to place.
	 */
	function inputField( label, input, node, hint ) {
		var id = input.id || ESTM.uid( 'san' );
		input.id = id;
		var err = h( 'div.field-error', { id: id + '-err', hidden: true } );
		input.setAttribute( 'aria-describedby', ( hint ? id + '-hint ' : '' ) + id + '-err' );
		var el = h(
			'div.field',
			h( 'label', { for: id }, label ),
			node || input,
			hint ? h( 'span.hint', { id: id + '-hint' }, hint ) : null,
			err
		);
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

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	function clip( text, max ) {
		text = String( text );
		return text.length > max ? text.slice( 0, max ) + '…' : text;
	}

	function listText( items ) {
		return items.join( ', ' );
	}

	/* ---------------------------------------------------------------- Screen */

	ESTM.screen( 'sanitizer', function ( root ) {
		var lede = __( 'Make a copy of this database that is safe to share, test on or hand over. The live database is never changed.', 'estasoft-migrate' );
		var title = __( 'Database sanitizer', 'estasoft-migrate' );

		function load() {
			ESTM.replace( root, ui.pageHead( title, lede ), h( 'div.stack', ui.skeleton( 3 ), ui.skeleton( 6 ) ) );
			Promise.all( [
				ESTM.get( 'sanitizer' ),
				ESTM.get( 'tables' ).then( function ( t ) { return t; }, function () { return null; } ),
			] ).then( function ( res ) {
				build( root, title, lede, res[ 0 ], res[ 1 ] );
			}, function ( err ) {
				ESTM.replace( root, ui.pageHead( title, lede ), ui.loadError( err, load ) );
			} );
		}
		load();
	} );

	function build( root, title, lede, data, tablesData ) {
		/* ---------------- Data and state */

		var profiles = {};
		data.profiles.forEach( function ( p ) { profiles[ p.id ] = p; } );
		var ruleIds = data.rules.map( function ( r ) { return r.id; } );
		var safeIds = data.safety.map( function ( s ) { return s.id; } );

		// Table names by full name, to accept "wp_users" where the engine wants "users".
		var byFull = {};
		var logicalSet = {};
		var tablesKnown = !! ( tablesData && tablesData.tables && tablesData.tables.length );
		if ( tablesKnown ) {
			tablesData.tables.forEach( function ( t ) {
				byFull[ t.name ] = t.logical;
				logicalSet[ t.logical ] = true;
			} );
		}

		function fromSaved( saved ) {
			saved = saved || {};
			var id = profiles[ saved.profile ] ? saved.profile : data.profiles[ 0 ].id;
			var s = {
				profile: id,
				rules: {},
				safety: {},
				keep_users: ESTM.toList( saved.keep_users || [] ),
				keep_admins: undefined === saved.keep_admins ? !! profiles[ id ].keep_admins : false !== saved.keep_admins,
				skip_tables: ESTM.toList( saved.skip_tables || [] ),
				skip_columns: ESTM.toList( saved.skip_columns || [] ),
				on_export: !! saved.on_export,
			};
			ruleIds.forEach( function ( r ) {
				s.rules[ r ] = saved.rules && undefined !== saved.rules[ r ] ? !! saved.rules[ r ] : !! profiles[ id ].rules[ r ];
			} );
			safeIds.forEach( function ( r ) {
				s.safety[ r ] = saved.safety && undefined !== saved.safety[ r ] ? !! saved.safety[ r ] : !! profiles[ id ].safety[ r ];
			} );
			return s;
		}

		var st = fromSaved( data.saved );
		var leaving = false;

		function signature( s ) {
			return JSON.stringify( [
				s.profile,
				ruleIds.map( function ( r ) { return s.rules[ r ] ? 1 : 0; } ),
				safeIds.map( function ( r ) { return s.safety[ r ] ? 1 : 0; } ),
				s.keep_users,
				s.keep_admins,
				s.skip_tables,
				s.skip_columns,
				s.on_export,
			] );
		}
		var savedSig = signature( st );
		var lastSaved = payload();

		// Coming back from Pre-flight with the Back link: bring back what was being edited, saved or not.
		var kept = ESTM.draft.get( 'sanitizer-form' );
		ESTM.draft.clear( 'sanitizer-form' );
		if ( kept && cameFromPreflight() ) {
			st = fromSaved( kept );
		}

		function isDirty() {
			return signature( st ) !== savedSig;
		}

		function isCustomised() {
			var base = profiles[ st.profile ];
			return ruleIds.some( function ( r ) { return !! st.rules[ r ] !== !! base.rules[ r ]; } ) ||
				safeIds.some( function ( r ) { return !! st.safety[ r ] !== !! base.safety[ r ]; } );
		}

		function resetToProfile() {
			var base = profiles[ st.profile ];
			ruleIds.forEach( function ( r ) { st.rules[ r ] = !! base.rules[ r ]; } );
			safeIds.forEach( function ( r ) { st.safety[ r ] = !! base.safety[ r ]; } );
		}

		function count( map, ids ) {
			return ids.filter( function ( id ) { return map[ id ]; } ).length;
		}

		function payload() {
			return clone( {
				profile: st.profile,
				rules: st.rules,
				safety: st.safety,
				keep_users: st.keep_users,
				keep_admins: st.keep_admins,
				skip_tables: st.skip_tables,
				skip_columns: st.skip_columns,
				on_export: st.on_export,
			} );
		}

		/** The settings as a job wants them (also what Preview sends, under "overrides"). */
		function overrides() {
			return clone( {
				rules: st.rules,
				safety: st.safety,
				keep_users: st.keep_users,
				keep_admins: st.keep_admins,
				skip_tables: st.skip_tables,
				skip_columns: st.skip_columns,
			} );
		}

		/* ---------------- Skip lists */

		function normTable( name ) {
			return byFull[ name ] ? byFull[ name ] : name;
		}

		function normColumn( path ) {
			var dot = path.indexOf( '.' );
			return dot > 0 ? normTable( path.slice( 0, dot ) ) + path.slice( dot ) : path;
		}

		function parseTables( text ) {
			return ESTM.toList( text ).map( normTable ).filter( function ( v, i, a ) { return a.indexOf( v ) === i; } );
		}

		function parseColumns( text ) {
			return ESTM.toList( text ).map( normColumn ).filter( function ( v, i, a ) { return a.indexOf( v ) === i; } );
		}

		/** Entries that do not look right, shown as a hint (they are still saved: the other site may have different tables). */
		function skipProblems( kind ) {
			var out = [];
			var list = 'tables' === kind ? st.skip_tables : st.skip_columns;
			list.forEach( function ( item ) {
				if ( 'tables' === kind ) {
					if ( tablesKnown && ! logicalSet[ item ] ) {
						out.push( item );
					}
					return;
				}
				var dot = item.indexOf( '.' );
				if ( dot < 1 || dot === item.length - 1 || ( tablesKnown && ! logicalSet[ item.slice( 0, dot ) ] ) ) {
					out.push( item );
				}
			} );
			return out;
		}

		/* ---------------- Page head and notices */

		var previewBtn = ui.btn( __( 'Preview sample rows', 'estasoft-migrate' ), { onClick: runPreview } );
		var copyBtn = ui.btn( __( 'Create sanitized copy', 'estasoft-migrate' ), { kind: 'primary', onClick: startCopy } );

		var safeNote = ui.note(
			'tint',
			__( 'The sanitizer never changes this site. It masks values while it writes the copy, so the live database keeps its real data.', 'estasoft-migrate' ),
			{ title: __( 'Your live site stays as it is.', 'estasoft-migrate' ) }
		);

		/* ---------------- Profile cards */

		var profileEls = data.profiles.map( function ( p ) {
			var el = h(
				'button',
				{ type: 'button', class: 'opt', 'aria-pressed': 'false', onclick: function () { pickProfile( p.id, el ); } },
				h( 'span.t', p.name ),
				h( 'span.sub', p.text )
			);
			return { id: p.id, el: el };
		} );

		var profileSection = h(
			'section',
			{ 'aria-labelledby': 'san-profile-h' },
			h( 'h2', { id: 'san-profile-h', style: 'font-size:16px;margin-bottom:10px' }, __( 'Profile', 'estasoft-migrate' ) ),
			h( 'div.g-auto', { style: 'grid-template-columns:repeat(auto-fit,minmax(260px,1fr))' }, profileEls.map( function ( p ) { return p.el; } ) )
		);

		function pickProfile( id, el ) {
			if ( id === st.profile ) {
				return;
			}
			function apply() {
				// The administrator switch follows the profile unless it was changed by hand.
				var followed = !! st.keep_admins === !! profiles[ st.profile ].keep_admins;
				st.profile = id;
				resetToProfile();
				if ( followed ) {
					st.keep_admins = !! profiles[ id ].keep_admins;
				}
				refresh();
			}
			if ( ! isCustomised() ) {
				apply();
				return;
			}
			ui.confirm( {
				title: sprintf(
					/* translators: %s: name of a sanitizer profile */
					__( 'Switch to “%s”?', 'estasoft-migrate' ),
					profiles[ id ].name
				),
				message: __( 'You changed some rules or staging safety switches. Switching replaces them with the defaults of the new profile. The accounts you keep and the skipped tables stay as they are.', 'estasoft-migrate' ),
				confirm: __( 'Switch profile', 'estasoft-migrate' ),
			} ).then( function ( ok ) {
				if ( ok ) {
					apply();
				}
				el.focus();
			} );
		}

		/* ---------------- Rules table */

		var rulesSummary = h( 'span.sub' );
		var resetBtn = ui.btn( __( 'Reset to profile defaults', 'estasoft-migrate' ), { kind: 'quiet', size: 'sm', onClick: function () {
			resetToProfile();
			refresh();
			ui.toast( __( 'Rules and safety switches are back to the profile defaults.', 'estasoft-migrate' ) );
		} } );
		resetBtn.hidden = true;

		var ruleEls = data.rules.map( function ( r ) {
			var nameId = ESTM.uid( 'san-rule' );
			var control = sw( { checked: st.rules[ r.id ], labelledBy: nameId, onToggle: function ( on ) {
				st.rules[ r.id ] = on;
				refresh();
			} } );
			var row = h(
				'div.rrow',
				{ role: 'row' },
				h( 'div', { role: 'cell' }, control ),
				h( 'div', { role: 'cell' }, h( 'span.cell-main', { id: nameId }, r.what ), h( 'span.cell-sub', r.where ) ),
				h( 'div', { role: 'cell' }, r.how ),
				h( 'div.mono', { role: 'cell' }, r.example )
			);
			return { id: r.id, control: control, row: row };
		} );

		var rulesCard = h(
			'section.card.flush',
			h( 'div.card-h', h( 'h2', __( 'What gets changed', 'estasoft-migrate' ) ), h( 'div.row', rulesSummary, resetBtn ) ),
			h(
				'div',
				{ role: 'table', 'aria-label': __( 'Masking rules', 'estasoft-migrate' ) },
				h(
					'div.rrow.head',
					{ role: 'row' },
					h( 'span', { role: 'columnheader' }, __( 'On', 'estasoft-migrate' ) ),
					h( 'span', { role: 'columnheader' }, __( 'Data', 'estasoft-migrate' ) ),
					h( 'span', { role: 'columnheader' }, __( 'What happens', 'estasoft-migrate' ) ),
					h( 'span', { role: 'columnheader' }, __( 'Example', 'estasoft-migrate' ) )
				),
				ruleEls.map( function ( r ) { return r.row; } )
			),
			h( 'div.card-f', h( 'span.sub', __( 'Inside one copy the same real value always becomes the same fake one, so counts and relationships stay intact.', 'estasoft-migrate' ) ) )
		);

		/* ---------------- Sanitize on export */

		var exportId = ESTM.uid( 'san-export' );
		var exportSw = sw( { checked: st.on_export, labelledBy: exportId, onToggle: function ( on ) {
			st.on_export = on;
			refresh();
		} } );
		var exportCard = h(
			'section.card.hi',
			h( 'div.card-h', { style: 'margin-bottom:6px' }, h( 'h2', { id: exportId }, __( 'Sanitize on export', 'estasoft-migrate' ) ), exportSw ),
			h( 'p.sub', __( 'Sensitive values are masked while the package is written. The unmasked data never reaches the package file or the other site.', 'estasoft-migrate' ) ),
			h( 'p.sub', { style: 'margin-top:8px' }, __( 'When this is on, New migration starts with sanitizing already selected.', 'estasoft-migrate' ) )
		);

		/* ---------------- Staging safety */

		var safetySummary = h( 'span.sub' );
		var safetyEls = data.safety.map( function ( s ) {
			var tid = ESTM.uid( 'san-safe' );
			var control = sw( { checked: st.safety[ s.id ], labelledBy: tid, describedBy: tid + '-d', onToggle: function ( on ) {
				st.safety[ s.id ] = on;
				refresh();
			} } );
			var row = h( 'div.set.san-set', h( 'div.tx', h( 'b', { id: tid }, s.title ), h( 'span', { id: tid + '-d' }, s.text ) ), h( 'div.ctl', control ) );
			return { id: s.id, control: control, row: row };
		} );
		var safetyCard = h(
			'section.card',
			h( 'div.card-h', h( 'h2', __( 'Staging safety', 'estasoft-migrate' ) ), safetySummary ),
			safetyEls.map( function ( s ) { return s.row; } )
		);

		/* ---------------- Keep unchanged */

		var keepAdminsId = ESTM.uid( 'san-keepadm' );
		var keepAdminsSw = sw( { checked: st.keep_admins, labelledBy: keepAdminsId, describedBy: keepAdminsId + '-d', onToggle: function ( on ) {
			st.keep_admins = on;
			refresh();
		} } );
		var adminLogins = data.admins.map( function ( a ) { return a.login; } );
		var keepAdminsRow = h(
			'div.set.san-set',
			{ style: 'padding-top:0;border-top:0' },
			h(
				'div.tx',
				h( 'b', { id: keepAdminsId }, __( 'Keep all administrator accounts', 'estasoft-migrate' ) ),
				h( 'span', { id: keepAdminsId + '-d' }, __( 'Administrators keep their real names, emails and password hashes, so you can still sign in to the copy. Switch this off when the copy leaves your hands.', 'estasoft-migrate' ) )
			),
			h( 'div.ctl', keepAdminsSw )
		);
		var keepAdminsList = h( 'p.sub', { style: 'margin-top:-4px' } );

		var chipsBox = h( 'div.chips', { 'aria-live': 'off' } );
		var addToggle = ui.btn( __( 'Add account', 'estasoft-migrate' ), { kind: 'quiet', size: 'sm', onClick: function () {
			var open = addPanel.hidden;
			addPanel.hidden = ! open;
			addToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				addInput.focus();
			}
		} } );
		addToggle.setAttribute( 'aria-expanded', 'false' );
		addToggle.setAttribute( 'aria-controls', 'san-add-panel' );

		var addInput = ui.input( { placeholder: __( 'admin or name@example.com', 'estasoft-migrate' ), onKeydown: function ( ev ) {
			if ( 'Enter' === ev.key ) {
				ev.preventDefault();
				addTyped();
			}
		} } );
		var addField = inputField(
			__( 'Login, email or user ID', 'estasoft-migrate' ),
			addInput,
			h( 'div.input-group', addInput, ui.btn( __( 'Add', 'estasoft-migrate' ), { onClick: addTyped } ) ),
			__( 'Accounts that do not exist are ignored.', 'estasoft-migrate' )
		);
		var adminRows = data.admins.map( function ( a ) {
			var nameId = ESTM.uid( 'san-adm' );
			var btn = ui.btn( __( 'Add', 'estasoft-migrate' ), { size: 'sm', kind: 'quiet', onClick: function () { toggleKept( a.login ); } } );
			btn.id = ESTM.uid( 'san-admbtn' );
			btn.setAttribute( 'aria-labelledby', btn.id + ' ' + nameId );
			var row = h(
				'li.row',
				{ style: 'justify-content:space-between;flex-wrap:nowrap' },
				h( 'span', { id: nameId, class: 'break' }, a.login, h( 'span.cell-sub', a.email ) ),
				btn
			);
			return { login: a.login, btn: btn, row: row };
		} );
		var adminList = adminRows.length
			? h(
				'div.stack.tight',
				h( 'span.lbl', __( 'Administrators of this site', 'estasoft-migrate' ) ),
				h( 'ul.stack.tight', adminRows.map( function ( a ) { return a.row; } ) )
			)
			: null;
		var addPanel = h( 'div.stack.san-add', { id: 'san-add-panel', hidden: true }, addField, adminList );

		function addTyped() {
			var value = addInput.value.trim();
			if ( ! value ) {
				addField.setError( __( 'Type a login, an email address or a user ID.', 'estasoft-migrate' ) );
				return;
			}
			if ( st.keep_users.indexOf( value ) >= 0 ) {
				addField.setError( __( 'That account is already in the list.', 'estasoft-migrate' ) );
				return;
			}
			if ( st.keep_users.length >= MAX_KEPT ) {
				addField.setError( sprintf( /* translators: %d: most accounts that can be kept */ __( 'You can keep up to %d accounts.', 'estasoft-migrate' ), MAX_KEPT ) );
				return;
			}
			addField.setError( '' );
			st.keep_users.push( value );
			addInput.value = '';
			refresh();
			addInput.focus();
		}

		function toggleKept( login ) {
			var at = st.keep_users.indexOf( login );
			if ( at >= 0 ) {
				st.keep_users.splice( at, 1 );
			} else if ( st.keep_users.length < MAX_KEPT ) {
				st.keep_users.push( login );
			}
			refresh();
		}

		function drawChips() {
			ESTM.replace( chipsBox, st.keep_users.map( function ( user, i ) {
				return h(
					'span.chip.on',
					user,
					h( 'button', {
						type: 'button',
						'aria-label': sprintf( /* translators: %s: login or email of an account */ __( 'Stop keeping %s unchanged', 'estasoft-migrate' ), user ),
						onclick: function () {
							st.keep_users.splice( i, 1 );
							refresh();
							var next = chipsBox.querySelectorAll( 'button' );
							( next[ Math.min( i, next.length - 1 ) ] || addToggle ).focus();
						},
					}, '×' )
				);
			} ) );
		}

		// Skip tables and columns.
		var skipTablesArea = ui.textarea( { rows: 3, mono: true, placeholder: 'usermeta\noptions', onInput: function () {
			st.skip_tables = parseTables( skipTablesArea.value );
			refresh();
		} } );
		skipTablesArea.value = st.skip_tables.join( '\n' );
		skipTablesArea.addEventListener( 'change', function () { skipTablesArea.value = st.skip_tables.join( '\n' ); } );
		var skipTablesHint = h( 'span.hint', { 'aria-live': 'off' } );
		var skipTablesField = inputField(
			__( 'Tables to leave unmasked', 'estasoft-migrate' ),
			skipTablesArea,
			h( 'div.stack.tight', skipTablesArea, skipTablesHint ),
			__( 'One per line or separated by commas. Write the name without the prefix, for example usermeta.', 'estasoft-migrate' )
		);

		var skipColsArea = ui.textarea( { rows: 3, mono: true, placeholder: 'users.user_email\ncomments.comment_author', onInput: function () {
			st.skip_columns = parseColumns( skipColsArea.value );
			refresh();
		} } );
		skipColsArea.value = st.skip_columns.join( '\n' );
		skipColsArea.addEventListener( 'change', function () { skipColsArea.value = st.skip_columns.join( '\n' ); } );
		var skipColsHint = h( 'span.hint', { 'aria-live': 'off' } );
		var skipColsField = inputField(
			__( 'Columns to leave unmasked', 'estasoft-migrate' ),
			skipColsArea,
			h( 'div.stack.tight', skipColsArea, skipColsHint ),
			__( 'One per line or separated by commas, written as table.column, for example users.user_email.', 'estasoft-migrate' )
		);

		var skipSummary = ui.btn( '', { kind: 'link', onClick: function () {
			var open = skipPanel.hidden;
			skipPanel.hidden = ! open;
			skipSummary.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} } );
		skipSummary.setAttribute( 'aria-controls', 'san-skip-panel' );
		skipSummary.setAttribute( 'aria-expanded', st.skip_tables.length || st.skip_columns.length ? 'true' : 'false' );
		var skipPanel = h( 'div.stack', { id: 'san-skip-panel', style: 'margin-top:12px', hidden: ! ( st.skip_tables.length || st.skip_columns.length ) }, skipTablesField, skipColsField );

		var keepCard = h(
			'section.card',
			h( 'h2', { style: 'margin-bottom:6px' }, __( 'Keep unchanged', 'estasoft-migrate' ) ),
			h( 'p.sub', { style: 'margin-bottom:12px' }, __( 'These accounts keep their real details and passwords in the copy.', 'estasoft-migrate' ) ),
			keepAdminsRow,
			keepAdminsList,
			h( 'div.row', { style: 'margin-top:8px' }, chipsBox, addToggle ),
			addPanel,
			h( 'hr.hr', { style: 'margin:16px 0' } ),
			h( 'p.sub', __( 'Skip tables or columns:', 'estasoft-migrate' ), ' ', skipSummary ),
			skipPanel
		);

		/* ---------------- Preview */

		var previewBox = h( 'div', { 'aria-live': 'off' } );

		function runPreview() {
			var started = ESTM.post( 'sanitizer/preview', { profile: st.profile, overrides: overrides(), per_table: 3 } ).then( function ( res ) {
				ESTM.replace( previewBox, previewCard( res ) );
				var heading = previewBox.querySelector( 'h2' );
				if ( heading ) {
					heading.setAttribute( 'tabindex', '-1' );
					ui.reveal( heading, previewBox );
				}
			}, function ( err ) {
				ESTM.replace( previewBox, ui.card( { title: __( 'Preview of sample rows', 'estasoft-migrate' ) }, ui.note( 'err', ESTM.errorMessage( err ), { title: __( 'The preview could not be made.', 'estasoft-migrate' ) } ) ) );
			} );
			ESTM.replace( previewBox, ui.card( { title: __( 'Preview of sample rows', 'estasoft-migrate' ) }, h( 'p.sub', ui.spinner( __( 'Reading sample rows', 'estasoft-migrate' ) ), ' ', __( 'Reading a few rows from each table…', 'estasoft-migrate' ) ) ) );
			ui.busy( previewBtn, started );
		}

		function previewCard( res ) {
			var tables = res.tables || [];
			var body = [
				ui.note(
					'tint',
					[
						__( 'This is only a preview. Fake values are different on every run, so the copy will not match this exactly.', 'estasoft-migrate' ),
						' ',
						__( '“Before” shows real values from this site. They are shown to you only and are not saved anywhere.', 'estasoft-migrate' ),
					],
					{ title: __( 'Nothing was changed.', 'estasoft-migrate' ) }
				),
			];
			if ( res.kept > 0 ) {
				body.push( h( 'p.sub', sprintf(
					/* translators: %s: number of accounts */
					_n( '%s account is kept unchanged and is left out of this preview.', '%s accounts are kept unchanged and are left out of this preview.', res.kept, 'estasoft-migrate' ),
					ESTM.fmt.number( res.kept )
				) ) );
			}
			if ( ! tables.length ) {
				body.push( ui.empty(
					__( 'No sample row would change', 'estasoft-migrate' ),
					__( 'With these settings no row that was looked at is masked. Turn on at least one rule, or check which accounts and tables you keep unchanged.', 'estasoft-migrate' )
				) );
			}
			return h(
				'section.card',
				h( 'div.card-h', h( 'h2', __( 'Preview of sample rows', 'estasoft-migrate' ) ), ui.btn( __( 'Close preview', 'estasoft-migrate' ), { kind: 'quiet', size: 'sm', onClick: function () {
					ESTM.replace( previewBox );
					previewBtn.focus();
				} } ) ),
				h( 'div.stack', body, tables.map( previewTable ) )
			);
		}

		function previewTable( t ) {
			return h(
				'div.stack.tight.san-tbl',
				h( 'div.row', h( 'h3.mono', t.table ), t.dropped
					? ui.badge( sprintf(
						/* translators: %s: number of rows */
						_n( '%s row is left out of the copy', '%s rows are left out of the copy', t.dropped, 'estasoft-migrate' ),
						ESTM.fmt.number( t.dropped )
					), 'warn' )
					: null ),
				t.examples.map( function ( ex, i ) {
					return h(
						'div.san-ex',
						h( 'span.cell-sub', sprintf(
							/* translators: %d: example number */
							__( 'Example %d', 'estasoft-migrate' ),
							i + 1
						) ),
						ex.changed.map( function ( col ) {
							var before = ex.before && undefined !== ex.before[ col ] ? String( ex.before[ col ] ) : '';
							var after = ex.after && undefined !== ex.after[ col ] ? String( ex.after[ col ] ) : '';
							return h(
								'div.stack.tight',
								h( 'span.san-col', col ),
								h( 'div.diff', h( 'del', before ? clip( before, 240 ) : __( '(empty)', 'estasoft-migrate' ) ), h( 'ins', after ? clip( after, 240 ) : __( '(empty)', 'estasoft-migrate' ) ) )
							);
						} )
					);
				} )
			);
		}

		/* ---------------- Save */

		var savebarText = h( 'span', { role: 'status' } );
		var savebarDirty = null;
		var savebarSave = ui.btn( __( 'Save settings', 'estasoft-migrate' ), { onClick: save } );
		var savebarUndo = ui.btn( __( 'Discard changes', 'estasoft-migrate' ), { kind: 'quiet', onClick: function () {
			st = fromSaved( clone( lastSaved ) );
			syncAreas();
			refresh();
		} } );
		var savebar = h( 'div.san-savebar', savebarText, h( 'span.sp' ), savebarUndo, savebarSave );

		function save() {
			var request = ESTM.post( 'sanitizer/save', payload() ).then( function ( res ) {
				lastSaved = res && res.saved ? res.saved : payload();
				st = fromSaved( lastSaved );
				savedSig = signature( st );
				syncAreas();
				refresh();
				ui.toast( __( 'Sanitizer settings saved.', 'estasoft-migrate' ) );
			}, function ( err ) {
				ui.toast( ESTM.errorMessage( err ), 'err' );
			} );
			ui.busy( savebarSave, request );
			return request;
		}

		/** Put the skip lists back into the text boxes (after a save or a discard). */
		function syncAreas() {
			skipTablesArea.value = st.skip_tables.join( '\n' );
			skipColsArea.value = st.skip_columns.join( '\n' );
		}

		window.addEventListener( 'beforeunload', function ( ev ) {
			if ( isDirty() && ! leaving ) {
				ev.preventDefault();
				ev.returnValue = '';
			}
		} );

		/* ---------------- Create a sanitized copy */

		function startCopy() {
			var request = ESTM.get( 'settings' ).then( function ( s ) {
				copyDialog( !! ( s && s.values && s.values.encrypt_packages ) );
			}, function ( err ) {
				ui.toast( ESTM.errorMessage( err ), 'err' );
			} );
			ui.busy( copyBtn, request );
		}

		function planLines( protection ) {
			var lines = [
				sprintf(
					/* translators: %s: name of a sanitizer profile */
					__( 'Sanitized copy with the “%s” profile', 'estasoft-migrate' ),
					profiles[ st.profile ].name
				),
				sprintf(
					/* translators: 1: number of masking rules that are on, 2: number of rules */
					__( '%1$d of %2$d masking rules on', 'estasoft-migrate' ),
					count( st.rules, ruleIds ),
					ruleIds.length
				),
			];
			var safe = data.safety.filter( function ( s ) { return st.safety[ s.id ]; } ).map( function ( s ) { return s.title; } );
			lines.push( safe.length
				? sprintf(
					/* translators: %s: list of staging safety switches */
					__( 'Staging safety: %s', 'estasoft-migrate' ),
					listText( safe )
				)
				: __( 'No staging safety switches', 'estasoft-migrate' ) );
			var kept = [];
			if ( st.keep_admins ) {
				kept.push( __( 'all administrators', 'estasoft-migrate' ) );
			}
			kept = kept.concat( st.keep_users );
			if ( kept.length ) {
				lines.push( sprintf(
					/* translators: %s: list of accounts that stay unchanged */
					__( 'Kept unchanged: %s', 'estasoft-migrate' ),
					listText( kept )
				) );
			}
			if ( st.skip_tables.length ) {
				lines.push( sprintf(
					/* translators: %s: list of tables */
					__( 'Tables not masked: %s', 'estasoft-migrate' ),
					listText( st.skip_tables )
				) );
			}
			if ( st.skip_columns.length ) {
				lines.push( sprintf(
					/* translators: %s: list of columns */
					__( 'Columns not masked: %s', 'estasoft-migrate' ),
					listText( st.skip_columns )
				) );
			}
			lines.push( protection );
			lines.push( __( 'This site is not changed. Values are masked while the copy is written.', 'estasoft-migrate' ) );
			return lines;
		}

		function copyDialog( encryptRequired ) {
			var mode = 'password';
			var dlg;
			var pw1 = ui.password( {} );
			var pw2 = ui.password( {} );
			var pw1Field = inputField( __( 'Password', 'estasoft-migrate' ), pw1.input, pw1, __( 'Anyone who opens the copy needs it. A lost password cannot be recovered.', 'estasoft-migrate' ) );
			var pw2Field = inputField( __( 'Repeat the password', 'estasoft-migrate' ), pw2.input, pw2 );
			var pwBox = h( 'div.stack.tight', pw1Field, pw2Field );
			var nameInput = ui.input( { placeholder: __( 'For example: staging copy for the agency', 'estasoft-migrate' ), onKeydown: function ( ev ) {
				if ( 'Enter' === ev.key ) {
					ev.preventDefault();
					submit();
				}
			} } );
			var nameField = inputField(
				[ __( 'Name', 'estasoft-migrate' ), h( 'span.muted', ' ' + __( '(optional)', 'estasoft-migrate' ) ) ],
				nameInput,
				null,
				__( 'Helps you find this copy in Packages.', 'estasoft-migrate' )
			);

			var choices = [
				{ id: 'password', title: __( 'Protect with a password', 'estasoft-migrate' ), text: __( 'The copy is encrypted. You need the password to open it, wherever you take it.', 'estasoft-migrate' ) },
				{ id: 'sitekey', title: __( 'Protect with this site’s key only', 'estasoft-migrate' ), text: __( 'The copy is encrypted without a password. Only this site can open it again, so it is not for handing to someone else.', 'estasoft-migrate' ) },
			];
			if ( ! encryptRequired ) {
				choices.push( { id: 'none', title: __( 'No protection', 'estasoft-migrate' ), text: __( 'The file is not encrypted. Anyone who gets the file can read it.', 'estasoft-migrate' ) } );
			}
			var optEls = choices.map( function ( c ) {
				var el = h( 'button', { type: 'button', class: 'opt', 'aria-pressed': 'false', onclick: function () { choose( c.id ); } }, h( 'span.t', c.title ), h( 'span.d', c.text ) );
				return { id: c.id, el: el };
			} );

			function choose( id ) {
				mode = id;
				optEls.forEach( function ( o ) {
					o.el.classList.toggle( 'sel', o.id === id );
					o.el.setAttribute( 'aria-pressed', o.id === id ? 'true' : 'false' );
				} );
				pwBox.hidden = 'password' !== id;
				pw1Field.setError( '' );
				pw2Field.setError( '' );
			}
			choose( 'password' );

			function protectionInput() {
				if ( 'password' === mode ) {
					return { password: pw1.input.value };
				}
				return 'sitekey' === mode ? { protect_with_site_key: true } : {};
			}

			function submit() {
				if ( 'password' === mode ) {
					pw1Field.setError( '' );
					pw2Field.setError( '' );
					if ( ! pw1.input.value ) {
						pw1Field.setError( __( 'Enter a password, or choose another way to protect the copy.', 'estasoft-migrate' ) );
						pw1.input.focus();
						return;
					}
					if ( pw1.input.value !== pw2.input.value ) {
						pw2Field.setError( __( 'The two passwords are not the same.', 'estasoft-migrate' ) );
						pw2.input.focus();
						return;
					}
				}
				var input = { sanitize: { profile: st.profile, overrides: overrides() } };
				Object.assign( input, protectionInput() );
				var label = nameInput.value.trim();
				if ( label ) {
					input.label = label;
				}
				var protectionLine = 'password' === mode
					? __( 'Encrypted with a password', 'estasoft-migrate' )
					: ( 'sitekey' === mode ? __( 'Encrypted with this site’s key (only this site can open it)', 'estasoft-migrate' ) : __( 'Not encrypted', 'estasoft-migrate' ) );
				var plan = {
					mode: 'sanitize',
					type: 'export',
					input: input,
					title: __( 'Create sanitized copy', 'estasoft-migrate' ),
					lines: planLines( protectionLine ),
					back: 'sanitizer',
				};
				if ( ! ESTM.draft.set( 'plan', plan ) ) {
					ui.toast( __( 'Your browser would not keep the plan for the next step. Allow site data for this page and try again.', 'estasoft-migrate' ), 'err' );
					return;
				}
				ESTM.draft.set( 'sanitizer-form', payload() );
				leaving = true;
				dlg.close( true );
				ESTM.go( ESTM.url( 'preflight' ) );
			}

			var noRules = 0 === count( st.rules, ruleIds );
			dlg = ui.dialog( {
				title: __( 'Create a sanitized copy', 'estasoft-migrate' ),
				body: [
					h( 'p', __( 'The copy is written as a package. This site is not changed: values are masked while the package is written.', 'estasoft-migrate' ) ),
					noRules ? ui.note( 'warn', __( 'Every masking rule is off, so this copy would contain real personal data.', 'estasoft-migrate' ) ) : null,
					h( 'div.stack.tight', { role: 'group', 'aria-label': __( 'How to protect the copy', 'estasoft-migrate' ) }, optEls.map( function ( o ) { return o.el; } ) ),
					pwBox,
					nameField,
				],
				actions: [
					ui.btn( __( 'Cancel', 'estasoft-migrate' ), { kind: 'quiet', onClick: function () { dlg.close( false ); copyBtn.focus(); } } ),
					ui.btn( __( 'Continue to checks', 'estasoft-migrate' ), { kind: 'primary', onClick: submit } ),
				],
			} );
			pw1.input.addEventListener( 'keydown', function ( ev ) {
				if ( 'Enter' === ev.key ) {
					ev.preventDefault();
					pw2.input.focus();
				}
			} );
			pw2.input.addEventListener( 'keydown', function ( ev ) {
				if ( 'Enter' === ev.key ) {
					ev.preventDefault();
					submit();
				}
			} );
		}

		/* ---------------- Keep everything in step with the state (never touches a text box) */

		function refresh() {
			profileEls.forEach( function ( p ) {
				var on = p.id === st.profile;
				p.el.classList.toggle( 'sel', on );
				p.el.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			ruleEls.forEach( function ( r ) { r.control.setChecked( st.rules[ r.id ] ); } );
			safetyEls.forEach( function ( s ) { s.control.setChecked( st.safety[ s.id ] ); } );
			exportSw.setChecked( st.on_export );
			keepAdminsSw.setChecked( st.keep_admins );

			ESTM.replace( rulesSummary, sprintf(
				/* translators: 1: number of rules that are on, 2: number of rules */
				__( '%1$d of %2$d rules on', 'estasoft-migrate' ),
				count( st.rules, ruleIds ),
				ruleIds.length
			) );
			ESTM.replace( safetySummary, sprintf(
				/* translators: 1: number of switches that are on, 2: number of switches */
				__( '%1$d of %2$d on', 'estasoft-migrate' ),
				count( st.safety, safeIds ),
				safeIds.length
			) );
			resetBtn.hidden = ! isCustomised();

			keepAdminsList.hidden = ! ( st.keep_admins && adminLogins.length );
			ESTM.replace( keepAdminsList, sprintf(
				/* translators: %s: logins of administrator accounts */
				__( 'Kept because they are administrators: %s', 'estasoft-migrate' ),
				listText( adminLogins )
			) );
			drawChips();
			adminRows.forEach( function ( a ) {
				var kept = st.keep_users.indexOf( a.login ) >= 0;
				a.btn.textContent = st.keep_admins ? __( 'Kept', 'estasoft-migrate' ) : ( kept ? __( 'Remove', 'estasoft-migrate' ) : __( 'Add', 'estasoft-migrate' ) );
				a.btn.disabled = st.keep_admins;
			} );

			var tProblems = skipProblems( 'tables' );
			var cProblems = skipProblems( 'columns' );
			ESTM.replace( skipTablesHint, tProblems.length
				? sprintf(
					/* translators: %s: names that were typed */
					__( 'Not a table on this site: %s. It is still saved.', 'estasoft-migrate' ),
					listText( tProblems )
				)
				: null );
			ESTM.replace( skipColsHint, cProblems.length
				? sprintf(
					/* translators: %s: names that were typed */
					__( 'Not a column of this site, written as table.column: %s. It is still saved.', 'estasoft-migrate' ),
					listText( cProblems )
				)
				: null );
			var skipTotal = st.skip_tables.length + st.skip_columns.length;
			skipSummary.textContent = skipTotal
				? sprintf(
					/* translators: 1: number of tables, 2: number of columns */
					__( '%1$d tables, %2$d columns', 'estasoft-migrate' ),
					st.skip_tables.length,
					st.skip_columns.length
				)
				: __( 'none selected', 'estasoft-migrate' );

			var dirty = isDirty();
			savebar.classList.toggle( 'dirty', dirty );
			savebarUndo.hidden = ! dirty;
			savebarSave.classList.toggle( 'btn-primary', dirty );
			// Rewritten only when the state flips, so a screen reader does not repeat it at every keystroke.
			if ( savebarDirty !== dirty ) {
				savebarDirty = dirty;
				ESTM.replace( savebarText, dirty
					? h( 'b', __( 'You have unsaved changes.', 'estasoft-migrate' ) )
					: h( 'span.sub', __( 'All changes are saved.', 'estasoft-migrate' ) ) );
			}
		}

		/* ---------------- Draw */

		ESTM.replace(
			root,
			ui.pageHead( title, lede, [ previewBtn, copyBtn ] ),
			safeNote,
			profileSection,
			h(
				'div.cols',
				h( 'div.main.stack', rulesCard, previewBox ),
				h( 'div.side.stack', exportCard, safetyCard, keepCard )
			),
			savebar
		);
		refresh();
	}
}() );
