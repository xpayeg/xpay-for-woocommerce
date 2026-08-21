/**
 * XPay checkout driver — classic checkout.
 *
 * The library in checkout-elements.js knows how to mount XPay's fields and
 * confirm a payment. This file is what connects it to a WooCommerce
 * checkout page: it finds the mount point, asks the server for a session,
 * keeps that session's amount in step with a cart that keeps moving, and
 * takes over the Place Order button.
 *
 * Deliberately separate from the library. The library is exercised by
 * tools/js-tests without a DOM; this file is the part that only makes
 * sense inside a real checkout, and keeping them apart stops DOM details
 * leaking into the tested surface.
 *
 * THE ORDER OF EVENTS THAT MATTERS
 *
 * WooCommerce recalculates totals constantly — shipping, coupons, address
 * changes — and each recalculation replaces the payment box's markup. So:
 *
 *   1. updated_checkout fires. Our mount point is a NEW element; the old
 *      one and everything mounted into it are gone.
 *   2. We re-mount, and ask the server to bring the session's amount in
 *      line with the cart.
 *   3. The server may refuse that: a payment is running. That is not an
 *      error, and the shopper must not be shown one.
 *
 * The card fields live in XPay's iframe, so a re-mount costs the shopper
 * whatever they had typed. We therefore re-mount only when the mount point
 * is genuinely a different element, not on every recalculation.
 *
 * @package XPay_For_WooCommerce
 */
