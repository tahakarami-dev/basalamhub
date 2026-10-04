/* SalamHub admin: vanilla JS, no dependencies.
 *
 * Two parts:
 * - Delegated handlers on `document` (bound once) for buttons that may appear on any page.
 * - initPage(root): per-page setup (pollers, counters), re-run after every page swap.
 * Inside the app shell, links to other SalamHub pages load without a full reload; any
 * failure falls back to normal navigation, so the plugin never depends on this script.
 */
( function () {
	'use strict';

	var cfg = window.SalamHub || {};
	var t = cfg.i18n || {};
	var timers = [];

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json().catch( function () {
					return { success: false, data: { message: t.networkError } };
				} );
			} )
			.catch( function () {
				return { success: false, data: { message: t.networkError } };
			} );
	}

	function setMessage( el, text, kind ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.remove( 'is-ok', 'is-error' );
		if ( kind ) {
			el.classList.add( kind === 'ok' ? 'is-ok' : 'is-error' );
		}
	}

	function busy( btn, label ) {
		btn.dataset.label = btn.innerHTML;
		btn.disabled = true;
		btn.textContent = label;
	}

	function idle( btn ) {
		btn.disabled = false;
		if ( btn.dataset.label ) {
			btn.innerHTML = btn.dataset.label;
		}
	}

	/** setTimeout that is cancelled automatically when the page changes. */
	function later( fn, ms ) {
		var id = window.setTimeout( fn, ms );
		timers.push( id );
		return id;
	}

	function clearTimers() {
		timers.forEach( window.clearTimeout );
		timers = [];
	}

	function closest( e, selector ) {
		return e.target && e.target.closest ? e.target.closest( selector ) : null;
	}

	/* ------------------------------------------------------------------
	 * App shell: theme, mobile menu, page router
	 * ---------------------------------------------------------------- */

	var app = document.querySelector( '[data-slh-app]' );
	var reloadContent = function () {
		window.location.reload();
	};

	function store( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	}

	if ( app ) {
		// Move the app to <body> so wp-admin wrappers can't clip or restyle it.
		document.body.appendChild( app );
		document.documentElement.classList.add( 'slh-app-open' );

		var themes = [ 'auto', 'light', 'dark' ];
		var themeLabels = { auto: t.themeAuto, light: t.themeLight, dark: t.themeDark };
		var themeBtn = app.querySelector( '[data-slh-theme]' );
		var showTheme = function () {
			var cur = app.getAttribute( 'data-theme' ) || 'auto';
			if ( themeBtn && themeLabels[ cur ] ) {
				themeBtn.title = themeLabels[ cur ];
				themeBtn.setAttribute( 'aria-label', themeLabels[ cur ] );
			}
		};
		showTheme();
		if ( themeBtn ) {
			themeBtn.addEventListener( 'click', function () {
				var cur = app.getAttribute( 'data-theme' ) || 'auto';
				var next = themes[ ( themes.indexOf( cur ) + 1 ) % themes.length ];
				app.setAttribute( 'data-theme', next );
				store( 'slh-theme', next );
				showTheme();
			} );
		}

		var scrim = app.querySelector( '[data-slh-scrim]' );
		var setMenu = function ( open ) {
			app.classList.toggle( 'is-menu-open', open );
			scrim.hidden = ! open;
		};
		app.querySelector( '[data-slh-menu]' ).addEventListener( 'click', function () {
			setMenu( ! app.classList.contains( 'is-menu-open' ) );
		} );
		scrim.addEventListener( 'click', function () {
			setMenu( false );
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				setMenu( false );
			}
		} );

		var main = app.querySelector( '[data-slh-main]' );
		var loading = app.querySelector( '[data-slh-loading]' );

		var isAppUrl = function ( href ) {
			try {
				var u = new URL( href, window.location.href );
				return u.origin === window.location.origin && /\/wp-admin\/admin\.php$/.test( u.pathname ) && /^salamhub/.test( u.searchParams.get( 'page' ) || '' );
			} catch ( e ) {
				return false;
			}
		};

		var navigate = function ( url, push ) {
			var content = app.querySelector( '[data-slh-content]' );
			clearTimers();
			setMenu( false );
			loading.hidden = false;
			content.classList.add( 'is-loading' );
			return fetch( url, { credentials: 'same-origin', headers: { 'X-SalamHub-Nav': '1' } } )
				.then( function ( r ) {
					if ( ! r.ok || r.redirected && ! isAppUrl( r.url ) ) {
						throw new Error( 'nav' );
					}
					return r.text();
				} )
				.then( function ( html ) {
					var doc = new DOMParser().parseFromString( html, 'text/html' );
					var fresh = doc.querySelector( '[data-slh-content]' );
					var nav = doc.querySelector( '[data-slh-sidebar-nav]' );
					if ( ! fresh ) {
						throw new Error( 'nav' );
					}
					content.replaceWith( fresh );
					if ( nav ) {
						app.querySelector( '[data-slh-sidebar-nav]' ).replaceWith( nav );
					}
					app.querySelector( '[data-slh-crumb]' ).textContent = fresh.getAttribute( 'data-title' );
					document.title = doc.title;
					if ( push ) {
						window.history.pushState( { slh: 1 }, '', url );
					}
					main.scrollTop = 0;
					main.focus( { preventScroll: true } );
					initPage( fresh );
				} )
				.catch( function () {
					window.location.href = url;
				} )
				.then( function () {
					loading.hidden = true;
				} );
		};

		document.addEventListener( 'click', function ( e ) {
			var a = closest( e, 'a[href]' );
			if ( ! a || ! app.contains( a ) || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}
			if ( a.target === '_blank' || a.hasAttribute( 'download' ) || ! isAppUrl( a.href ) ) {
				return;
			}
			e.preventDefault();
			navigate( a.href, true );
		} );

		// GET forms (search, log filters) also stay inside the app.
		document.addEventListener( 'submit', function ( e ) {
			var form = e.target;
			if ( ! app.contains( form ) || ( form.method || 'get' ).toLowerCase() !== 'get' || form.hasAttribute( 'data-slh-bulk-form' ) ) {
				return;
			}
			var url = new URL( form.getAttribute( 'action' ) || window.location.href, window.location.href );
			if ( ! /\/wp-admin\/admin\.php$/.test( url.pathname ) ) {
				return;
			}
			e.preventDefault();
			url.search = new URLSearchParams( new FormData( form ) ).toString();
			navigate( url.toString(), true );
		} );

		reloadContent = function () {
			navigate( window.location.href, false );
		};

		window.addEventListener( 'popstate', function () {
			if ( isAppUrl( window.location.href ) ) {
				navigate( window.location.href, false );
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Delegated actions (bound once)
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var btn;

		// Settings: test connection.
		if ( ( btn = closest( e, '[data-slh-test]' ) ) ) {
			var testOut = document.querySelector( '[data-slh-test-result]' );
			busy( btn, t.testing );
			setMessage( testOut, '' );
			post( 'slh_test_connection' ).then( function ( res ) {
				idle( btn );
				var d = res.data || {};
				setMessage( testOut, [ d.message, d.suggestion ].filter( Boolean ).join( ' ' ), res.success ? 'ok' : 'error' );
			} );
			return;
		}

		// Log: retry one row.
		if ( ( btn = closest( e, '[data-slh-retry]' ) ) ) {
			var msg = document.querySelector( '[data-slh-message]' );
			busy( btn, t.retrying );
			post( 'slh_retry_log', { log_id: btn.getAttribute( 'data-slh-retry' ) } ).then( function ( res ) {
				var d = res.data || {};
				if ( res.success ) {
					btn.replaceWith( Object.assign( document.createElement( 'span' ), { className: 'slh-badge slh-badge--queued', textContent: d.message } ) );
				} else {
					idle( btn );
					setMessage( msg, d.message, 'error' );
				}
			} );
			return;
		}

		// Log: retry all errors.
		if ( ( btn = closest( e, '[data-slh-retry-all]' ) ) ) {
			var allMsg = document.querySelector( '[data-slh-message]' );
			busy( btn, t.retrying );
			post( 'slh_retry_all' ).then( function ( res ) {
				var d = res.data || {};
				setMessage( allMsg, d.message, res.success ? 'ok' : 'error' );
				if ( res.success ) {
					btn.remove();
					document.querySelectorAll( '[data-slh-retry]' ).forEach( function ( b ) {
						b.remove();
					} );
				} else {
					idle( btn );
				}
			} );
			return;
		}

		// Orders: poll now / stock pull now.
		if ( ( btn = closest( e, '[data-slh-orders-poll], [data-slh-stock-pull]' ) ) ) {
			var pollMsg = document.querySelector( '[data-slh-message]' );
			var isPull = btn.hasAttribute( 'data-slh-stock-pull' );
			busy( btn, t.starting );
			post( isPull ? 'slh_stock_pull' : 'slh_orders_poll' ).then( function ( res ) {
				idle( btn );
				setMessage( pollMsg, ( res.data || {} ).message, res.success ? 'ok' : 'error' );
			} );
			return;
		}

		// Orders: copy the webhook URL.
		if ( ( btn = closest( e, '[data-slh-copy]' ) ) ) {
			var src = btn.parentNode.querySelector( '[data-slh-copy-src]' );
			src.select();
			( navigator.clipboard ? navigator.clipboard.writeText( src.value ) : Promise.reject() ).catch( function () {
				document.execCommand( 'copy' );
			} );
			btn.textContent = t.copied;
			return;
		}

		// Order box: confirm / posted on Basalam.
		if ( ( btn = closest( e, '[data-slh-order-action]' ) ) ) {
			var box = btn.closest( '[data-slh-order]' );
			var todo = btn.getAttribute( 'data-slh-order-action' );
			var boxMsg = box.querySelector( '[data-slh-message]' );
			var method = box.querySelector( '[name="slh_shipping_method"]' );
			var code = box.querySelector( '[name="slh_tracking_code"]' );
			if ( todo === 'posted' && ! window.confirm( t.confirmPosted ) ) {
				return;
			}
			busy( btn, t.saving );
			post( 'slh_order_action', {
				order_id: box.getAttribute( 'data-slh-order' ),
				todo: todo,
				shipping_method: method ? method.value : '',
				tracking_code: code ? code.value : ''
			} ).then( function ( res ) {
				var d = res.data || {};
				setMessage( boxMsg, d.message, res.success ? 'ok' : 'error' );
				if ( res.success && d.reload ) {
					window.location.reload();
				} else {
					idle( btn );
				}
			} );
			return;
		}

		// Categories: refresh Basalam list.
		if ( ( btn = closest( e, '[data-slh-cat-refresh]' ) ) ) {
			var catMsg = document.querySelector( '[data-slh-cat-message]' );
			busy( btn, t.refreshing );
			post( 'slh_categories_refresh' ).then( function ( res ) {
				if ( res.success ) {
					setMessage( catMsg, res.data.message, 'ok' );
					window.location.reload();
				} else {
					idle( btn );
					setMessage( catMsg, res.data.message, 'error' );
				}
			} );
			return;
		}

		// Categories: load required attributes for a row.
		if ( ( btn = closest( e, '[data-slh-load-attrs]' ) ) ) {
			var row = btn.closest( 'tr' );
			var holder = row.querySelector( '[data-slh-attrs]' );
			var input = row.querySelector( 'input[name$="[category_id]"]' );
			busy( btn, t.loading );
			post( 'slh_category_attributes', { term_id: row.getAttribute( 'data-term' ), category: input.value } ).then( function ( res ) {
				if ( res.success ) {
					holder.innerHTML = res.data.html;
				} else {
					idle( btn );
					holder.appendChild( Object.assign( document.createElement( 'p' ), { className: 'slh-field__hint is-error', textContent: res.data.message } ) );
				}
			} );
			return;
		}

		// Price rules: add a category row.
		if ( ( btn = closest( e, '[data-slh-rule-add]' ) ) ) {
			var termPick = document.querySelector( '[data-slh-rule-term]' );
			var opt = termPick.options[ termPick.selectedIndex ];
			if ( ! termPick.value || opt.disabled ) {
				termPick.focus();
				return;
			}
			var tpl = document.querySelector( '[data-slh-rule-template]' );
			var tmp = document.createElement( 'tbody' );
			var nameEl = document.createElement( 'span' );
			nameEl.textContent = opt.getAttribute( 'data-name' );
			tmp.innerHTML = tpl.innerHTML.split( '__TERM__' ).join( termPick.value ).replace( '__NAME__', nameEl.innerHTML );
			var newRow = tmp.querySelector( 'tr' );
			document.querySelector( '[data-slh-rules]' ).appendChild( newRow );
			document.querySelector( '[data-slh-rules-wrap]' ).hidden = false;
			opt.disabled = true;
			termPick.value = '';
			var first = newRow.querySelector( 'input' );
			if ( first ) {
				first.focus();
			}
			return;
		}

		// Price rules: remove a category row.
		if ( ( btn = closest( e, '[data-slh-rule-remove]' ) ) ) {
			var ruleRow = btn.closest( 'tr' );
			var pick = document.querySelector( '[data-slh-rule-term]' );
			var o = pick && pick.querySelector( 'option[value="' + ruleRow.getAttribute( 'data-term' ) + '"]' );
			if ( o ) {
				o.disabled = false;
			}
			var body = ruleRow.parentNode;
			ruleRow.remove();
			document.querySelector( '[data-slh-rules-wrap]' ).hidden = ! body.querySelector( 'tr' );
			return;
		}

		// Link page: approve selected / all certain.
		if ( ( btn = closest( e, '[data-slh-link-selected], [data-slh-link-all]' ) ) ) {
			var all = btn.hasAttribute( 'data-slh-link-all' );
			var pairs = {};
			document.querySelectorAll( '.slh-link-table tbody tr' ).forEach( function ( tr ) {
				var c = tr.querySelector( '[data-slh-link-check]' );
				var target = tr.querySelector( '[data-slh-link-target]' );
				if ( c && c.checked && target && parseInt( target.value, 10 ) > 0 ) {
					pairs[ tr.getAttribute( 'data-basalam-id' ) ] = target.value;
				}
			} );
			var out = document.querySelector( '[data-slh-link-result]' );
			if ( ! all && ! Object.keys( pairs ).length ) {
				setMessage( out, t.nothingSelected, 'error' );
				return;
			}
			if ( all && ! window.confirm( t.confirmLinkAll ) ) {
				return;
			}
			var push = document.querySelector( '[data-slh-link-push]' );
			busy( btn, t.linking );
			post( 'slh_link_approve', { pairs: JSON.stringify( all ? {} : pairs ), all_certain: all ? 1 : '', push: push && push.checked ? 1 : '' } ).then( function ( res ) {
				idle( btn );
				setMessage( out, res.data && res.data.message, res.success ? 'ok' : 'error' );
				if ( res.success ) {
					later( reloadContent, 1200 );
				}
			} );
			return;
		}

		// Link page: start fetch + match.
		if ( ( btn = closest( e, '[data-slh-link-start]' ) ) ) {
			var box = document.querySelector( '[data-slh-link-progress]' );
			busy( btn, t.starting );
			post( 'slh_link_start' ).then( function ( res ) {
				box.hidden = false;
				if ( ! res.success ) {
					idle( btn );
					setMessage( box.querySelector( '[data-slh-link-message]' ), res.data.message, 'error' );
					return;
				}
				box.querySelector( '[data-slh-link-html]' ).innerHTML = res.data.html;
				later( linkPoll, 3000 );
			} );
			return;
		}

		// Products page: send one row.
		if ( ( btn = closest( e, '[data-slh-row-send]' ) ) ) {
			var id = btn.getAttribute( 'data-slh-row-send' );
			var cell = btn.closest( 'tr' ).querySelector( '[data-slh-row-status]' );
			busy( btn, t.sending );
			post( 'slh_send_product', { product_id: id } ).then( function ( res ) {
				if ( res.success ) {
					cell.innerHTML = '<span class="slh-badge slh-badge--queued">' + ( t.queuedShort || '' ) + '</span>';
					btn.textContent = t.queuedShort || '';
				} else {
					idle( btn );
					window.alert( ( res.data && res.data.message ) || t.networkError );
				}
			} );
			return;
		}

		// Products page: send selected.
		if ( ( btn = closest( e, '[data-slh-send-selected]' ) ) ) {
			var ids = Array.prototype.map.call( document.querySelectorAll( '[data-slh-check]:checked' ), function ( c ) {
				return c.value;
			} );
			var barMsg = document.querySelector( '[data-slh-bulkbar] [data-slh-message]' );
			if ( ! ids.length ) {
				return;
			}
			busy( btn, t.sending );
			post( 'slh_products_send', { ids: ids.join( ',' ) } ).then( function ( res ) {
				idle( btn );
				setMessage( barMsg, res.data && res.data.message, res.success ? 'ok' : 'error' );
				if ( res.success ) {
					document.querySelectorAll( '[data-slh-check]:checked' ).forEach( function ( c ) {
						c.checked = false;
						var st = c.closest( 'tr' ).querySelector( '[data-slh-row-status]' );
						st.innerHTML = '<span class="slh-badge slh-badge--queued">' + ( t.queuedShort || '' ) + '</span>';
					} );
				}
			} );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var el = e.target;

		// Products page: selection.
		if ( el.matches && ( el.matches( '[data-slh-check]' ) || el.matches( '[data-slh-check-all]' ) ) ) {
			if ( el.matches( '[data-slh-check-all]' ) ) {
				document.querySelectorAll( '[data-slh-check]' ).forEach( function ( c ) {
					c.checked = el.checked;
				} );
			}
			var n = document.querySelectorAll( '[data-slh-check]:checked' ).length;
			var bar = document.querySelector( '[data-slh-bulkbar]' );
			if ( bar ) {
				bar.hidden = ! n;
				bar.querySelector( '[data-slh-selected-count]' ).textContent = ( t.selected || '%s' ).replace( '%s', n.toLocaleString( 'fa-IR' ) );
			}
			return;
		}

		if ( el.matches && el.matches( '[data-slh-link-check-all]' ) ) {
			document.querySelectorAll( '[data-slh-link-check]' ).forEach( function ( c ) {
				c.checked = el.checked;
			} );
			return;
		}

		// Categories: offer the attribute check after choosing a category.
		if ( el.matches && el.matches( '.slh-map-table input[name$="[category_id]"]' ) ) {
			var holder = el.closest( 'tr' ).querySelector( '[data-slh-attrs]' );
			holder.innerHTML = '';
			if ( /\d+\)?\s*$/.test( el.value ) ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'slh-btn slh-btn--ghost';
				b.setAttribute( 'data-slh-load-attrs', '' );
				b.textContent = t.checkAttrs;
				holder.appendChild( b );
			}
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-slh-confirm="disconnect"]' ) && ! window.confirm( t.confirmDisconn ) ) {
			e.preventDefault();
		}
	} );

	/* ------------------------------------------------------------------
	 * Per-page setup (runs on load and after every page swap)
	 * ---------------------------------------------------------------- */

	function initChart( root ) {
		var chart = root.querySelector( '[data-slh-chart]' );
		if ( ! chart ) {
			return;
		}
		var tip = chart.querySelector( '[data-slh-tip]' );
		var show = function ( col ) {
			tip.innerHTML = '';
			var title = document.createElement( 'strong' );
			title.textContent = col.getAttribute( 'data-label' );
			tip.appendChild( title );
			[ [ 'ok', t.chartOk ], [ 'err', t.chartErr ] ].forEach( function ( s ) {
				var line = document.createElement( 'span' );
				var key = document.createElement( 'i' );
				key.style.background = 'var(--chart-' + s[ 0 ] + ')';
				line.appendChild( key );
				line.appendChild( document.createTextNode( s[ 1 ] + ': ' + col.getAttribute( 'data-' + s[ 0 ] ) ) );
				tip.appendChild( line );
			} );
			tip.hidden = false;
			var c = chart.getBoundingClientRect();
			var r = col.getBoundingClientRect();
			var left = r.left - c.left + r.width / 2 - tip.offsetWidth / 2;
			tip.style.left = Math.max( 0, Math.min( left, c.width - tip.offsetWidth ) ) + 'px';
			tip.style.top = '-8px';
		};
		chart.querySelectorAll( '.slh-chart__col' ).forEach( function ( col ) {
			col.addEventListener( 'mouseenter', function () {
				show( col );
			} );
			col.addEventListener( 'focus', function () {
				show( col );
			} );
			col.addEventListener( 'mouseleave', function () {
				tip.hidden = true;
			} );
			col.addEventListener( 'blur', function () {
				tip.hidden = true;
			} );
		} );
	}

	function initBulk( root ) {
		var bulkBox = root.querySelector( '[data-slh-bulk-progress]' );
		if ( ! bulkBox ) {
			return;
		}
		var bulkForm = root.querySelector( '[data-slh-bulk-form]' );
		var bulkHtml = bulkBox.querySelector( '[data-slh-bulk-html]' );
		var bulkActions = bulkBox.querySelector( '[data-slh-bulk-running-actions]' );
		var bulkMsg = bulkBox.querySelector( '[data-slh-bulk-message]' );
		var startBtn = bulkForm ? bulkForm.querySelector( '[data-slh-bulk-start]' ) : null;

		var render = function ( data ) {
			bulkBox.hidden = false;
			bulkHtml.innerHTML = data.html;
			bulkActions.hidden = ! data.running;
			if ( startBtn ) {
				startBtn.disabled = data.running;
			}
		};
		var poll = function () {
			post( 'slh_bulk_status' ).then( function ( res ) {
				if ( ! res.success || ! document.body.contains( bulkBox ) ) {
					return;
				}
				render( res.data );
				if ( res.data.running ) {
					later( poll, 5000 );
				}
			} );
		};
		if ( bulkBox.getAttribute( 'data-running' ) === '1' ) {
			later( poll, 3000 );
		}

		var cancelBtn = bulkBox.querySelector( '[data-slh-bulk-cancel]' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( t.confirmCancel ) ) {
					return;
				}
				clearTimers();
				busy( cancelBtn, t.loading );
				post( 'slh_bulk_cancel' ).then( function ( res ) {
					idle( cancelBtn );
					if ( res.success ) {
						render( res.data );
						setMessage( bulkMsg, res.data.message, 'ok' );
					}
				} );
			} );
		}

		if ( bulkForm ) {
			var termSel = bulkForm.querySelector( '[data-slh-bulk-term]' );
			termSel.addEventListener( 'change', function () {
				post( 'slh_bulk_count', { term_id: termSel.value } ).then( function ( res ) {
					if ( res.success ) {
						bulkForm.querySelector( '[data-slh-count="all"]' ).textContent = res.data.all_fa;
						bulkForm.querySelector( '[data-slh-count="unsent"]' ).textContent = res.data.unsent_fa;
					}
				} );
			} );
			bulkForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var scope = bulkForm.querySelector( 'input[name="scope"]:checked' ).value;
				var count = bulkForm.querySelector( '[data-slh-count="' + scope + '"]' ).textContent;
				if ( ! window.confirm( ( t.confirmBulk || '%s' ).replace( '%s', count ) ) ) {
					return;
				}
				busy( startBtn, t.starting );
				post( 'slh_bulk_start', { scope: scope, term_id: termSel.value } ).then( function ( res ) {
					idle( startBtn );
					if ( ! res.success ) {
						bulkBox.hidden = false;
						setMessage( bulkMsg, res.data.message, 'error' );
						return;
					}
					setMessage( bulkMsg, '' );
					render( res.data );
					clearTimers();
					later( poll, 3000 );
					bulkBox.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} );
			} );
		}
	}

	function initProductBox( root ) {
		var box = root.querySelector( '.slh-product-box' );
		if ( ! box ) {
			return;
		}
		var productId = box.getAttribute( 'data-product-id' );
		var statusEl = box.querySelector( '[data-slh-status]' );
		var msgEl = box.querySelector( '[data-slh-message]' );
		var sendBtn = box.querySelector( '[data-slh-send]' );
		var polls = 0;

		var poll = function () {
			polls++;
			post( 'slh_product_status', { product_id: productId } ).then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				statusEl.innerHTML = res.data.html;
				if ( res.data.status === 'queued' && polls < 60 ) {
					later( poll, 5000 );
				} else {
					setMessage( msgEl, '' );
					if ( sendBtn ) {
						idle( sendBtn );
					}
				}
			} );
		};

		if ( sendBtn ) {
			sendBtn.addEventListener( 'click', function () {
				busy( sendBtn, t.sending );
				post( 'slh_send_product', { product_id: productId } ).then( function ( res ) {
					var d = res.data || {};
					if ( ! res.success ) {
						idle( sendBtn );
						setMessage( msgEl, d.message, 'error' );
						return;
					}
					statusEl.innerHTML = d.html;
					setMessage( msgEl, d.message, 'ok' );
					polls = 0;
					clearTimers();
					later( poll, 4000 );
				} );
			} );
		}
		if ( statusEl && statusEl.querySelector( '.slh-badge--queued' ) ) {
			later( poll, 5000 );
		}
	}

	function linkPoll() {
		var box = document.querySelector( '[data-slh-link-progress]' );
		if ( ! box ) {
			return;
		}
		post( 'slh_link_status' ).then( function ( res ) {
			if ( ! res.success || ! document.body.contains( box ) ) {
				return;
			}
			if ( res.data.running ) {
				box.querySelector( '[data-slh-link-html]' ).innerHTML = res.data.html;
				later( linkPoll, 4000 );
			} else {
				reloadContent();
			}
		} );
	}

	function initPage( root ) {
		var linkBox = root.querySelector( '[data-slh-link-progress]' );
		if ( linkBox && linkBox.getAttribute( 'data-running' ) === '1' ) {
			later( linkPoll, 3000 );
		}
		initChart( root );
		initBulk( root );
		initProductBox( root );
	}

	initPage( document );
} )();
