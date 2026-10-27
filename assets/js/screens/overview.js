/**
 * Overview screen: what is here, what is running, and the way into everything else.
 * Data: GET overview, POST preflight (for the environment card).
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

	ESTM.screen( 'overview', function ( root ) {
		var runningNote = h( 'div' );
		var tiles = h( 'div.g-auto' );
		var recent = h( 'div' );
		var env = h( 'div' );
		var timer = null;
		var drawn = false;
		var shown = { job: null, parts: null, tiles: '', recent: '' };

		ESTM.replace(
			root,
			ui.pageHead(
				__( 'Overview', 'estasoft-migrate' ),
				__( 'Move, copy and clean a WordPress database without timeouts, broken serialized data or leaked customer details.', 'estasoft-migrate' ),
				[ ui.btn( __( 'New migration', 'estasoft-migrate' ), { kind: 'primary', href: ESTM.url( 'new' ) } ) ]
			),
			runningNote,
			tiles,
			h(
				'div.cols',
				h( 'div.main.stack', actionCards(), recent ),
				h( 'div.side.stack', env, freeCard(), plannedCard() )
			)
		);

		ESTM.replace( tiles, [ 1, 2, 3, 4 ].map( function () {
			return h( 'div.tile', ui.skeleton( 2 ) );
		} ) );

		function schedule( data, delay ) {
			clearTimeout( timer );
			if ( data && data.current ) {
				timer = setTimeout( load, delay || 4000 );
			}
		}

		function load() {
			return ESTM.get( 'overview' ).then( function ( data ) {
				draw( data );
				schedule( data );
				return data;
			}, function ( err ) {
				if ( ! drawn ) {
					ESTM.replace( tiles, h( 'div', { style: 'grid-column: 1 / -1' }, ui.loadError( err, load ) ) );
					return null;
				}
				// A failed refresh leaves what is on the screen alone and tries again a little later.
				schedule( { current: true }, 10000 );
				return null;
			} );
		}

		/** The numbers and the table are drawn again only when what they show has changed, so focus and screen readers are left alone. */
		function draw( data ) {
			drawn = true;
			drawRunning( data );
			var tileSig = JSON.stringify( [ data.database, data.packages, data.last && ( data.last.finished || data.last.updated ), data.site.prefix ] );
			if ( tileSig !== shown.tiles ) {
				shown.tiles = tileSig;
				ESTM.replace( tiles, tileList( data ) );
			}
			var rows = JSON.stringify( ( data.recent || [] ).map( function ( j ) {
				return [ j.id, j.status, j.title, j.type, j.message, j.remote, j.started, j.created, j.package ];
			} ) );
			if ( rows !== shown.recent ) {
				shown.recent = rows;
				ESTM.replace( recent, recentJobs( data.recent ) );
			}
		}

		function drawRunning( data ) {
			var job = data.current;
			if ( ! job ) {
				if ( null !== shown.job ) {
					shown.job = null;
					ESTM.replace( runningNote, null );
				}
				return;
			}
			if ( shown.job !== job.id ) {
				shown.job = job.id;
				shown.parts = running( job, data.health );
				ESTM.replace( runningNote, shown.parts.note );
			} else {
				shown.parts.update( job );
			}
		}

		load().then( function ( data ) {
			if ( data ) {
				loadEnvironment( env, data );
			}
		} );
		ESTM.replace( env, ui.card( { title: __( 'Environment check', 'estasoft-migrate' ) }, ui.skeleton( 4 ) ) );
	} );

	/* ---------------------------------------------------------------- Pieces */

	function running( job, env ) {
		var text = h( 'span', { 'aria-live': 'off' } );
		// Whether the person may leave depends on how this server runs jobs.
		var leave = ESTM.canLeave( env );
		function update( j ) {
			text.textContent = leave
				? sprintf( /* translators: 1: job title, 2: percent done */ __( '%1$s is %2$s done. You can close this page; the job keeps going and resumes by itself if the server restarts.', 'estasoft-migrate' ), j.title, fmt.percent( j.progress ) )
				: sprintf( /* translators: 1: job title, 2: percent done */ __( '%1$s is %2$s done. Keep the progress page open and visible: on this server the job only moves forward while that page is open.', 'estasoft-migrate' ), j.title, fmt.percent( j.progress ) );
		}
		update( job );
		return {
			update: update,
			note: ui.note(
				'tint',
				[ text, ' ', h( 'a', { href: ESTM.url( 'progress', { job: job.id } ) }, __( 'View progress', 'estasoft-migrate' ) ) ],
				{ title: __( 'A job is running.', 'estasoft-migrate' ) }
			),
		};
	}

	function tileList( data ) {
		var db = data.database;
		var snaps = data.packages.snapshots;
		var last = data.last;
		var largest = db.largest;
		return [
			ui.tile(
				__( 'Database size', 'estasoft-migrate' ),
				fmt.bytes( db.bytes ),
				/* translators: 1: number of tables, 2: table prefix */
				sprintf( _n( '%1$s table · prefix %2$s', '%1$s tables · prefix %2$s', db.tables, 'estasoft-migrate' ), fmt.number( db.tables ), data.site.prefix )
			),
			ui.tile(
				__( 'Largest table', 'estasoft-migrate' ),
				largest ? largest.name : '–',
				largest ? sprintf( /* translators: 1: number of rows, 2: size */ _n( '%1$s row · %2$s', '%1$s rows · %2$s', largest.rows, 'estasoft-migrate' ), fmt.number( largest.rows ), fmt.bytes( largest.bytes ) ) : __( 'No tables found', 'estasoft-migrate' ),
				{ small: true, mono: true }
			),
			ui.tile(
				__( 'Last completed migration', 'estasoft-migrate' ),
				last ? fmt.date( last.finished || last.updated, false ) : __( 'None yet', 'estasoft-migrate' ),
				last ? fmt.clock( last.finished || last.updated ) + ' · ' + ESTM.jobLabel( last ) : __( 'Nothing has been moved yet.', 'estasoft-migrate' ),
				{ small: true }
			),
			ui.tile(
				__( 'Rollback snapshots', 'estasoft-migrate' ),
				fmt.number( snaps.count ),
				[
					/* translators: %s: size on disk */
					sprintf( __( '%s on disk', 'estasoft-migrate' ), fmt.bytes( snaps.bytes ) ),
					' · ',
					h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Manage', 'estasoft-migrate' ) ),
				]
			),
		];
	}

	function card( title, text, label, screen, primary ) {
		return h(
			'section',
			{ class: 'card' + ( primary ? ' hi' : '' ), style: 'display:flex;flex-direction:column;gap:12px' },
			h( 'h2', title ),
			h( 'p.sub', { style: 'flex:1' }, text ),
			ui.btn( label, { kind: primary ? 'primary' : null, href: ESTM.url( screen ) } )
		);
	}

	function actionCards() {
		return h(
			'div.g-auto',
			{ style: 'grid-template-columns:repeat(auto-fit,minmax(230px,1fr))' },
			card( __( 'Migrate a site', 'estasoft-migrate' ), __( 'Export a package, or push to and pull from another WordPress site. Large tables are copied in small batches.', 'estasoft-migrate' ), __( 'Start a migration', 'estasoft-migrate' ), 'new', true ),
			card( __( 'Sanitize a database', 'estasoft-migrate' ), __( 'Make a staging, developer or GDPR-safe copy with fake personal data and no passwords or keys.', 'estasoft-migrate' ), __( 'Open sanitizer', 'estasoft-migrate' ), 'sanitizer' ),
			card( __( 'Search & replace', 'estasoft-migrate' ), __( 'Change URLs and text everywhere, including inside serialized and JSON data, with a dry run first.', 'estasoft-migrate' ), __( 'Open search & replace', 'estasoft-migrate' ), 'replace' ),
			card( __( 'Packages and snapshots', 'estasoft-migrate' ), __( 'Download, verify and import packages, or restore a rollback snapshot if something goes wrong.', 'estasoft-migrate' ), __( 'Open packages', 'estasoft-migrate' ), 'packages' )
		);
	}

	function jobSub( job ) {
		var parts = [];
		if ( job.message && 'completed' !== job.status ) {
			parts.push( job.message );
		} else if ( job.message ) {
			parts.push( job.message );
		}
		if ( job.remote ) {
			parts.push( job.remote.replace( /^https?:\/\//, '' ) );
		}
		return parts.join( ' · ' );
	}

	function jobAction( job ) {
		if ( ESTM.isActive( job.status ) || 'paused' === job.status ) {
			return h( 'a', { href: ESTM.url( 'progress', { job: job.id } ) }, __( 'View progress', 'estasoft-migrate' ) );
		}
		if ( 'search_replace' === job.type && 'completed' === job.status ) {
			return h( 'a', { href: ESTM.url( 'replace', { job: job.id } ) }, __( 'Review', 'estasoft-migrate' ) );
		}
		if ( 'export' === job.type && 'completed' === job.status && job.package ) {
			return h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Open package', 'estasoft-migrate' ) );
		}
		return h( 'a', { href: ESTM.url( 'logs', { job: job.id } ) }, __( 'View log', 'estasoft-migrate' ) );
	}

	function recentJobs( jobs ) {
		var table = ui.table(
			[
				{ label: __( 'Job', 'estasoft-migrate' ), render: function ( j ) {
					return ui.cell( j.title, jobSub( j ) );
				} },
				{ label: __( 'Type', 'estasoft-migrate' ), render: function ( j ) { return ESTM.jobLabel( j ); } },
				{ label: __( 'Status', 'estasoft-migrate' ), render: function ( j ) { return ESTM.jobBadge( j ); } },
				{ label: __( 'Started', 'estasoft-migrate' ), className: 'num nowrap', render: function ( j ) { return fmt.date( j.started || j.created ); } },
				{ label: __( 'Action', 'estasoft-migrate' ), srOnly: true, render: jobAction },
			],
			jobs,
			{ empty: ui.empty( __( 'No jobs yet', 'estasoft-migrate' ), __( 'Start with a migration, a sanitized copy or a search and replace. Every job shows up here.', 'estasoft-migrate' ) ), caption: __( 'Recent jobs', 'estasoft-migrate' ) }
		);
		return h(
			'section.card.flush',
			h( 'div.card-h', h( 'h2', __( 'Recent jobs', 'estasoft-migrate' ) ), ui.btn( __( 'Open logs', 'estasoft-migrate' ), { kind: 'quiet', size: 'sm', href: ESTM.url( 'logs' ) } ) ),
			table
		);
	}

	function freeCard() {
		var links = ESTM.links || {};
		return ui.card(
			{ dark: true, title: __( 'Free, with nothing held back', 'estasoft-migrate' ) },
			h( 'p.sub', { style: 'margin-top:6px' }, __( 'Every feature is free under the GPL v2 or later. No license key, no locked tabs, no upgrade banners, and no data sent to anyone.', 'estasoft-migrate' ) ),
			h(
				'div.row',
				{ style: 'margin-top:14px;gap:14px;font-size:13px' },
				links.docs ? h( 'a', { href: links.docs, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Documentation', 'estasoft-migrate' ) ) : null,
				links.support ? h( 'a', { href: links.support, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Support forum', 'estasoft-migrate' ) ) : null,
				links.source ? h( 'a', { href: links.source, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Source code', 'estasoft-migrate' ) ) : null
			)
		);
	}

	function plannedCard() {
		return ui.card(
			{ title: __( 'Planned next', 'estasoft-migrate' ) },
			h( 'p.sub', { style: 'margin-top:6px' }, __( 'Delta sync will push only the chunks that changed. Estasoft Migrate already stores a checksum for every table and chunk after each migration, so the data is ready.', 'estasoft-migrate' ) )
		);
	}

	/* ---------------------------------------------------------------- Environment check */

	function loadEnvironment( box, data ) {
		ESTM.post( 'preflight', { mode: 'export' } ).then( function ( r ) {
			var list = r.checks.slice( 0, 4 );
			var badge = r.counts.block
				? ui.badge( sprintf( /* translators: %d: number of problems */ _n( '%d problem', '%d problems', r.counts.block, 'estasoft-migrate' ), r.counts.block ), 'block' )
				: ( r.counts.warn
					? ui.badge( sprintf( /* translators: %d: number of warnings */ _n( '%d warning', '%d warnings', r.counts.warn, 'estasoft-migrate' ), r.counts.warn ), 'warn' )
					: ui.badge( __( 'All good', 'estasoft-migrate' ), 'ready' ) );
			ESTM.replace(
				box,
				ui.card(
					{ title: __( 'Environment check', 'estasoft-migrate' ), actions: [ badge ] },
					h(
						'ul.stack',
						{ style: 'gap:10px;font-size:13.5px' },
						list.map( function ( c ) {
							return h(
								'li.row',
								{ style: 'justify-content:space-between;flex-wrap:nowrap;align-items:flex-start' },
								h( 'span', c.title ),
								'block' === c.status ? ui.badge( __( 'Problem', 'estasoft-migrate' ), 'block' ) : ( 'warn' === c.status ? ui.badge( __( 'Warning', 'estasoft-migrate' ), 'warn' ) : ui.badge( __( 'Passed', 'estasoft-migrate' ), 'ready' ) )
							);
						} )
					),
					h( 'div.card-f', { style: 'margin:16px -20px -20px' }, ui.btn( __( 'Run full pre-flight check', 'estasoft-migrate' ), { size: 'sm', href: ESTM.url( 'preflight', { mode: 'export' } ) } ) )
				)
			);
			return data;
		}, function ( err ) {
			ESTM.replace( box, ui.card( { title: __( 'Environment check', 'estasoft-migrate' ) }, ui.note( 'err', ESTM.errorMessage( err ) ) ) );
		} );
	}
}() );