( function ( window, document ) {
	'use strict';

	var params = window.xpayElementsParams;
	if ( ! params || ! window.XPayElements ) {
		return;
	}

	var handle = null;
	var mountedNode = null;
	var selectedMethod = '';
	var paying = false;
	// Whether this mount point has already traded in a spent session for a
	// fresh one. One attempt is a recovery; repeating it on whatever comes
	// back is a loop that hammers the platform for as long as the shopper
	// sits on the page.
	var restarted = false;

	/**
	 * Ask one of our endpoints. Always POST, always nonced.
	 *
	 * @param {string} action Endpoint suffix: session, sync, paying, paid.
	 * @param {Object} body   Extra fields, if any.
	 * @return {Promise<Object>} Resolves with the decoded JSON envelope.
	 */
	function ask( action, body ) {
		var form = new window.FormData();
		form.append( 'action', 'xpay_elements_' + action );
		form.append( 'nonce', params.nonce );
		Object.keys( body || {} ).forEach( function ( key ) {
			form.append( key, body[ key ] );
		} );

		return window
			.fetch( params.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: form,
			} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, status: response.status, json: json };
				} );
			} );
	}

	/** The XPay row's container in the current payment box, if it is there. */
	function container() {
		return document.querySelector( '[data-xpay-elements]' );
	}

	/** Whether the shopper currently has the XPay row selected. */
	function xpaySelected() {
		var input = document.querySelector( 'input[name="payment_method"]:checked' );
		return !! input && input.value === params.gatewayId;
	}

	function showError( message ) {
		var node = document.querySelector( '[data-xpay-elements-error]' );
		if ( ! node ) {
			return;
		}
		node.textContent = message || '';
		node.hidden = ! message;
	}

	/* ── The valU number prompt ──────────────────────────────────────── */

	/**
	 * Show or hide the prompt for the number valU will charge.
	 *
	 * Which method is selected is a fact from inside XPay's own accordion,
	 * never guessed from a row id: the shopper picks it in the iframe and
	 * the SDK tells us.
	 *
	 * @param {string} method The method the shopper just picked.
	 */
	function updatePrompt( method ) {
		var wrap = document.querySelector( '[data-xpay-bnpl-phone]' );
		if ( ! wrap ) {
			return;
		}
		var copy = params.bnplPhone || {};
		var needed = !! ( copy.methods && copy.methods.indexOf( method ) !== -1 );

		if ( ! needed ) {
			wrap.hidden = true;
			return;
		}

		wrap.hidden = false;
		var hint = wrap.querySelector( '[data-xpay-bnpl-hint]' );
		var input = wrap.querySelector( '[data-xpay-bnpl-input]' );
		if ( hint ) {
			hint.textContent = input && input.value ? copy.whyKnown || '' : copy.whyMissing || '';
		}
		if ( input && ! input.value && copy.prefill ) {
			input.value = copy.prefill;
		}
		if ( input && copy.placeholder ) {
			input.setAttribute( 'placeholder', copy.placeholder );
		}
	}

	/** The number the shopper gave for valU, or an empty string. */
	function bnplNumber() {
		var input = document.querySelector( '[data-xpay-bnpl-input]' );
		return input ? String( input.value || '' ).trim() : '';
	}

	/* ── Mounting ────────────────────────────────────────────────────── */

	/**
	 * Mount XPay's fields into the current mount point.
	 *
	 * Re-mounts only when the node has actually been replaced, because a
	 * re-mount throws away whatever the shopper has typed into the card
	 * fields inside XPay's iframe.
	 */
	function mount() {
		var node = container();
		if ( ! node || ! xpaySelected() ) {
			return;
		}
		if ( node === mountedNode && handle ) {
			return;
		}

		if ( handle ) {
			handle.destroy();
			handle = null;
		}
		mountedNode = node;
		restarted = false;

		ask( 'session', {} ).then( function ( result ) {
			if ( ! result.ok || ! result.json || ! result.json.success ) {
				showError( params.i18n && params.i18n.unavailable );
				return;
			}
			attach( result.json.data.clientSecret );
		} );
	}

	/**
	 * Put XPay's fields in the mount point, against one client secret.
	 *
	 * Separate from mount() because a session can be replaced without the
	 * mount point changing: a session that expired under a shopper is traded
	 * in for a fresh one and the fields go back into the same box.
	 *
	 * @param {string} clientSecret The session to mount against.
	 */
	function attach( clientSecret ) {
		if ( handle ) {
			handle.destroy();
			handle = null;
		}

		handle = window.XPayElements.mount( {
			selector: '#xpay-elements-mount',
			clientSecret: clientSecret,
			publishableKey: params.publishableKey,
			sdkUrl: params.sdkUrl,
			colorMode: params.colorMode,
			// The library holds no wording of its own, so every line it
			// may need to show a shopper is handed to it here.
			i18n: params.i18n,
			onMethodChange: function ( method ) {
				selectedMethod = method || '';
				updatePrompt( selectedMethod );
			},
			onReady: function () {
				showError( '' );
			},
			onSessionChange: function () {
				// A session that has been spent announces itself on the same
				// change that carries the totals, and the message it left is
				// the only explanation the shopper has. Clearing it here
				// would replace it with an empty box.
				if ( handle && handle.terminal ) {
					return;
				}
				// The total the fields are quoting has just been re-read
				// from the server, so any complaint about a stale one is
				// now about a state that no longer exists.
				showError( '' );
			},
			onTerminal: recover,
			onError: function ( message ) {
				showError( message || ( params.i18n && params.i18n.unavailable ) );
			},
			onUnavailable: function () {
				showError( params.i18n && params.i18n.unavailable );
			},
		} );
	}

	/**
	 * Trade in a session that can never be paid for one that can.
	 *
	 * Nothing on the server knows this session is spent. The platform serves
	 * an expired session with a 200 exactly as it serves a live one, so the
	 * page is the only thing that ever sees the difference, and until it
	 * says so the same dead secret is handed back on every remount and every
	 * refresh — for as long as the shopper's cart survives, which is twice
	 * as long as a session lives. The advice to reload the page is only true
	 * because of this.
	 *
	 * The server checks the claim before acting on it, and may answer that
	 * there is nothing to replace: a session already paid for is left alone,
	 * because a payable replacement would charge the shopper twice for one
	 * basket. In that case the message the library already showed stands.
	 */
	function recover() {
		if ( restarted ) {
			return;
		}
		restarted = true;

		ask( 'restart', {} ).then( function ( result ) {
			var data = result.ok && result.json && result.json.success ? result.json.data : null;
			if ( ! data || ! data.clientSecret ) {
				return;
			}
			showError( '' );
			attach( data.clientSecret );
		} );
	}

	/* ── Keeping the amount honest ───────────────────────────────────── */

	/**
	 * Tell the server the cart moved, then tell the fields.
	 *
	 * The amount is not sent: the server reads the cart itself. A refusal
	 * because a payment is running is expected and silent.
	 *
	 * The second half is the part that is easy to leave out and wrong to.
	 * Patching the session server-side is invisible to the mounted
	 * element: it holds the copy it was given at load and keeps quoting
	 * that number until something asks it to look again. Only 'updated'
	 * warrants the round trip, since the other outcomes mean the session
	 * was not touched.
	 *
	 * @return {Promise<string>} The server's outcome for the sync.
	 */
	function sync() {
		return ask( 'sync', {} ).then( function ( result ) {
			if ( ! result.ok || ! result.json || ! result.json.success ) {
				return 'failed';
			}
			var outcome = result.json.data.outcome;
			if ( 'updated' === outcome && handle ) {
				handle.refresh();
			}
			return outcome;
		} );
	}

	/* ── Paying ──────────────────────────────────────────────────────── */

	/**
	 * Run the payment in place of WooCommerce's normal submit.
	 *
	 * The server is asked to lock the amount first, and gets the last word:
	 * if the cart has drifted from what the session holds, it refuses, and
	 * the shopper is told rather than charged a number they did not see.
	 */
	function pay() {
		if ( paying || ! handle ) {
			return;
		}

		paying = true;
		showError( '' );

		// Settled before the lock, not after. Taking the payment lock freezes
		// cart syncing, so a shopper who has not finished the fields yet
		// would stop their own total updating by mis-clicking.
		//
		// Asked rather than remembered. The fields report their state when
		// something changes and report nothing at all about the method they
		// select for themselves when they load, so what they last said is
		// not an answer — and the reason they give back is the reason for
		// the state the shopper is actually in, which for a session that has
		// expired under them is not advice to fill in more fields.
		handle.check().then( function ( problem ) {
			if ( problem ) {
				// Refused before the lock was taken, so there is nothing to
				// release and nothing to undo.
				paying = false;
				showError( problem );
				return;
			}
			charge();
		} );
	}

	/**
	 * Lock the amount, take the payment, then hand the order to WooCommerce.
	 */
	function charge() {
		ask( 'paying', {} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.json || ! result.json.success ) {
					var reason = result.json && result.json.data && result.json.data.reason;
					throw new Error(
						'stale-amount' === reason
							? params.i18n && params.i18n.totalChanged
							: params.i18n && params.i18n.unavailable
					);
				}
				return handle.confirm( customerDetails() );
			} )
			.then( function ( outcome ) {
				return ask( 'paid', {} ).then( function () {
					return outcome;
				} );
			} )
			.then( function ( outcome ) {
				paying = false;
				if ( outcome && outcome.ok ) {
					submitOrder();
					return;
				}
				showError( ( outcome && outcome.message ) || ( params.i18n && params.i18n.notCompleted ) );
			} )
			.catch( function ( error ) {
				paying = false;
				ask( 'paid', {} );
				showError( error && error.message ? error.message : params.i18n && params.i18n.notCompleted );
			} );
	}

	/** What XPay is told about the shopper, gathered from the form. */
	function customerDetails() {
		var details = {
			name: value( '#billing_first_name' ) + ' ' + value( '#billing_last_name' ),
			email: value( '#billing_email' ),
			phone: value( '#billing_phone' ),
		};
		// valU charges the number registered with it, which may not be the
		// one on the order, so the prompt's answer wins when it has one.
		var given = bnplNumber();
		if ( given ) {
			details.phone = given;
		}
		details.name = details.name.trim();
		return details;
	}

	/**
	 * @param {string} selector Field selector.
	 * @return {string} Its trimmed value, or an empty string.
	 */
	function value( selector ) {
		var node = document.querySelector( selector );
		return node ? String( node.value || '' ).trim() : '';
	}

	/**
	 * Hand the order to WooCommerce once XPay says the money is arranged.
	 *
	 * The gateway's own process_payment then runs server-side and reads the
	 * session that was just paid, so the order is created from a payment
	 * that already happened rather than the other way round.
	 */
	function submitOrder() {
		var form = document.querySelector( 'form.checkout' );
		if ( ! form ) {
			return;
		}
		var marker = form.querySelector( 'input[name="xpay_paid_session"]' );
		if ( ! marker ) {
			marker = document.createElement( 'input' );
			marker.type = 'hidden';
			marker.name = 'xpay_paid_session';
			form.appendChild( marker );
		}
		marker.value = '1';

		if ( window.jQuery ) {
			window.jQuery( form ).trigger( 'submit' );
		} else {
			form.submit();
		}
	}

	/* ── Wiring ──────────────────────────────────────────────────────── */

	function onUpdatedCheckout() {
		mount();
		sync();
	}

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', onUpdatedCheckout );
		window.jQuery( document.body ).on( 'payment_method_selected', mount );

		// Take over Place Order while XPay is the chosen row. Returning
		// false stops WooCommerce's own submit; the order is placed by
		// submitOrder() once the payment has actually gone through.
		window.jQuery( document ).on( 'checkout_place_order_' + params.gatewayId, function () {
			var form = document.querySelector( 'form.checkout' );
			var marker = form && form.querySelector( 'input[name="xpay_paid_session"]' );
			if ( marker && '1' === marker.value ) {
				return true;
			}
			pay();
			return false;
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', onUpdatedCheckout );
	} else {
		onUpdatedCheckout();
	}
} )( window, document );
