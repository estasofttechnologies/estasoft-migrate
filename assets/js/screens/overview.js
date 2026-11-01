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
				__( 'Overview', 'estasoft-migration-database-sanitizer' ),
				__( 'Move, copy and clean a WordPress database without timeouts, broken serialized data or leaked customer details.', 'estasoft-migration-database-sanitizer' ),
				[ ui.btn( __( 'New migration', 'estasoft-migration-database-sanitizer' ), { kind: 'primary', href: ESTM.url( 'new' ) } ) ]
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
		ESTM.replace( env, ui.card( { title: __( 'Environment check', 'estasoft-migration-database-sanitizer' ) }, ui.skeleton( 4 ) ) );
	} );

	/* ---------------------------------------------------------------- Pieces */

	function running( job, env ) {
		var text = h( 'span', { 'aria-live': 'off' } );
		// Whether the person may leave depends on how this server runs jobs.
		var leave = ESTM.canLeave( env );
		function update( j ) {
			text.textContent = leave
				? sprintf( /* translators: 1: job title, 2: percent done */ __( '%1$s is %2$s done. You can close this page; the job keeps going and resumes by itself if the server restarts.', 'estasoft-migration-database-sanitizer' ), j.title, fmt.percent( j.progress ) )
				: sprintf( /* translators: 1: job title, 2: percent done */ __( '%1$s is %2$s done. Keep the progress page open and visible: on this server the job only moves forward while that page is open.', 'estasoft-migration-database-sanitizer' ), j.title, fmt.percent( j.progress ) );
		}
		update( job );
		return {
			update: update,
			note: ui.note(
				'tint',
				[ text, ' ', h( 'a', { href: ESTM.url( 'progress', { job: job.id } ) }, __( 'View progress', 'estasoft-migration-database-sanitizer' ) ) ],
				{ title: __( 'A job is running.', 'estasoft-migration-database-sanitizer' ) }
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
				__( 'Database size', 'estasoft-migration-database-sanitizer' ),
				fmt.bytes( db.bytes ),
				/* translators: 1: number of tables, 2: table prefix */
				sprintf( _n( '%1$s table · prefix %2$s', '%1$s tables · prefix %2$s', db.tables, 'estasoft-migration-database-sanitizer' ), fmt.number( db.tables ), data.site.prefix )
			),
			ui.tile(
				__( 'Largest table', 'estasoft-migration-database-sanitizer' ),
				largest ? largest.name : '–',
				largest ? sprintf( /* translators: 1: number of rows, 2: size */ _n( '%1$s row · %2$s', '%1$s rows · %2$s', largest.rows, 'estasoft-migration-database-sanitizer' ), fmt.number( largest.rows ), fmt.bytes( largest.bytes ) ) : __( 'No tables found', 'estasoft-migration-database-sanitizer' ),
				{ small: true, mono: true }
			),
			ui.tile(
				__( 'Last completed migration', 'estasoft-migration-database-sanitizer' ),
				last ? fmt.date( last.finished || last.updated, false ) : __( 'None yet', 'estasoft-migration-database-sanitizer' ),
				last ? fmt.clock( last.finished || last.updated ) + ' · ' + ESTM.jobLabel( last ) : __( 'Nothing has been moved yet.', 'estasoft-migration-database-sanitizer' ),
				{ small: true }
			),
			ui.tile(
				__( 'Rollback snapshots', 'estasoft-migration-database-sanitizer' ),
				fmt.number( snaps.count ),
				[
					/* translators: %s: size on disk */
					sprintf( __( '%s on disk', 'estasoft-migration-database-sanitizer' ), fmt.bytes( snaps.bytes ) ),
					' · ',
					h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Manage', 'estasoft-migration-database-sanitizer' ) ),
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
			card( __( 'Migrate a site', 'estasoft-migration-database-sanitizer' ), __( 'Export a package, or push to and pull from another WordPress site. Large tables are copied in small batches.', 'estasoft-migration-database-sanitizer' ), __( 'Start a migration', 'estasoft-migration-database-sanitizer' ), 'new', true ),
			card( __( 'Sanitize a database', 'estasoft-migration-database-sanitizer' ), __( 'Make a staging, developer or GDPR-safe copy with fake personal data and no passwords or keys.', 'estasoft-migration-database-sanitizer' ), __( 'Open sanitizer', 'estasoft-migration-database-sanitizer' ), 'sanitizer' ),
			card( __( 'Search & replace', 'estasoft-migration-database-sanitizer' ), __( 'Change URLs and text everywhere, including inside serialized and JSON data, with a dry run first.', 'estasoft-migration-database-sanitizer' ), __( 'Open search & replace', 'estasoft-migration-database-sanitizer' ), 'replace' ),
			card( __( 'Packages and snapshots', 'estasoft-migration-database-sanitizer' ), __( 'Download, verify and import packages, or restore a rollback snapshot if something goes wrong.', 'estasoft-migration-database-sanitizer' ), __( 'Open packages', 'estasoft-migration-database-sanitizer' ), 'packages' )
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
			return h( 'a', { href: ESTM.url( 'progress', { job: job.id } ) }, __( 'View progress', 'estasoft-migration-database-sanitizer' ) );
		}
		if ( 'search_replace' === job.type && 'completed' === job.status ) {
			return h( 'a', { href: ESTM.url( 'replace', { job: job.id } ) }, __( 'Review', 'estasoft-migration-database-sanitizer' ) );
		}
		if ( 'export' === job.type && 'completed' === job.status && job.package ) {
			return h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Open package', 'estasoft-migration-database-sanitizer' ) );
		}
		return h( 'a', { href: ESTM.url( 'logs', { job: job.id } ) }, __( 'View log', 'estasoft-migration-database-sanitizer' ) );
	}

	function recentJobs( jobs ) {
		var table = ui.table(
			[
				{ label: __( 'Job', 'estasoft-migration-database-sanitizer' ), render: function ( j ) {
					return ui.cell( j.title, jobSub( j ) );
				} },
				{ label: __( 'Type', 'estasoft-migration-database-sanitizer' ), render: function ( j ) { return ESTM.jobLabel( j ); } },
				{ label: __( 'Status', 'estasoft-migration-database-sanitizer' ), render: function ( j ) { return ESTM.jobBadge( j ); } },
				{ label: __( 'Started', 'estasoft-migration-database-sanitizer' ), className: 'num nowrap', render: function ( j ) { return fmt.date( j.started || j.created ); } },
				{ label: __( 'Action', 'estasoft-migration-database-sanitizer' ), srOnly: true, render: jobAction },
			],
			jobs,
			{ empty: ui.empty( __( 'No jobs yet', 'estasoft-migration-database-sanitizer' ), __( 'Start with a migration, a sanitized copy or a search and replace. Every job shows up here.', 'estasoft-migration-database-sanitizer' ) ), caption: __( 'Recent jobs', 'estasoft-migration-database-sanitizer' ) }
		);
		return h(
			'section.card.flush',
			h( 'div.card-h', h( 'h2', __( 'Recent jobs', 'estasoft-migration-database-sanitizer' ) ), ui.btn( __( 'Open logs', 'estasoft-migration-database-sanitizer' ), { kind: 'quiet', size: 'sm', href: ESTM.url( 'logs' ) } ) ),
			table
		);
	}

	function freeCard() {
		var links = ESTM.links || {};
		return ui.card(
			{ dark: true, title: __( 'Free, with nothing held back', 'estasoft-migration-database-sanitizer' ) },
			h( 'p.sub', { style: 'margin-top:6px' }, __( 'Every feature is free under the GPL v2 or later. No license key, no locked tabs, no upgrade banners, and no data sent to anyone.', 'estasoft-migration-database-sanitizer' ) ),
			h(
				'div.row',
				{ style: 'margin-top:14px;gap:14px;font-size:13px' },
				links.docs ? h( 'a', { href: links.docs, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Documentation', 'estasoft-migration-database-sanitizer' ) ) : null,
				links.support ? h( 'a', { href: links.support, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Support forum', 'estasoft-migration-database-sanitizer' ) ) : null,
				links.source ? h( 'a', { href: links.source, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Source code', 'estasoft-migration-database-sanitizer' ) ) : null
			)
		);
	}

	function plannedCard() {
		return ui.card(
			{ title: __( 'Planned next', 'estasoft-migration-database-sanitizer' ) },
			h( 'p.sub', { style: 'margin-top:6px' }, __( 'Delta sync will push only the chunks that changed. Estasoft Migrate already stores a checksum for every table and chunk after each migration, so the data is ready.', 'estasoft-migration-database-sanitizer' ) )
		);
	}

	/* ---------------------------------------------------------------- Environment check */

	function loadEnvironment( box, data ) {
		ESTM.post( 'preflight', { mode: 'export' } ).then( function ( r ) {
			var list = r.checks.slice( 0, 4 );
			var badge = r.counts.block
				? ui.badge( sprintf( /* translators: %d: number of problems */ _n( '%d problem', '%d problems', r.counts.block, 'estasoft-migration-database-sanitizer' ), r.counts.block ), 'block' )
				: ( r.counts.warn
					? ui.badge( sprintf( /* translators: %d: number of warnings */ _n( '%d warning', '%d warnings', r.counts.warn, 'estasoft-migration-database-sanitizer' ), r.counts.warn ), 'warn' )
					: ui.badge( __( 'All good', 'estasoft-migration-database-sanitizer' ), 'ready' ) );
			ESTM.replace(
				box,
				ui.card(
					{ title: __( 'Environment check', 'estasoft-migration-database-sanitizer' ), actions: [ badge ] },
					h(
						'ul.stack',
						{ style: 'gap:10px;font-size:13.5px' },
						list.map( function ( c ) {
							return h(
								'li.row',
								{ style: 'justify-content:space-between;flex-wrap:nowrap;align-items:flex-start' },
								h( 'span', c.title ),
								'block' === c.status ? ui.badge( __( 'Problem', 'estasoft-migration-database-sanitizer' ), 'block' ) : ( 'warn' === c.status ? ui.badge( __( 'Warning', 'estasoft-migration-database-sanitizer' ), 'warn' ) : ui.badge( __( 'Passed', 'estasoft-migration-database-sanitizer' ), 'ready' ) )
							);
						} )
					),
					h( 'div.card-f', { style: 'margin:16px -20px -20px' }, ui.btn( __( 'Run full pre-flight check', 'estasoft-migration-database-sanitizer' ), { size: 'sm', href: ESTM.url( 'preflight', { mode: 'export' } ) } ) )
				)
			);
			return data;
		}, function ( err ) {
			ESTM.replace( box, ui.card( { title: __( 'Environment check', 'estasoft-migration-database-sanitizer' ) }, ui.note( 'err', ESTM.errorMessage( err ) ) ) );
		} );
	}
}() );
