/**
 * XPay settings screen behavior.
 *
 * The page never reloads. Saving posts the SAME settings form to the
 * SAME URL WooCommerce would have received — so validation, webhook
 * creation and every notice run exactly as a normal save — but over
 * fetch: the fresh screen is lifted out of the response and swapped in
 * place, and the notices become a snackbar. The status card's verbs
 * (refresh, reconfigure, disconnect) do the same. Stripe's settings
 * screen behaves this way, and the reload-per-click screen this
 * replaces was the complaint.
 *
 * Every listener is delegated from the document, so a swapped-in screen
 * needs no re-binding.
 *
 * @package XPay_For_WooCommerce
 */
( function ( window, document ) {
	'use strict';

	var params = window.xpayAdminParams;
	if ( ! params || ! document.querySelector( '[data-xpay-admin]' ) ) {
		return;
	}

	function root() {
		return document.querySelector( '[data-xpay-admin]' );
	}

	function modal() {
		return document.querySelector( '[data-xpay-modal]' );
	}

	function text( key ) {
		return ( params.i18n && params.i18n[ key ] ) || '';
	}

	/* ── Snackbar ────────────────────────────────────────────────────── */

	var toastNode = null;
	var toastTimer = null;

	function toast( message ) {
		if ( ! message ) {
			return;
		}
		if ( ! toastNode ) {
			toastNode = document.createElement( 'div' );
			toastNode.className = 'xpay-ad__toast';
			toastNode.setAttribute( 'role', 'status' );
			document.body.appendChild( toastNode );
		}
		toastNode.textContent = message;
		toastNode.classList.add( 'is-visible' );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			toastNode.classList.remove( 'is-visible' );
		}, 4000 );
	}

	/* ── AJAX verbs ──────────────────────────────────────────────────── */

	function ask( verb, body ) {
		var form = new window.FormData();
		form.append( 'action', 'xpay_admin_' + verb );
		form.append( 'nonce', params.nonce );
		Object.keys( body || {} ).forEach( function ( key ) {
			var value = body[ key ];
			// An array posts as PHP's repeated-field shape (key[]), which
			// is how the reorder save carries its list.
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					form.append( key + '[]', item );
				} );
				return;
			}
			form.append( key, value );
		} );
		return window
			.fetch( params.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, json: json };
				} );
			} );
	}

	/* ── The soft reload: swap the screen out of a fetched page ──────── */

	/**
	 * Pull the fresh screen and the admin notices out of a full settings
	 * page and swap them in.
	 *
	 * @param {string} html    The full page HTML.
	 * @param {string} fallbackMessage Toast when the page carried no notice.
	 */
	function swapFrom( html, fallbackMessage ) {
		// A swap replaces the whole screen, reorder mode included; a
		// snapshot of nodes that no longer exist must not survive it.
		reorderSnapshot = null;
		draggedRow = null;

		var parsed = new window.DOMParser().parseFromString( html, 'text/html' );

		// The fresh screen renders with its default tab; the merchant is
		// mid-conversation on a specific one, so it is carried across.
		var activePage = document.querySelector( '[data-xpay-page-tab].is-active' );
		var page = activePage ? activePage.getAttribute( 'data-xpay-page-tab' ) : '';

		var fresh = parsed.querySelector( '[data-xpay-admin]' );
		var current = root();
		if ( fresh && current ) {
			current.replaceWith( fresh );
		}

		if ( page ) {
			document.querySelectorAll( '[data-xpay-page-tab]' ).forEach( function ( other ) {
				var selected = other.getAttribute( 'data-xpay-page-tab' ) === page;
				other.classList.toggle( 'is-active', selected );
				other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			} );
			document.querySelectorAll( '[data-xpay-page]' ).forEach( function ( pane ) {
				pane.hidden = pane.getAttribute( 'data-xpay-page' ) !== page;
			} );
		}

		var notices = [];
		parsed.querySelectorAll( '#wpbody .updated, #wpbody .error, #wpbody .notice' ).forEach( function ( notice ) {
			/*
			 * Hidden boilerplate shares these classes: WordPress prints a
			 * hidden "Connection lost" heartbeat template on every admin
			 * page, and scraping it made every save read as a failure.
			 * Only notices that would actually RENDER are the message.
			 */
			if ( notice.classList.contains( 'hidden' ) || notice.hidden || notice.closest( '.hidden, template' ) ) {
				return;
			}
			notice.querySelectorAll( 'p' ).forEach( function ( p ) {
				var line = ( p.textContent || '' ).trim();
				if ( line ) {
					notices.push( line );
				}
			} );
		} );
		toast( notices.length ? notices.join( ' ' ) : fallbackMessage );
	}

	/** Re-fetch the settings page and swap the screen in place. */
	function softReload( fallbackMessage ) {
		return window
			.fetch( window.location.href, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( html ) {
				swapFrom( html, fallbackMessage );
			} )
			.catch( function () {
				toast( text( 'failed' ) );
			} );
	}

	/* ── Save without leaving ────────────────────────────────────────── */

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.contains || ! form.contains( root() ) ) {
			return;
		}
		event.preventDefault();
		window.onbeforeunload = null;

		var body = new window.FormData( form );
		// The submit button's value never rides FormData; WooCommerce's
		// save handler keys on it.
		body.append( 'save', '1' );

		var buttons = form.querySelectorAll( 'button[type="submit"]' );
		buttons.forEach( function ( b ) {
			b.disabled = true;
		} );

		window
			.fetch( window.location.href, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( html ) {
				swapFrom( html, text( 'saved' ) );
			} )
			.catch( function () {
				toast( text( 'failed' ) );
			} )
			.then( function () {
				buttons.forEach( function ( b ) {
					b.disabled = false;
				} );
			} );
	} );

	/* ── Connect with XPay ───────────────────────────────────────────── */

	/**
	 * Ask the store for an authorize URL and navigate there. The full
	 * navigation is deliberate: OAuth needs a top-level redirect, the one
	 * legitimate page exit in this screen's no-reload world. On failure
	 * the button comes back and the error lands INLINE next to it (a
	 * toast outlives its moment; the merchant is looking at the button).
	 *
	 * @param {HTMLButtonElement} button The clicked connect button.
	 */
	function startConnect( button ) {
		// The error node is a sibling of the actions row, so scope the
		// lookup to the pane (or the card on the get-started screen).
		var scope = button.closest( '[data-xpay-pane], .xpay-ad__card--hero' ) || button.parentElement;
		var errorNode = scope && scope.querySelector( '[data-xpay-connect-error]' );
		var label = button.textContent;
		if ( errorNode ) {
			errorNode.hidden = true;
		}
		button.disabled = true;
		button.textContent = text( 'connecting' );

		function fail( message ) {
			button.disabled = false;
			button.textContent = label;
			if ( errorNode ) {
				errorNode.textContent = message || text( 'failed' );
				errorNode.hidden = false;
			} else {
				toast( message || text( 'failed' ) );
			}
		}

		ask( 'connect', { plane: button.getAttribute( 'data-xpay-plane' ) || 'test' } )
			.then( function ( answer ) {
				var data = ( answer.json && answer.json.data ) || {};
				if ( answer.ok && answer.json && answer.json.success && data.url ) {
					window.location.assign( data.url );
					return;
				}
				fail( data.message );
			} )
			.catch( function () {
				fail( '' );
			} );
	}

	/* ── Delegated clicks ────────────────────────────────────────────── */

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var dialog = modal();

		var connect = target.closest( '[data-xpay-connect]' );
		if ( connect && ! connect.disabled ) {
			startConnect( connect );
		}

		var opener = target.closest( '[data-xpay-open-modal]' );
		if ( opener && dialog ) {
			dialog.hidden = false;
			// An opener can name the pane it is about (the mode-lock notice
			// sends the merchant straight to the plane missing its keys);
			// without one, the dialog keeps whichever tab was active.
			var wantedTab = opener.getAttribute( 'data-xpay-modal-tab' );
			if ( wantedTab ) {
				dialog.querySelectorAll( '[data-xpay-tab]' ).forEach( function ( other ) {
					var selected = other.getAttribute( 'data-xpay-tab' ) === wantedTab;
					other.classList.toggle( 'is-active', selected );
					other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				} );
				dialog.querySelectorAll( '[data-xpay-pane]' ).forEach( function ( pane ) {
					pane.hidden = pane.getAttribute( 'data-xpay-pane' ) !== wantedTab;
				} );
			}
			var activeTab = dialog.querySelector( '.xpay-ad__tab.is-active' ) || dialog.querySelector( '.xpay-ad__tab' );
			if ( activeTab ) {
				activeTab.focus();
			}
		}
		if ( ( target.closest( '[data-xpay-close-modal]' ) || target === dialog ) && dialog ) {
			dialog.hidden = true;
		}

		var pageTab = target.closest( '[data-xpay-page-tab]' );
		if ( pageTab ) {
			var page = pageTab.getAttribute( 'data-xpay-page-tab' );
			document.querySelectorAll( '[data-xpay-page-tab]' ).forEach( function ( other ) {
				other.classList.toggle( 'is-active', other === pageTab );
				other.setAttribute( 'aria-selected', other === pageTab ? 'true' : 'false' );
			} );
			document.querySelectorAll( '[data-xpay-page]' ).forEach( function ( pane ) {
				pane.hidden = pane.getAttribute( 'data-xpay-page' ) !== page;
			} );
		}

		if ( target.closest( '[data-xpay-reorder-start]' ) ) {
			enterReorder();
		}
		if ( target.closest( '[data-xpay-reorder-cancel]' ) ) {
			exitReorder( true );
		}
		if ( target.closest( '[data-xpay-reorder-save]' ) ) {
			saveReorder( target.closest( '[data-xpay-reorder-save]' ) );
		}

		var tab = target.closest( '[data-xpay-tab]' );
		if ( tab ) {
			var mode = tab.getAttribute( 'data-xpay-tab' );
			document.querySelectorAll( '[data-xpay-tab]' ).forEach( function ( other ) {
				other.classList.toggle( 'is-active', other === tab );
				other.setAttribute( 'aria-selected', other === tab ? 'true' : 'false' );
			} );
			document.querySelectorAll( '[data-xpay-pane]' ).forEach( function ( pane ) {
				pane.hidden = pane.getAttribute( 'data-xpay-pane' ) !== mode;
			} );
		}

		var menuToggle = target.closest( '[data-xpay-menu-toggle]' );
		document.querySelectorAll( '.xpay-ad__menu-list' ).forEach( function ( list ) {
			if ( menuToggle && list === menuToggle.parentElement.querySelector( '.xpay-ad__menu-list' ) ) {
				list.hidden = ! list.hidden;
				menuToggle.setAttribute( 'aria-expanded', list.hidden ? 'false' : 'true' );
			} else {
				list.hidden = true;
			}
		} );

		if ( target.closest( '[data-xpay-refresh-health]' ) ) {
			var health = target.closest( '[data-xpay-health]' );
			var message = health && health.querySelector( '[data-xpay-health-message]' );
			if ( message ) {
				message.textContent = text( 'refreshing' );
				ask( 'health', { plane: health.getAttribute( 'data-xpay-plane' ) } ).then( function ( answer ) {
					message.textContent =
						answer.ok && answer.json && answer.json.success
							? answer.json.data.message
							: text( 'failed' );
				} );
			}
		}

		if ( target.closest( '[data-xpay-refresh-account]' ) ) {
			toast( text( 'refreshing' ) );
			ask( 'refresh_account', {} ).then( function ( answer ) {
				if ( answer.ok && answer.json && answer.json.success ) {
					softReload( text( 'accountRefreshed' ) );
				} else {
					toast( text( 'failed' ) );
				}
			} );
		}

		var disconnect = target.closest( '[data-xpay-disconnect]' );
		if ( disconnect && window.confirm( text( 'disconnectConfirm' ) ) ) {
			ask( 'disconnect', { plane: disconnect.getAttribute( 'data-xpay-plane' ) } ).then( function ( answer ) {
				if ( answer.ok && answer.json && answer.json.success ) {
					softReload( text( 'disconnected' ) );
				} else {
					toast( text( 'failed' ) );
				}
			} );
		}

		var reconfigure = target.closest( '[data-xpay-reconfigure]' );
		if ( reconfigure ) {
			var pane = reconfigure.closest( '[data-xpay-pane]' ) || reconfigure.parentElement;
			var result = pane.querySelector( '[data-xpay-reconfigure-result]' );
			reconfigure.disabled = true;
			ask( 'reconfigure_webhooks', { plane: reconfigure.getAttribute( 'data-xpay-plane' ) } ).then( function ( answer ) {
				reconfigure.disabled = false;
				var data = ( answer.json && answer.json.data ) || {};
				if ( result ) {
					result.textContent = data.message || ( answer.ok ? '' : text( 'failed' ) );
				}
			} );
		}
	} );

	/* ── Payment Methods reorder mode ────────────────────────────────── */

	/**
	 * The row order at the moment reorder mode was entered, so Cancel can
	 * put things back. Null whenever the mode is off.
	 */
	var reorderSnapshot = null;

	/** The row being dragged, while a drag is live. */
	var draggedRow = null;

	function methodsCard() {
		return document.querySelector( '[data-xpay-methods]' );
	}

	function methodRows() {
		var card = methodsCard();
		return card ? Array.prototype.slice.call( card.querySelectorAll( '[data-xpay-method-row]' ) ) : [];
	}

	function setReorderChrome( on ) {
		var card = methodsCard();
		if ( ! card ) {
			return;
		}
		card.classList.toggle( 'is-reordering', on );
		// The idle controls (Change display order + the kebab) and the
		// reorder controls (Cancel + Save display order) swap as one:
		// Stripe's header renders one set or the other, never both.
		var idle = card.querySelector( '[data-xpay-methods-idle]' );
		var actions = card.querySelector( '.xpay-ad__reorder-actions' );
		if ( idle ) {
			idle.hidden = on;
		}
		if ( actions ) {
			actions.hidden = ! on;
		}
		methodRows().forEach( function ( row ) {
			if ( on ) {
				row.setAttribute( 'draggable', 'true' );
			} else {
				row.removeAttribute( 'draggable' );
			}
		} );
	}

	function enterReorder() {
		reorderSnapshot = methodRows();
		setReorderChrome( true );
	}

	function exitReorder( restore ) {
		if ( restore && reorderSnapshot ) {
			var card = methodsCard();
			var list = card && card.querySelector( '[data-xpay-method-list]' );
			if ( list ) {
				reorderSnapshot.forEach( function ( row ) {
					list.appendChild( row );
				} );
			}
		}
		reorderSnapshot = null;
		draggedRow = null;
		setReorderChrome( false );
	}

	function saveReorder( button ) {
		var order = methodRows().map( function ( row ) {
			return row.getAttribute( 'data-xpay-type' ) || '';
		} );
		button.disabled = true;
		ask( 'save_method_order', { order: order } ).then( function ( answer ) {
			button.disabled = false;
			if ( answer.ok && answer.json && answer.json.success ) {
				exitReorder( false );
				toast( ( answer.json.data && answer.json.data.message ) || text( 'saved' ) );
			} else {
				toast( ( answer.json && answer.json.data && answer.json.data.message ) || text( 'failed' ) );
			}
		} );
	}

	// HTML5 drag and drop, delegated. dragover decides where the dragged
	// row lands: before the row whose upper half the pointer is over,
	// after it otherwise.
	document.addEventListener( 'dragstart', function ( event ) {
		var row = event.target.closest && event.target.closest( '[data-xpay-method-row]' );
		if ( ! row || null === reorderSnapshot ) {
			return;
		}
		draggedRow = row;
		row.classList.add( 'is-dragging' );
		// Firefox starts no drag without data on the transfer.
		if ( event.dataTransfer ) {
			event.dataTransfer.setData( 'text/plain', row.getAttribute( 'data-xpay-type' ) || '' );
			event.dataTransfer.effectAllowed = 'move';
		}
	} );

	document.addEventListener( 'dragover', function ( event ) {
		if ( ! draggedRow ) {
			return;
		}
		var over = event.target.closest && event.target.closest( '[data-xpay-method-row]' );
		var list = event.target.closest && event.target.closest( '[data-xpay-method-list]' );
		if ( ! list ) {
			return;
		}
		event.preventDefault();
		if ( ! over || over === draggedRow ) {
			return;
		}
		var box = over.getBoundingClientRect();
		var before = event.clientY < box.top + box.height / 2;
		list.insertBefore( draggedRow, before ? over : over.nextSibling );
	} );

	document.addEventListener( 'dragend', function () {
		if ( draggedRow ) {
			draggedRow.classList.remove( 'is-dragging' );
			draggedRow = null;
		}
	} );

	/* ── Delegated changes ───────────────────────────────────────────── */

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;

		var testmode = target.closest( '[data-xpay-testmode]' );
		if ( testmode ) {
			var carrier = document.querySelector( '[data-xpay-mode-carrier]' );
			if ( carrier ) {
				carrier.value = testmode.checked ? 'test' : 'live';
			}
		}

		if ( target.matches( '.xpay-ad__segment input[type="radio"]' ) ) {
			target.closest( '.xpay-ad__segment' ).querySelectorAll( '.xpay-ad__segment-opt' ).forEach( function ( opt ) {
				opt.classList.toggle( 'is-active', opt.contains( target ) );
			} );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		var dialog = modal();
		if ( 'Escape' === event.key && dialog && ! dialog.hidden ) {
			dialog.hidden = true;
		}
	} );

	var initial = modal();
	if ( initial && initial.hasAttribute( 'data-xpay-autopen' ) ) {
		initial.hidden = false;
	}
} )( window, document );
