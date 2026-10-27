/**
 * Packages and snapshots screen.
 *
 * Lists exports and received packages (download, verify, import, delete) and rollback snapshots (restore, delete),
 * takes a package from the browser in small resumable pieces, and shows where the files live.
 * Data: GET packages, DELETE packages/{id}, POST packages/upload[/{id}[/done]], POST jobs (verify), GET settings (storage).
 * Import and restore never start a job here: they save a plan and open Pre-flight (see docs/UI.md).
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

	var MIN_CHUNK = 64 * 1024;
	var MAX_RETRIES = 6;

	/* ---------------------------------------------------------------- Small helpers */

	function hostOf( url ) {
		return String( url || '' ).replace( /^[a-z][a-z0-9+.-]*:\/\//i, '' ).replace( /\/+$/, '' );
	}

	function sameSite( url ) {
		return hostOf( url ).toLowerCase() === hostOf( ESTM.site && ESTM.site.url ).toLowerCase();
	}

	function shortHash( hash ) {
		hash = String( hash || '' );
		return hash.length > 12 ? hash.slice( 0, 4 ) + '\u2026' + hash.slice( -4 ) : hash;
	}

	function isSnapshot( p ) {
		return 'snapshot' === p.type;
	}

	function isFinishedPackage( p ) {
		return 'complete' === p.status;
	}

	function sumBytes( list ) {
		return list.reduce( function ( sum, p ) { return sum + ( Number( p.size ) || 0 ); }, 0 );
	}

	function packageName( p ) {
		if ( p.label ) {
			return p.label;
		}
		if ( 'sanitize' === p.kind ) {
			return __( 'Sanitized copy', TD );
		}
		if ( isSnapshot( p ) ) {
			return __( 'Rollback snapshot', TD );
		}
		if ( 'received' === p.type ) {
			return __( 'Received package', TD );
		}
		return __( 'Package', TD );
	}

	function contents( p ) {
		if ( ! p.tables ) {
			return '';
		}
		/* translators: 1: number of tables, 2: number of rows */
		return sprintf( __( '%1$s \u00b7 %2$s', TD ), sprintf( _n( '%s table', '%s tables', p.tables, TD ), fmt.number( p.tables ) ), sprintf( _n( '%s row', '%s rows', p.rows, TD ), fmt.number( p.rows ) ) );
	}

	/** A site-key package can only be opened by the site that made it. */
	function belongsElsewhere( p ) {
		return 'site-key' === p.protection && !! p.source_url && ! sameSite( p.source_url );
	}

	/** What a snapshot was taken before, in a few words. */
	function beforeText( p ) {
		var o = p.origin;
		var host = o ? hostOf( o.remote ) : '';
		var label = String( p.label || '' ).toLowerCase();
		if ( o ) {
			if ( 'search_replace' === o.type ) {
				return __( 'Before search & replace', TD );
			}
			if ( 'pull' === o.type ) {
				/* translators: %s: address of the other site */
				return host ? sprintf( __( 'Before pulling from %s', TD ), host ) : __( 'Before pulling a database', TD );
			}
			if ( 'push' === o.type ) {
				/* translators: %s: address of the other site */
				return host ? sprintf( __( 'Before pushing to %s', TD ), host ) : __( 'Before pushing a database', TD );
			}
			if ( 'import' === o.type ) {
				return 'restore' === o.kind ? __( 'Before restore', TD ) : __( 'Before import', TD );
			}
		}
		if ( label.indexOf( 'search' ) >= 0 ) {
			return __( 'Before search & replace', TD );
		}
		if ( label.indexOf( 'restore' ) >= 0 ) {
			return __( 'Before restore', TD );
		}
		if ( label.indexOf( 'pull' ) >= 0 ) {
			return __( 'Before pulling a database', TD );
		}
		if ( label.indexOf( 'import' ) >= 0 ) {
			return __( 'Before import', TD );
		}
		return __( 'Before a change', TD );
	}

	/** Fixed-width table with a labelled cell per column, so it can stack on small screens. */
	function makeTable( columns, caption ) {
		var head = h( 'tr', { role: 'row' }, columns.map( function ( c ) {
			return h( 'th', { scope: 'col', role: 'columnheader', class: ( c.numeric ? 'n ' : '' ) + ( c.actions ? 'actions ' : '' ) + ( c.className || '' ) }, c.srOnly ? h( 'span.sr-only', c.label ) : c.label );
		} ) );
		var body = h( 'tbody', { role: 'rowgroup' } );
		var table = h( 'table.tbl.estm-tbl', { role: 'table' }, h( 'caption.sr-only', caption ), h( 'thead', { role: 'rowgroup' }, head ), body );
		return { el: h( 'div.scroll', table ), body: body, columns: columns };
	}

	function cellOf( column, content, extra ) {
		return h( 'td', { role: 'cell', class: ( column.numeric ? 'n' : '' ) + ( column.actions ? ' actions' : '' ) + ( extra ? ' ' + extra : '' ), dataset: { label: column.label } }, h( 'div.estm-c', content ) );
	}

	/* ---------------------------------------------------------------- Screen */

	ESTM.screen( 'packages', function ( root ) {
		var state = {
			packages: [],
			env: null,
			envFailed: false,
			loaded: false,
			failed: null,
			verify: {},
			verified: {},
			hiddenId: '',
			highlight: ESTM.params().package || '',
		};
		var rows = {};
		var listsBox = h( 'div.stack' );
		var storageBox = h( 'div' );
		var uploader = null;

		/* -------- loading */

		function loadPackages() {
			return ESTM.get( 'packages' ).then( function ( data ) {
				state.packages = data.packages || [];
				state.freeFromList = data.totals ? data.totals.free : null;
				state.loaded = true;
				state.failed = null;
				render();
				return data;
			}, function ( err ) {
				state.failed = err;
				if ( ! state.loaded ) {
					renderLoadError();
				} else {
					ui.toast( ESTM.errorMessage( err ), 'err' );
				}
				return null;
			} );
		}

		function loadEnvironment() {
			ESTM.get( 'settings' ).then( function ( data ) {
				state.env = data.environment || null;
				state.envFailed = false;
				renderStorage();
			}, function () {
				state.envFailed = true;
				renderStorage();
			} );
		}

		function loadAll() {
			state.failed = null;
			state.loaded = false;
			renderSkeleton();
			renderStorage();
			loadPackages().then( function ( data ) {
				if ( data && state.highlight && rows[ state.highlight ] ) {
					rows[ state.highlight ].scrollIntoView( { block: 'center' } );
				}
			} );
			loadEnvironment();
		}

		/* -------- layout */

		uploader = buildUploader( {
			uploaded: function ( pkg ) {
				state.hiddenId = '';
				loadPackages();
			},
			hide: function ( id ) {
				state.hiddenId = id || '';
				if ( state.loaded ) {
					render();
				}
			},
			verify: runVerify,
			reload: loadPackages,
			startImport: startImport,
		} );

		ESTM.replace(
			root,
			ui.pageHead(
				__( 'Packages and snapshots', TD ),
				__( 'Packages are exports you can move to another site. Snapshots are automatic safety copies taken before a change overwrites this site.', TD ),
				[ ui.btn( __( 'Create a package', TD ), { kind: 'primary', href: ESTM.url( 'new' ) } ) ]
			),
			h(
				'div.stack',
				listsBox,
				h( 'div.g-auto', { style: 'grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); align-items: start' }, uploader.el, storageBox )
			)
		);
		root.classList.add( 'estm-pk' );

		/* -------- rendering */

		function renderSkeleton() {
			ESTM.replace(
				listsBox,
				[ __( 'Packages', TD ), __( 'Rollback snapshots', TD ) ].map( function ( title ) {
					return h( 'section.card.flush', h( 'div.card-h', h( 'h2', title ) ), h( 'div.pad', ui.skeleton( 3 ) ) );
				} )
			);
		}

		function renderLoadError() {
			ESTM.replace( listsBox, h( 'section.card', ui.loadError( state.failed, loadAll ) ) );
			renderStorage();
		}

		function focusKey() {
			var el = document.activeElement;
			var tr = el && el.closest ? el.closest( 'tr[data-id]' ) : null;
			if ( tr && el.dataset && el.dataset.act ) {
				return { id: tr.dataset.id, act: el.dataset.act };
			}
			return null;
		}

		function restoreFocus( key ) {
			if ( ! key ) {
				return;
			}
			var tr = rows[ key.id ];
			var target = tr ? tr.querySelector( '[data-act="' + key.act + '"]:not([disabled])' ) : null;
			if ( ! target && tr ) {
				target = tr.querySelector( 'a.btn, button:not([disabled])' );
			}
			if ( ! target ) {
				target = listsBox.querySelector( 'h2' );
			}
			if ( target ) {
				target.focus();
			}
		}

		function render() {
			var key = focusKey();
			var visible = state.packages.filter( function ( p ) { return p.id !== state.hiddenId; } );
			rows = {};
			ESTM.replace(
				listsBox,
				packagesCard( visible.filter( function ( p ) { return ! isSnapshot( p ); } ) ),
				snapshotsCard( visible.filter( isSnapshot ) )
			);
			renderStorage();
			restoreFocus( key );
			if ( state.highlight && rows[ state.highlight ] ) {
				rows[ state.highlight ].classList.add( 'is-selected' );
			}
		}

		function updateRow( id ) {
			var old = rows[ id ];
			var p = findPackage( id );
			if ( ! old || ! p ) {
				return;
			}
			var key = focusKey();
			var fresh = isSnapshot( p ) ? snapshotRow( p ) : packageRow( p );
			if ( old.classList.contains( 'is-selected' ) ) {
				fresh.classList.add( 'is-selected' );
			}
			old.parentNode.replaceChild( fresh, old );
			rows[ id ] = fresh;
			restoreFocus( key );
		}

		function findPackage( id ) {
			for ( var i = 0; i < state.packages.length; i += 1 ) {
				if ( state.packages[ i ].id === id ) {
					return state.packages[ i ];
				}
			}
			return null;
		}

		/* -------- packages table */

		var PACKAGE_COLUMNS = function () {
			return [
				{ label: __( 'Package', TD ) },
				{ label: __( 'Created', TD ), className: 'nowrap' },
				{ label: __( 'Size', TD ), numeric: true },
				{ label: __( 'Protection', TD ) },
				{ label: __( 'Check', TD ) },
				{ label: __( 'Actions', TD ), srOnly: true, actions: true },
			];
		};

		function packagesCard( list ) {
			var cols = PACKAGE_COLUMNS();
			var head = h(
				'div.card-h',
				h( 'h2', { tabindex: '-1' }, __( 'Packages', TD ) ),
				list.length
					? h( 'span.sub', sprintf( /* translators: 1: number of packages, 2: total size */ __( '%1$s \u00b7 %2$s', TD ), sprintf( _n( '%s package', '%s packages', list.length, TD ), fmt.number( list.length ) ), fmt.bytes( sumBytes( list ) ) ) )
					: null
			);
			if ( ! list.length ) {
				return h(
					'section.card.flush',
					head,
					ui.empty(
						__( 'No packages yet', TD ),
						__( 'A package is a copy of this database that you can keep, download or move to another site. Create one in a new migration, or import one you already have below.', TD ),
						h( 'div', { style: 'margin-top:12px' }, ui.btn( __( 'Create a package', TD ), { kind: 'primary', href: ESTM.url( 'new' ) } ) )
					)
				);
			}
			var table = makeTable( cols, __( 'Packages', TD ) );
			list.forEach( function ( p ) {
				var tr = packageRow( p );
				rows[ p.id ] = tr;
				table.body.appendChild( tr );
			} );
			return h(
				'section.card.flush',
				head,
				table.el,
				h( 'div.card-f', h( 'span.sub', __( 'Verify re-reads the file and compares every block against its checksum. It needs no password. Download links work only for a logged-in administrator.', TD ) ) )
			);
		}

		function packageRow( p ) {
			var cols = PACKAGE_COLUMNS();
			var name = packageName( p );
			var finished = isFinishedPackage( p );
			var elsewhere = belongsElsewhere( p );
			var verifying = !! state.verify[ p.id ];
			var origin = null;
			if ( 'received' === p.type && p.source_url ) {
				/* translators: %s: address of the other site */
				origin = sprintf( __( 'Received from %s', TD ), hostOf( p.source_url ) );
			} else if ( 'upload' === p.kind ) {
				origin = __( 'Uploaded from your computer', TD );
			}

			var nameCell = [
				h( 'span.cell-main.break', name ),
				contents( p ) ? h( 'span.cell-sub', contents( p ) ) : null,
				origin ? h( 'span.cell-sub', origin ) : null,
				h( 'span.cell-sub.mono.break', p.file ),
				p.sha256 ? h( 'span.cell-sub.mono', { title: p.sha256 }, 'SHA-256 ' + shortHash( p.sha256 ) ) : null,
				p.in_use ? h( 'span.cell-sub', ui.badge( __( 'In use by a job', TD ), 'run' ) ) : null,
			];

			var importBtn = ui.btn( __( 'Import', TD ), {
				kind: 'dark',
				size: 'sm',
				disabled: ! finished || elsewhere,
				ariaLabel: sprintf( /* translators: %s: package name */ __( 'Import %s', TD ), name ),
				title: elsewhere ? __( 'This package is locked to the site that made it and cannot be opened here.', TD ) : null,
				onClick: function () { startImport( p ); },
			} );
			importBtn.dataset.act = 'import';

			var verifyBtn = ui.btn( __( 'Verify', TD ), {
				kind: 'quiet',
				size: 'sm',
				disabled: ! finished,
				ariaLabel: sprintf( /* translators: %s: package name */ __( 'Verify %s', TD ), name ),
				onClick: function () { runVerify( p.id ); },
			} );
			verifyBtn.dataset.act = 'verify';
			if ( verifying ) {
				verifyBtn.classList.add( 'is-busy' );
				verifyBtn.setAttribute( 'aria-busy', 'true' );
			}

			var delBtn = deleteButton( p, name );

			var actions = h(
				'div.estm-pk-actions',
				finished ? ui.btn( __( 'Download', TD ), {
					href: ESTM.downloadUrl( 'package', p.id ),
					download: true,
					size: 'sm',
					ariaLabel: sprintf( /* translators: %s: package name */ __( 'Download %s', TD ), name ),
				} ) : null,
				verifyBtn,
				importBtn,
				delBtn
			);
			if ( actions.firstChild && actions.firstChild.tagName === 'A' ) {
				actions.firstChild.dataset.act = 'download';
			}

			var tr = h( 'tr', { role: 'row', dataset: { id: p.id } } );
			tr.appendChild( cellOf( cols[ 0 ], nameCell, 'estm-pk-name' ) );
			tr.appendChild( cellOf( cols[ 1 ], [ h( 'span.num.estm-date', fmt.date( p.created, false ) ), h( 'span.cell-sub.num', fmt.clock( p.created ) ) ] ) );
			tr.appendChild( cellOf( cols[ 2 ], fmt.bytes( p.size ) ) );
			tr.appendChild( cellOf( cols[ 3 ], finished ? protectionCell( p, elsewhere ) : h( 'span.muted', '\u2013' ) ) );
			tr.appendChild( cellOf( cols[ 4 ], checkCell( p ) ) );
			tr.appendChild( cellOf( cols[ 5 ], actions, 'estm-pk-act' ) );
			return tr;
		}

		function protectionCell( p, elsewhere ) {
			var badges = [];
			if ( 'password' === p.protection ) {
				badges.push( ui.badge( __( 'Encrypted \u00b7 password', TD ), 'ready' ) );
			} else if ( 'site-key' === p.protection ) {
				badges.push( ui.badge( __( 'Encrypted \u00b7 site key', TD ), 'ready' ) );
			} else {
				badges.push( ui.badge( __( 'Not encrypted', TD ), 'warn' ) );
			}
			if ( p.sanitized ) {
				var san = ui.badge( __( 'Sanitized', TD ) );
				/* translators: %s: sanitizer profile, for example staging */
				san.title = sprintf( __( 'Personal data was replaced (profile: %s)', TD ), String( p.sanitized ) );
				badges.push( san );
			}
			var sub = null;
			if ( 'site-key' === p.protection ) {
				/* translators: %s: address of the site that made the package */
				sub = elsewhere ? sprintf( __( 'Made on %s. Only that site can open it.', TD ), hostOf( p.source_url ) ) : __( 'Opens only on this site.', TD );
			}
			return [ h( 'div.row', { style: 'gap:6px' }, badges ), sub ? h( 'span.cell-sub', { style: 'margin-top:4px' }, sub ) : null ];
		}

		function checkCell( p ) {
			var v = state.verify[ p.id ];
			if ( v ) {
				v.textNode = document.createTextNode( v.text );
				return h( 'span.estm-pk-check', h( 'span.spinner', { 'aria-hidden': 'true' } ), ' ', v.textNode );
			}
			if ( ! isFinishedPackage( p ) ) {
				if ( 'receiving' === p.status && ! p.in_use ) {
					return [ ui.badge( __( 'Upload not finished', TD ), 'warn' ), h( 'span.cell-sub', { style: 'margin-top:4px' }, __( 'The file did not arrive completely. Delete it and upload again.', TD ) ) ];
				}
				return ui.badge( 'receiving' === p.status ? __( 'Receiving', TD ) : __( 'Being written', TD ), 'run' );
			}
			var result = state.verified[ p.id ] || p.verified || null;
			if ( ! result ) {
				return ui.badge( __( 'Not checked', TD ), 'idle' );
			}
			if ( result.ok ) {
				return [ ui.badge( __( 'Verified', TD ), 'ready' ), h( 'span.cell-sub', { style: 'margin-top:4px' }, result.at ? fmt.ago( result.at ) : __( 'just now', TD ) ) ];
			}
			return [
				ui.badge( __( 'Failed', TD ), 'block' ),
				h( 'span.cell-sub.break', { style: 'margin-top:4px' }, result.message || __( 'The file is damaged or incomplete.', TD ) ),
			];
		}

		function deleteButton( p, name ) {
			var btn = ui.btn( __( 'Delete', TD ), {
				kind: isSnapshot( p ) ? 'quiet' : 'danger',
				size: 'sm',
				disabled: !! p.in_use || !! state.verify[ p.id ],
				ariaLabel: sprintf( /* translators: %s: package name */ __( 'Delete %s', TD ), name ),
				title: p.in_use ? __( 'A job is using this package. Wait for it to finish, then delete it.', TD ) : null,
				onClick: function ( ev, el ) { removePackage( p, name, el ); },
			} );
			btn.dataset.act = 'delete';
			return btn;
		}

		/* -------- snapshots table */

		var SNAPSHOT_COLUMNS = function () {
			return [
				{ label: __( 'Taken', TD ), className: 'nowrap' },
				{ label: __( 'Before', TD ) },
				{ label: __( 'Size', TD ), numeric: true },
				{ label: __( 'Actions', TD ), srOnly: true, actions: true },
			];
		};

		function snapshotsCard( list ) {
			var head = h(
				'div.card-h',
				h( 'h2', { tabindex: '-1' }, __( 'Rollback snapshots', TD ) ),
				list.length
					? h( 'span.sub', sprintf( /* translators: 1: number of snapshots, 2: total size */ __( '%1$s \u00b7 %2$s', TD ), sprintf( _n( '%s snapshot', '%s snapshots', list.length, TD ), fmt.number( list.length ) ), fmt.bytes( sumBytes( list ) ) ) )
					: null
			);
			if ( ! list.length ) {
				return h(
					'section.card.flush',
					head,
					ui.empty(
						__( 'No snapshots yet', TD ),
						__( 'A snapshot is saved by itself before an import, a pull or a search and replace changes this database. You can go back to it from here.', TD )
					)
				);
			}
			var table = makeTable( SNAPSHOT_COLUMNS(), __( 'Rollback snapshots', TD ) );
			list.forEach( function ( p ) {
				var tr = snapshotRow( p );
				rows[ p.id ] = tr;
				table.body.appendChild( tr );
			} );
			return h(
				'section.card.flush',
				head,
				h(
					'div',
					{ style: 'padding: 16px 20px 0' },
					ui.note( 'tint', __( 'Restoring replaces this site\u2019s database with the snapshot. Anything changed since is lost.', TD ) )
				),
				h( 'div', { style: 'margin-top:8px' }, table.el )
			);
		}

		function snapshotRow( p ) {
			var cols = SNAPSHOT_COLUMNS();
			var name = packageName( p );
			var finished = isFinishedPackage( p );
			var o = p.origin;
			var sub = [ __( 'Saved automatically', TD ) ];
			if ( o && o.job_id ) {
				sub.push( ' \u00b7 ', h( 'a', { href: ESTM.url( 'logs', { job: o.job_id } ) }, __( 'View log', TD ) ) );
			}
			var takenLabel = beforeText( p );

			var restoreBtn = ui.btn( __( 'Restore', TD ), {
				kind: 'danger',
				size: 'sm',
				disabled: ! finished || !! p.in_use,
				ariaLabel: sprintf( /* translators: %s: what the snapshot was taken before, for example "Before import" */ __( 'Restore snapshot: %s', TD ), takenLabel ),
				title: p.in_use ? __( 'A job is using this snapshot. Wait for it to finish.', TD ) : null,
				onClick: function () { startRestore( p, takenLabel ); },
			} );
			restoreBtn.dataset.act = 'restore';

			var tr = h( 'tr', { role: 'row', dataset: { id: p.id } } );
			tr.appendChild( cellOf( cols[ 0 ], [ h( 'span.num.estm-date', fmt.date( p.created, false ) ), h( 'span.cell-sub', fmt.clock( p.created ) + ' \u00b7 ' + fmt.ago( p.created ) ) ] ) );
			tr.appendChild( cellOf( cols[ 1 ], [ h( 'span.cell-main', takenLabel ), h( 'span.cell-sub', sub ), p.in_use ? h( 'span.cell-sub', ui.badge( __( 'In use by a job', TD ), 'run' ) ) : null ] ) );
			tr.appendChild( cellOf( cols[ 2 ], [ fmt.bytes( p.size ), contents( p ) ? h( 'span.cell-sub', sprintf( _n( '%s table', '%s tables', p.tables, TD ), fmt.number( p.tables ) ) ) : null ] ) );
			tr.appendChild( cellOf( cols[ 3 ], h( 'div.estm-pk-actions', restoreBtn, deleteButton( p, takenLabel ) ), 'estm-pk-act' ) );
			return tr;
		}

		/* -------- storage card */

		function renderStorage() {
			var env = state.env;
			var storage = env && env.storage ? env.storage : null;
			var list = state.packages;
			var free = storage && null !== storage.free && undefined !== storage.free ? storage.free : ( undefined !== state.freeFromList ? state.freeFromList : null );
			var kids = [ h( 'h2', { style: 'margin-bottom: 12px' }, __( 'Storage', TD ) ) ];

			if ( ! state.loaded && ! state.failed ) {
				kids.push( ui.skeleton( 4 ) );
				ESTM.replace( storageBox, h( 'section.card', kids ) );
				return;
			}

			var pk = list.filter( function ( p ) { return ! isSnapshot( p ); } );
			var sn = list.filter( isSnapshot );
			var lowSpace = null !== free && free < 512 * 1024 * 1024;
			var rowsOut = [];
			if ( state.loaded ) {
				rowsOut.push( h( 'dt', __( 'Packages', TD ) ), h( 'dd.num', fmt.bytes( sumBytes( pk ) ) ) );
				rowsOut.push( h( 'dt', __( 'Snapshots', TD ) ), h( 'dd.num', fmt.bytes( sumBytes( sn ) ) ) );
			}
			rowsOut.push(
				h( 'dt', __( 'Free disk space', TD ) ),
				h( 'dd.num', null === free ? __( 'Not known', TD ) : [ fmt.bytes( free ), lowSpace ? [ ' ', ui.badge( __( 'Low', TD ), 'warn' ) ] : null ] )
			);
			if ( storage ) {
				rowsOut.push(
					h( 'dt', __( 'Direct downloads', TD ) ),
					h( 'dd', storage.protected ? ui.badge( __( 'Blocked', TD ), 'ready' ) : ui.badge( __( 'Not blocked', TD ), 'warn' ) )
				);
			}
			kids.push( h( 'dl.kv', rowsOut ) );

			if ( storage && ! storage.writable ) {
				kids.push( h( 'div', { style: 'margin-top:12px' }, ui.note( 'err', __( 'The package folder is not writable. Packages and snapshots cannot be saved until the server allows writing to wp-content/uploads.', TD ) ) ) );
			}
			if ( storage && storage.writable && ! storage.protected ) {
				kids.push( h( 'div', { style: 'margin-top:12px' }, ui.note( 'warn', __( 'The folder has no access file that blocks direct downloads. Anyone who learns a file\u2019s address could fetch it. Encrypt your packages, or ask your host to block direct access to the folder.', TD ) ) ) );
			}
			if ( state.envFailed ) {
				kids.push( h( 'p.sub', { style: 'margin-top:12px' }, __( 'Some storage details could not be loaded.', TD ) ) );
			}
			kids.push(
				h(
					'p.sub',
					{ style: 'margin-top:12px' },
					__( 'Files live in a private folder inside wp-content/uploads.', TD ),
					' ',
					h( 'a', { href: ESTM.url( 'settings' ) }, __( 'Change clean-up rules', TD ) )
				)
			);
			ESTM.replace( storageBox, h( 'section.card', kids ) );
		}

		/* -------- actions */

		function removePackage( p, name, button ) {
			var snap = isSnapshot( p );
			ui.confirm( {
				title: snap ? __( 'Delete this snapshot?', TD ) : __( 'Delete this package?', TD ),
				message: snap
					? sprintf( /* translators: 1: what the snapshot was taken before, 2: date, 3: size */ __( 'The snapshot \u201c%1$s\u201d from %2$s (%3$s) is removed from this server. You will no longer be able to go back to it. This cannot be undone.', TD ), name, fmt.date( p.created ), fmt.bytes( p.size ) )
					: sprintf( /* translators: 1: package name, 2: size */ __( '\u201c%1$s\u201d (%2$s) is removed from this server. Download it first if you may need it again. This cannot be undone.', TD ), name, fmt.bytes( p.size ) ),
				confirm: snap ? __( 'Delete snapshot', TD ) : __( 'Delete package', TD ),
				danger: true,
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				ui.busy( button, ESTM.del( 'packages/' + encodeURIComponent( p.id ) ) ).then( function () {
					state.packages = state.packages.filter( function ( q ) { return q.id !== p.id; } );
					render();
					ui.toast( snap ? __( 'Snapshot deleted.', TD ) : __( 'Package deleted.', TD ) );
				}, function ( err ) {
					ui.toast( ESTM.errorMessage( err ), 'err' );
					if ( err && ( 409 === err.status || 404 === err.status ) ) {
						loadPackages();
					}
				} );
			} );
		}

		function openPlan( plan ) {
			if ( ! ESTM.draft.set( 'plan', plan ) ) {
				ui.toast( __( 'Your browser would not keep the plan for the next page. Allow site data for this page and try again.', TD ), 'err' );
				return;
			}
			ESTM.go( ESTM.url( 'preflight' ) );
		}

		function startImport( p ) {
			var name = packageName( p );
			var needsPassword = 'password' === p.protection;
			var other = p.source_url && ! sameSite( p.source_url );
			if ( belongsElsewhere( p ) ) {
				ui.toast( sprintf( /* translators: %s: address of the site that made the package */ __( 'This package is locked to %s and cannot be opened here.', TD ), hostOf( p.source_url ) ), 'err' );
				return;
			}
			var dlg;
			var passField = null;
			var passInput = null;
			if ( needsPassword ) {
				var pw = ui.password( {} );
				var pwId = ESTM.uid( 'pw' );
				var pwErr = h( 'div.field-error', { id: pwId + '-err', hidden: true } );
				passInput = pw.input;
				passInput.id = pwId;
				passInput.required = true;
				passInput.setAttribute( 'aria-describedby', pwId + '-hint ' + pwId + '-err' );
				passField = h(
					'div.field',
					h( 'label', { for: pwId }, __( 'Package password', TD ) ),
					pw,
					h( 'span.hint', { id: pwId + '-hint' }, __( 'The password you chose when this package was created. It is not stored anywhere.', TD ) ),
					pwErr
				);
				passField.setError = function ( message ) {
					passField.classList.toggle( 'has-error', !! message );
					pwErr.hidden = ! message;
					pwErr.textContent = message || '';
					if ( message ) {
						passInput.setAttribute( 'aria-invalid', 'true' );
					} else {
						passInput.removeAttribute( 'aria-invalid' );
					}
				};
				passInput.addEventListener( 'input', function () { passField.setError( '' ); } );
				passInput.addEventListener( 'keydown', function ( ev ) {
					if ( 'Enter' === ev.key ) {
						ev.preventDefault();
						go();
					}
				} );
			}
			var snapBox = ui.checkbox( { checked: true, id: ESTM.uid( 'snap' ) } );

			function go() {
				var password = passInput ? passInput.value : '';
				if ( needsPassword && ! password ) {
					passField.setError( __( 'Enter the password to continue.', TD ) );
					passInput.focus();
					return;
				}
				var snapshot = !! snapBox.checked;
				var lines = [];
				var what = contents( p );
				lines.push( what ? sprintf( /* translators: 1: package name, 2: contents such as "12 tables \u00b7 205,313 rows" */ __( 'Package \u201c%1$s\u201d: %2$s', TD ), name, what ) : sprintf( /* translators: %s: package name */ __( 'Package \u201c%s\u201d', TD ), name ) );
				if ( p.created ) {
					/* translators: %s: date and time */
					lines.push( sprintf( __( 'Created %s', TD ), fmt.date( p.created ) ) );
				}
				if ( needsPassword ) {
					lines.push( __( 'Protected with a password', TD ) );
				}
				lines.push( snapshot ? __( 'A rollback snapshot of this site is saved first', TD ) : __( 'No rollback snapshot: the current data cannot be recovered', TD ) );
				if ( other ) {
					/* translators: 1: address of the site that made the package, 2: address of this site */
					lines.push( sprintf( __( 'The address %1$s is changed to %2$s', TD ), hostOf( p.source_url ), hostOf( ESTM.site.url ) ) );
				}
				var input = { package_id: p.id, snapshot: snapshot, rewrite: true };
				if ( needsPassword ) {
					input.password = password;
				}
				dlg.close( true );
				openPlan( { mode: 'import', type: 'import', input: input, title: __( 'Import package', TD ), lines: lines, back: 'packages' } );
			}

			var cancel = ui.btn( __( 'Cancel', TD ), { kind: 'quiet', onClick: function () { dlg.close( false ); } } );
			var next = ui.btn( __( 'Continue to checks', TD ), { kind: 'primary', onClick: go } );
			var what = contents( p );
			dlg = ui.dialog( {
				title: __( 'Import this package?', TD ),
				body: [
					h( 'p', sprintf( /* translators: 1: package name, 2: contents such as "12 tables \u00b7 205,313 rows" */ __( 'Importing replaces the data of this site with \u201c%1$s\u201d%2$s.', TD ), name, what ? ' (' + what + ')' : '' ) ),
					other ? ui.note( '', sprintf( /* translators: 1: address of the site that made the package, 2: address of this site */ __( 'This package comes from %1$s. Its address is changed to %2$s while importing.', TD ), hostOf( p.source_url ), hostOf( ESTM.site.url ) ) ) : null,
					passField,
					h( 'label.row', { style: 'flex-wrap:nowrap;align-items:flex-start;gap:10px' }, snapBox, h( 'span', h( 'b', __( 'Save a rollback snapshot first', TD ) ), h( 'span.cell-sub', __( 'Recommended. It lets you go back if something looks wrong.', TD ) ) ) ),
					h( 'p.sub', __( 'Nothing changes yet. The next page checks this site and the package, and asks you to start.', TD ) ),
				],
				actions: [ cancel, next ],
			} );
			( passInput || next ).focus();
		}

		function startRestore( p, takenLabel ) {
			ui.confirm( {
				title: __( 'Restore this snapshot?', TD ),
				message: sprintf( /* translators: 1: date and time the snapshot was taken, 2: size of the snapshot */ __( 'This site\u2019s database goes back to how it was on %1$s (%2$s). Everything changed since then is lost, including posts, orders and settings. Nothing changes yet: the next page runs the checks and asks you to start.', TD ), fmt.date( p.created ), fmt.bytes( p.size ) ),
				confirm: __( 'Continue to checks', TD ),
				danger: true,
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				var lines = [
					/* translators: %s: date and time */
					sprintf( __( 'Restore the snapshot taken %s', TD ), fmt.date( p.created ) ),
					__( 'Changes made since the snapshot will be lost', TD ),
				];
				if ( contents( p ) ) {
					lines.push( contents( p ) );
				}
				openPlan( { mode: 'import', type: 'import', input: { package_id: p.id, snapshot: false }, title: __( 'Restore snapshot', TD ), lines: lines, back: 'packages' } );
			} );
		}

		/* -------- verify */

		/**
		 * Check a package with a verify job and keep its row up to date. Resolves with { ok, message, started }.
		 * Calling it again while a check runs for the same package returns the same promise.
		 */
		function runVerify( id, onProgress ) {
			var existing = state.verify[ id ];
			if ( existing ) {
				if ( onProgress ) {
					existing.listeners.push( onProgress );
				}
				return existing.promise;
			}
			var v = { text: __( 'Starting\u2026', TD ), listeners: onProgress ? [ onProgress ] : [], watcher: null };
			state.verify[ id ] = v;
			v.promise = new Promise( function ( resolve ) { v.resolve = resolve; } );
			updateRow( id );

			function finish( result ) {
				delete state.verify[ id ];
				state.verified[ id ] = { ok: result.ok, message: result.message, at: new Date().toISOString().replace( 'T', ' ' ).slice( 0, 19 ) };
				updateRow( id );
				loadPackages().then( function ( data ) {
					if ( data ) {
						delete state.verified[ id ];
						updateRow( id );
					}
				} );
				var pkg = findPackage( id );
				var label = pkg ? packageName( pkg ) : id;
				if ( result.ok ) {
					/* translators: %s: package name */
					ui.toast( sprintf( __( '\u201c%s\u201d is complete and undamaged.', TD ), label ) );
				} else {
					/* translators: 1: package name, 2: what is wrong with it */
					ui.toast( sprintf( __( '\u201c%1$s\u201d did not pass the check. %2$s', TD ), label, result.message ), 'err' );
				}
				v.resolve( result );
			}

			ESTM.post( 'jobs', { type: 'verify', package_id: id } ).then( function ( job ) {
				v.watcher = ESTM.watchJob( job.id, {
					onUpdate: function ( view ) {
						v.pct = view.progress;
						v.text = sprintf( /* translators: %s: percent done */ __( 'Checking\u2026 %s', TD ), fmt.percent( view.progress || 0 ) );
						if ( v.textNode ) {
							v.textNode.nodeValue = v.text;
						}
						v.listeners.forEach( function ( fn ) { fn( view.progress || 0 ); } );
					},
					onDone: function ( view ) {
						if ( 'completed' === view.status ) {
							finish( { ok: true, started: true, message: '' } );
						} else {
							var msg = view.error && view.error.message ? view.error.message : ( view.message || __( 'The check did not finish.', TD ) );
							finish( { ok: false, started: true, message: msg } );
						}
					},
					onError: function ( err, failures ) {
						if ( failures >= 3 && v.textNode ) {
							v.textNode.nodeValue = __( 'Connection problem, trying again\u2026', TD );
						}
						if ( err && 404 === err.status ) {
							finish( { ok: false, started: true, message: ESTM.errorMessage( err ) } );
						}
					},
				} );
			}, function ( err ) {
				delete state.verify[ id ];
				updateRow( id );
				ui.toast( ESTM.errorMessage( err ), 'err' );
				v.resolve( { ok: false, started: false, message: ESTM.errorMessage( err ) } );
			} );
			return v.promise;
		}

		loadAll();
	} );

	/* ---------------------------------------------------------------- Upload */

	function abortError() {
		var e = new Error( 'aborted' );
		e.name = 'AbortError';
		return e;
	}

	function isTransient( err ) {
		if ( ! err ) {
			return false;
		}
		if ( 'estm_network' === err.code || 0 === err.status ) {
			return true;
		}
		if ( 507 === err.status || 501 === err.status ) {
			return false;
		}
		return err.status >= 500 || 408 === err.status || 429 === err.status;
	}

	/**
	 * The "Import a package" card: drop zone, chunked upload that resumes, and the check after it.
	 * hooks: uploaded(pkg), hide(id), verify(id, onProgress) -> Promise, reload(), startImport(pkg).
	 */
	function buildUploader( hooks ) {
		var up = null;
		var guard = function ( ev ) {
			ev.preventDefault();
			ev.returnValue = '';
			return '';
		};

		var input = h( 'input', { type: 'file', accept: '.estm', hidden: true, tabindex: '-1', 'aria-hidden': 'true' } );
		var chooseBtn = ui.btn( __( 'Choose a .estm file', TD ), { onClick: function () { input.click(); } } );
		var zone = h(
			'div.drop',
			h( 'p', { style: 'font-weight:600;margin-bottom:4px' }, __( 'Drop a package here', TD ) ),
			h( 'p.sub', { style: 'margin-bottom:12px' }, __( 'or choose a .estm file from your computer', TD ) ),
			chooseBtn
		);

		var fileName = h( 'b.break' );
		var fileSize = h( 'span.sub' );
		var bar = ui.bar( 0, { label: __( 'Upload progress', TD ), live: true } );
		var statusText = h( 'p.sub', { 'aria-hidden': 'true' } );
		var announce = h( 'div.sr-only', { role: 'status', 'aria-live': 'polite' } );
		var cancelBtn = ui.btn( __( 'Cancel upload', TD ), { kind: 'quiet', size: 'sm', onClick: function () { askCancel(); } } );
		var resumeBtn = ui.btn( __( 'Resume upload', TD ), { kind: 'primary', size: 'sm', onClick: function () { resume(); } } );
		var progress = h(
			'div.stack.tight',
			{ hidden: true },
			h( 'div.row', { style: 'justify-content:space-between;flex-wrap:nowrap;align-items:baseline' }, fileName, fileSize ),
			bar,
			statusText,
			h( 'div.row', resumeBtn, cancelBtn )
		);
		var result = h( 'div.stack.tight', { hidden: true, 'aria-live': 'polite' } );

		var el = h(
			'section.card',
			h( 'h2', { style: 'margin-bottom: 6px' }, __( 'Import a package', TD ) ),
			h( 'p.sub', { style: 'margin-bottom: 12px' }, __( 'Large files upload in small parts and resume if the connection drops.', TD ) ),
			zone,
			progress,
			result,
			announce,
			input,
			h( 'p.sub', { style: 'margin-top: 12px' }, __( 'Packages protected with a password ask for it when you import them.', TD ) )
		);

		/* -------- drop zone */

		function hasFiles( ev ) {
			return ev.dataTransfer && Array.prototype.indexOf.call( ev.dataTransfer.types || [], 'Files' ) >= 0;
		}

		zone.addEventListener( 'dragenter', function ( ev ) { if ( hasFiles( ev ) ) { ev.preventDefault(); zone.classList.add( 'over' ); } } );
		zone.addEventListener( 'dragover', function ( ev ) { if ( hasFiles( ev ) ) { ev.preventDefault(); ev.dataTransfer.dropEffect = 'copy'; zone.classList.add( 'over' ); } } );
		zone.addEventListener( 'dragleave', function ( ev ) { if ( ! ev.relatedTarget || ! zone.contains( ev.relatedTarget ) ) { zone.classList.remove( 'over' ); } } );
		zone.addEventListener( 'drop', function ( ev ) {
			ev.preventDefault();
			zone.classList.remove( 'over' );
			var files = ev.dataTransfer && ev.dataTransfer.files ? ev.dataTransfer.files : [];
			choose( files );
		} );
		// A file dropped next to the zone must not make the browser leave the page.
		window.addEventListener( 'dragover', function ( ev ) { if ( hasFiles( ev ) ) { ev.preventDefault(); } } );
		window.addEventListener( 'drop', function ( ev ) { if ( hasFiles( ev ) ) { ev.preventDefault(); } } );

		input.addEventListener( 'change', function () {
			var files = input.files;
			choose( files );
			input.value = '';
		} );

		function choose( files ) {
			if ( ! files || ! files.length ) {
				return;
			}
			if ( up && up.busy ) {
				ui.toast( __( 'An upload is already running. Wait for it to finish or cancel it first.', TD ), 'err' );
				return;
			}
			if ( files.length > 1 ) {
				ui.toast( __( 'Only one package can be uploaded at a time. The first file was used.', TD ) );
			}
			begin( files[ 0 ] );
		}

		/* -------- states */

		function show( mode ) {
			var running = 'uploading' === mode || 'finishing' === mode;
			zone.hidden = 'idle' !== mode;
			progress.hidden = 'idle' === mode || 'done' === mode || 'failed' === mode;
			result.hidden = 'done' !== mode && 'failed' !== mode;
			resumeBtn.hidden = 'stalled' !== mode;
			cancelBtn.hidden = ! running && 'stalled' !== mode;
			bar.classList.toggle( 'err', 'stalled' === mode );
			bar.classList.toggle( 'live', running || 'verifying' === mode );
			progress.setAttribute( 'aria-busy', running || 'verifying' === mode ? 'true' : 'false' );
			if ( up ) {
				up.busy = running || 'verifying' === mode || 'stalled' === mode;
			}
			// Leaving the page while the file is on its way would stop the upload.
			if ( running || 'stalled' === mode ) {
				window.addEventListener( 'beforeunload', guard );
			} else {
				window.removeEventListener( 'beforeunload', guard );
			}
		}

		function say( text, announceIt ) {
			statusText.textContent = text;
			if ( announceIt ) {
				announce.textContent = text;
			}
		}

		function reset() {
			up = null;
			ESTM.replace( result );
			bar.firstChild.style.width = '0%';
			show( 'idle' );
			hooks.hide( '' );
			chooseBtn.focus();
		}

		function showResult( kind, title, text, buttons ) {
			ESTM.replace( result, ui.note( kind, text, { title: title } ), h( 'div.row', { style: 'margin-top:8px' }, buttons ) );
			show( 'err' === kind ? 'failed' : 'done' );
			announce.textContent = title + ' ' + text;
			var first = result.querySelector( 'button, a' );
			if ( first ) {
				first.focus();
			}
		}

		function paint( cur, force ) {
			var now = Date.now();
			if ( ! force && now - ( cur.lastPaint || 0 ) < 200 ) {
				return;
			}
			cur.lastPaint = now;
			var total = cur.file.size;
			var pct = total ? Math.min( 100, ( cur.offset / total ) * 100 ) : 0;
			ui.setBar( bar, pct );
			cur.samples.push( { t: now, b: cur.offset } );
			while ( cur.samples.length > 2 && now - cur.samples[ 0 ].t > 8000 ) {
				cur.samples.shift();
			}
			var first = cur.samples[ 0 ];
			var elapsed = ( now - first.t ) / 1000;
			var speed = elapsed > 0.5 ? ( cur.offset - first.b ) / elapsed : 0;
			var text = sprintf( /* translators: 1: bytes uploaded, 2: total bytes, 3: percent */ __( 'Uploading %1$s of %2$s (%3$s)', TD ), fmt.bytes( cur.offset ), fmt.bytes( total ), fmt.percent( pct ) );
			if ( speed > 0 ) {
				/* translators: %s: upload speed, for example 3.2 MB */
				text += ' \u00b7 ' + sprintf( __( '%s/s', TD ), fmt.bytes( speed ) );
				var left = ( total - cur.offset ) / speed;
				if ( left > 1 && total > cur.offset ) {
					/* translators: %s: time left, for example 2 min 05 s */
					text += ' \u00b7 ' + sprintf( __( 'about %s left', TD ), fmt.duration( left ) );
				}
			}
			say( text, false );
		}

		function sleep( cur, ms ) {
			return new Promise( function ( resolve, reject ) {
				cur.timer = setTimeout( resolve, ms );
				cur.wake = function () { clearTimeout( cur.timer ); reject( abortError() ); };
			} );
		}

		/** Run fn, retrying temporary network and server problems with growing pauses. */
		function attempt( cur, fn ) {
			return fn().catch( function ( err ) {
				if ( cur.cancelled || ( err && 'AbortError' === err.name ) ) {
					throw err;
				}
				if ( ! isTransient( err ) ) {
					throw err;
				}
				cur.tries += 1;
				if ( cur.tries > MAX_RETRIES ) {
					err.stalled = true;
					throw err;
				}
				var wait = Math.min( 30000, 1000 * Math.pow( 2, cur.tries - 1 ) );
				say( sprintf( /* translators: 1: seconds to wait, 2: attempt number, 3: number of attempts */ __( 'The connection dropped. Trying again in %1$d s (attempt %2$d of %3$d).', TD ), Math.round( wait / 1000 ), cur.tries, MAX_RETRIES ), true );
				return sleep( cur, wait ).then( function () {
					return attempt( cur, fn );
				} );
			} );
		}

		function request( cur, method, path, body, opts ) {
			opts = opts || {};
			if ( cur.controller ) {
				opts.signal = cur.controller.signal;
			}
			return ESTM.api( method, path, body, opts );
		}

		function init( cur ) {
			return attempt( cur, function () {
				return request( cur, 'POST', 'packages/upload', { name: cur.file.name, size: cur.file.size } );
			} ).then( function ( r ) {
				cur.id = r.package_id;
				cur.chunk = Math.max( MIN_CHUNK, Number( r.chunk ) || 1048576 );
				cur.offset = Number( r.received ) || 0;
				hooks.hide( cur.id );
			} );
		}

		function sendPiece( cur ) {
			var start = cur.offset;
			var end = Math.min( start + cur.chunk, cur.file.size );
			return request( cur, 'POST', 'packages/upload/' + cur.id, cur.file.slice( start, end ), { raw: true, query: { offset: start } } ).then( function ( r ) {
				var got = r && 'number' === typeof r.received ? r.received : end;
				if ( got <= start ) {
					var e = new ESTM.ApiError( __( 'The server did not keep the piece that was sent.', TD ), 'estm_network', 0 );
					throw e;
				}
				cur.offset = Math.min( got, cur.file.size );
			}, function ( err ) {
				if ( err && 'estm_offset' === err.code && err.data && 'number' === typeof err.data.received ) {
					// The server holds a different amount than we thought: carry on from what it really has.
					cur.jumps += 1;
					if ( cur.jumps > 25 ) {
						throw err;
					}
					cur.offset = Math.min( err.data.received, cur.file.size );
					return;
				}
				if ( err && 413 === err.status && cur.chunk > MIN_CHUNK ) {
					// The server limit is smaller than the piece: use smaller pieces.
					cur.chunk = Math.max( MIN_CHUNK, Math.floor( cur.chunk / 2 ) );
					return;
				}
				throw err;
			} );
		}

		function sendAll( cur ) {
			return new Promise( function ( resolve, reject ) {
				function next() {
					if ( cur.cancelled ) {
						reject( abortError() );
						return;
					}
					if ( cur.offset >= cur.file.size ) {
						resolve();
						return;
					}
					attempt( cur, function () { return sendPiece( cur ); } ).then( function () {
						cur.tries = 0;
						paint( cur, false );
						next();
					}, reject );
				}
				next();
			} );
		}

		function complete( cur ) {
			show( 'finishing' );
			ui.setBar( bar, 100 );
			say( __( 'Upload finished. Checking that the file is complete and really is a package\u2026', TD ), true );
			return attempt( cur, function () {
				return request( cur, 'POST', 'packages/upload/' + cur.id + '/done', { size: cur.file.size } );
			} ).then( function ( r ) {
				return r.package;
			}, function ( err ) {
				if ( err && 'estm_offset' === err.code && err.data && 'number' === typeof err.data.received && cur.redo < 3 ) {
					cur.redo += 1;
					cur.offset = Math.min( err.data.received, cur.file.size );
					show( 'uploading' );
					return sendAll( cur ).then( function () { return complete( cur ); } );
				}
				if ( err && ( 422 === err.status || 404 === err.status ) ) {
					cur.id = '';
				}
				throw err;
			} );
		}

		function verifyUploaded( cur, pkg ) {
			cur.package = pkg;
			hooks.hide( '' );
			hooks.uploaded( pkg );
			show( 'verifying' );
			ui.setBar( bar, 0 );
			bar.firstChild.style.width = '0%';
			say( __( 'Checking every block of the package against its checksum\u2026', TD ), true );
			return hooks.verify( pkg.id, function ( pct ) {
				ui.setBar( bar, pct );
				say( sprintf( /* translators: %s: percent done */ __( 'Checking every block of the package\u2026 %s', TD ), fmt.percent( pct ) ), false );
			} );
		}

		function finishedOk( cur, pkg, check ) {
			var name = pkg.label || cur.file.name;
			var buttons = [];
			var title;
			var text;
			if ( check.ok ) {
				title = __( 'The package is ready.', TD );
				text = sprintf( /* translators: 1: file name, 2: size */ __( '\u201c%1$s\u201d (%2$s) was uploaded and checked. It is listed under Packages.', TD ), name, fmt.bytes( pkg.size || cur.file.size ) );
				buttons.push( ui.btn( __( 'Import this package', TD ), { kind: 'dark', size: 'sm', onClick: function () { hooks.startImport( pkg ); } } ) );
			} else if ( check.started ) {
				title = __( 'The package arrived, but the check found a problem.', TD );
				text = check.message + ' ' + __( 'Delete it from the list and upload the file again.', TD );
			} else {
				title = __( 'The package arrived, but it could not be checked yet.', TD );
				text = check.message + ' ' + __( 'You can verify it from the list.', TD );
			}
			buttons.push( ui.btn( __( 'Upload another', TD ), { kind: 'quiet', size: 'sm', onClick: reset } ) );
			showResult( check.ok ? '' : 'warn', title, text, buttons );
		}

		function fail( cur, err ) {
			if ( cur.cancelled || ( err && 'AbortError' === err.name ) ) {
				return;
			}
			var reason = err && 'estm_network' === err.code ? __( 'The connection to the server was lost.', TD ) : ESTM.errorMessage( err );
			if ( err && err.stalled && cur.id ) {
				show( 'stalled' );
				paint( cur, true );
				var pct = cur.file.size ? ( cur.offset / cur.file.size ) * 100 : 0;
				say( sprintf( /* translators: 1: percent uploaded, 2: reason */ __( 'The upload stopped at %1$s. %2$s What has arrived is kept, so you can carry on from there.', TD ), fmt.percent( pct ), reason ), true );
				return;
			}
			var file = cur.file;
			cur.busy = false;
			// Do not leave a half-finished package on the server.
			discard( cur ).then( function () {
				hooks.hide( '' );
				hooks.reload();
			} );
			var rejected = 422 === ( err && err.status );
			showResult(
				'err',
				rejected ? __( 'This file cannot be used.', TD ) : __( 'The upload did not work.', TD ),
				reason,
				[
					rejected ? null : ui.btn( __( 'Try again', TD ), { kind: 'primary', size: 'sm', onClick: function () { reset(); begin( file ); } } ),
					ui.btn( __( 'Choose another file', TD ), { kind: rejected ? 'primary' : 'quiet', size: 'sm', onClick: reset } ),
				]
			);
		}

		function discard( cur ) {
			var id = cur.id;
			cur.id = '';
			if ( ! id ) {
				return Promise.resolve();
			}
			return ESTM.del( 'packages/' + encodeURIComponent( id ) ).then( null, function () {
				return null;
			} );
		}

		/* -------- flow */

		function checkFile( file ) {
			if ( ! /\.estm$/i.test( file.name ) ) {
				return __( 'That is not a package. Package files end in .estm.', TD );
			}
			if ( ! file.size ) {
				return __( 'That file is empty.', TD );
			}
			return '';
		}

		function begin( file ) {
			var problem = checkFile( file );
			if ( problem ) {
				up = { file: file, busy: false };
				showResult( 'err', __( 'This file cannot be uploaded.', TD ), problem, [ ui.btn( __( 'Choose another file', TD ), { kind: 'quiet', size: 'sm', onClick: reset } ) ] );
				return;
			}
			up = { file: file, id: '', chunk: 1048576, offset: 0, tries: 0, jumps: 0, redo: 0, samples: [], cancelled: false, busy: true, controller: null, package: null };
			ESTM.replace( result );
			fileName.textContent = file.name;
			fileSize.textContent = fmt.bytes( file.size );
			ui.setBar( bar, 0 );
			say( __( 'Starting the upload\u2026', TD ), true );
			run();
		}

		function run() {
			var cur = up;
			cur.cancelled = false;
			cur.tries = 0;
			cur.samples = [];
			cur.controller = 'undefined' !== typeof AbortController ? new AbortController() : null;
			show( 'uploading' );
			( cur.id ? Promise.resolve() : init( cur ) )
				.then( function () { paint( cur, true ); return sendAll( cur ); } )
				.then( function () { return complete( cur ); } )
				.then( function ( pkg ) { return verifyUploaded( cur, pkg ); } )
				.then( function ( check ) {
					if ( cur.cancelled ) {
						return;
					}
					window.removeEventListener( 'beforeunload', guard );
					up.busy = false;
					hooks.reload();
					finishedOk( cur, cur.package, check );
				} )
				.catch( function ( err ) { fail( cur, err ); } );
		}

		function resume() {
			if ( up && up.id ) {
				say( __( 'Picking up where the upload stopped\u2026', TD ), true );
				run();
			}
		}

		function askCancel() {
			var cur = up;
			if ( ! cur ) {
				return;
			}
			ui.confirm( {
				title: __( 'Cancel this upload?', TD ),
				message: __( 'What has already been sent is deleted from the server. You can start again later.', TD ),
				confirm: __( 'Cancel upload', TD ),
				cancel: __( 'Keep uploading', TD ),
				danger: true,
			} ).then( function ( ok ) {
				if ( ! ok || up !== cur || ! cur.busy ) {
					return;
				}
				cur.cancelled = true;
				if ( cur.controller ) {
					cur.controller.abort();
				}
				if ( cur.wake ) {
					cur.wake();
				}
				say( __( 'Cancelling\u2026', TD ), true );
				// Give a request that was in flight a moment to stop, then remove the partial file.
				setTimeout( function () {
					discard( cur ).then( function () {
						window.removeEventListener( 'beforeunload', guard );
						reset();
						hooks.reload();
						ui.toast( __( 'Upload cancelled. The partial file was removed.', TD ) );
					} );
				}, 150 );
			} );
		}

		return { el: el };
	}
}() );
