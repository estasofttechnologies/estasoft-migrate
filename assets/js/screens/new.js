/**
 * New migration screen: pick a method (export a package, push to another site, pull from another site),
 * set the options, then hand a *plan* to Pre-flight. Nothing is written here.
 * Data: GET tables, GET sanitizer, GET settings; POST settings/test-connection.
 * Hand-off: ESTM.draft 'plan' (docs/UI.md), then the Pre-flight screen.
 * Coming back from Pre-flight (?edit=1) restores the form from the plan, so nothing has to be typed twice.
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

	var LAST_REMOTE = 'estm:last-remote';
	var METHODS = [ 'export', 'push', 'pull' ];

	/* ---------------------------------------------------------------- Small helpers */

	/** The last address used for push or pull. Never the key. */
	function lastRemote() {
		try {
			return window.localStorage.getItem( LAST_REMOTE ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function rememberRemote( url ) {
		try {
			window.localStorage.setItem( LAST_REMOTE, url );
		} catch ( e ) {
			return;
		}
	}

	function cleanUrl( url ) {
		return String( url || '' ).trim().replace( /\/+$/, '' );
	}

	/** Same rule as the server. */
	function isHttpUrl( url ) {
		return /^https?:\/\/[^\s/]+(?:\/[^\s?#]*)?$/i.test( url );
	}

	function hostOf( url ) {
		return String( url || '' ).replace( /^https?:\/\//i, '' ).replace( /\/+$/, '' );
	}

	function code( text ) {
		return h( 'code', text );
	}

	/** Put nodes where a translated sentence has %1$s, %2$s… so the sentence stays one translatable string. A plain %s works for a single node. */
	function fill( template, nodes ) {
		var next = 0;
		return String( template ).split( /(%\d+\$s|%s)/ ).map( function ( part ) {
			var m = /^%(?:(\d+)\$)?s$/.exec( part );
			if ( ! m ) {
				return part;
			}
			var at = m[ 1 ] ? m[ 1 ] - 1 : next++;
			return undefined !== nodes[ at ] ? nodes[ at ] : part;
		} );
	}

	function byId( list, id ) {
		for ( var i = 0; i < list.length; i += 1 ) {
			if ( list[ i ].id === id ) {
				return list[ i ];
			}
		}
		return null;
	}

	function dbName( db ) {
		if ( ! db || ! db.type ) {
			return '';
		}
		var name = 'mariadb' === db.type ? 'MariaDB' : ( 'mysql' === db.type ? 'MySQL' : db.type );
		return db.version ? name + ' ' + db.version : name;
	}

	/** Password input with a visible label, tied to its hint and error the way ui.field does for plain inputs. */
	function passwordField( o ) {
		var group = ui.password( { id: o.id, placeholder: o.placeholder, onInput: o.onInput } );
		var input = group.input;
		var field = ui.field( { id: o.id, label: o.label, hint: o.hint } );
		field.insertBefore( group, field.querySelector( '.hint, .field-error' ) );
		input.setAttribute( 'aria-describedby', [ o.hint ? o.id + '-hint' : '', o.id + '-err' ].filter( Boolean ).join( ' ' ) );
		var base = field.setError;
		field.setError = function ( message ) {
			base( message );
			if ( message ) {
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				input.removeAttribute( 'aria-invalid' );
			}
		};
		field.input = input;
		return field;
	}

	/** One line of the Options card: text on one side, control on the other. */
	function makeRow( labelFor ) {
		var title = labelFor ? h( 'label', { for: labelFor } ) : h( 'b' );
		var desc = h( 'span' );
		var ctl = h( 'div.ctl' );
		return { el: h( 'div.set', h( 'div.tx', title, desc ), ctl ), title: title, desc: desc, ctl: ctl };
	}

	function okNote( kids ) {
		return h( 'div.note', { role: 'status' }, h( 'span.ic', { 'aria-hidden': 'true' }, '✓' ), h( 'div', kids ) );
	}

	/* ---------------------------------------------------------------- Table picker */

	function buildPicker( tables, picked, onChange ) {
		var count = h( 'span.sub', { 'aria-live': 'polite' } );
		var filter = ui.input( { type: 'search', placeholder: __( 'Filter tables', 'estasoft-migrate' ), ariaLabel: __( 'Filter tables', 'estasoft-migrate' ), className: 'pick-filter' } );
		var body = h( 'tbody' );
		var rows = [];

		tables.forEach( function ( t, i ) {
			var id = 'estm-pick-' + i;
			var box = ui.checkbox( { id: id, checked: !! picked[ t.name ], onChange: function () {
				picked[ t.name ] = box.checked;
				update();
				onChange();
			} } );
			var tr = h( 'tr', h( 'td', box ), h( 'td.nowrap', h( 'label', { for: id, class: 'mono' }, t.name ) ), h( 'td.n', fmt.number( t.rows ) ), h( 'td.n.nowrap', fmt.bytes( t.bytes ) ) );
			rows.push( { t: t, tr: tr, box: box } );
			body.appendChild( tr );
		} );

		function selectedCount() {
			return rows.filter( function ( r ) { return picked[ r.t.name ]; } ).length;
		}

		function update() {
			/* translators: 1: number of tables selected, 2: number of tables */
			count.textContent = sprintf( _n( '%1$s of %2$s table selected', '%1$s of %2$s tables selected', rows.length, 'estasoft-migrate' ), fmt.number( selectedCount() ), fmt.number( rows.length ) );
		}

		function setShown( on ) {
			rows.forEach( function ( r ) {
				if ( ! r.tr.hidden ) {
					r.box.checked = on;
					picked[ r.t.name ] = on;
				}
			} );
			update();
			onChange();
		}

		filter.addEventListener( 'input', function () {
			var needle = filter.value.trim().toLowerCase();
			rows.forEach( function ( r ) {
				r.tr.hidden = needle && r.t.name.toLowerCase().indexOf( needle ) < 0;
			} );
		} );

		var bar = h(
			'div.pick-bar',
			filter,
			ui.btn( __( 'Select all', 'estasoft-migrate' ), { size: 'sm', onClick: function () { setShown( true ); } } ),
			ui.btn( __( 'Select none', 'estasoft-migrate' ), { size: 'sm', onClick: function () { setShown( false ); } } ),
			count
		);
		var table = h(
			'div.scroll.tbl-wrap-sm',
			h(
				'table.tbl',
				h( 'caption.sr-only', __( 'Tables of this site', 'estasoft-migrate' ) ),
				h( 'thead', h( 'tr',
					h( 'th', { scope: 'col' }, h( 'span.sr-only', __( 'Include', 'estasoft-migrate' ) ) ),
					h( 'th', { scope: 'col' }, __( 'Table', 'estasoft-migrate' ) ),
					h( 'th.n', { scope: 'col' }, __( 'Rows', 'estasoft-migrate' ) ),
					h( 'th.n', { scope: 'col' }, __( 'Size', 'estasoft-migrate' ) )
				) ),
				body
			)
		);
		update();

		return {
			el: h( 'div.pick', bar, table, h( 'p.hint.pick-hint', __( 'Select all and Select none apply to the tables shown.', 'estasoft-migrate' ) ) ),
			filter: filter,
			names: function () {
				return rows.filter( function ( r ) { return picked[ r.t.name ]; } ).map( function ( r ) { return r.t.name; } );
			},
			total: rows.length,
		};
	}

	/* ---------------------------------------------------------------- Screen */

	ESTM.screen( 'new', function ( root ) {
		var head = ui.pageHead( __( 'New migration', 'estasoft-migrate' ), __( 'Choose where the database goes and what happens to it on the way. Nothing is written until you start the run.', 'estasoft-migrate' ) );

		if ( ESTM.site && ESTM.site.multisite ) {
			ESTM.replace(
				root,
				head,
				ui.note( 'err', __( 'Estasoft Migrate works on single WordPress sites. On a multisite network nothing can be exported, pushed or pulled from here.', 'estasoft-migrate' ), { title: __( 'This is a multisite network.', 'estasoft-migrate' ) } ),
				h( 'div', ui.btn( __( 'Back to overview', 'estasoft-migrate' ), { href: ESTM.url( 'overview' ) } ) )
			);
			return;
		}

		// Coming back from Pre-flight to change something: start from the plan. Otherwise drop any old plan with its secrets.
		var restore = ESTM.params().edit ? ESTM.draft.get( 'plan' ) : null;
		if ( restore && 'new' !== restore.back ) {
			restore = null;
		}
		if ( ! restore ) {
			var old = ESTM.draft.get( 'plan' );
			if ( old && 'new' === old.back ) {
				ESTM.draft.clear( 'plan' );
			}
		}

		function load() {
			ESTM.replace( root, head, h( 'div.card', ui.skeleton( 6 ) ) );
			Promise.all( [ ESTM.get( 'tables' ), ESTM.get( 'sanitizer' ), ESTM.get( 'settings' ) ] ).then( function ( r ) {
				build( root, head, restore, r[ 0 ], r[ 1 ], r[ 2 ] );
			}, function ( err ) {
				ESTM.replace( root, head, ui.loadError( err, load ) );
			} );
		}

		load();
	} );

	function build( root, head, restore, tablesData, sanData, settings ) {
		var tables = tablesData.tables || [];
		var profiles = sanData.profiles || [];
		var saved = sanData.saved || {};
		var required = !! ( settings.values && settings.values.encrypt_packages );
		var form = restore && restore.form ? restore.form : null;
		var input0 = restore && restore.input ? restore.input : {};
		var thisUrl = cleanUrl( ESTM.site.url );
		var wanted = ESTM.params().method;

		var picked = {};
		tables.forEach( function ( t ) {
			picked[ t.name ] = form && form.picked ? form.picked.indexOf( t.name ) >= 0 : true;
		} );

		var S = {
			method: form && METHODS.indexOf( form.method ) >= 0 ? form.method : ( METHODS.indexOf( wanted ) >= 0 ? wanted : 'export' ),
			tablesMode: form && 'choose' === form.tablesMode ? 'choose' : 'all',
			rewrite: form ? false !== form.rewrite : true,
			sanitize: form ? !! form.sanitize : !! saved.on_export,
			profile: form && form.profile ? form.profile : ( saved.profile || 'staging' ),
			protection: form && form.protection ? form.protection : 'password',
			tested: null,
		};
		if ( ! byId( profiles, S.profile ) && profiles.length ) {
			S.profile = profiles[ 0 ].id;
		}
		if ( required && 'none' === S.protection ) {
			S.protection = 'password';
		}

		/* ---- Method */

		var methodSub = h( 'p.sub' );
		var seg = ui.seg(
			[
				{ value: 'export', label: __( 'Export a package', 'estasoft-migrate' ) },
				{ value: 'push', label: __( 'Push to another site', 'estasoft-migrate' ) },
				{ value: 'pull', label: __( 'Pull from another site', 'estasoft-migrate' ) },
			],
			S.method,
			function ( v ) {
				S.method = v;
				applyMethod();
			},
			{ label: __( 'Migration method', 'estasoft-migrate' ) }
		);
		var methodCard = h( 'section.card', h( 'div.card-h', h( 'h2', __( 'Method', 'estasoft-migrate' ) ), seg ), methodSub );

		/* ---- Other site */

		var urlInput = ui.input( { type: 'url', inputmode: 'url', placeholder: 'https://staging.example.com', value: input0.remote_url || lastRemote(), onInput: onUrlInput } );
		var urlField = ui.field(
			{ id: 'estm-remote-url', label: __( 'Site address', 'estasoft-migrate' ), hint: __( 'The other site needs Estasoft Migration & Database Sanitizer installed and active, with “Accept incoming migrations” turned on in its Settings.', 'estasoft-migrate' ) },
			urlInput
		);
		var keyField = passwordField( {
			id: 'estm-remote-key',
			label: __( 'Connection key', 'estasoft-migrate' ),
			placeholder: 'esm_…',
			hint: __( 'Copy it from the Settings screen of the other site. Every request is signed with it, so the key itself is never sent.', 'estasoft-migrate' ),
			onInput: onKeyInput,
		} );
		var keyInput = keyField.input;
		keyInput.value = input0.remote_key || '';
		[ urlInput, keyInput ].forEach( function ( el ) {
			el.addEventListener( 'keydown', function ( ev ) {
				if ( 'Enter' === ev.key ) {
					ev.preventDefault();
					testConnection();
				}
			} );
		} );

		var testBtn = ui.btn( __( 'Test connection', 'estasoft-migrate' ), { kind: 'dark', onClick: testConnection } );
		var testBadge = h( 'span', { 'aria-live': 'polite' } );
		var testResult = h( 'div.stack.tight', { 'aria-live': 'polite' } );
		var destTitle = h( 'h2', { style: 'margin-bottom:14px' } );
		var destCard = h(
			'section.card',
			destTitle,
			h( 'div.stack', { style: 'gap:18px' }, urlField, keyField, h( 'div.row', testBtn, testBadge ), testResult )
		);

		/* ---- Options: tables */

		var tablesErr = h( 'div.field-error', { role: 'alert', hidden: true } );
		var picker = buildPicker( tables, picked, function () {
			tablesErr.hidden = true;
			ESTM.replace( formError );
			refresh();
		} );
		picker.el.hidden = true;
		var tablesRow = makeRow();
		var tablesSeg = ui.seg(
			[ { value: 'all', label: __( 'All tables', 'estasoft-migrate' ) }, { value: 'choose', label: __( 'Choose tables', 'estasoft-migrate' ) } ],
			S.tablesMode,
			function ( v ) {
				S.tablesMode = v;
				tablesErr.hidden = true;
				picker.el.hidden = 'choose' !== v;
				refresh();
			},
			{ label: __( 'Tables', 'estasoft-migrate' ) }
		);
		tablesRow.ctl.appendChild( tablesSeg );
		tablesRow.el.appendChild( h( 'div.wide', picker.el, tablesErr ) );

		/* ---- Options: addresses */

		var rewriteRow = makeRow( 'estm-rewrite' );
		var rewriteSwitch = ui.switch( { id: 'estm-rewrite', checked: S.rewrite, onChange: function () {
			S.rewrite = rewriteSwitch.checked;
			refresh();
		} } );
		rewriteRow.ctl.appendChild( rewriteSwitch );

		/* ---- Options: sanitizer */

		var sanRow = makeRow( 'estm-sanitize' );
		var sanDetail = h( 'span' );
		sanRow.desc.parentNode.appendChild( sanDetail );
		var profSelect = ui.select(
			profiles.map( function ( p ) { return { value: p.id, label: p.name }; } ),
			{ id: 'estm-profile', value: S.profile, ariaLabel: __( 'Sanitizer profile', 'estasoft-migrate' ), disabled: ! S.sanitize, onChange: function () {
				S.profile = profSelect.value;
				refresh();
			} }
		);
		var sanSwitch = ui.switch( { id: 'estm-sanitize', checked: S.sanitize, onChange: function () {
			S.sanitize = sanSwitch.checked;
			profSelect.disabled = ! S.sanitize;
			refresh();
		} } );
		sanRow.ctl.appendChild( profSelect );
		sanRow.ctl.appendChild( sanSwitch );
		if ( ! profiles.length ) {
			sanRow.el.hidden = true;
			S.sanitize = false;
		}

		/* ---- Options: snapshot (push and pull) */

		var snapRow = makeRow( 'estm-snapshot' );
		snapRow.ctl.appendChild( ui.switch( { id: 'estm-snapshot', checked: true, disabled: true } ) );

		/* ---- Options: package protection (export) */

		var pwField = passwordField( {
			id: 'estm-pw',
			label: __( 'Password', 'estasoft-migrate' ),
			hint: __( 'At least 8 characters. You need it to import the package, and it cannot be recovered.', 'estasoft-migrate' ),
			onInput: function () { clearFormError(); pwField.setError( '' ); },
		} );
		var pw2Field = passwordField( {
			id: 'estm-pw2',
			label: __( 'Repeat the password', 'estasoft-migrate' ),
			onInput: function () { clearFormError(); pw2Field.setError( '' ); },
		} );
		pwField.input.value = input0.password || '';
		pw2Field.input.value = input0.password || '';
		var pwBox = h( 'div.pw-grid', pwField, pw2Field );
		var opts = {};

		function protectOption( value, title, text, disabled ) {
			var radio = h( 'input.sr-only', { type: 'radio', name: 'estm-protection', value: value, checked: S.protection === value, disabled: !! disabled, onchange: function () {
				S.protection = value;
				syncProtection();
				refresh();
			} } );
			var label = h( 'label.opt' + ( disabled ? '.is-disabled' : '' ), radio, h( 'span.t', title ), h( 'span.d', text ) );
			opts[ value ] = label;
			return label;
		}

		function syncProtection() {
			Object.keys( opts ).forEach( function ( k ) {
				opts[ k ].classList.toggle( 'sel', k === S.protection );
			} );
			pwBox.hidden = 'password' !== S.protection;
		}

		var protectRow = h(
			'div.set',
			h( 'div.tx', h( 'b', { id: 'estm-protect-title' }, __( 'Package protection', 'estasoft-migrate' ) ), h( 'span', __( 'A package holds the whole database, including email addresses and password hashes. Protect it, then keep it somewhere private.', 'estasoft-migrate' ) ) ),
			h(
				'div.wide',
				h(
					'div.opt-grid',
					{ role: 'radiogroup', 'aria-labelledby': 'estm-protect-title' },
					protectOption( 'password', __( 'Password', 'estasoft-migrate' ), __( 'Recommended. Locked with a password you choose, so it can be imported on any site.', 'estasoft-migrate' ) ),
					protectOption( 'site-key', __( 'This site’s key only', 'estasoft-migrate' ), __( 'Locked with a key made from this site’s security keys in wp-config.php. Only this site can open it.', 'estasoft-migrate' ) ),
					protectOption(
						'none',
						__( 'No protection', 'estasoft-migrate' ),
						required ? __( 'Your settings require every package to be protected.', 'estasoft-migrate' ) : __( 'Anyone who gets the file can read everything in it.', 'estasoft-migrate' ),
						required
					)
				),
				pwBox
			)
		);

		/* ---- Options: label */

		var labelInput = ui.input( { id: 'estm-label', placeholder: __( 'before-redesign', 'estasoft-migrate' ), value: input0.label || '' } );
		labelInput.setAttribute( 'maxlength', '80' );
		var labelRow = h(
			'div.set',
			h( 'div.tx', h( 'label', { for: 'estm-label' }, __( 'Label', 'estasoft-migrate' ), h( 'span.muted', { style: 'font-weight:400' }, ' ' + __( '(optional)', 'estasoft-migrate' ) ) ), h( 'span', __( 'A short name that helps you find this job and its package later.', 'estasoft-migrate' ) ) ),
			h( 'div.ctl', labelInput )
		);

		/* ---- Footer and summary */

		var formError = h( 'div', { 'aria-live': 'assertive' } );
		var continueBtn = ui.btn( __( 'Continue to pre-flight', 'estasoft-migrate' ), { kind: 'primary', onClick: onContinue } );
		var optionsCard = h(
			'section.card',
			h( 'h2', { style: 'margin-bottom:14px' }, __( 'Options', 'estasoft-migrate' ) ),
			tablesRow.el,
			rewriteRow.el,
			sanRow.el,
			snapRow.el,
			protectRow,
			labelRow,
			formError,
			h( 'div.card-f', { style: 'margin: 16px -20px -20px' }, ui.btn( __( 'Cancel', 'estasoft-migrate' ), { href: ESTM.url( 'overview' ) } ), h( 'span.sp' ), continueBtn )
		);

		var summaryBox = h( 'div' );
		var nextBox = h( 'div' );

		ESTM.replace(
			root,
			head,
			h(
				'ol.steps',
				{ 'aria-label': __( 'Progress', 'estasoft-migrate' ) },
				h( 'li.done', h( 'span.n', { 'aria-hidden': 'true' }, '✓' ), __( 'Method', 'estasoft-migrate' ) ),
				h( 'li.cur', { 'aria-current': 'step' }, h( 'span.n', { 'aria-hidden': 'true' }, '2' ), __( 'Destination and options', 'estasoft-migrate' ) ),
				h( 'li', h( 'span.n', { 'aria-hidden': 'true' }, '3' ), __( 'Pre-flight check', 'estasoft-migrate' ) ),
				h( 'li', h( 'span.n', { 'aria-hidden': 'true' }, '4' ), __( 'Run', 'estasoft-migrate' ) )
			),
			h(
				'div.cols',
				h( 'div.main.stack', methodCard, destCard, optionsCard ),
				h( 'div.side.stack', h( 'section.card', h( 'h2', { style: 'margin-bottom:12px' }, __( 'Summary', 'estasoft-migrate' ) ), summaryBox ), h( 'section.card', h( 'h2', { style: 'margin-bottom:8px' }, __( 'What happens next', 'estasoft-migrate' ) ), nextBox ) )
			)
		);

		/* ---- Behaviour */

		function remoteUrl() {
			return cleanUrl( urlInput.value );
		}

		/** The other site's own address when the test reported one for what is typed now; otherwise what was typed. */
		function remoteAddress() {
			var t = S.tested;
			if ( t && t.res && t.res.site && t.res.site.url && t.url === remoteUrl() ) {
				return cleanUrl( t.res.site.url );
			}
			return remoteUrl();
		}

		function remoteStats() {
			var t = S.tested;
			return t && t.res && t.url === remoteUrl() && t.key === keyInput.value.trim() && t.res.stats && t.res.stats.tables ? t.res.stats : null;
		}

		function chosenTables() {
			if ( 'pull' === S.method || 'choose' !== S.tablesMode ) {
				return null;
			}
			var names = picker.names();
			return names.length === picker.total ? null : names;
		}

		function profileName() {
			var p = byId( profiles, S.profile );
			return p ? p.name : S.profile;
		}

		function clearFormError() {
			ESTM.replace( formError );
		}

		function onUrlInput() {
			clearFormError();
			urlField.setError( '' );
			dropStaleTest();
			refresh();
		}

		function onKeyInput() {
			clearFormError();
			keyField.setError( '' );
			dropStaleTest();
		}

		function dropStaleTest() {
			if ( S.tested && ( S.tested.url !== remoteUrl() || S.tested.key !== keyInput.value.trim() ) ) {
				S.tested = null;
				ESTM.replace( testBadge );
				ESTM.replace( testResult );
			}
		}

		function testConnection() {
			if ( testBtn.classList.contains( 'is-busy' ) ) {
				return;
			}
			var url = remoteUrl();
			var key = keyInput.value.trim();
			var first = checkRemoteFields();
			if ( first ) {
				first.focus();
				return;
			}
			ui.busy( testBtn, ESTM.post( 'settings/test-connection', { remote_url: url, remote_key: key } ).then( function ( res ) {
				S.tested = { url: url, key: key, res: res };
				rememberRemote( url );
				drawTest();
				refresh();
			}, function ( err ) {
				S.tested = { url: url, key: key, err: err };
				drawTest();
				refresh();
			} ) );
		}

		function drawTest() {
			var t = S.tested;
			if ( ! t ) {
				ESTM.replace( testBadge );
				ESTM.replace( testResult );
				return;
			}
			if ( t.err ) {
				ESTM.replace( testBadge, ui.badge( __( 'Not connected', 'estasoft-migrate' ), 'block' ) );
				ESTM.replace( testResult, ui.note( 'err', ESTM.errorMessage( t.err ), { title: __( 'Could not connect.', 'estasoft-migrate' ) } ) );
				return;
			}
			var r = t.res;
			var site = r.site || {};
			var name = site.name || hostOf( site.url || t.url );
			var blockers = [];
			var warnings = [];
			if ( site.multisite ) {
				blockers.push( __( 'It is a multisite network. This version migrates single sites only.', 'estasoft-migrate' ) );
			}
			if ( false === r.writable ) {
				blockers.push( __( 'Its uploads folder is not writable, so it cannot store packages.', 'estasoft-migrate' ) );
			}
			if ( ! r.https ) {
				warnings.push( __( 'The address starts with http://. Data is signed and packages are encrypted, but use https:// if the other site has it.', 'estasoft-migrate' ) );
			}
			if ( r.skew > 60 ) {
				/* translators: %d: seconds */
				warnings.push( sprintf( __( 'The two sites differ by %d seconds. Requests are refused above five minutes, so set the time correctly on one of them.', 'estasoft-migrate' ), r.skew ) );
			}

			var facts = [
				site.url || t.url,
				/* translators: %s: WordPress version */
				site.wp_version ? sprintf( __( 'WordPress %s', 'estasoft-migrate' ), site.wp_version ) : '',
				/* translators: %s: PHP version */
				site.php ? sprintf( __( 'PHP %s', 'estasoft-migrate' ), site.php ) : '',
				dbName( site.db ),
			].filter( Boolean ).join( ' · ' );
			var more = [
				/* translators: %s: free disk space, for example 6.8 GB */
				r.free_bytes > 0 ? sprintf( __( '%s free disk space', 'estasoft-migrate' ), fmt.bytes( r.free_bytes ) ) : '',
				/* translators: %d: seconds of clock difference */
				sprintf( _n( 'Clock difference %d second', 'Clock difference %d seconds', r.skew, 'estasoft-migrate' ), r.skew ),
			].filter( Boolean ).join( ' · ' );

			ESTM.replace(
				testBadge,
				blockers.length ? ui.badge( __( 'Cannot be used', 'estasoft-migrate' ), 'block' ) : ui.badge( __( 'Connected', 'estasoft-migrate' ), 'ready' )
			);
			var kids = [];
			if ( blockers.length ) {
				kids.push( ui.note( 'err', blockers.join( ' ' ), {
					/* translators: %s: site name */
					title: sprintf( __( '%s cannot take part.', 'estasoft-migrate' ), name ),
				} ) );
			} else {
				kids.push( okNote( [
					/* translators: %s: site name */
					h( 'b', sprintf( __( '%s is ready and accepting migrations.', 'estasoft-migrate' ), name ) ),
					h( 'div', { class: 'sub', style: 'margin-top:2px' }, facts ),
					h( 'div', { class: 'sub' }, more ),
				] ) );
			}
			warnings.forEach( function ( w ) {
				kids.push( ui.note( 'warn', w ) );
			} );
			ESTM.replace( testResult, kids );
		}

		function methodText() {
			if ( 'push' === S.method ) {
				return __( 'Push sends this site’s database straight to the other site. No file to download or upload by hand. The database of the other site is replaced, after it saves a rollback snapshot.', 'estasoft-migrate' );
			}
			if ( 'pull' === S.method ) {
				return __( 'Pull brings the database of the other site to this site. The database of this site is replaced, after a rollback snapshot of it is saved.', 'estasoft-migrate' );
			}
			return __( 'Export saves the database as a package file on this site. Download it, keep it as a backup, or import it on another site. Nothing on this site changes.', 'estasoft-migrate' );
		}

		function tablesText() {
			if ( 'pull' === S.method ) {
				return __( 'Every table of the other site is pulled.', 'estasoft-migrate' );
			}
			/* translators: 1: number of tables, 2: table prefix, for example wp_ */
			return sprintf( _n( 'All %1$s table with the %2$s prefix. Choose tables if you only need part of the database.', 'All %1$s tables with the %2$s prefix. Choose tables if you only need part of the database.', tables.length, 'estasoft-migrate' ), fmt.number( tables.length ), tablesData.prefix || '' );
		}

		function rewriteText() {
			var m = S.method;
			if ( 'export' === m ) {
				return fill(
					/* translators: %1$s: address of this site */
					__( 'The package remembers this site’s address (%1$s). When it is imported on another site, addresses are changed to that site’s address, including inside serialized data.', 'estasoft-migrate' ),
					[ code( thisUrl ) ]
				);
			}
			var there = remoteAddress();
			if ( ! isHttpUrl( there ) ) {
				return __( 'Enter the address of the other site to see which address is replaced.', 'estasoft-migrate' );
			}
			var from = 'push' === m ? thisUrl : there;
			var to = 'push' === m ? there : thisUrl;
			if ( ! S.rewrite ) {
				return fill( /* translators: %1$s: the address of the old site */ __( 'Addresses are copied as they are. Content on the new site will still point at %1$s.', 'estasoft-migrate' ), [ code( from ) ] );
			}
			return fill( /* translators: 1: the address of the old site, 2: the address of the new site */ __( '%1$s becomes %2$s, including inside serialized data.', 'estasoft-migrate' ), [ code( from ), code( to ) ] );
		}

		function sanitizeText() {
			if ( 'push' === S.method ) {
				return __( 'Personal data is masked in the stream, so it never reaches the other site.', 'estasoft-migrate' );
			}
			if ( 'pull' === S.method ) {
				return __( 'The other site masks personal data before anything leaves it.', 'estasoft-migrate' );
			}
			return __( 'Personal data is masked as the package is written, so the file never holds the real data.', 'estasoft-migrate' );
		}

		function sanitizeDetail() {
			var kids = [];
			var p = byId( profiles, S.profile );
			if ( S.sanitize && p && p.text ) {
				kids.push( p.text + ' ' );
			}
			if ( S.sanitize ) {
				kids.push( S.profile === saved.profile
					? __( 'Your saved sanitizer settings are used. ', 'estasoft-migrate' )
					: __( 'This profile’s own settings are used, plus the accounts and tables you chose to keep in the Sanitizer. ', 'estasoft-migrate' ) );
			}
			kids.push( h( 'a', { href: ESTM.url( 'sanitizer' ), target: '_blank', rel: 'noopener noreferrer' }, __( 'Change sanitizer settings', 'estasoft-migrate' ), h( 'span.sr-only', ' ' + __( '(opens in a new tab)', 'estasoft-migrate' ) ) ) );
			return kids;
		}

		function protectionLabel() {
			if ( 'password' === S.protection ) {
				return __( 'Password', 'estasoft-migrate' );
			}
			return 'site-key' === S.protection ? __( 'This site’s key only', 'estasoft-migrate' ) : __( 'None', 'estasoft-migrate' );
		}

		function summaryList() {
			var m = S.method;
			var here = hostOf( thisUrl );
			var there = isHttpUrl( remoteAddress() ) ? hostOf( remoteAddress() ) : null;
			var notSet = h( 'span.muted', { style: 'font-weight:400' }, __( 'Not set yet', 'estasoft-migrate' ) );
			var chosen = chosenTables();
			var rs = remoteStats();
			var pairs = [];

			if ( 'export' === m ) {
				pairs.push( [ __( 'From', 'estasoft-migrate' ), here ], [ __( 'To', 'estasoft-migrate' ), __( 'A package file here', 'estasoft-migrate' ) ] );
			} else if ( 'push' === m ) {
				pairs.push( [ __( 'From', 'estasoft-migrate' ), here ], [ __( 'To', 'estasoft-migrate' ), there || notSet ] );
			} else {
				pairs.push( [ __( 'From', 'estasoft-migrate' ), there || notSet ], [ __( 'To', 'estasoft-migrate' ), here ] );
			}
			pairs.push( [ __( 'Method', 'estasoft-migrate' ), 'export' === m ? __( 'Export', 'estasoft-migrate' ) : ( 'push' === m ? __( 'Push', 'estasoft-migrate' ) : __( 'Pull', 'estasoft-migrate' ) ) ] );

			if ( 'pull' === m ) {
				pairs.push( [ __( 'Tables', 'estasoft-migrate' ), rs ? sprintf( /* translators: %s: number of tables */ __( 'All %s', 'estasoft-migrate' ), fmt.number( rs.tables ) ) : __( 'All of them', 'estasoft-migrate' ) ] );
				pairs.push( [ __( 'Database size', 'estasoft-migrate' ), rs ? fmt.bytes( rs.bytes ) : '–' ] );
			} else {
				var count = chosen ? chosen.length : tables.length;
				var bytes = 0;
				tables.forEach( function ( t ) {
					if ( ! chosen || chosen.indexOf( t.name ) >= 0 ) {
						bytes += t.bytes;
					}
				} );
				pairs.push( [ __( 'Tables', 'estasoft-migrate' ), chosen ? sprintf( /* translators: 1: how many so far or chosen, 2: how many there are in total */ __( '%1$s of %2$s', 'estasoft-migrate' ), fmt.number( count ), fmt.number( tables.length ) ) : sprintf( /* translators: %s: number of tables */ __( 'All %s', 'estasoft-migrate' ), fmt.number( count ) ) ] );
				pairs.push( [ __( 'Database size', 'estasoft-migrate' ), fmt.bytes( bytes ) ] );
			}
			pairs.push( [ __( 'Sanitizer', 'estasoft-migrate' ), S.sanitize ? profileName() : __( 'Off', 'estasoft-migrate' ) ] );
			if ( 'export' === m ) {
				pairs.push( [ __( 'Protection', 'estasoft-migrate' ), protectionLabel() ] );
				pairs.push( [ __( 'Snapshot', 'estasoft-migrate' ), __( 'Not needed', 'estasoft-migrate' ) ] );
			} else {
				pairs.push( [ __( 'Addresses', 'estasoft-migrate' ), S.rewrite ? __( 'Rewritten', 'estasoft-migrate' ) : __( 'Kept as they are', 'estasoft-migrate' ) ] );
				pairs.push( [ __( 'Snapshot', 'estasoft-migrate' ), __( 'Yes', 'estasoft-migrate' ) ] );
			}

			var dl = h( 'dl.kv' );
			pairs.forEach( function ( p ) {
				dl.appendChild( h( 'dt', p[ 0 ] ) );
				dl.appendChild( h( 'dd.break', p[ 1 ] ) );
			} );
			return dl;
		}

		function nextList() {
			var run = 'export' === S.method
				? __( 'The database is read in small batches and written to a package file. Download it from Packages when it is done.', 'estasoft-migrate' )
				: __( 'Tables are copied in small batches. Every batch is verified before the next one starts.', 'estasoft-migrate' );
			return h(
				'ol.stack',
				{ style: 'gap:10px;font-size:13.5px;list-style:none' },
				h( 'li', h( 'b', __( 'Pre-flight check.', 'estasoft-migrate' ) ), ' ', h( 'span.muted', __( 'Server limits, disk space and collation are checked, and you see a ready, warning or blocked report.', 'estasoft-migrate' ) ) ),
				h( 'li', h( 'b', __( 'Run.', 'estasoft-migrate' ) ), ' ', h( 'span.muted', run ) ),
				h( 'li', h( 'b', __( 'Resume anytime.', 'estasoft-migrate' ) ), ' ', h( 'span.muted', __( 'If the browser closes or the server restarts, the job carries on from the last verified batch.', 'estasoft-migrate' ) ) )
			);
		}

		function refresh() {
			ESTM.replace( rewriteRow.desc, rewriteText() );
			ESTM.replace( sanRow.desc, sanitizeText() );
			ESTM.replace( sanDetail, sanitizeDetail() );
			ESTM.replace( summaryBox, summaryList() );
		}

		function applyMethod() {
			var m = S.method;
			var isExport = 'export' === m;
			var isPush = 'push' === m;
			var isPull = 'pull' === m;
			ESTM.replace( methodSub, methodText() );
			destCard.hidden = isExport;
			ESTM.replace( destTitle, isPush ? __( 'Destination site', 'estasoft-migrate' ) : __( 'Source site', 'estasoft-migrate' ) );

			ESTM.replace( tablesRow.title, isPush ? __( 'Tables to send', 'estasoft-migrate' ) : ( isPull ? __( 'Tables to pull', 'estasoft-migrate' ) : __( 'Tables to export', 'estasoft-migrate' ) ) );
			ESTM.replace( tablesRow.desc, tablesText() );
			tablesSeg.hidden = isPull;
			picker.el.hidden = isPull || 'choose' !== S.tablesMode;
			tablesErr.hidden = true;

			ESTM.replace( rewriteRow.title, isExport ? __( 'Package can be moved to any address', 'estasoft-migrate' ) : __( 'Rewrite addresses automatically', 'estasoft-migrate' ) );
			rewriteSwitch.checked = isExport ? true : S.rewrite;
			rewriteSwitch.disabled = isExport;

			ESTM.replace( sanRow.title, isPush ? __( 'Sanitize while sending', 'estasoft-migrate' ) : ( isPull ? __( 'Sanitize while pulling', 'estasoft-migrate' ) : __( 'Sanitize while exporting', 'estasoft-migrate' ) ) );

			snapRow.el.hidden = isExport;
			ESTM.replace( snapRow.title, isPush ? __( 'Rollback snapshot on the other site', 'estasoft-migrate' ) : __( 'Take a rollback snapshot first', 'estasoft-migrate' ) );
			ESTM.replace( snapRow.desc, isPush
				? __( 'The other site saves its current database before anything is overwritten. This is always on, so a push can be undone from its Packages screen.', 'estasoft-migrate' )
				: __( 'This site saves its current database before anything is overwritten. This is always on, so a pull can be undone from Packages.', 'estasoft-migrate' ) );

			protectRow.hidden = ! isExport;
			syncProtection();
			formError.textContent = '';
			ESTM.replace( nextBox, nextList() );
			refresh();
		}

		/* ---- Validation and plan */

		/** Mark problems with the address and key. Returns the first control with a problem. */
		function checkRemoteFields() {
			var first = null;
			var url = remoteUrl();
			var key = keyInput.value.trim();
			urlField.setError( '' );
			keyField.setError( '' );
			if ( '' === url ) {
				urlField.setError( __( 'Enter the address of the other site, for example https://staging.example.com', 'estasoft-migrate' ) );
				first = urlInput;
			} else if ( ! isHttpUrl( url ) ) {
				urlField.setError( __( 'Enter the full address of the other site, starting with http:// or https://', 'estasoft-migrate' ) );
				first = urlInput;
			} else if ( url.toLowerCase() === thisUrl.toLowerCase() ) {
				urlField.setError( __( 'That is the address of this site. Enter the address of the other site.', 'estasoft-migrate' ) );
				first = urlInput;
			}
			if ( '' === key ) {
				keyField.setError( __( 'Paste the connection key from the Settings screen of the other site.', 'estasoft-migrate' ) );
				first = first || keyInput;
			} else if ( 0 !== key.indexOf( 'esm_' ) ) {
				keyField.setError( __( 'That does not look like a connection key. Keys start with esm_.', 'estasoft-migrate' ) );
				first = first || keyInput;
			}
			return first;
		}

		function validate() {
			var first = null;
			var m = S.method;
			if ( 'export' !== m ) {
				first = checkRemoteFields();
			}
			tablesErr.hidden = true;
			if ( 'pull' !== m && 'choose' === S.tablesMode && ! picker.names().length ) {
				tablesErr.textContent = __( 'Choose at least one table, or switch back to all tables.', 'estasoft-migrate' );
				tablesErr.hidden = false;
				first = first || picker.filter;
			}
			pwField.setError( '' );
			pw2Field.setError( '' );
			if ( 'export' === m && 'password' === S.protection ) {
				var pw = pwField.input.value;
				if ( pw.length < 8 ) {
					pwField.setError( __( 'Use at least 8 characters.', 'estasoft-migrate' ) );
					first = first || pwField.input;
				} else if ( pw !== pw2Field.input.value ) {
					pw2Field.setError( __( 'The two passwords do not match.', 'estasoft-migrate' ) );
					first = first || pw2Field.input;
				}
			}
			return first;
		}

		function sanitizeInput() {
			var overrides = {
				keep_users: saved.keep_users || [],
				skip_tables: saved.skip_tables || [],
				skip_columns: saved.skip_columns || [],
			};
			// Rule and safety switches, and whether administrators are kept, belong to the profile they were saved with.
			if ( S.profile === saved.profile ) {
				overrides.rules = saved.rules || {};
				overrides.safety = saved.safety || {};
				overrides.keep_admins = false !== saved.keep_admins;
			}
			return { profile: S.profile, overrides: overrides };
		}

		function planLines( chosen, address ) {
			var m = S.method;
			var lines = [];
			var count = chosen ? chosen.length : tables.length;
			var there = hostOf( address );
			var name = profileName();

			if ( 'export' === m ) {
				if ( chosen ) {
					/* translators: 1: tables chosen, 2: tables on this site */
					lines.push( sprintf( __( '%1$s of the %2$s tables are saved in one package file on this site.', 'estasoft-migrate' ), fmt.number( count ), fmt.number( tables.length ) ) );
				} else {
					/* translators: %s: number of tables */
					lines.push( sprintf( _n( 'The %s table is saved in one package file on this site.', 'All %s tables are saved in one package file on this site.', count, 'estasoft-migrate' ), fmt.number( count ) ) );
				}
				if ( S.sanitize ) {
					/* translators: %s: sanitizer profile name */
					lines.push( sprintf( __( 'Personal data is replaced with fake data (%s profile) before it is written to the package.', 'estasoft-migrate' ), name ) );
				}
				if ( 'password' === S.protection ) {
					lines.push( __( 'The package is locked with the password you entered.', 'estasoft-migrate' ) );
				} else if ( 'site-key' === S.protection ) {
					lines.push( __( 'The package is locked with a key made from this site’s security keys, so only this site can open it.', 'estasoft-migrate' ) );
				} else {
					lines.push( __( 'The package is not protected. Anyone with the file can read it.', 'estasoft-migrate' ) );
				}
				lines.push( __( 'The package remembers this site’s address, so it can be imported on any site.', 'estasoft-migrate' ) );
				lines.push( __( 'Nothing on this site is changed.', 'estasoft-migrate' ) );
				return lines;
			}

			if ( 'push' === m ) {
				if ( chosen ) {
					/* translators: 1: tables chosen, 2: tables on this site, 3: address of the other site */
					lines.push( sprintf( __( '%1$s of the %2$s tables are copied from this site to %3$s.', 'estasoft-migrate' ), fmt.number( count ), fmt.number( tables.length ), there ) );
				} else {
					/* translators: 1: number of tables, 2: address of the other site */
					lines.push( sprintf( _n( 'The %1$s table is copied from this site to %2$s.', 'All %1$s tables are copied from this site to %2$s.', count, 'estasoft-migrate' ), fmt.number( count ), there ) );
				}
			} else {
				/* translators: %s: address of the other site */
				lines.push( sprintf( __( 'Every table of %s is copied to this site.', 'estasoft-migrate' ), there ) );
			}
			if ( S.rewrite ) {
				/* translators: 1: old address, 2: new address */
				lines.push( sprintf( __( 'Addresses are changed from %1$s to %2$s, including inside serialized data.', 'estasoft-migrate' ), 'push' === m ? thisUrl : address, 'push' === m ? address : thisUrl ) );
			} else {
				lines.push( __( 'Addresses are copied as they are.', 'estasoft-migrate' ) );
			}
			if ( S.sanitize ) {
				if ( 'push' === m ) {
					/* translators: %s: sanitizer profile name */
					lines.push( sprintf( __( 'Personal data is masked (%s profile) before it leaves this site.', 'estasoft-migrate' ), name ) );
				} else {
					/* translators: 1: address of the other site, 2: sanitizer profile name */
					lines.push( sprintf( __( '%1$s masks personal data (%2$s profile) before anything leaves it.', 'estasoft-migrate' ), there, name ) );
				}
			}
			if ( 'push' === m ) {
				/* translators: %s: address of the other site */
				lines.push( sprintf( __( '%s saves a rollback snapshot of its database, then its database is replaced.', 'estasoft-migrate' ), there ) );
			} else {
				lines.push( __( 'This site saves a rollback snapshot first, then its database is replaced.', 'estasoft-migrate' ) );
			}
			return lines;
		}

		function buildPlan() {
			var m = S.method;
			var input = {};
			var label = labelInput.value.trim();
			var chosen = chosenTables();
			var address = remoteAddress() || remoteUrl();
			var title;
			var mode;

			if ( label ) {
				input.label = label;
			}
			if ( chosen && 'pull' !== m ) {
				input.tables = chosen;
			}
			if ( S.sanitize ) {
				input.sanitize = sanitizeInput();
			}

			if ( 'export' === m ) {
				if ( 'password' === S.protection ) {
					input.password = pwField.input.value;
				} else if ( 'site-key' === S.protection ) {
					input.protect_with_site_key = true;
				}
				mode = S.sanitize ? 'sanitize' : 'export';
				title = S.sanitize ? __( 'Sanitized copy', 'estasoft-migrate' ) : __( 'Export package', 'estasoft-migrate' );
			} else {
				input.remote_url = remoteUrl();
				input.remote_key = keyInput.value.trim();
				input.snapshot = true;
				input.rewrite = S.rewrite;
				mode = m;
				/* translators: %s: address of the other site */
				title = 'push' === m ? sprintf( __( 'Push to %s', 'estasoft-migrate' ), hostOf( address ) ) : sprintf( __( 'Pull from %s', 'estasoft-migrate' ), hostOf( address ) );
			}

			return {
				mode: mode,
				type: 'export' === m ? 'export' : m,
				input: input,
				title: title,
				lines: planLines( chosen, address ),
				back: 'new',
				form: {
					method: m,
					tablesMode: S.tablesMode,
					picked: 'choose' === S.tablesMode ? picker.names() : null,
					rewrite: S.rewrite,
					sanitize: S.sanitize,
					profile: S.profile,
					protection: S.protection,
				},
			};
		}

		function onContinue() {
			var first = validate();
			if ( first ) {
				ESTM.replace( formError, ui.note( 'err', __( 'Some fields need attention. They are marked above; fix them and continue.', 'estasoft-migrate' ) ) );
				first.focus();
				return;
			}
			ESTM.replace( formError );
			if ( 'export' !== S.method ) {
				rememberRemote( remoteUrl() );
			}
			if ( ! ESTM.draft.set( 'plan', buildPlan() ) ) {
				ESTM.replace( formError, ui.note( 'err', __( 'Your browser would not keep the plan for the next step. Allow site data for this page, then try again.', 'estasoft-migrate' ) ) );
				return;
			}
			ESTM.go( ESTM.url( 'preflight' ) );
		}

		/* The sanitizer settings can be changed in another tab: read them again when this tab comes back. */
		window.addEventListener( 'focus', ESTM.debounce( function () {
			ESTM.get( 'sanitizer' ).then( function ( d ) {
				saved = d.saved || saved;
				refresh();
			}, function () {
				return;
			} );
		}, 300 ) );

		applyMethod();
	}
}() );
