/* BasalamHub admin: vanilla JS, no dependencies.
 *
 * Two parts:
 * - Delegated handlers on `document` (bound once) for buttons that may appear on any page.
 * - initPage(root): per-page setup (pollers, counters), re-run after every page swap.
 * Inside the app shell, links to other BasalamHub pages load without a full reload; any
 * failure falls back to normal navigation, so the plugin never depends on this script.
 */
( function () {
	'use strict';

	var cfg = window.BasalamHub || {};
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

	var app = document.querySelector( '[data-bsh-app]' );
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
		document.documentElement.classList.add( 'bsh-app-open' );

		var themes = [ 'auto', 'light', 'dark' ];
		var themeLabels = { auto: t.themeAuto, light: t.themeLight, dark: t.themeDark };
		var themeBtn = app.querySelector( '[data-bsh-theme]' );
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
				store( 'bsh-theme', next );
				showTheme();
			} );
		}

		var scrim = app.querySelector( '[data-bsh-scrim]' );
		var setMenu = function ( open ) {
			app.classList.toggle( 'is-menu-open', open );
			scrim.hidden = ! open;
		};
		app.querySelector( '[data-bsh-menu]' ).addEventListener( 'click', function () {
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

		var main = app.querySelector( '[data-bsh-main]' );
		var loading = app.querySelector( '[data-bsh-loading]' );

		var isAppUrl = function ( href ) {
			try {
				var u = new URL( href, window.location.href );
				return u.origin === window.location.origin && /\/wp-admin\/admin\.php$/.test( u.pathname ) && /^basalamhub/.test( u.searchParams.get( 'page' ) || '' );
			} catch ( e ) {
				return false;
			}
		};

		var navigate = function ( url, push ) {
			var content = app.querySelector( '[data-bsh-content]' );
			clearTimers();
			setMenu( false );
			loading.hidden = false;
			content.classList.add( 'is-loading' );
			return fetch( url, { credentials: 'same-origin', headers: { 'X-BasalamHub-Nav': '1' } } )
				.then( function ( r ) {
					if ( ! r.ok || r.redirected && ! isAppUrl( r.url ) ) {
						throw new Error( 'nav' );
					}
					return r.text();
				} )
				.then( function ( html ) {
					var doc = new DOMParser().parseFromString( html, 'text/html' );
					var fresh = doc.querySelector( '[data-bsh-content]' );
					var nav = doc.querySelector( '[data-bsh-sidebar-nav]' );
					if ( ! fresh ) {
						throw new Error( 'nav' );
					}
					content.replaceWith( fresh );
					if ( nav ) {
						app.querySelector( '[data-bsh-sidebar-nav]' ).replaceWith( nav );
					}
					app.querySelector( '[data-bsh-crumb]' ).textContent = fresh.getAttribute( 'data-title' );
					document.title = doc.title;
					if ( push ) {
						window.history.pushState( { bsh: 1 }, '', url );
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
			if ( ! app.contains( form ) || ( form.method || 'get' ).toLowerCase() !== 'get' || form.hasAttribute( 'data-bsh-bulk-form' ) ) {
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
	 * Live: a toast when a Basalam order arrives while the panel is open
	 * ---------------------------------------------------------------- */

	var liveSince = 0;
	var livePrimed = false;
	var liveBusy = false;
	var toasts = null;

	function toastStack() {
		if ( ! toasts ) {
			toasts = document.createElement( 'div' );
			toasts.className = 'bsh-toasts';
			toasts.setAttribute( 'aria-live', 'polite' );
			toasts.setAttribute( 'role', 'status' );
			( app || document.body ).appendChild( toasts );
		}
		return toasts;
	}

	function showToast( item ) {
		var el = document.createElement( 'div' );
		el.className = 'bsh-toast';
		var icon = document.createElement( 'span' );
		icon.className = 'bsh-toast__icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.innerHTML = cfg.bagIcon || '';
		var body = document.createElement( 'div' );
		body.className = 'bsh-toast__body';
		var title = document.createElement( 'strong' );
		title.textContent = ( t.liveTitle || '%s' ).replace( '%s', item.number );
		var meta = document.createElement( 'span' );
		meta.textContent = [ item.total, ( t.liveItems || '%s' ).replace( '%s', item.items ), item.city ].filter( Boolean ).join( ' · ' );
		body.appendChild( title );
		body.appendChild( meta );
		var view = document.createElement( 'a' );
		view.className = 'bsh-toast__link';
		view.href = item.url;
		view.textContent = t.liveView;
		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'bsh-toast__close';
		close.setAttribute( 'aria-label', t.close );
		close.textContent = '×';
		var remove = function () {
			el.classList.add( 'is-leaving' );
			window.setTimeout( function () {
				el.remove();
			}, 250 );
		};
		close.addEventListener( 'click', remove );
		el.appendChild( icon );
		el.appendChild( body );
		el.appendChild( view );
		el.appendChild( close );
		toastStack().appendChild( el );
		window.setTimeout( remove, 9000 );
	}

	function livePoll() {
		if ( liveBusy || document.hidden ) {
			return;
		}
		liveBusy = true;
		post( 'bsh_live', livePrimed ? { since: liveSince, primed: 1 } : {} ).then( function ( res ) {
			liveBusy = false;
			if ( ! res.success ) {
				return;
			}
			var d = res.data || {};
			var fresh = d.items && d.items.length;
			( d.items || [] ).forEach( showToast );
			liveSince = Math.max( liveSince, d.latest || 0 );
			livePrimed = true;
			if ( fresh && app ) {
				var content = app.querySelector( '[data-bsh-content]' );
				var page = content ? content.getAttribute( 'data-page' ) : '';
				if ( [ 'basalamhub', 'basalamhub-sales', 'basalamhub-orders' ].indexOf( page ) !== -1 ) {
					reloadContent(); // Fresh numbers behind the toast.
				}
			}
		} );
	}

	if ( app && t.live ) {
		livePoll(); // Sets the starting point; old orders never pop up.
		window.setInterval( livePoll, 15000 );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				livePoll();
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Delegated actions (bound once)
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var btn;

		// Settings: test connection.
		if ( ( btn = closest( e, '[data-bsh-test]' ) ) ) {
			var testOut = document.querySelector( '[data-bsh-test-result]' );
			busy( btn, t.testing );
			setMessage( testOut, '' );
			post( 'bsh_test_connection' ).then( function ( res ) {
				idle( btn );
				var d = res.data || {};
				setMessage( testOut, [ d.message, d.suggestion ].filter( Boolean ).join( ' ' ), res.success ? 'ok' : 'error' );
			} );
			return;
		}

		// Log: retry one row.
		if ( ( btn = closest( e, '[data-bsh-retry]' ) ) ) {
			var msg = document.querySelector( '[data-bsh-message]' );
			busy( btn, t.retrying );
			post( 'bsh_retry_log', { log_id: btn.getAttribute( 'data-bsh-retry' ) } ).then( function ( res ) {
				var d = res.data || {};
				if ( res.success ) {
					btn.replaceWith( Object.assign( document.createElement( 'span' ), { className: 'bsh-badge bsh-badge--queued', textContent: d.message } ) );
				} else {
					idle( btn );
					setMessage( msg, d.message, 'error' );
				}
			} );
			return;
		}

		// Log: retry all errors.
		if ( ( btn = closest( e, '[data-bsh-retry-all]' ) ) ) {
			var allMsg = document.querySelector( '[data-bsh-message]' );
			busy( btn, t.retrying );
			post( 'bsh_retry_all' ).then( function ( res ) {
				var d = res.data || {};
				setMessage( allMsg, d.message, res.success ? 'ok' : 'error' );
				if ( res.success ) {
					btn.remove();
					document.querySelectorAll( '[data-bsh-retry]' ).forEach( function ( b ) {
						b.remove();
					} );
				} else {
					idle( btn );
				}
			} );
			return;
		}

		// Import: start / stop.
		if ( ( btn = closest( e, '[data-bsh-import-start]' ) ) ) {
			var upd = document.querySelector( '[data-bsh-import-update]' );
			var pub = document.querySelector( '[data-bsh-import-publish]' );
			var count = parseInt( btn.getAttribute( 'data-new' ), 10 ) + ( upd && upd.checked ? parseInt( btn.getAttribute( 'data-linked' ), 10 ) : 0 );
			if ( ! window.confirm( ( t.confirmImport || '' ).replace( '%s', count ) ) ) {
				return;
			}
			busy( btn, t.starting );
			post( 'bsh_import_start', { update_linked: upd && upd.checked ? 1 : '', publish: pub ? pub.value : 'publish' } ).then( function ( res ) {
				if ( res.success ) {
					reloadContent();
				} else {
					idle( btn );
					setMessage( document.querySelector( '[data-bsh-import-message]' ), ( res.data || {} ).message, 'error' );
				}
			} );
			return;
		}
		if ( ( btn = closest( e, '[data-bsh-import-cancel]' ) ) ) {
			if ( ! window.confirm( t.confirmStopImport ) ) {
				return;
			}
			busy( btn, t.loading );
			post( 'bsh_import_cancel' ).then( function () {
				reloadContent();
			} );
			return;
		}

		// Notifications: find chat id / send a test message.
		if ( ( btn = closest( e, '[data-bsh-notify-find], [data-bsh-notify-test]' ) ) ) {
			var card = btn.closest( '[data-bsh-channel]' );
			var out = card.querySelector( '[data-bsh-channel-message]' );
			var isTest = btn.hasAttribute( 'data-bsh-notify-test' );
			busy( btn, isTest ? t.sendingTest : t.searching );
			post( isTest ? 'bsh_notify_test' : 'bsh_notify_find_chat', { channel: card.getAttribute( 'data-bsh-channel' ) } ).then( function ( res ) {
				idle( btn );
				var d = res.data || {};
				setMessage( out, d.message, res.success ? 'ok' : 'error' );
				if ( res.success && d.id ) {
					card.querySelector( '[data-bsh-chat-id]' ).value = d.id;
					var test = card.querySelector( '[data-bsh-notify-test]' );
					if ( test ) {
						test.disabled = false;
					}
				}
			} );
			return;
		}

		// Demo mode: fill, clear, simulate a Basalam order.
		if ( ( btn = closest( e, '[data-bsh-demo]' ) ) ) {
			var mode = btn.getAttribute( 'data-bsh-demo' );
			if ( 'clear' === mode && ! window.confirm( t.confirmDemoClear ) ) {
				return;
			}
			busy( btn, 'order' === mode ? t.loading : ( 'clear' === mode ? t.demoClearing : t.demoFilling ) );
			post( 'bsh_demo_' + mode ).then( function ( res ) {
				var d = res.data || {};
				if ( ! res.success ) {
					idle( btn );
					window.alert( d.message || t.networkError );
					return;
				}
				if ( 'order' === mode ) {
					idle( btn );
					livePoll(); // Show the toast right away instead of on the next tick.
					return;
				}
				window.location.reload(); // The banner and the menu change too.
			} );
			return;
		}

		// Notifications: weekly report now.
		if ( ( btn = closest( e, '[data-bsh-report-now]' ) ) ) {
			busy( btn, t.loading );
			post( 'bsh_report_now' ).then( function ( res ) {
				idle( btn );
				var d = res.data || {};
				var pre = document.querySelector( '[data-bsh-report-text]' );
				if ( res.success && pre ) {
					pre.textContent = d.text;
					pre.hidden = false;
				}
				setMessage( document.querySelector( '[data-bsh-report-message]' ), d.message, res.success ? 'ok' : 'error' );
			} );
			return;
		}

		// Orders: nightly reconciliation now.
		if ( ( btn = closest( e, '[data-bsh-reconcile]' ) ) ) {
			busy( btn, t.starting );
			post( 'bsh_reconcile_now' ).then( function ( res ) {
				idle( btn );
				setMessage( document.querySelector( '[data-bsh-message]' ), ( res.data || {} ).message, res.success ? 'ok' : 'error' );
			} );
			return;
		}

		// Orders: poll now / stock pull now.
		if ( ( btn = closest( e, '[data-bsh-orders-poll], [data-bsh-stock-pull]' ) ) ) {
			var pollMsg = document.querySelector( '[data-bsh-message]' );
			var isPull = btn.hasAttribute( 'data-bsh-stock-pull' );
			busy( btn, t.starting );
			post( isPull ? 'bsh_stock_pull' : 'bsh_orders_poll' ).then( function ( res ) {
				idle( btn );
				setMessage( pollMsg, ( res.data || {} ).message, res.success ? 'ok' : 'error' );
			} );
			return;
		}

		// Orders: copy the webhook URL.
		if ( ( btn = closest( e, '[data-bsh-copy]' ) ) ) {
			var src = btn.parentNode.querySelector( '[data-bsh-copy-src]' );
			src.select();
			( navigator.clipboard ? navigator.clipboard.writeText( src.value ) : Promise.reject() ).catch( function () {
				document.execCommand( 'copy' );
			} );
			btn.textContent = t.copied;
			return;
		}

		// Order box: confirm / posted on Basalam.
		if ( ( btn = closest( e, '[data-bsh-order-action]' ) ) ) {
			var box = btn.closest( '[data-bsh-order]' );
			var todo = btn.getAttribute( 'data-bsh-order-action' );
			var boxMsg = box.querySelector( '[data-bsh-message]' );
			var method = box.querySelector( '[name="bsh_shipping_method"]' );
			var code = box.querySelector( '[name="bsh_tracking_code"]' );
			if ( todo === 'posted' && ! window.confirm( t.confirmPosted ) ) {
				return;
			}
			busy( btn, t.saving );
			post( 'bsh_order_action', {
				order_id: box.getAttribute( 'data-bsh-order' ),
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
		if ( ( btn = closest( e, '[data-bsh-cat-refresh]' ) ) ) {
			var catMsg = document.querySelector( '[data-bsh-cat-message]' );
			busy( btn, t.refreshing );
			post( 'bsh_categories_refresh' ).then( function ( res ) {
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
		if ( ( btn = closest( e, '[data-bsh-load-attrs]' ) ) ) {
			var row = btn.closest( 'tr' );
			var holder = row.querySelector( '[data-bsh-attrs]' );
			var input = row.querySelector( 'input[name$="[category_id]"]' );
			busy( btn, t.loading );
			post( 'bsh_category_attributes', { term_id: row.getAttribute( 'data-term' ), category: input.value } ).then( function ( res ) {
				if ( res.success ) {
					holder.innerHTML = res.data.html;
				} else {
					idle( btn );
					holder.appendChild( Object.assign( document.createElement( 'p' ), { className: 'bsh-field__hint is-error', textContent: res.data.message } ) );
				}
			} );
			return;
		}

		// Price rules: add a category row.
		if ( ( btn = closest( e, '[data-bsh-rule-add]' ) ) ) {
			var termPick = document.querySelector( '[data-bsh-rule-term]' );
			var opt = termPick.options[ termPick.selectedIndex ];
			if ( ! termPick.value || opt.disabled ) {
				termPick.focus();
				return;
			}
			var tpl = document.querySelector( '[data-bsh-rule-template]' );
			var tmp = document.createElement( 'tbody' );
			var nameEl = document.createElement( 'span' );
			nameEl.textContent = opt.getAttribute( 'data-name' );
			tmp.innerHTML = tpl.innerHTML.split( '__TERM__' ).join( termPick.value ).replace( '__NAME__', nameEl.innerHTML );
			var newRow = tmp.querySelector( 'tr' );
			document.querySelector( '[data-bsh-rules]' ).appendChild( newRow );
			document.querySelector( '[data-bsh-rules-wrap]' ).hidden = false;
			opt.disabled = true;
			termPick.value = '';
			var first = newRow.querySelector( 'input' );
			if ( first ) {
				first.focus();
			}
			return;
		}

		// Price rules: remove a category row.
		if ( ( btn = closest( e, '[data-bsh-rule-remove]' ) ) ) {
			var ruleRow = btn.closest( 'tr' );
			var pick = document.querySelector( '[data-bsh-rule-term]' );
			var o = pick && pick.querySelector( 'option[value="' + ruleRow.getAttribute( 'data-term' ) + '"]' );
			if ( o ) {
				o.disabled = false;
			}
			var body = ruleRow.parentNode;
			ruleRow.remove();
			document.querySelector( '[data-bsh-rules-wrap]' ).hidden = ! body.querySelector( 'tr' );
			return;
		}

		// Link page: approve selected / all certain.
		if ( ( btn = closest( e, '[data-bsh-link-selected], [data-bsh-link-all]' ) ) ) {
			var all = btn.hasAttribute( 'data-bsh-link-all' );
			var pairs = {};
			document.querySelectorAll( '.bsh-link-table tbody tr' ).forEach( function ( tr ) {
				var c = tr.querySelector( '[data-bsh-link-check]' );
				var target = tr.querySelector( '[data-bsh-link-target]' );
				if ( c && c.checked && target && parseInt( target.value, 10 ) > 0 ) {
					pairs[ tr.getAttribute( 'data-basalam-id' ) ] = target.value;
				}
			} );
			var out = document.querySelector( '[data-bsh-link-result]' );
			if ( ! all && ! Object.keys( pairs ).length ) {
				setMessage( out, t.nothingSelected, 'error' );
				return;
			}
			if ( all && ! window.confirm( t.confirmLinkAll ) ) {
				return;
			}
			var push = document.querySelector( '[data-bsh-link-push]' );
			busy( btn, t.linking );
			post( 'bsh_link_approve', { pairs: JSON.stringify( all ? {} : pairs ), all_certain: all ? 1 : '', push: push && push.checked ? 1 : '' } ).then( function ( res ) {
				idle( btn );
				setMessage( out, res.data && res.data.message, res.success ? 'ok' : 'error' );
				if ( res.success ) {
					later( reloadContent, 1200 );
				}
			} );
			return;
		}

		// Link page: start fetch + match.
		if ( ( btn = closest( e, '[data-bsh-link-start]' ) ) ) {
			var box = document.querySelector( '[data-bsh-link-progress]' );
			busy( btn, t.starting );
			post( 'bsh_link_start' ).then( function ( res ) {
				box.hidden = false;
				if ( ! res.success ) {
					idle( btn );
					setMessage( box.querySelector( '[data-bsh-link-message]' ), res.data.message, 'error' );
					return;
				}
				box.querySelector( '[data-bsh-link-html]' ).innerHTML = res.data.html;
				later( linkPoll, 3000 );
			} );
			return;
		}

		// Products page: send one row.
		if ( ( btn = closest( e, '[data-bsh-row-send]' ) ) ) {
			var id = btn.getAttribute( 'data-bsh-row-send' );
			var cell = btn.closest( 'tr' ).querySelector( '[data-bsh-row-status]' );
			busy( btn, t.sending );
			post( 'bsh_send_product', { product_id: id } ).then( function ( res ) {
				if ( res.success ) {
					cell.innerHTML = '<span class="bsh-badge bsh-badge--queued">' + ( t.queuedShort || '' ) + '</span>';
					btn.textContent = t.queuedShort || '';
				} else {
					idle( btn );
					window.alert( ( res.data && res.data.message ) || t.networkError );
				}
			} );
			return;
		}

		// Products page: send selected.
		if ( ( btn = closest( e, '[data-bsh-send-selected]' ) ) ) {
			var ids = Array.prototype.map.call( document.querySelectorAll( '[data-bsh-check]:checked' ), function ( c ) {
				return c.value;
			} );
			var barMsg = document.querySelector( '[data-bsh-bulkbar] [data-bsh-message]' );
			if ( ! ids.length ) {
				return;
			}
			busy( btn, t.sending );
			post( 'bsh_products_send', { ids: ids.join( ',' ) } ).then( function ( res ) {
				idle( btn );
				setMessage( barMsg, res.data && res.data.message, res.success ? 'ok' : 'error' );
				if ( res.success ) {
					document.querySelectorAll( '[data-bsh-check]:checked' ).forEach( function ( c ) {
						c.checked = false;
						var st = c.closest( 'tr' ).querySelector( '[data-bsh-row-status]' );
						st.innerHTML = '<span class="bsh-badge bsh-badge--queued">' + ( t.queuedShort || '' ) + '</span>';
					} );
				}
			} );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var el = e.target;

		// Products page: selection.
		if ( el.matches && ( el.matches( '[data-bsh-check]' ) || el.matches( '[data-bsh-check-all]' ) ) ) {
			if ( el.matches( '[data-bsh-check-all]' ) ) {
				document.querySelectorAll( '[data-bsh-check]' ).forEach( function ( c ) {
					c.checked = el.checked;
				} );
			}
			var n = document.querySelectorAll( '[data-bsh-check]:checked' ).length;
			var bar = document.querySelector( '[data-bsh-bulkbar]' );
			if ( bar ) {
				bar.hidden = ! n;
				bar.querySelector( '[data-bsh-selected-count]' ).textContent = ( t.selected || '%s' ).replace( '%s', n.toLocaleString( 'fa-IR' ) );
			}
			return;
		}

		if ( el.matches && el.matches( '[data-bsh-link-check-all]' ) ) {
			document.querySelectorAll( '[data-bsh-link-check]' ).forEach( function ( c ) {
				c.checked = el.checked;
			} );
			return;
		}

		// Categories: offer the attribute check after choosing a category.
		if ( el.matches && el.matches( '.bsh-map-table input[name$="[category_id]"]' ) ) {
			var holder = el.closest( 'tr' ).querySelector( '[data-bsh-attrs]' );
			holder.innerHTML = '';
			if ( /\d+\)?\s*$/.test( el.value ) ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'bsh-btn bsh-btn--ghost';
				b.setAttribute( 'data-bsh-load-attrs', '' );
				b.textContent = t.checkAttrs;
				holder.appendChild( b );
			}
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-bsh-confirm="disconnect"]' ) && ! window.confirm( t.confirmDisconn ) ) {
			e.preventDefault();
		}
	} );

	/* ------------------------------------------------------------------
	 * Per-page setup (runs on load and after every page swap)
	 * ---------------------------------------------------------------- */

	function initChart( root ) {
		var chart = root.querySelector( '[data-bsh-chart]' );
		if ( ! chart ) {
			return;
		}
		var tip = chart.querySelector( '[data-bsh-tip]' );
		var show = function ( col ) {
			tip.innerHTML = '';
			var title = document.createElement( 'strong' );
			title.textContent = col.getAttribute( 'data-label' );
			tip.appendChild( title );
			var series = [ [ 'ok', t.chartOk ], [ 'err', t.chartErr ] ];
			try {
				series = JSON.parse( chart.getAttribute( 'data-series' ) ) || series;
			} catch ( e ) {}
			series.forEach( function ( s ) {
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
		chart.querySelectorAll( '.bsh-chart__col' ).forEach( function ( col ) {
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
		var bulkBox = root.querySelector( '[data-bsh-bulk-progress]' );
		if ( ! bulkBox ) {
			return;
		}
		var bulkForm = root.querySelector( '[data-bsh-bulk-form]' );
		var bulkHtml = bulkBox.querySelector( '[data-bsh-bulk-html]' );
		var bulkActions = bulkBox.querySelector( '[data-bsh-bulk-running-actions]' );
		var bulkMsg = bulkBox.querySelector( '[data-bsh-bulk-message]' );
		var startBtn = bulkForm ? bulkForm.querySelector( '[data-bsh-bulk-start]' ) : null;

		var render = function ( data ) {
			bulkBox.hidden = false;
			bulkHtml.innerHTML = data.html;
			bulkActions.hidden = ! data.running;
			if ( startBtn ) {
				startBtn.disabled = data.running;
			}
		};
		var poll = function () {
			post( 'bsh_bulk_status' ).then( function ( res ) {
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

		var cancelBtn = bulkBox.querySelector( '[data-bsh-bulk-cancel]' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( t.confirmCancel ) ) {
					return;
				}
				clearTimers();
				busy( cancelBtn, t.loading );
				post( 'bsh_bulk_cancel' ).then( function ( res ) {
					idle( cancelBtn );
					if ( res.success ) {
						render( res.data );
						setMessage( bulkMsg, res.data.message, 'ok' );
					}
				} );
			} );
		}

		if ( bulkForm ) {
			var termSel = bulkForm.querySelector( '[data-bsh-bulk-term]' );
			termSel.addEventListener( 'change', function () {
				post( 'bsh_bulk_count', { term_id: termSel.value } ).then( function ( res ) {
					if ( res.success ) {
						bulkForm.querySelector( '[data-bsh-count="all"]' ).textContent = res.data.all_fa;
						bulkForm.querySelector( '[data-bsh-count="unsent"]' ).textContent = res.data.unsent_fa;
					}
				} );
			} );
			bulkForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var scope = bulkForm.querySelector( 'input[name="scope"]:checked' ).value;
				var count = bulkForm.querySelector( '[data-bsh-count="' + scope + '"]' ).textContent;
				if ( ! window.confirm( ( t.confirmBulk || '%s' ).replace( '%s', count ) ) ) {
					return;
				}
				busy( startBtn, t.starting );
				post( 'bsh_bulk_start', { scope: scope, term_id: termSel.value } ).then( function ( res ) {
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
		var box = root.querySelector( '.bsh-product-box' );
		if ( ! box ) {
			return;
		}
		var productId = box.getAttribute( 'data-product-id' );
		var statusEl = box.querySelector( '[data-bsh-status]' );
		var msgEl = box.querySelector( '[data-bsh-message]' );
		var sendBtn = box.querySelector( '[data-bsh-send]' );
		var polls = 0;

		var poll = function () {
			polls++;
			post( 'bsh_product_status', { product_id: productId } ).then( function ( res ) {
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
				post( 'bsh_send_product', { product_id: productId } ).then( function ( res ) {
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
		if ( statusEl && statusEl.querySelector( '.bsh-badge--queued' ) ) {
			later( poll, 5000 );
		}
	}

	function linkPoll() {
		var box = document.querySelector( '[data-bsh-link-progress]' );
		if ( ! box ) {
			return;
		}
		post( 'bsh_link_status' ).then( function ( res ) {
			if ( ! res.success || ! document.body.contains( box ) ) {
				return;
			}
			if ( res.data.running ) {
				box.querySelector( '[data-bsh-link-html]' ).innerHTML = res.data.html;
				later( linkPoll, 4000 );
			} else {
				reloadContent();
			}
		} );
	}

	function importPoll() {
		var box = document.querySelector( '[data-bsh-import-progress]' );
		if ( ! box ) {
			return;
		}
		post( 'bsh_import_status' ).then( function ( res ) {
			if ( ! res.success || ! document.body.contains( box ) ) {
				return;
			}
			box.querySelector( '[data-bsh-import-html]' ).innerHTML = res.data.html;
			if ( res.data.running ) {
				later( importPoll, 4000 );
			} else {
				reloadContent();
			}
		} );
	}

	function initPage( root ) {
		var linkBox = root.querySelector( '[data-bsh-link-progress]' );
		if ( linkBox && linkBox.getAttribute( 'data-running' ) === '1' ) {
			later( linkPoll, 3000 );
		}
		var importBox = root.querySelector( '[data-bsh-import-progress]' );
		if ( importBox && importBox.getAttribute( 'data-running' ) === '1' ) {
			later( importPoll, 3000 );
		}
		initChart( root );
		initBulk( root );
		initProductBox( root );
	}

	initPage( document );
} )();
