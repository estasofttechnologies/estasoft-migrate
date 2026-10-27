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
	var TD = 'estasoft-migrate';

	ESTM.screen( 'overview', function ( root ) {
		var runningNote = h( 'div', { 'aria-live': 'polite' } );
		var tiles = h( 'div.g-auto' );
		var recent = h( 'div' );
		var env = h( 'div' );
		var timer = null;

		ESTM.replace(
			root,
			ui.pageHead(
				__( 'Overview', TD ),
				__( 'Move, copy and clean a WordPress database without timeouts, broken serialized data or leaked customer details.', TD ),
				[ ui.btn( __( 'New migration', TD ), { kind: 'primary', href: ESTM.url( 'new' ) } ) ]
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

		function load() {
			return ESTM.get( 'overview' ).then( function ( data ) {
				draw( data );
				clearTimeout( timer );
				if ( data.current ) {
					timer = setTimeout( load, 4000 );
				}
				return data;
			}, function ( err ) {
				ESTM.replace( tiles, h( 'div', { style: 'grid-column: 1 / -1' }, ui.loadError( err, load ) ) );
			} );
		}

		function draw( data ) {
			ESTM.replace( runningNote, data.current ? running( data.current ) : null );
			ESTM.replace( tiles, tileList( data ) );
			ESTM.replace( recent, recentJobs( data.recent ) );
		}

		load().then( function ( data ) {
			if ( data ) {
				loadEnvironment( env, data );
			}
		} );
		ESTM.replace( env, ui.card( { title: __( 'Environment check', TD ) }, ui.skeleton( 4 ) ) );
	} );

	/* ---------------------------------------------------------------- Pieces */

	function running( job ) {
		return ui.note(
			'tint',
			[
				/* translators: 1: job title, 2: percent done */
				sprintf( __( '%1$s is %2$s done. You can close this page; the job keeps going and resumes by itself if the server restarts.', TD ), job.title, fmt.percent( job.progress ) ),
				' ',
				h( 'a', { href: ESTM.url( 'progress', { job: job.id } ) }, __( 'View progress', TD ) ),
			],
			{ title: __( 'A job is running.', TD ) }
		);
	}

	function tileList( data ) {
		var db = data.database;
		var snaps = data.packages.snapshots;
		var last = data.last;
		var largest = db.largest;
		return [
			ui.tile(
				__( 'Database size', TD ),
				fmt.bytes( db.bytes ),
				/* translators: 1: number of tables, 2: table prefix */
				sprintf( _n( '%1$s table · prefix %2$s', '%1$s tables · prefix %2$s', db.tables, TD ), fmt.number( db.tables ), data.site.prefix )
			),
			ui.tile(
				__( 'Largest table', TD ),
				largest ? largest.name : '–',
				largest ? sprintf( /* translators: 1: number of rows, 2: size */ _n( '%1$s row · %2$s', '%1$s rows · %2$s', largest.rows, TD ), fmt.number( largest.rows ), fmt.bytes( largest.bytes ) ) : __( 'No tables found', TD ),
				{ small: true, mono: true }
			),
			ui.tile(
				__( 'Last completed migration', TD ),
				last ? fmt.date( last.finished || last.updated, false ) : __( 'None yet', TD ),
				last ? fmt.clock( last.finished || last.updated ) + ' · ' + ESTM.jobLabel( last ) : __( 'Nothing has been moved yet.', TD ),
				{ small: true }
			),
			ui.tile(
				__( 'Rollback snapshots', TD ),
				fmt.number( snaps.count ),
				[
					/* translators: %s: size on disk */
					sprintf( __( '%s on disk', TD ), fmt.bytes( snaps.bytes ) ),
					' · ',
					h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Manage', TD ) ),
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
			card( __( 'Migrate a site', TD ), __( 'Export a package, or push to and pull from another WordPress site. Large tables are copied in small batches.', TD ), __( 'Start a migration', TD ), 'new', true ),
			card( __( 'Sanitize a database', TD ), __( 'Make a staging, developer or GDPR-safe copy with fake personal data and no passwords or keys.', TD ), __( 'Open sanitizer', TD ), 'sanitizer' ),
			card( __( 'Search & replace', TD ), __( 'Change URLs and text everywhere, including inside serialized and JSON data, with a dry run first.', TD ), __( 'Open search & replace', TD ), 'replace' ),
			card( __( 'Packages and snapshots', TD ), __( 'Download, verify and import packages, or restore a rollback snapshot if something goes wrong.', TD ), __( 'Open packages', TD ), 'packages' )
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
			return h( 'a', { href: ESTM.url( 'progress', { job: job.id } ) }, __( 'View progress', TD ) );
		}
		if ( 'search_replace' === job.type && 'completed' === job.status ) {
			return h( 'a', { href: ESTM.url( 'replace', { job: job.id } ) }, __( 'Review', TD ) );
		}
		if ( 'export' === job.type && 'completed' === job.status && job.package ) {
			return h( 'a', { href: ESTM.url( 'packages' ) }, __( 'Open package', TD ) );
		}
		return h( 'a', { href: ESTM.url( 'logs', { job: job.id } ) }, __( 'View log', TD ) );
	}

	function recentJobs( jobs ) {
		var table = ui.table(
			[
				{ label: __( 'Job', TD ), render: function ( j ) {
					return ui.cell( j.title, jobSub( j ) );
				} },
				{ label: __( 'Type', TD ), render: function ( j ) { return ESTM.jobLabel( j ); } },
				{ label: __( 'Status', TD ), render: function ( j ) { return ESTM.jobBadge( j ); } },
				{ label: __( 'Started', TD ), className: 'num nowrap', render: function ( j ) { return fmt.date( j.started || j.created ); } },
				{ label: __( 'Action', TD ), srOnly: true, render: jobAction },
			],
			jobs,
			{ empty: ui.empty( __( 'No jobs yet', TD ), __( 'Start with a migration, a sanitized copy or a search and replace. Every job shows up here.', TD ) ), caption: __( 'Recent jobs', TD ) }
		);
		return h(
			'section.card.flush',
			h( 'div.card-h', h( 'h2', __( 'Recent jobs', TD ) ), ui.btn( __( 'Open logs', TD ), { kind: 'quiet', size: 'sm', href: ESTM.url( 'logs' ) } ) ),
			table
		);
	}

	function freeCard() {
		var links = ESTM.links || {};
		return ui.card(
			{ dark: true, title: __( 'Free, with nothing held back', TD ) },
			h( 'p.sub', { style: 'margin-top:6px' }, __( 'Every feature is free under the GPL v2 or later. No license key, no locked tabs, no upgrade banners, and no data sent to anyone.', TD ) ),
			h(
				'div.row',
				{ style: 'margin-top:14px;gap:14px;font-size:13px' },
				links.docs ? h( 'a', { href: links.docs, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Documentation', TD ) ) : null,
				links.support ? h( 'a', { href: links.support, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Support forum', TD ) ) : null,
				links.source ? h( 'a', { href: links.source, target: '_blank', rel: 'noopener noreferrer', style: 'color:var(--yellow)' }, __( 'Source code', TD ) ) : null
			)
		);
	}

	function plannedCard() {
		return ui.card(
			{ title: __( 'Planned next', TD ) },
			h( 'p.sub', { style: 'margin-top:6px' }, __( 'Delta sync will push only the chunks that changed. Estasoft Migrate already stores a checksum for every table and chunk after each migration, so the data is ready.', TD ) )
		);
	}

	/* ---------------------------------------------------------------- Environment check */

	function loadEnvironment( box, data ) {
		ESTM.post( 'preflight', { mode: 'export' } ).then( function ( r ) {
			var list = r.checks.slice( 0, 4 );
			var badge = r.counts.block
				? ui.badge( sprintf( _n( '%d problem', '%d problems', r.counts.block, TD ), r.counts.block ), 'block' )
				: ( r.counts.warn
					? ui.badge( sprintf( _n( '%d warning', '%d warnings', r.counts.warn, TD ), r.counts.warn ), 'warn' )
					: ui.badge( __( 'All good', TD ), 'ready' ) );
			ESTM.replace(
				box,
				ui.card(
					{ title: __( 'Environment check', TD ), actions: [ badge ] },
					h(
						'ul.stack',
						{ style: 'gap:10px;font-size:13.5px' },
						list.map( function ( c ) {
							return h(
								'li.row',
								{ style: 'justify-content:space-between;flex-wrap:nowrap;align-items:flex-start' },
								h( 'span', c.title ),
								'block' === c.status ? ui.badge( __( 'Problem', TD ), 'block' ) : ( 'warn' === c.status ? ui.badge( __( 'Warning', TD ), 'warn' ) : ui.badge( __( 'Passed', TD ), 'ready' ) )
							);
						} )
					),
					h( 'div.card-f', { style: 'margin:16px -20px -20px' }, ui.btn( __( 'Run full pre-flight check', TD ), { size: 'sm', href: ESTM.url( 'preflight', { mode: 'export' } ) } ) )
				)
			);
			return data;
		}, function ( err ) {
			ESTM.replace( box, ui.card( { title: __( 'Environment check', TD ) }, ui.note( 'err', ESTM.errorMessage( err ) ) ) );
		} );
	}
}() );
