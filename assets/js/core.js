/**
 * Estasoft Migrate: shared code for every screen.
 *
 * No build step and no dependencies other than wp.i18n: this file is what runs. Each screen script
 * (assets/js/screens/<id>.js) registers a function with ESTM.screen('<id>', function (root) { … }).
 * The conventions are written down in docs/UI.md.
 *
 * Everything that reaches the page is created with createElement/createTextNode (see ESTM.h), never
 * from HTML strings, so text that comes from the database or from another site cannot inject markup.
 */
( function () {
	'use strict';

	var ESTM = window.ESTM || {};
	window.ESTM = ESTM;

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( s ) { return s; };
	var _n = i18n ? i18n._n : function ( single, plural, n ) { return 1 === n ? single : plural; };
	var sprintf = i18n ? i18n.sprintf : function ( s ) { return s; };
	var TD = 'estasoft-migrate';

	/* ------------------------------------------------------------------ DOM builder */

	var idCounter = 0;

	/**
	 * Create an element. h('div.card.hi', { onclick: fn }, child, [children], 'text').
	 * attrs: class, style (string or object), dataset (object), on<event> (function), value/checked/disabled/selected
	 * (set as properties), anything else as an attribute (true = present, false/null = left out).
	 */
	function h( tag, attrs ) {
		var parts = String( tag ).split( /(?=[.#])/ );
		var el = document.createElement( parts[ 0 ] || 'div' );
		parts.slice( 1 ).forEach( function ( p ) {
			if ( '#' === p.charAt( 0 ) ) {
				el.id = p.slice( 1 );
			} else {
				el.classList.add( p.slice( 1 ) );
			}
		} );
		var start = 1;
		if ( attrs && 'object' === typeof attrs && ! ( attrs instanceof Node ) && ! Array.isArray( attrs ) ) {
			start = 2;
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( null === v || undefined === v || false === v ) {
					return;
				}
				if ( 'class' === k || 'className' === k ) {
					String( v ).split( /\s+/ ).forEach( function ( c ) { if ( c ) { el.classList.add( c ); } } );
				} else if ( 'style' === k ) {
					if ( 'string' === typeof v ) {
						el.setAttribute( 'style', v );
					} else {
						Object.keys( v ).forEach( function ( s ) { el.style.setProperty( s.replace( /[A-Z]/g, function ( m ) { return '-' + m.toLowerCase(); } ), v[ s ] ); } );
					}
				} else if ( 'dataset' === k ) {
					Object.keys( v ).forEach( function ( d ) { el.dataset[ d ] = v[ d ]; } );
				} else if ( 0 === k.indexOf( 'on' ) && 'function' === typeof v ) {
					el.addEventListener( k.slice( 2 ).toLowerCase(), v );
				} else if ( 'value' === k || 'checked' === k || 'disabled' === k || 'selected' === k || 'indeterminate' === k ) {
					el[ k ] = v;
				} else if ( true === v ) {
					el.setAttribute( k, '' );
				} else {
					el.setAttribute( k, v );
				}
			} );
		}
		append( el, Array.prototype.slice.call( arguments, start ) );
		return el;
	}

	function append( el, kids ) {
		kids.forEach( function ( k ) {
			if ( Array.isArray( k ) ) {
				append( el, k );
			} else if ( null === k || undefined === k || false === k || true === k ) {
				return;
			} else if ( k instanceof Node ) {
				el.appendChild( k );
			} else {
				el.appendChild( document.createTextNode( String( k ) ) );
			}
		} );
	}

	/** Replace the content of a node. */
	function replace( el ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		append( el, Array.prototype.slice.call( arguments, 1 ) );
		return el;
	}

	function uid( prefix ) {
		idCounter += 1;
		return ( prefix || 'estm' ) + '-' + idCounter;
	}

	/* ------------------------------------------------------------------ Formatting */

	var locale = ( document.documentElement.lang || 'en' ).replace( '_', '-' );

	function number( n ) {
		try {
			return new Intl.NumberFormat( locale ).format( Number( n ) || 0 );
		} catch ( e ) {
			return String( Number( n ) || 0 );
		}
	}

	function bytes( n ) {
		n = Number( n ) || 0;
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( n >= 1024 && i < units.length - 1 ) {
			n /= 1024;
			i += 1;
		}
		var text = 0 === i || n >= 10 ? String( Math.round( n ) ) : ( Math.round( n * 10 ) / 10 ).toFixed( 1 ).replace( /\.0$/, '' );
		return text + ' ' + units[ i ];
	}

	function duration( sec ) {
		sec = Math.max( 0, Math.round( Number( sec ) || 0 ) );
		if ( sec < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%d s', TD ), sec );
		}
		var m = Math.floor( sec / 60 );
		var s = sec % 60;
		if ( m < 60 ) {
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$d min %2$02d s', TD ), m, s );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$d h %2$02d min', TD ), Math.floor( m / 60 ), m % 60 );
	}

	/** The server sends UTC as "YYYY-MM-DD HH:MM:SS". */
	function parseGmt( s ) {
		if ( ! s ) {
			return null;
		}
		var d = new Date( String( s ).replace( ' ', 'T' ) + 'Z' );
		return isNaN( d.getTime() ) ? null : d;
	}

	function date( s, withTime ) {
		var d = parseGmt( s );
		if ( ! d ) {
			return '';
		}
		var opts = { day: 'numeric', month: 'short', year: 'numeric' };
		if ( false !== withTime ) {
			opts.hour = '2-digit';
			opts.minute = '2-digit';
		}
		return formatIn( d, opts );
	}

	/** Format a date in the site's time zone (a name such as Asia/Baghdad, or an offset such as +03:00). */
	function formatIn( d, opts ) {
		var tz = ESTM.timezone;
		if ( tz ) {
			try {
				return new Intl.DateTimeFormat( locale, Object.assign( { timeZone: tz }, opts ) ).format( d );
			} catch ( e ) {
				// Older browsers do not know offsets as zone names: shift the time by hand.
				var m = /^([+-])(\d\d):(\d\d)$/.exec( tz );
				if ( m ) {
					var shift = ( '-' === m[ 1 ] ? -1 : 1 ) * ( parseInt( m[ 2 ], 10 ) * 60 + parseInt( m[ 3 ], 10 ) ) * 60000;
					return new Intl.DateTimeFormat( locale, Object.assign( { timeZone: 'UTC' }, opts ) ).format( new Date( d.getTime() + shift ) );
				}
			}
		}
		return new Intl.DateTimeFormat( locale, opts ).format( d );
	}

	function clock( s ) {
		var d = parseGmt( s );
		if ( ! d ) {
			return '';
		}
		return formatIn( d, { hour: '2-digit', minute: '2-digit' } );
	}

	function time( s ) {
		var d = parseGmt( s );
		if ( ! d ) {
			return '';
		}
		return formatIn( d, { hour: '2-digit', minute: '2-digit', second: '2-digit' } );
	}

	function ago( s ) {
		var d = parseGmt( s );
		if ( ! d ) {
			return '';
		}
		var sec = Math.max( 0, Math.round( ( Date.now() - d.getTime() ) / 1000 ) );
		if ( sec < 45 ) {
			return __( 'just now', TD );
		}
		if ( sec < 3600 ) {
			/* translators: %d: minutes */
			return sprintf( _n( '%d minute ago', '%d minutes ago', Math.round( sec / 60 ), TD ), Math.round( sec / 60 ) );
		}
		if ( sec < 86400 ) {
			/* translators: %d: hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', Math.round( sec / 3600 ), TD ), Math.round( sec / 3600 ) );
		}
		return date( s );
	}

	function percent( n ) {
		n = Number( n ) || 0;
		return ( n >= 10 || Math.round( n ) === n ? String( Math.round( n ) ) : n.toFixed( 1 ) ) + '%';
	}

	/* ------------------------------------------------------------------ API */

	function ApiError( message, code, status, data ) {
		this.name = 'ApiError';
		this.message = message;
		this.code = code || 'estm_error';
		this.status = status || 0;
		this.data = data || {};
	}
	ApiError.prototype = Object.create( Error.prototype );
	ApiError.prototype.constructor = ApiError;

	function queryString( obj ) {
		return Object.keys( obj ).filter( function ( k ) {
			return null !== obj[ k ] && undefined !== obj[ k ] && '' !== obj[ k ];
		} ).map( function ( k ) {
			var v = obj[ k ];
			return encodeURIComponent( k ) + '=' + encodeURIComponent( Array.isArray( v ) ? v.join( ',' ) : ( true === v ? '1' : ( false === v ? '0' : v ) ) );
		} ).join( '&' );
	}

	function parseBody( text ) {
		if ( ! text ) {
			return null;
		}
		try {
			return JSON.parse( text );
		} catch ( e ) {
			// A PHP notice printed before the JSON should not break the screen: use the JSON that follows it.
			var at = text.search( /[{[]/ );
			if ( at > 0 ) {
				try {
					return JSON.parse( text.slice( at ) );
				} catch ( e2 ) {
					return null;
				}
			}
			return null;
		}
	}

	/**
	 * Call the REST API. Resolves with the parsed JSON, rejects with an ApiError whose message can be shown to people.
	 * opts: query (object), raw (send body as bytes), signal (AbortSignal).
	 */
	function api( method, path, body, opts ) {
		opts = opts || {};
		var url = ESTM.rest + String( path ).replace( /^\//, '' );
		if ( opts.query ) {
			var qs = queryString( opts.query );
			if ( qs ) {
				url += ( url.indexOf( '?' ) >= 0 ? '&' : '?' ) + qs;
			}
		}
		var headers = { 'X-WP-Nonce': ESTM.nonce };
		var init = { method: method, headers: headers, credentials: 'same-origin' };
		if ( undefined !== body && null !== body ) {
			if ( opts.raw ) {
				init.body = body;
				headers[ 'Content-Type' ] = 'application/octet-stream';
			} else {
				init.body = JSON.stringify( body );
				headers[ 'Content-Type' ] = 'application/json';
			}
		}
		if ( opts.signal ) {
			init.signal = opts.signal;
		}
		return fetch( url, init ).then( function ( res ) {
			return res.text().then( function ( text ) {
				var data = parseBody( text );
				if ( res.ok && ( null !== data || ! text ) ) {
					return data;
				}
				throw httpError( res, data );
			} );
		}, function ( err ) {
			if ( err && 'AbortError' === err.name ) {
				throw err;
			}
			throw new ApiError( __( 'Could not reach the server. Check your connection. Jobs that are already running keep going on the server.', TD ), 'estm_network', 0 );
		} );
	}

	function httpError( res, data ) {
		var code = data && data.code ? data.code : 'estm_http';
		var message = data && data.message ? data.message : '';
		if ( 'rest_cookie_invalid_nonce' === code ) {
			message = __( 'Your login session has ended. This can happen after an import, because it replaces this site’s users. Reload the page and sign in again; jobs keep going on the server.', TD );
		} else if ( 'rest_forbidden' === code || 401 === res.status || 403 === res.status ) {
			message = message || __( 'You are not allowed to do that.', TD );
		} else if ( ! message ) {
			/* translators: %d: HTTP status code */
			message = sprintf( __( 'The server answered with an error (HTTP %d). The PHP error log has the details.', TD ), res.status );
		}
		return new ApiError( message, code, res.status, data && data.data ? data.data : {} );
	}

	ESTM.get = function ( path, query ) { return api( 'GET', path, null, { query: query } ); };
	ESTM.post = function ( path, body ) { return api( 'POST', path, body || {} ); };
	ESTM.del = function ( path, query ) { return api( 'DELETE', path, null, { query: query } ); };
	ESTM.api = api;
	ESTM.ApiError = ApiError;

	/** Message for people from anything that was thrown. */
	function errorMessage( e ) {
		if ( e && e.message ) {
			return e.message;
		}
		return __( 'Something went wrong. Try again.', TD );
	}

	/* ------------------------------------------------------------------ Jobs */

	var FINISHED = [ 'completed', 'failed', 'cancelled' ];

	function isFinished( status ) {
		return FINISHED.indexOf( status ) >= 0;
	}

	function isActive( status ) {
		return 'queued' === status || 'running' === status;
	}

	/**
	 * Keep a job moving and report on it. Each round trip does one slice of work (unless a background
	 * runner is busy with the job, in which case it only reports).
	 * handlers: onUpdate(view), onLogs(lines, view), onDone(view) (finished or paused), onError(err, failures).
	 * Returns { stop(), poke() }.
	 */
	function watchJob( id, handlers ) {
		handlers = handlers || {};
		var cursor = handlers.cursor || 0;
		var stopped = false;
		var timer = null;
		var failures = 0;
		var running = false;

		function step() {
			if ( stopped || running ) {
				return;
			}
			running = true;
			ESTM.post( 'jobs/' + id + '/tick', { after_log: cursor } ).then( function ( view ) {
				running = false;
				failures = 0;
				cursor = view.log_cursor || cursor;
				if ( stopped ) {
					return;
				}
				if ( handlers.onUpdate ) {
					handlers.onUpdate( view );
				}
				if ( view.logs && view.logs.length && handlers.onLogs ) {
					handlers.onLogs( view.logs, view );
				}
				if ( isFinished( view.status ) || 'paused' === view.status ) {
					stopped = true;
					if ( handlers.onDone ) {
						handlers.onDone( view );
					}
					return;
				}
				timer = setTimeout( step, view.ran ? 30 : ( view.locked ? 1500 : 1000 ) );
			}, function ( err ) {
				running = false;
				failures += 1;
				if ( stopped ) {
					return;
				}
				if ( handlers.onError ) {
					handlers.onError( err, failures );
				}
				if ( 404 === err.status ) {
					stopped = true;
					return;
				}
				timer = setTimeout( step, Math.min( 10000, 1500 * failures ) );
			} );
		}

		step();
		return {
			stop: function () { stopped = true; clearTimeout( timer ); },
			poke: function () { clearTimeout( timer ); stopped = false; step(); },
		};
	}

	var TYPE_LABELS = function () {
		return {
			export: __( 'Export', TD ),
			sanitize: __( 'Sanitize', TD ),
			import: __( 'Import', TD ),
			restore: __( 'Restore', TD ),
			snapshot: __( 'Snapshot', TD ),
			push: __( 'Push', TD ),
			pull: __( 'Pull', TD ),
			search_replace: __( 'Search & replace', TD ),
			verify: __( 'Check package', TD ),
		};
	};

	/** What kind of job it is, in a word or two. */
	function jobLabel( job ) {
		var labels = TYPE_LABELS();
		return labels[ job.kind ] || labels[ job.type ] || job.type;
	}

	/** Status badge for a job. */
	function jobBadge( job ) {
		var status = job.status;
		if ( 'completed' === status && job.dry_run ) {
			return badge( __( 'Dry run', TD ), 'warn' );
		}
		switch ( status ) {
			case 'queued': return badge( __( 'Queued', TD ), 'idle' );
			case 'running': return badge( __( 'Running', TD ), 'run' );
			case 'paused': return badge( __( 'Paused', TD ), 'pause' );
			case 'failed': return badge( __( 'Failed', TD ), 'block' );
			case 'completed': return badge( __( 'Completed', TD ), 'ready' );
			case 'cancelled': return badge( __( 'Cancelled', TD ), 'idle' );
		}
		return badge( status, 'plain' );
	}

	/* ------------------------------------------------------------------ Navigation and small helpers */

	/** URL of a screen. ESTM.url('progress', { job: 12 }). */
	function url( screen, params ) {
		var base = ESTM.pages[ screen ] || ESTM.pages.overview;
		var qs = params ? queryString( params ) : '';
		return qs ? base + '&' + qs : base;
	}

	function downloadUrl( kind, id ) {
		return ( ESTM.downloads[ kind ] || '' ) + encodeURIComponent( null === id || undefined === id ? '' : id );
	}

	/** Query string of this page as an object. */
	function params() {
		var out = {};
		new URLSearchParams( window.location.search ).forEach( function ( v, k ) { out[ k ] = v; } );
		return out;
	}

	/** Remember something for the next screen of a flow. Survives a reload, not a closed tab. */
	var draft = {
		get: function ( key ) {
			try {
				var raw = window.sessionStorage.getItem( 'estm:' + key );
				return raw ? JSON.parse( raw ) : null;
			} catch ( e ) {
				return null;
			}
		},
		set: function ( key, value ) {
			try {
				window.sessionStorage.setItem( 'estm:' + key, JSON.stringify( value ) );
				return true;
			} catch ( e ) {
				return false;
			}
		},
		clear: function ( key ) {
			try {
				window.sessionStorage.removeItem( 'estm:' + key );
			} catch ( e ) {
				return;
			}
		},
	};

	function debounce( fn, wait ) {
		var t;
		return function () {
			var args = arguments;
			var self = this;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( self, args ); }, wait );
		};
	}

	/** "a, b\nc" or ["a"] to a clean list. */
	function toList( value ) {
		var raw = Array.isArray( value ) ? value : String( value || '' ).split( /[\s,]+/ );
		var seen = {};
		return raw.map( function ( s ) { return String( s ).trim(); } ).filter( function ( s ) {
			if ( ! s || seen[ s ] ) {
				return false;
			}
			seen[ s ] = true;
			return true;
		} );
	}

	/* ------------------------------------------------------------------ UI components */

	var ui = {};

	function badge( text, kind, extra ) {
		return h( 'span.badge' + ( kind ? '.b-' + kind : '' ) + ( extra ? '.' + extra : '' ), text );
	}
	ui.badge = badge;

	/** Button or link that looks like one. kind: primary, dark, quiet, danger, link. */
	ui.btn = function ( label, o ) {
		o = o || {};
		var cls = 'btn' + ( o.kind ? ' btn-' + o.kind : '' ) + ( o.size ? ' btn-' + o.size : '' ) + ( o.className ? ' ' + o.className : '' );
		var attrs = { class: cls, title: o.title, 'aria-label': o.ariaLabel, id: o.id };
		var el;
		if ( o.href ) {
			attrs.href = o.href;
			if ( o.target ) {
				attrs.target = o.target;
				attrs.rel = 'noopener noreferrer';
			}
			if ( o.download ) {
				attrs.download = true;
			}
			el = h( 'a', attrs, label );
		} else {
			attrs.type = o.type || 'button';
			attrs.disabled = !! o.disabled;
			el = h( 'button', attrs, label );
		}
		if ( o.onClick ) {
			el.addEventListener( 'click', function ( ev ) {
				if ( el.classList.contains( 'is-busy' ) || el.disabled ) {
					ev.preventDefault();
					return;
				}
				o.onClick( ev, el );
			} );
		}
		return el;
	};

	/** Show a spinner on a button and ignore clicks while the promise runs. */
	ui.busy = function ( button, promise ) {
		button.classList.add( 'is-busy' );
		button.setAttribute( 'aria-busy', 'true' );
		var done = function () {
			button.classList.remove( 'is-busy' );
			button.removeAttribute( 'aria-busy' );
		};
		promise.then( done, done );
		return promise;
	};

	/** A card. o: title, sub, actions (nodes), flush, dark, hi, className, id. Children go below the heading. */
	ui.card = function ( o ) {
		var kids = Array.prototype.slice.call( arguments, 1 );
		var cls = 'card' + ( o.flush ? ' flush' : '' ) + ( o.dark ? ' dark' : '' ) + ( o.hi ? ' hi' : '' ) + ( o.className ? ' ' + o.className : '' );
		var head = null;
		if ( o.title ) {
			head = h( 'div.card-h', h( 'div', h( 'h2', o.title ), o.sub ? h( 'p.sub', o.sub ) : null ), o.actions ? h( 'div.row', o.actions ) : null );
		}
		return h( 'section', { class: cls, id: o.id }, head, kids );
	};

	ui.tile = function ( label, value, sub, o ) {
		o = o || {};
		return h( 'div.tile', h( 'span.k', label ), h( 'span.v' + ( o.small ? '.sm' : '' ) + ( o.mono ? '.mono' : '' ), value ), sub ? h( 'span.s', sub ) : null );
	};

	/** Notice. kind: '' (calm), 'tint', 'warn', 'err'. */
	ui.note = function ( kind, content, o ) {
		o = o || {};
		var icon = 'err' === kind ? '!' : ( 'warn' === kind ? '!' : ( 'tint' === kind ? '●' : 'i' ) );
		return h(
			'div',
			{ class: 'note' + ( kind ? ' ' + kind : '' ), role: 'err' === kind ? 'alert' : 'status' },
			h( 'span.ic', { 'aria-hidden': 'true' }, icon ),
			h( 'div', o.title ? [ h( 'b', o.title ), ' ' ] : null, content )
		);
	};

	/** Progress bar. Update it with ui.setBar(el, percent). */
	ui.bar = function ( pct, o ) {
		o = o || {};
		var el = h( 'div', { class: 'bar' + ( o.large ? ' lg' : '' ) + ( o.live ? ' live' : '' ) + ( o.err ? ' err' : '' ), role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-label': o.label }, h( 'i' ) );
		ui.setBar( el, pct );
		return el;
	};

	ui.setBar = function ( el, pct, o ) {
		pct = Math.max( 0, Math.min( 100, Number( pct ) || 0 ) );
		el.firstChild.style.width = pct + '%';
		el.setAttribute( 'aria-valuenow', String( Math.round( pct ) ) );
		if ( o ) {
			el.classList.toggle( 'live', !! o.live );
			el.classList.toggle( 'err', !! o.err );
		}
	};

	/** Label, control, hint and error message tied together for screen readers. */
	ui.field = function ( o, control ) {
		var id = o.id || uid( 'f' );
		var hintId = o.hint ? id + '-hint' : null;
		var errId = id + '-err';
		var err = h( 'div.field-error', { id: errId, hidden: true } );
		// A wrapper such as ui.password() exposes the real control as .input; the label and messages belong to that.
		var target = control && control.input && control.input.setAttribute ? control.input : control;
		if ( target && target.setAttribute ) {
			target.id = target.id || id;
			id = target.id;
			var describe = [ hintId, errId ].filter( Boolean ).join( ' ' );
			target.setAttribute( 'aria-describedby', describe );
		}
		var el = h( 'div.field', o.className ? { class: o.className } : null, o.label ? h( 'label', { for: id }, o.label, o.optional ? h( 'span.muted', ' ' + __( '(optional)', TD ) ) : null ) : null, control, o.hint ? h( 'span.hint', { id: hintId }, o.hint ) : null, err );
		el.setError = function ( message ) {
			el.classList.toggle( 'has-error', !! message );
			err.hidden = ! message;
			err.textContent = message || '';
			if ( target && target.setAttribute ) {
				if ( message ) {
					target.setAttribute( 'aria-invalid', 'true' );
				} else {
					target.removeAttribute( 'aria-invalid' );
				}
			}
		};
		return el;
	};

	ui.input = function ( o ) {
		o = o || {};
		return h( 'input', {
			class: 'input' + ( o.mono ? ' mono' : '' ) + ( o.className ? ' ' + o.className : '' ),
			type: o.type || 'text',
			value: undefined === o.value ? '' : o.value,
			placeholder: o.placeholder,
			id: o.id,
			name: o.name,
			readonly: !! o.readonly,
			disabled: !! o.disabled,
			required: !! o.required,
			autocomplete: o.autocomplete || 'off',
			spellcheck: o.spellcheck === undefined ? 'false' : String( o.spellcheck ),
			inputmode: o.inputmode,
			min: o.min,
			max: o.max,
			step: o.step,
			oninput: o.onInput,
			onchange: o.onChange,
			onkeydown: o.onKeydown,
			'aria-label': o.ariaLabel,
		} );
	};

	/** Password input with a show/hide button. Returns the wrapper; .input is the input element. */
	ui.password = function ( o ) {
		o = o || {};
		var input = ui.input( { type: 'password', autocomplete: 'new-password', id: o.id, placeholder: o.placeholder, onInput: o.onInput, mono: true } );
		var shown = false;
		var toggle = ui.btn( __( 'Show', TD ), { kind: 'quiet', onClick: function () {
			shown = ! shown;
			input.type = shown ? 'text' : 'password';
			toggle.textContent = shown ? __( 'Hide', TD ) : __( 'Show', TD );
			toggle.setAttribute( 'aria-pressed', shown ? 'true' : 'false' );
		} } );
		toggle.setAttribute( 'aria-pressed', 'false' );
		var el = h( 'div.input-group', input, toggle );
		el.input = input;
		return el;
	};

	ui.textarea = function ( o ) {
		o = o || {};
		return h( 'textarea', { class: 'input' + ( o.mono ? ' mono' : '' ), rows: o.rows || 4, placeholder: o.placeholder, id: o.id, value: o.value || '', oninput: o.onInput, spellcheck: 'false' } );
	};

	/** options: [{ value, label }]. */
	ui.select = function ( options, o ) {
		o = o || {};
		var el = h( 'select', { class: 'select', id: o.id, onchange: o.onChange, 'aria-label': o.ariaLabel, disabled: !! o.disabled }, options.map( function ( opt ) {
			return h( 'option', { value: opt.value, selected: String( opt.value ) === String( o.value ), disabled: !! opt.disabled }, opt.label );
		} ) );
		el.value = undefined === o.value ? el.value : String( o.value );
		return el;
	};

	/** Switch (checkbox with role=switch). Returns the input; wrap it with a label yourself or use ui.switchRow. */
	ui.switch = function ( o ) {
		o = o || {};
		return h( 'input.switch', { type: 'checkbox', role: 'switch', checked: !! o.checked, disabled: !! o.disabled, id: o.id, onchange: o.onChange, 'aria-label': o.ariaLabel, 'aria-describedby': o.describedBy } );
	};

	ui.checkbox = function ( o ) {
		o = o || {};
		return h( 'input.chk', { type: 'checkbox', checked: !! o.checked, disabled: !! o.disabled, id: o.id, onchange: o.onChange, 'aria-label': o.ariaLabel } );
	};

	/** A settings row: text on one side, control on the other. */
	ui.setting = function ( title, text, control, o ) {
		o = o || {};
		var id = o.id || uid( 's' );
		if ( control && control.setAttribute && ! control.id ) {
			control.id = id;
		}
		return h( 'div.set', h( 'div.tx', h( 'label', { for: control && control.id ? control.id : id, style: 'display:block;font-weight:600' }, title ), text ? h( 'span', text ) : null ), h( 'div.ctl', control ) );
	};

	/**
	 * Segmented control. options: [{ value, label }]. onChange(value). Returns the element; el.set(value) and el.value().
	 */
	ui.seg = function ( options, value, onChange, o ) {
		o = o || {};
		var current = value;
		var buttons = options.map( function ( opt ) {
			var b = h( 'button', { type: 'button', 'aria-pressed': String( opt.value ) === String( current ) ? 'true' : 'false', class: String( opt.value ) === String( current ) ? 'on' : null }, opt.label );
			b.addEventListener( 'click', function () { set( opt.value, true ); } );
			b.dataset.value = opt.value;
			return b;
		} );
		var el = h( 'div', { class: 'seg', role: 'group', 'aria-label': o.label }, buttons );
		function set( v, fire ) {
			current = v;
			buttons.forEach( function ( b ) {
				var on = b.dataset.value === String( v );
				b.classList.toggle( 'on', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			if ( fire && onChange ) {
				onChange( v );
			}
		}
		el.set = function ( v ) { set( v, false ); };
		el.value = function () { return current; };
		return el;
	};

	ui.spinner = function ( label ) {
		return h( 'span.spinner', { role: 'status', 'aria-label': label || __( 'Loading', TD ) } );
	};

	ui.empty = function ( title, text, action ) {
		return h( 'div.empty', h( 'b', title ), text ? h( 'p', text ) : null, action || null );
	};

	/** Placeholder lines while a screen loads. */
	ui.skeleton = function ( lines ) {
		var out = [];
		for ( var i = 0; i < ( lines || 3 ); i += 1 ) {
			out.push( h( 'span.skeleton', { style: { height: '16px', width: ( 60 + ( i * 17 ) % 40 ) + '%', marginBottom: '10px' } } ) );
		}
		return h( 'div', { 'aria-hidden': 'true' }, out );
	};

	/** Page title, short description and the main action. */
	ui.pageHead = function ( title, lede, actions ) {
		return h( 'div.est-pagehead', h( 'div', h( 'h1', title ), lede ? h( 'p.lede', lede ) : null ), actions ? h( 'div.row', actions ) : null );
	};

	/**
	 * Table. columns: [{ label, className, render(row) | key, numeric, actions }], rows, o: empty (node), caption.
	 */
	ui.table = function ( columns, rows, o ) {
		o = o || {};
		var head = h( 'tr', columns.map( function ( c ) {
			return h( 'th', { scope: 'col', class: ( c.numeric ? 'n ' : '' ) + ( c.actions ? 'actions' : '' ) + ( c.className ? ' ' + c.className : '' ) }, c.srOnly ? h( 'span.sr-only', c.label ) : c.label );
		} ) );
		var body = h( 'tbody' );
		if ( ! rows.length && o.empty ) {
			body.appendChild( h( 'tr.tbl-empty', h( 'td', { colspan: String( columns.length ) }, o.empty ) ) );
		}
		rows.forEach( function ( row ) {
			var tr = h( 'tr' );
			columns.forEach( function ( c ) {
				var value = c.render ? c.render( row ) : row[ c.key ];
				tr.appendChild( h( 'td', { class: ( c.numeric ? 'n' : '' ) + ( c.actions ? ' actions' : '' ) + ( c.className ? ' ' + c.className : '' ) }, value ) );
			} );
			body.appendChild( tr );
		} );
		return h( 'div.scroll', h( 'table.tbl', o.caption ? h( 'caption.sr-only', o.caption ) : null, h( 'thead', head ), body ) );
	};

	/** Two-line cell: a main line and a quieter one. */
	ui.cell = function ( main, sub ) {
		return [ h( 'span.cell-main', main ), sub ? h( 'span.cell-sub', sub ) : null ];
	};

	/** Message at the top of a screen when loading failed, with a retry button. */
	ui.loadError = function ( err, retry ) {
		return h( 'div.stack', ui.note( 'err', errorMessage( err ), { title: __( 'This screen could not load.', TD ) } ), retry ? h( 'div', ui.btn( __( 'Try again', TD ), { onClick: retry } ) ) : null );
	};

	/* Dialogs and toasts ------------------------------------------------- */

	var appRoot = null;

	/**
	 * Modal dialog. o: title, body (nodes), actions (nodes; the first button gets focus unless o.focus is set),
	 * wide. Returns { el, close(result), result: Promise }.
	 */
	ui.dialog = function ( o ) {
		var dlg = h( 'dialog', { class: 'estm-dialog' + ( o.wide ? ' wide' : '' ), 'aria-labelledby': uid( 'dlg' ) } );
		var titleId = dlg.getAttribute( 'aria-labelledby' );
		var resolve;
		var result = new Promise( function ( r ) { resolve = r; } );
		var closed = false;
		function close( value ) {
			if ( closed ) {
				return;
			}
			closed = true;
			if ( dlg.open ) {
				dlg.close();
			}
			dlg.remove();
			resolve( value );
		}
		append( dlg, [
			h( 'div.dlg-h', h( 'h2', { id: titleId }, o.title ) ),
			h( 'div.dlg-b', o.body ),
			o.actions ? h( 'div.dlg-f', o.actions ) : null,
		] );
		dlg.addEventListener( 'cancel', function ( ev ) { ev.preventDefault(); close( undefined ); } );
		dlg.addEventListener( 'click', function ( ev ) { if ( ev.target === dlg && ! o.sticky ) { close( undefined ); } } );
		( appRoot || document.body ).appendChild( dlg );
		if ( 'function' === typeof dlg.showModal ) {
			dlg.showModal();
		} else {
			dlg.setAttribute( 'open', '' );
		}
		return { el: dlg, close: close, result: result };
	};

	/**
	 * Ask before something that cannot be undone. Resolves true or false.
	 * o: title, message (node or text), confirm (button label), cancel, danger.
	 */
	ui.confirm = function ( o ) {
		var d;
		var cancel = ui.btn( o.cancel || __( 'Cancel', TD ), { kind: 'quiet', onClick: function () { d.close( false ); } } );
		var ok = ui.btn( o.confirm || __( 'Continue', TD ), { kind: o.danger ? 'danger' : 'primary', onClick: function () { d.close( true ); } } );
		d = ui.dialog( { title: o.title, body: [ 'string' === typeof o.message ? h( 'p', o.message ) : o.message ], actions: [ cancel, ok ] } );
		( o.danger ? cancel : ok ).focus();
		return d.result.then( function ( v ) { return true === v; } );
	};

	var toastHost = null;

	/** Short message that fades by itself. kind: '' or 'err'. */
	ui.toast = function ( message, kind ) {
		if ( ! toastHost ) {
			toastHost = h( 'div.estm-toasts', { 'aria-live': 'polite', role: 'status' } );
			( appRoot || document.body ).appendChild( toastHost );
		}
		var t = h( 'div', { class: 'estm-toast' + ( 'err' === kind ? ' err' : '' ) }, message );
		toastHost.appendChild( t );
		setTimeout( function () { t.remove(); }, 'err' === kind ? 8000 : 3500 );
	};

	/** Copy text and say so. */
	ui.copy = function ( text ) {
		function done() { ui.toast( __( 'Copied to the clipboard.', TD ) ); }
		function fallback() {
			var ta = h( 'textarea', { style: 'position:fixed;opacity:0', 'aria-hidden': 'true' } );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {
				ui.toast( __( 'Copying did not work here. Select the text and copy it yourself.', TD ), 'err' );
			}
			ta.remove();
		}
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	};

	/** Input that shows a value to copy (a key, an address). */
	ui.copyField = function ( value, o ) {
		o = o || {};
		var input = ui.input( { value: value, readonly: true, mono: true, ariaLabel: o.label } );
		input.addEventListener( 'focus', function () { input.select(); } );
		return h( 'div.copy', input, ui.btn( __( 'Copy', TD ), { kind: 'quiet', onClick: function () { ui.copy( input.value ); } } ) );
	};

	/** Escape-free highlighting: wrap the first occurrence of `needle` in <mark>. */
	ui.highlight = function ( text, needle ) {
		text = String( text );
		if ( ! needle ) {
			return text;
		}
		var at = text.toLowerCase().indexOf( String( needle ).toLowerCase() );
		if ( at < 0 ) {
			return text;
		}
		return [ text.slice( 0, at ), h( 'mark', text.slice( at, at + needle.length ) ), text.slice( at + needle.length ) ];
	};

	/** Log line as shown in the live log: time, level, message. */
	ui.logLine = function ( line ) {
		return h( 'div', { class: 'lv-' + line.level }, h( 'span.t', time( line.created_gmt ) ), line.message );
	};

	/* ------------------------------------------------------------------ Boot */

	var screens = {};

	/** Register the function that draws a screen. It gets the root element and ESTM. */
	ESTM.screen = function ( id, fn ) {
		screens[ id ] = fn;
	};

	function run() {
		var root = document.getElementById( 'estm-app' );
		if ( ! root ) {
			return;
		}
		appRoot = root.closest( '.estm' ) || root;
		var fn = screens[ ESTM.screenId ];
		if ( ! fn ) {
			replace( root, ui.note( 'err', __( 'This screen could not be started because its script did not load.', TD ) ) );
			root.setAttribute( 'aria-busy', 'false' );
			return;
		}
		replace( root, h( 'div', { style: 'padding:48px 0;text-align:center' }, ui.spinner() ) );
		try {
			fn( root, ESTM );
		} catch ( e ) {
			window.console && window.console.error( e );
			replace( root, ui.note( 'err', e && e.message ? e.message : '', { title: __( 'Something went wrong while drawing this screen.', TD ) } ) );
		}
		root.setAttribute( 'aria-busy', 'false' );
	}

	Object.assign( ESTM, {
		h: h,
		replace: replace,
		uid: uid,
		ui: ui,
		fmt: { number: number, bytes: bytes, duration: duration, date: date, time: time, clock: clock, ago: ago, percent: percent },
		errorMessage: errorMessage,
		isFinished: isFinished,
		isActive: isActive,
		watchJob: watchJob,
		jobLabel: jobLabel,
		jobBadge: jobBadge,
		url: url,
		downloadUrl: downloadUrl,
		params: params,
		draft: draft,
		debounce: debounce,
		toList: toList,
		go: function ( target ) { window.location.assign( target ); },
		i18n: { __: __, _n: _n, sprintf: sprintf },
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		setTimeout( run, 0 );
	}
}() );
