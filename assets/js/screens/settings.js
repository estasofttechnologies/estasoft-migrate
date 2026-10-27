/**
 * Settings screen.
 *
 * The form is built once from GET settings and is never redrawn while someone uses it. After a save only the
 * values that were not edited in the meantime are filled in from the answer. Only the changed values are sent.
 * Data: GET settings, POST settings, POST settings/key.
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

	/** The limits are the ones Settings::sanitize() applies on the server. */
	var FIELDS = {
		accept_incoming: { type: 'bool' },
		require_https: { type: 'bool' },
		ip_allowlist: { type: 'ips' },
		runner: { type: 'choice' },
		max_batch: { type: 'int', min: 100, max: 100000 },
		slice_seconds: { type: 'int', min: 3, max: 60 },
		encrypt_packages: { type: 'bool' },
		delete_packages_days: { type: 'int', min: 0, max: 3650 },
		keep_snapshots: { type: 'int', min: 1, max: 20 },
		delete_snapshots_days: { type: 'int', min: 0, max: 3650 },
		delete_on_uninstall: { type: 'bool' },
	};
	var KEYS = Object.keys( FIELDS );

	/* ---------------------------------------------------------------- Helpers */

	function clone( obj ) {
		return JSON.parse( JSON.stringify( obj ) );
	}

	function ipList( text ) {
		var seen = {};
		return String( text || '' ).split( /[\s,]+/ ).filter( function ( s ) {
			if ( ! s || seen[ s ] ) {
				return false;
			}
			seen[ s ] = true;
			return true;
		} );
	}

	/** Unix seconds to the "YYYY-MM-DD HH:MM:SS" UTC string the fmt helpers take. */
	function gmtFromUnix( seconds ) {
		return new Date( seconds * 1000 ).toISOString().replace( 'T', ' ' ).slice( 0, 19 );
	}

	function runnerLabel( id ) {
		switch ( id ) {
			case 'auto': return __( 'Automatic (recommended)', 'estasoft-migrate' );
			case 'ajax': return __( 'Browser requests (AJAX)', 'estasoft-migrate' );
			case 'cron': return __( 'WP-Cron', 'estasoft-migrate' );
			case 'action_scheduler': return __( 'Action Scheduler', 'estasoft-migrate' );
			case 'cli': return __( 'WP-CLI only', 'estasoft-migrate' );
		}
		return id;
	}

	function dbLabel( db ) {
		if ( ! db ) {
			return __( 'Unknown', 'estasoft-migrate' );
		}
		if ( 'string' === typeof db ) {
			return db;
		}
		var names = { mariadb: 'MariaDB', mysql: 'MySQL' };
		return ( names[ db.type ] || db.type || '' ) + ' ' + ( db.version || '' );
	}

	function limitText( n, none ) {
		return n > 0 ? n : none;
	}

	/* ---------------------------------------------------------------- Screen */

	ESTM.screen( 'settings', function ( root ) {
		var body = h( 'div' );
		var guard = { dirty: false };

		window.addEventListener( 'beforeunload', function ( ev ) {
			if ( guard.dirty ) {
				ev.preventDefault();
				ev.returnValue = '';
				return '';
			}
			return undefined;
		} );

		ESTM.replace(
			root,
			ui.pageHead(
				__( 'Settings', 'estasoft-migrate' ),
				__( 'The defaults work for most sites. Change them only if your host has strict limits or you connect sites to each other.', 'estasoft-migrate' )
			),
			body
		);

		function load() {
			body.setAttribute( 'aria-busy', 'true' );
			ESTM.replace( body, h( 'div.cols', h( 'div.main.stack', [ 1, 2, 3 ].map( function () {
				return h( 'section.card', ui.skeleton( 4 ) );
			} ) ), h( 'div.side.stack', h( 'section.card', ui.skeleton( 5 ) ) ) ) );
			return ESTM.get( 'settings' ).then( function ( data ) {
				ESTM.replace( body, build( data, guard ) );
				body.removeAttribute( 'aria-busy' );
			}, function ( err ) {
				ESTM.replace( body, h( 'section.card', ui.loadError( err, load ) ) );
				body.removeAttribute( 'aria-busy' );
			} );
		}

		load();
	} );

	/* ---------------------------------------------------------------- The form */

	function build( data, guard ) {
		var state = {
			values: clone( data.values ),
			defaults: clone( data.defaults ),
			conn: clone( data.connection ),
			env: clone( data.environment ),
		};
		var controls = {};
		var rows = {};
		var saving = false;

		/* -------- one row of the form */

		function setting( o ) {
			var control = o.control;
			var id = ESTM.uid( 'st' );
			control.id = control.id || id;
			var descId = id + '-d';
			var errId = id + '-e';
			var err = h( 'div.field-error', { id: errId, hidden: true } );
			var desc = o.text ? h( 'span', { id: descId }, o.text ) : null;
			var extra = o.extra ? h( 'div', { style: 'flex: 0 0 100%; display: flex; flex-direction: column; gap: 8px' }, o.extra ) : null;
			var el = h(
				'div.set',
				h( 'div.tx', h( 'label', { for: control.id, style: 'display:block;font-weight:600' }, o.title ), desc, err ),
				h( 'div.ctl', control, o.suffix ? h( 'span.muted', o.suffix ) : null ),
				extra
			);
			control.setAttribute( 'aria-describedby', ( desc ? descId + ' ' : '' ) + errId );
			el.desc = desc;
			el.setError = function ( message ) {
				err.hidden = ! message;
				err.textContent = message || '';
				if ( message ) {
					control.setAttribute( 'aria-invalid', 'true' );
				} else {
					control.removeAttribute( 'aria-invalid' );
				}
			};
			return el;
		}

		function register( key, control, row ) {
			controls[ key ] = control;
			rows[ key ] = row;
		}

		function note( kind, text ) {
			var n = ui.note( kind, text );
			n.hidden = true;
			return n;
		}

		function switchRow( key, title, text, extra ) {
			var sw = ui.switch( { checked: !! state.values[ key ], onChange: function () { changed( key ); } } );
			var row = setting( { title: title, text: text, control: sw, extra: extra } );
			register( key, sw, row );
			return row;
		}

		function numberRow( key, title, text, suffix, extra ) {
			var def = FIELDS[ key ];
			var input = ui.input( {
				type: 'number',
				value: String( state.values[ key ] ),
				min: def.min,
				max: def.max,
				inputmode: 'numeric',
				onInput: function () { changed( key ); },
				onChange: function () { check( key ); },
			} );
			input.style.width = '120px';
			input.classList.add( 'mono' );
			var row = setting( { title: title, text: text, control: input, suffix: suffix, extra: extra } );
			register( key, input, row );
			return row;
		}

		/* -------- values */

		function readControl( key ) {
			var def = FIELDS[ key ];
			var el = controls[ key ];
			switch ( def.type ) {
				case 'bool': return !! el.checked;
				case 'ips': return ipList( el.value ).join( '\n' );
				case 'choice': return el.value;
				default:
					return /^\s*\d+\s*$/.test( el.value ) ? parseInt( el.value, 10 ) : null;
			}
		}

		function writeControl( key, value ) {
			var def = FIELDS[ key ];
			var el = controls[ key ];
			if ( 'bool' === def.type ) {
				el.checked = !! value;
			} else if ( 'ips' === def.type ) {
				el.value = String( value || '' );
			} else {
				el.value = String( value );
			}
		}

		function isChanged( key ) {
			return readControl( key ) !== state.values[ key ];
		}

		function changes() {
			var out = {};
			KEYS.forEach( function ( key ) {
				if ( isChanged( key ) ) {
					out[ key ] = readControl( key );
				}
			} );
			return out;
		}

		function rangeText( def ) {
			/* translators: 1: smallest allowed number, 2: largest allowed number */
			return sprintf( __( 'Enter a whole number from %1$s to %2$s.', 'estasoft-migrate' ), fmt.number( def.min ), fmt.number( def.max ) );
		}

		/** Validate one field and show or clear its message. Returns true when it is fine. */
		function check( key ) {
			var def = FIELDS[ key ];
			if ( 'int' !== def.type ) {
				return true;
			}
			var v = readControl( key );
			var ok = null !== v && v >= def.min && v <= def.max;
			rows[ key ].setError( ok ? '' : rangeText( def ) );
			return ok;
		}

		/* -------- unsaved changes */

		var dirtyBadge = h( 'span.badge.b-warn', { hidden: true }, __( 'Unsaved changes', 'estasoft-migrate' ) );
		var cleanText = h( 'span.sub', __( 'No unsaved changes', 'estasoft-migrate' ) );
		var status = h( 'div.row', { 'aria-live': 'polite', style: 'gap:8px' }, dirtyBadge, cleanText );
		// Not natively disabled: a disabled button that has focus would drop keyboard focus to the page when a save completes.
		var saveBtn = ui.btn( __( 'Save changes', 'estasoft-migrate' ), { kind: 'primary', onClick: save } );
		saveBtn.classList.add( 'is-disabled' );
		saveBtn.setAttribute( 'aria-disabled', 'true' );
		var resetBtn = ui.btn( __( 'Reset to defaults', 'estasoft-migrate' ), { kind: 'quiet', onClick: reset } );

		function refreshDirty() {
			var dirty = Object.keys( changes() ).length > 0;
			if ( dirty !== guard.dirty ) {
				guard.dirty = dirty;
				dirtyBadge.hidden = ! dirty;
				cleanText.hidden = dirty;
			}
			saveBtn.classList.toggle( 'is-disabled', ! dirty );
			saveBtn.setAttribute( 'aria-disabled', dirty ? 'false' : 'true' );
		}

		function changed( key ) {
			var def = FIELDS[ key ];
			if ( 'int' === def.type ) {
				// Clear the message while the person fixes the number; it is shown again when the field is left.
				var v = readControl( key );
				if ( null !== v && v >= def.min && v <= def.max ) {
					rows[ key ].setError( '' );
				}
			}
			if ( 'ips' === def.type ) {
				ipNote.hidden = true;
			}
			if ( 'runner' === key ) {
				updateRunnerInfo();
			}
			if ( 'require_https' === key || 'accept_incoming' === key ) {
				updateConnectionNotes();
			}
			if ( 'encrypt_packages' === key ) {
				updateEncryptNote();
			}
			refreshDirty();
		}

		/* -------- connection and security */

		var keyInput;
		var keyIdHint = h( 'span.hint' );
		var graceBox = h( 'div', { 'aria-live': 'polite' } );

		function keyIdText() {
			/* translators: %s: short identifier of the connection key */
			return sprintf( __( 'Key ID: %s. Other sites show the same ID when they use this key.', 'estasoft-migrate' ), state.conn.key_id );
		}

		function graceNote() {
			var until = Number( state.conn.previous_key_until ) || 0;
			if ( until * 1000 <= Date.now() ) {
				return null;
			}
			var when = gmtFromUnix( until );
			return ui.note(
				'warn',
				/* translators: %s: time of day, for example 14:32 */
				sprintf( __( 'The previous key still works until %s. Paste the new key into the other sites before then.', 'estasoft-migrate' ), fmt.clock( when ) ),
				{ title: __( 'A new key was created.', 'estasoft-migrate' ) }
			);
		}

		function connectionKey() {
			var copy = ui.copyField( state.conn.key, { label: __( 'Connection key', 'estasoft-migrate' ) } );
			keyInput = copy.querySelector( 'input' );
			keyInput.type = 'password';
			keyInput.id = ESTM.uid( 'key' );
			var shown = false;
			var toggle = ui.btn( __( 'Show', 'estasoft-migrate' ), { onClick: function () {
				shown = ! shown;
				keyInput.type = shown ? 'text' : 'password';
				toggle.textContent = shown ? __( 'Hide', 'estasoft-migrate' ) : __( 'Show', 'estasoft-migrate' );
			} } );
			copy.insertBefore( toggle, copy.lastChild );
			copy.querySelectorAll( '.btn' ).forEach( function ( b ) { b.classList.remove( 'btn-quiet' ); } );
			keyIdHint.textContent = keyIdText();
			ESTM.replace( graceBox, graceNote() );
			var replaceBtn = ui.btn( __( 'Replace key', 'estasoft-migrate' ), { kind: 'danger', size: 'sm', onClick: function ( ev, el ) { replaceKey( el ); } } );
			return h(
				'div.set.estm-set-block',
				h(
					'div.field',
					h( 'label', { for: keyInput.id }, __( 'Connection key', 'estasoft-migrate' ) ),
					copy,
					h( 'span.hint', __( 'Every request between sites is signed with this key and expires within minutes, so the key itself is never sent. Treat it like a password.', 'estasoft-migrate' ) ),
					keyIdHint,
					h( 'div.row', replaceBtn, h( 'span.hint', __( 'The old key keeps working for a short while, then sites that still use it are disconnected.', 'estasoft-migrate' ) ) ),
					graceBox
				)
			);
		}

		function replaceKey( button ) {
			ui.confirm( {
				title: __( 'Replace the connection key?', 'estasoft-migrate' ),
				message: __( 'A new key is created. The current key keeps working for a few minutes so you can paste the new key into the other sites. After that, any site that still uses the old key is disconnected.', 'estasoft-migrate' ),
				confirm: __( 'Replace key', 'estasoft-migrate' ),
				danger: true,
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				ui.busy( button, ESTM.post( 'settings/key' ) ).then( function ( res ) {
					state.conn = clone( res.connection );
					state.env = clone( res.environment );
					keyInput.value = state.conn.key;
					keyIdHint.textContent = keyIdText();
					ESTM.replace( graceBox, graceNote() );
					ui.toast( __( 'A new connection key was created.', 'estasoft-migrate' ) );
				}, function ( err ) {
					ui.toast( ESTM.errorMessage( err ), 'err' );
				} );
			} );
		}

		var httpsNote = note( 'warn', __( 'Without HTTPS, the connection key stays hidden, but the data that is sent can be read on its way. Turn this off only on a private network.', 'estasoft-migrate' ) );
		var insecureNote = note( 'warn', __( 'This site is not served over HTTPS, so other sites cannot connect while this is on.', 'estasoft-migrate' ) );
		var incomingNote = note( '', __( 'Incoming migrations are off. Other sites cannot connect, send packages or pull from this site.', 'estasoft-migrate' ) );
		var ipNote = note( 'warn', '' );

		function updateConnectionNotes() {
			var https = !! controls.require_https.checked;
			var accept = !! controls.accept_incoming.checked;
			httpsNote.hidden = https;
			insecureNote.hidden = ! ( https && 0 === String( state.conn.endpoint ).indexOf( 'http://' ) );
			incomingNote.hidden = accept;
		}

		function connectionCard() {
			var ipInput = ui.textarea( { rows: 4, mono: true, placeholder: '203.0.113.10\n198.51.100.0/24', value: state.values.ip_allowlist, onInput: function () { changed( 'ip_allowlist' ); } } );
			ipInput.id = ESTM.uid( 'ip' );
			var ipHintId = ESTM.uid( 'iph' );
			ipInput.setAttribute( 'aria-describedby', ipHintId );
			controls.ip_allowlist = ipInput;

			var accept = switchRow(
				'accept_incoming',
				__( 'Accept incoming migrations', 'estasoft-migrate' ),
				__( 'Lets another site that has the connection key send packages to this site or pull this site\u2019s database. Switch it off when you are not migrating.', 'estasoft-migrate' ),
				[ incomingNote ]
			);
			// What turning it on allows, in plain words.
			accept.querySelector( '.tx' ).insertBefore(
				h( 'span', { style: 'margin-top:4px' }, __( 'With the key, a site can send a package and start an import here, which replaces this site\u2019s data, or ask for a copy of the database. It cannot reach anything else.', 'estasoft-migrate' ) ),
				accept.querySelector( '.field-error' )
			);

			var endpoint = ui.copyField( state.conn.endpoint, { label: __( 'Connection address', 'estasoft-migrate' ) } );
			var endpointInput = endpoint.querySelector( 'input' );
			endpointInput.id = ESTM.uid( 'ep' );
			endpoint.querySelectorAll( '.btn' ).forEach( function ( b ) { b.classList.remove( 'btn-quiet' ); } );

			var https = switchRow( 'require_https', __( 'Require HTTPS', 'estasoft-migrate' ), __( 'Refuse connections that are not encrypted.', 'estasoft-migrate' ), [ httpsNote, insecureNote ] );

			var ipRow = h(
				'div.set.estm-set-block',
				h(
					'div.field',
					h( 'label', { for: ipInput.id }, __( 'Allowed IP addresses', 'estasoft-migrate' ), h( 'span.muted', { style: 'font-weight:400' }, ' ' + __( '(optional)', 'estasoft-migrate' ) ) ),
					ipInput,
					h( 'span.hint', { id: ipHintId }, __( 'One address or range (like 198.51.100.0/24) per line. Leave empty to accept any address that has the key. Lines that are not valid addresses are dropped when you save; if none is valid, nothing is saved.', 'estasoft-migrate' ) ),
					ipNote
				)
			);
			rows.ip_allowlist = { setError: function () {} };

			return h(
				'section.card',
				h( 'h2', { style: 'margin-bottom: 14px' }, __( 'Connection and security', 'estasoft-migrate' ) ),
				accept,
				connectionKey(),
				h(
					'div.set.estm-set-block',
					h(
						'div.field',
						h( 'label', { for: endpointInput.id }, __( 'Connection address', 'estasoft-migrate' ) ),
						endpoint,
						h( 'span.hint', __( 'Other sites reach this site at this address. In most cases you only need the site address and the key.', 'estasoft-migrate' ) )
					)
				),
				https,
				ipRow
			);
		}

		/* -------- performance */

		var runnerInfoText = null;
		var cronNote = note( 'warn', __( 'WP-Cron is switched off in wp-config.php. Jobs only continue in the background if a real cron job calls wp-cron.php. Until then, keep the Progress page open.', 'estasoft-migrate' ) );
		var schedulerNote = note( '', __( 'Action Scheduler is not installed on this site, so WP-Cron is used instead.', 'estasoft-migrate' ) );

		function effectiveRunner( choice ) {
			if ( 'auto' === choice ) {
				return state.env.action_scheduler ? 'action_scheduler' : 'cron';
			}
			if ( 'action_scheduler' === choice && ! state.env.action_scheduler ) {
				return 'cron';
			}
			return choice;
		}

		function runnerText( choice ) {
			switch ( choice ) {
				case 'auto':
					/* translators: %s: name of the runner that is used, for example WP-Cron */
					return sprintf( __( 'Picks the best background option this server allows and falls back if one fails. On this server that is %s.', 'estasoft-migrate' ), runnerLabel( effectiveRunner( 'auto' ) ) );
				case 'ajax':
					return __( 'Only this browser tab moves a job along. If you close the Progress page, the job waits until you open it again.', 'estasoft-migrate' );
				case 'cron':
					return __( 'WordPress continues the job in the background through WP-Cron, so you can close the page. It needs visits to your site or a real cron job.', 'estasoft-migrate' );
				case 'action_scheduler':
					return __( 'Continues jobs in the background with the Action Scheduler library that WooCommerce and some other plugins include.', 'estasoft-migrate' );
				case 'cli':
					return __( 'Nothing runs in the background. Start and resume jobs from the command line with wp estasoft-migrate resume.', 'estasoft-migrate' );
			}
			return '';
		}

		function updateRunnerInfo() {
			var choice = controls.runner.value;
			if ( runnerInfoText ) {
				runnerInfoText.textContent = runnerText( choice );
			}
			var eff = effectiveRunner( choice );
			cronNote.hidden = ! ( 'cron' === eff && state.env.cron_off );
			schedulerNote.hidden = ! ( 'action_scheduler' === choice && ! state.env.action_scheduler );
		}

		function performanceCard() {
			var runners = ( data.runners && data.runners.length ? data.runners : [ 'auto', 'ajax', 'cron', 'action_scheduler', 'cli' ] );
			var options = runners.map( function ( id ) {
				var missing = 'action_scheduler' === id && ! state.env.action_scheduler;
				return {
					value: id,
					label: missing ? sprintf( /* translators: %s: runner name */ __( '%s (not installed)', 'estasoft-migrate' ), runnerLabel( id ) ) : runnerLabel( id ),
					disabled: missing && state.values.runner !== id,
				};
			} );
			var select = ui.select( options, { value: state.values.runner, onChange: function () { changed( 'runner' ); } } );
			select.style.minWidth = '230px';
			var runnerRow = setting( {
				title: __( 'Runner', 'estasoft-migrate' ),
				text: runnerText( state.values.runner ),
				control: select,
				extra: [ cronNote, schedulerNote ],
			} );
			runnerInfoText = runnerRow.desc;
			register( 'runner', select, runnerRow );

			var env = state.env;
			var maxExec = Number( env.max_exec ) || 0;
			var memory = Number( env.memory ) || 0;
			var limits = [
				ui.badge( maxExec > 0 ? sprintf( /* translators: %d: seconds */ __( '%d s limit detected', 'estasoft-migrate' ), maxExec ) : __( 'No time limit', 'estasoft-migrate' ), maxExec > 0 && maxExec <= 30 ? 'warn' : 'ready' ),
				ui.badge( memory > 0 ? sprintf( /* translators: %s: memory size */ __( '%s memory', 'estasoft-migrate' ), fmt.bytes( memory ) ) : __( 'No memory limit', 'estasoft-migrate' ), memory > 0 && memory < 128 * 1048576 ? 'warn' : 'ready' ),
			];
			var maxExecText = maxExec > 0
				? sprintf( /* translators: %d: seconds */ __( 'Each request works for at most this long, and never more than 80%% of the server\u2019s %d-second limit.', 'estasoft-migrate' ), maxExec )
				: __( 'Each request works for at most this long. Shorter is gentler on slow hosts.', 'estasoft-migrate' );

			return h(
				'section.card',
				h( 'h2', { style: 'margin-bottom: 14px' }, __( 'Performance', 'estasoft-migrate' ) ),
				runnerRow,
				numberRow(
					'max_batch',
					__( 'Largest batch', 'estasoft-migrate' ),
					__( 'Rows per batch. Batches shrink on their own when the server is slow and grow again when it is fast.', 'estasoft-migrate' ),
					__( 'rows', 'estasoft-migrate' )
				),
				numberRow( 'slice_seconds', __( 'Time per request', 'estasoft-migrate' ), maxExecText, __( 'seconds', 'estasoft-migrate' ) ),
				h(
					'div.set',
					h( 'div.tx', h( 'b', __( 'Server limits', 'estasoft-migrate' ) ), h( 'span', __( 'What this server allows one request to use. Requests stop at 75% of the memory limit.', 'estasoft-migrate' ) ) ),
					h( 'div.ctl', { style: 'flex-wrap: wrap' }, limits )
				)
			);
		}

		/* -------- packages and clean-up */

		var encryptNote = note( 'warn', __( 'This server has no PHP sodium extension, so packages cannot be encrypted. Exports will be refused while this is on.', 'estasoft-migrate' ) );

		function updateEncryptNote() {
			encryptNote.hidden = ! ( controls.encrypt_packages.checked && ! state.env.sodium );
		}

		function cleanupCard() {
			return h(
				'section.card',
				h( 'h2', { style: 'margin-bottom: 14px' }, __( 'Packages and clean-up', 'estasoft-migrate' ) ),
				switchRow(
					'encrypt_packages',
					__( 'Encrypt new packages', 'estasoft-migrate' ),
					__( 'Every new package must be protected with a password or with this site\u2019s key. A password is not stored anywhere, so keep it safe.', 'estasoft-migrate' ),
					[ encryptNote ]
				),
				numberRow(
					'delete_packages_days',
					__( 'Delete old packages after', 'estasoft-migrate' ),
					__( 'Exports and received packages older than this are removed by themselves. 0 keeps them until you delete them.', 'estasoft-migrate' ),
					__( 'days', 'estasoft-migrate' )
				),
				numberRow(
					'keep_snapshots',
					__( 'Snapshots to keep', 'estasoft-migrate' ),
					__( 'Rollback snapshots are large. Only this many of the newest ones are kept; older ones are removed.', 'estasoft-migrate' ),
					__( 'snapshots', 'estasoft-migrate' )
				),
				numberRow(
					'delete_snapshots_days',
					__( 'Delete old snapshots after', 'estasoft-migrate' ),
					__( 'Snapshots older than this are removed, except the newest one. 0 keeps them until you delete them.', 'estasoft-migrate' ),
					__( 'days', 'estasoft-migrate' )
				)
			);
		}

		/* -------- privacy and removal */

		function privacyCard() {
			return h(
				'section.card',
				h( 'h2', { style: 'margin-bottom: 14px' }, __( 'Privacy and removal', 'estasoft-migrate' ) ),
				h(
					'div.set',
					h( 'div.tx', h( 'b', __( 'Usage tracking', 'estasoft-migrate' ) ), h( 'span', __( 'None. Estasoft Migrate collects no statistics and sends nothing to ESTASOFT TECHNOLOGIES or anyone else. It only contacts a site when you connect it yourself.', 'estasoft-migrate' ) ) ),
					h( 'div.ctl', ui.badge( __( 'Off, always', 'estasoft-migrate' ), 'ready' ) )
				),
				switchRow(
					'delete_on_uninstall',
					__( 'Delete all data when the plugin is deleted', 'estasoft-migrate' ),
					__( 'Removes jobs, logs, packages, snapshots and settings. Off by default, so reinstalling keeps your history.', 'estasoft-migrate' )
				)
			);
		}

		/* -------- save, reset */

		function save() {
			if ( saving ) {
				return;
			}
			var firstBad = null;
			KEYS.forEach( function ( key ) {
				if ( ! check( key ) && ! firstBad ) {
					firstBad = controls[ key ];
				}
			} );
			if ( firstBad ) {
				firstBad.focus();
				ui.toast( __( 'Some values are not valid. Fix the fields marked in red and save again.', 'estasoft-migrate' ), 'err' );
				return;
			}
			var sent = changes();
			if ( ! Object.keys( sent ).length ) {
				ui.toast( __( 'There is nothing to save.', 'estasoft-migrate' ) );
				return;
			}
			saving = true;
			ui.busy( saveBtn, ESTM.post( 'settings', sent ).then( function ( res ) {
				saving = false;
				applySaved( res, sent );
			}, function ( err ) {
				saving = false;
				ui.toast( ESTM.errorMessage( err ), 'err' );
			} ) );
		}

		function applySaved( res, sent ) {
			var adjusted = [];
			state.values = clone( res.values );
			state.env = clone( res.environment );
			state.conn = clone( res.connection );
			Object.keys( sent ).forEach( function ( key ) {
				var def = FIELDS[ key ];
				var stored = res.values[ key ];
				// Fill in what the server kept, unless the person has already changed the field again.
				if ( readControl( key ) === sent[ key ] ) {
					writeControl( key, stored );
				}
				if ( 'int' === def.type && stored !== sent[ key ] ) {
					adjusted.push( sprintf( /* translators: 1: setting name, 2: the number that was stored */ __( '%1$s is now %2$s', 'estasoft-migrate' ), rows[ key ].querySelector( 'label' ).textContent, fmt.number( stored ) ) );
				}
				if ( 'ip_allowlist' === key ) {
					var kept = ipList( stored );
					var dropped = ipList( sent[ key ] ).filter( function ( entry ) { return kept.indexOf( entry ) < 0; } );
					if ( dropped.length ) {
						ESTM.replace( ipNote.lastChild, __( 'These lines are not valid addresses and were not saved: ', 'estasoft-migrate' ), h( 'span.mono', dropped.join( ', ' ) ) );
						ipNote.hidden = false;
					} else {
						ipNote.hidden = true;
					}
				}
			} );
			updateRunnerInfo();
			updateConnectionNotes();
			updateEncryptNote();
			renderAbout();
			refreshDirty();
			if ( adjusted.length ) {
				ui.toast( sprintf( /* translators: %s: list of settings with their stored values */ __( 'Saved. Some numbers had to stay within the allowed range: %s.', 'estasoft-migrate' ), adjusted.join( '; ' ) ) );
			} else if ( ! ipNote.hidden ) {
				ui.toast( __( 'Saved. Some addresses were dropped, see the list under Allowed IP addresses.', 'estasoft-migrate' ) );
			} else {
				ui.toast( __( 'Settings saved.', 'estasoft-migrate' ) );
			}
		}

		function reset() {
			ui.confirm( {
				title: __( 'Reset to defaults?', 'estasoft-migrate' ),
				message: __( 'The form is filled with the factory values: incoming migrations off, HTTPS required, automatic runner, and the standard limits and clean-up times. Nothing is saved until you press Save changes. Your connection key does not change.', 'estasoft-migrate' ),
				confirm: __( 'Fill in the defaults', 'estasoft-migrate' ),
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				KEYS.forEach( function ( key ) {
					writeControl( key, state.defaults[ key ] );
					rows[ key ].setError( '' );
				} );
				ipNote.hidden = true;
				updateRunnerInfo();
				updateConnectionNotes();
				updateEncryptNote();
				refreshDirty();
				ui.toast( __( 'The defaults are filled in. Press Save changes to keep them.', 'estasoft-migrate' ) );
			} );
		}

		/* -------- about and server */

		var aboutBox = h( 'div.stack' );

		function row( label, value ) {
			return [ h( 'dt', label ), h( 'dd', value ) ];
		}

		function yesNo( ok, yes, no, badKind ) {
			return ui.badge( ok ? yes : no, ok ? 'ready' : ( badKind || 'warn' ) );
		}

		function serverWarnings() {
			var env = state.env;
			var s = env.storage || {};
			var out = [];
			var maxExec = Number( env.max_exec ) || 0;
			var memory = Number( env.memory ) || 0;
			if ( maxExec > 0 && maxExec <= 30 ) {
				out.push( sprintf( /* translators: %d: seconds */ __( 'The server stops a request after %d seconds. Jobs are cut into short slices to fit, so large databases take longer but still finish.', 'estasoft-migrate' ), maxExec ) );
			}
			if ( memory > 0 && memory < 128 * 1048576 ) {
				out.push( sprintf( /* translators: %s: memory size */ __( 'The memory limit is low (%s). Lower the largest batch if jobs run out of memory.', 'estasoft-migrate' ), fmt.bytes( memory ) ) );
			}
			if ( env.cron_off && 'cron' === effectiveRunner( state.values.runner ) ) {
				out.push( __( 'WP-Cron is switched off, so jobs only continue in the background if a real cron job calls wp-cron.php.', 'estasoft-migrate' ) );
			}
			if ( ! env.sodium ) {
				out.push( __( 'The PHP sodium extension is missing, so packages cannot be encrypted or opened if they are.', 'estasoft-migrate' ) );
			}
			if ( ! env.zlib ) {
				out.push( __( 'The PHP zlib extension is missing, so packages cannot be compressed and are larger.', 'estasoft-migrate' ) );
			}
			if ( false === s.writable ) {
				out.push( __( 'The package folder is not writable. Packages and snapshots cannot be saved.', 'estasoft-migrate' ) );
			}
			if ( false === s.protected ) {
				out.push( __( 'A test file in the package folder could be downloaded without signing in. Encrypt your packages or ask your host to block access to the folder.', 'estasoft-migrate' ) );
			}
			if ( null !== s.free && undefined !== s.free && s.free < 1024 * 1048576 ) {
				out.push( sprintf( /* translators: %s: free disk space */ __( 'Only %s of disk space is free. Imports need room for a rollback snapshot.', 'estasoft-migrate' ), fmt.bytes( s.free ) ) );
			}
			return out;
		}

		function renderAbout() {
			var env = state.env;
			var s = env.storage || {};
			var links = ESTM.links || {};
			var warnings = serverWarnings();
			var linkNodes = [];
			[ [ 'docs', __( 'Documentation', 'estasoft-migrate' ) ], [ 'support', __( 'Support forum', 'estasoft-migrate' ) ], [ 'source', __( 'Source code', 'estasoft-migrate' ) ] ].forEach( function ( pair ) {
				if ( links[ pair[ 0 ] ] ) {
					linkNodes.push( h( 'a', { href: links[ pair[ 0 ] ], target: '_blank', rel: 'noopener noreferrer' }, pair[ 1 ] ) );
				}
			} );
			var rate = links.support && /wordpress\.org\/support\/plugin\//.test( links.support ) ? links.support.replace( /\/?$/, '/reviews/' ) : '';

			ESTM.replace(
				aboutBox,
				h(
					'section.card',
					h( 'h2', { style: 'margin-bottom: 12px' }, __( 'About', 'estasoft-migrate' ) ),
					h(
						'dl.kv',
						row( __( 'Plugin', 'estasoft-migrate' ), __( 'Estasoft Migration & Database Sanitizer', 'estasoft-migrate' ) ),
						row( __( 'Version', 'estasoft-migrate' ), ESTM.version || '' ),
						row( __( 'Developer', 'estasoft-migrate' ), 'ESTASOFT TECHNOLOGIES' ),
						row( __( 'License', 'estasoft-migrate' ), __( 'GPL v2 or later', 'estasoft-migrate' ) )
					),
					linkNodes.length ? h( 'div.row', { style: 'margin-top: 12px; gap: 6px 16px; font-size: 13px' }, linkNodes ) : null
				),
				h(
					'section.card',
					h( 'div.card-h', h( 'h2', __( 'This server', 'estasoft-migrate' ) ), warnings.length
						? ui.badge( sprintf( /* translators: %d: number of things the person should know about their server */ _n( '%d thing to know', '%d things to know', warnings.length, 'estasoft-migrate' ), warnings.length ), 'warn' )
						: ui.badge( __( 'All good', 'estasoft-migrate' ), 'ready' ) ),
					warnings.length
						? h( 'ul.stack', { style: 'gap: 8px; margin-bottom: 14px' }, warnings.map( function ( w ) { return h( 'li', ui.note( 'warn', w ) ); } ) )
						: h( 'p.sub', { style: 'margin-bottom: 14px' }, __( 'Nothing on this server limits the plugin.', 'estasoft-migrate' ) ),
					h(
						'dl.kv',
						row( __( 'PHP', 'estasoft-migrate' ), env.php || '' ),
						row( __( 'WordPress', 'estasoft-migrate' ), env.wp || '' ),
						row( __( 'Database', 'estasoft-migrate' ), dbLabel( env.db ) ),
						row( __( 'Memory limit', 'estasoft-migrate' ), Number( env.memory ) > 0 ? fmt.bytes( env.memory ) : __( 'No limit', 'estasoft-migrate' ) ),
						row( __( 'Max execution time', 'estasoft-migrate' ), Number( env.max_exec ) > 0 ? sprintf( /* translators: %d: seconds */ __( '%d s', 'estasoft-migrate' ), env.max_exec ) : __( 'No limit', 'estasoft-migrate' ) ),
						row( __( 'WP-Cron', 'estasoft-migrate' ), yesNo( ! env.cron_off, __( 'Running', 'estasoft-migrate' ), __( 'Switched off', 'estasoft-migrate' ) ) ),
						row( __( 'Action Scheduler', 'estasoft-migrate' ), ui.badge( env.action_scheduler ? __( 'Installed', 'estasoft-migrate' ) : __( 'Not installed', 'estasoft-migrate' ), env.action_scheduler ? 'ready' : 'idle' ) ),
						row( __( 'Background runner', 'estasoft-migrate' ), runnerLabel( env.runner ) ),
						row( __( 'Encryption (sodium)', 'estasoft-migrate' ), yesNo( env.sodium, __( 'Available', 'estasoft-migrate' ), __( 'Missing', 'estasoft-migrate' ) ) ),
						row( __( 'Compression (zlib)', 'estasoft-migrate' ), yesNo( env.zlib, __( 'Available', 'estasoft-migrate' ), __( 'Missing', 'estasoft-migrate' ) ) ),
						row( __( 'Package folder', 'estasoft-migrate' ), yesNo( s.writable, __( 'Writable', 'estasoft-migrate' ), __( 'Not writable', 'estasoft-migrate' ), 'block' ) ),
						row( __( 'Direct downloads', 'estasoft-migrate' ), null === s.protected || undefined === s.protected ? ui.badge( __( 'Could not be checked', 'estasoft-migrate' ), 'idle' ) : yesNo( s.protected, __( 'Blocked', 'estasoft-migrate' ), __( 'Not blocked', 'estasoft-migrate' ) ) ),
						row( __( 'Used by packages', 'estasoft-migrate' ), fmt.bytes( s.used || 0 ) ),
						row( __( 'Free disk space', 'estasoft-migrate' ), null === s.free || undefined === s.free ? __( 'Not known', 'estasoft-migrate' ) : fmt.bytes( s.free ) ),
						row( __( 'Multisite', 'estasoft-migrate' ), env.multisite ? __( 'Yes', 'estasoft-migrate' ) : __( 'No', 'estasoft-migrate' ) )
					)
				),
				ui.card(
					{ dark: true, className: 'estm-free', title: __( 'Free, for everyone', 'estasoft-migrate' ) },
					h( 'p.sub', { style: 'margin-top: 6px' }, __( 'No license key, no locked features, no upgrade notices. If it helps you, a review on WordPress.org is the best thanks.', 'estasoft-migrate' ) ),
					h(
						'div.row',
						{ style: 'margin-top: 14px; gap: 14px; font-size: 13px' },
						rate ? h( 'a', { href: rate, target: '_blank', rel: 'noopener noreferrer', style: 'color: var(--yellow)' }, __( 'Rate the plugin', 'estasoft-migrate' ) ) : null,
						links.support ? h( 'a', { href: links.support, target: '_blank', rel: 'noopener noreferrer', style: 'color: var(--yellow)' }, __( 'Support forum', 'estasoft-migrate' ) ) : null
					)
				),
				ui.card(
					{ title: __( 'Languages and direction', 'estasoft-migrate' ), className: 'estm-lang' },
					h( 'p.sub', { style: 'margin-top: 6px' }, __( 'The plugin follows your WordPress language. Right-to-left languages such as Arabic and Kurdish (Sorani) mirror the whole interface.', 'estasoft-migrate' ) )
				)
			);
		}

		/* -------- assemble (once) */

		var cards = [ connectionCard(), performanceCard(), cleanupCard(), privacyCard() ];
		renderAbout();
		updateRunnerInfo();
		updateConnectionNotes();
		updateEncryptNote();
		refreshDirty();

		return h(
			'div.cols',
			h(
				'div.main.stack',
				cards,
				h( 'div.estm-savebar', status, h( 'div.row', saveBtn, resetBtn ) )
			),
			h( 'div.side.stack', aboutBox )
		);
	}
}() );
