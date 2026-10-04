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
	// Bulk send page.
	var bulkBox = document.querySelector( '[data-slh-bulk-progress]' );
	var bulkForm = document.querySelector( '[data-slh-bulk-form]' );
	if ( bulkBox ) {
		var bulkHtml = bulkBox.querySelector( '[data-slh-bulk-html]' );
		var bulkActions = bulkBox.querySelector( '[data-slh-bulk-running-actions]' );
		var bulkMsg = bulkBox.querySelector( '[data-slh-bulk-message]' );
		var startBtn = bulkForm ? bulkForm.querySelector( '[data-slh-bulk-start]' ) : null;
		var bulkTimer = null;

		var render = function ( data ) {
			bulkBox.hidden = false;
			bulkHtml.innerHTML = data.html;
			bulkActions.hidden = ! data.running;
			if ( startBtn ) {
				startBtn.disabled = data.running;
			}
		};
		var bulkPoll = function () {
			post( 'slh_bulk_status' ).then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				render( res.data );
				if ( res.data.running ) {
					bulkTimer = window.setTimeout( bulkPoll, 5000 );
				}
			} );
		};
		if ( bulkBox.getAttribute( 'data-running' ) === '1' ) {
			bulkTimer = window.setTimeout( bulkPoll, 3000 );
		}

		var cancelBtn = bulkBox.querySelector( '[data-slh-bulk-cancel]' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( t.confirmCancel ) ) {
					return;
				}
				window.clearTimeout( bulkTimer );
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
					window.clearTimeout( bulkTimer );
					bulkTimer = window.setTimeout( bulkPoll, 3000 );
					bulkBox.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} );
			} );
		}
	}

	// Category mapping: refresh the Basalam list.
	var catRefresh = document.querySelector( '[data-slh-cat-refresh]' );
	if ( catRefresh ) {
		var catMsg = document.querySelector( '[data-slh-cat-message]' );
		catRefresh.addEventListener( 'click', function () {
			busy( catRefresh, t.refreshing );
			post( 'slh_categories_refresh' ).then( function ( res ) {
				if ( res.success ) {
					setMessage( catMsg, res.data.message, 'ok' );
					window.location.reload();
				} else {
					idle( catRefresh );
					setMessage( catMsg, res.data.message, 'error' );
				}
			} );
		} );
	}

	// Category mapping: load required attributes for a row.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-slh-load-attrs]' );
		if ( ! btn ) {
			return;
		}
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
	} );

	// Category mapping: offer the attribute check after choosing a category.
	document.querySelectorAll( '.slh-map-table input[name$="[category_id]"]' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			var holder = input.closest( 'tr' ).querySelector( '[data-slh-attrs]' );
			holder.innerHTML = '';
			if ( /\d+\)?\s*$/.test( input.value ) ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'slh-btn slh-btn--ghost';
				b.setAttribute( 'data-slh-load-attrs', '' );
				b.textContent = t.checkAttrs;
				holder.appendChild( b );
			}
		} );
	} );
	// Price rules: add/remove a category rule row.
	var addRule = document.querySelector( '[data-slh-rule-add]' );
	if ( addRule ) {
		var termPick = document.querySelector( '[data-slh-rule-term]' );
		var rulesBody = document.querySelector( '[data-slh-rules]' );
		var rulesWrap = document.querySelector( '[data-slh-rules-wrap]' );
		var tpl = document.querySelector( '[data-slh-rule-template]' );
		addRule.addEventListener( 'click', function () {
			var opt = termPick.options[ termPick.selectedIndex ];
			if ( ! termPick.value || opt.disabled ) {
				termPick.focus();
				return;
			}
			var div = document.createElement( 'tbody' );
			var name = document.createElement( 'span' );
			name.textContent = opt.getAttribute( 'data-name' );
			div.innerHTML = tpl.innerHTML.split( '__TERM__' ).join( termPick.value ).replace( '__NAME__', name.innerHTML );
			var row = div.querySelector( 'tr' );
			rulesBody.appendChild( row );
			rulesWrap.hidden = false;
			opt.disabled = true;
			termPick.value = '';
			var input = row.querySelector( 'input' );
			if ( input ) {
				input.focus();
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-slh-rule-remove]' );
			if ( ! btn ) {
				return;
			}
			var row = btn.closest( 'tr' );
			var o = termPick.querySelector( 'option[value="' + row.getAttribute( 'data-term' ) + '"]' );
			if ( o ) {
				o.disabled = false;
			}
			row.remove();
			rulesWrap.hidden = ! rulesBody.querySelector( 'tr' );
		} );
	}
} )();
