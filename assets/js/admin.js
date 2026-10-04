/* SalamHub admin: tiny vanilla JS, no dependencies. */
( function () {
	'use strict';

	var cfg = window.SalamHub || {};
	var t = cfg.i18n || {};

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

	// Settings: "test connection".
	var testBtn = document.querySelector( '[data-slh-test]' );
	if ( testBtn ) {
		var testOut = document.querySelector( '[data-slh-test-result]' );
		testBtn.addEventListener( 'click', function () {
			busy( testBtn, t.testing );
			setMessage( testOut, '' );
			post( 'slh_test_connection' ).then( function ( res ) {
				idle( testBtn );
				var d = res.data || {};
				setMessage( testOut, [ d.message, d.suggestion ].filter( Boolean ).join( ' ' ), res.success ? 'ok' : 'error' );
			} );
		} );
	}

	// Settings: confirm before disconnecting.
	document.querySelectorAll( '[data-slh-confirm="disconnect"]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( t.confirmDisconn ) ) {
				e.preventDefault();
			}
		} );
	} );

	// Log: retry one row.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-slh-retry]' );
		if ( ! btn ) {
			return;
		}
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
	} );

	// Log: retry all errors.
	var allBtn = document.querySelector( '[data-slh-retry-all]' );
	if ( allBtn ) {
		allBtn.addEventListener( 'click', function () {
			var msg = document.querySelector( '[data-slh-message]' );
			busy( allBtn, t.retrying );
			post( 'slh_retry_all' ).then( function ( res ) {
				var d = res.data || {};
				setMessage( msg, d.message, res.success ? 'ok' : 'error' );
				if ( res.success ) {
					allBtn.remove();
					document.querySelectorAll( '[data-slh-retry]' ).forEach( function ( b ) {
						b.remove();
					} );
				} else {
					idle( allBtn );
				}
			} );
		} );
	}

	// Product edit box: send + light polling until the queue finishes.
	var box = document.querySelector( '.slh-product-box' );
	if ( box ) {
		var productId = box.getAttribute( 'data-product-id' );
		var statusEl = box.querySelector( '[data-slh-status]' );
		var msgEl = box.querySelector( '[data-slh-message]' );
		var sendBtn = box.querySelector( '[data-slh-send]' );
		var timer = null;
		var polls = 0;

		var poll = function () {
			polls++;
			post( 'slh_product_status', { product_id: productId } ).then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				statusEl.innerHTML = res.data.html;
				if ( res.data.status === 'queued' && polls < 60 ) {
					timer = window.setTimeout( poll, 5000 );
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
					window.clearTimeout( timer );
					timer = window.setTimeout( poll, 4000 );
				} );
			} );
		}

		// Resume polling if the page was opened while the product is in the queue.
		if ( statusEl && statusEl.querySelector( '.slh-badge--queued' ) ) {
			timer = window.setTimeout( poll, 5000 );
		}
	}
} )();
