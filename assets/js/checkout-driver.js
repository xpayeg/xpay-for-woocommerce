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
 *   1. updated_checkout fires. Our mount point may be a NEW element; if it
 *      is, the old one and everything mounted into it are gone.
 *   2. We re-mount only in that case, and otherwise just move the amount
 *      the element displays. Moving it costs NO API call: there is no
 *      session yet, so it is one message to the iframe.
 *
 * The card fields live in XPay's iframe, so a re-mount costs the shopper
 * whatever they had typed. We therefore re-mount only when the mount point
 * is genuinely a different element, not on every recalculation.
 *
 * THE SESSION IS CREATED AT PAY, NOT AT MOUNT
 *
 * Nothing exists on the platform while the shopper fills the form. Place
 * Order creates the ORDER first, the server hands back a session's
 * clientSecret with it, and the browser confirms against that. One session
 * per checkout: a retry on an unchanged cart resumes the same order and so
 * reuses the same secret, and the whole transaction stays on one Payment
 * Intent.
 *
 * @package XPay_For_WooCommerce
 */
( function ( window, document ) {
	'use strict';

	var params = window.xpayElementsParams;

	if ( ! params || ! window.XPayElements ) {
		return;
	}

	/**
	 * One live element per method row, keyed by the row's method type.
	 * Kept, not destroyed, when the shopper switches rows: the fields live
	 * in an iframe, and destroying them costs whatever was typed. An entry
	 * dies only when its own container was replaced by a redraw. Stripe's
	 * classic checkout keeps per-method components the same way.
	 *
	 * @type {Object<string, {node: Object, handle: Object}>}
	 */
	var rows = {};
	/** The SELECTED row's element handle, which is what pay() confirms on. */
	var handle = null;
	var paying = false;

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

	/** The selected payment method's gateway id, '' when none is picked. */
	function selectedId() {
		var input = document.querySelector( 'input[name="payment_method"]:checked' );
		return input ? String( input.value || '' ) : '';
	}

	/** Whether the selected row is one of ours (xpay, xpay_valu, ...). */
	function xpaySelected() {
		var id = selectedId();
		return id === params.gatewayId || 0 === id.indexOf( params.gatewayId + '_' );
	}

	/**
	 * The SELECTED row's container. Each method row renders one, inside
	 * core's .payment_method_<id> wrapper (the same class Stripe's plugin
	 * keys on). The bare-selector fallback covers the single-row fallback
	 * mode and the order-review markup themes strip the wrapper from.
	 */
	function container() {
		var id = selectedId();
		var scoped = id ? document.querySelector( '.payment_method_' + id + ' [data-xpay-elements]' ) : null;
		return scoped || document.querySelector( '[data-xpay-elements]' );
	}

	function showError( message ) {
		// The selected row's own notice, so a ValU refusal never renders
		// under the card fields. Falls back to the first notice on markup
		// without row wrappers.
		var row = container();
		var node = ( row && row.querySelector && row.querySelector( '[data-xpay-elements-error]' ) )
			|| document.querySelector( '[data-xpay-elements-error]' );
		if ( ! node ) {
			return;
		}
		node.textContent = message || '';
		node.hidden = ! message;
	}

	/* ── Mounting ────────────────────────────────────────────────────── */

	/**
	 * Mount XPay's fields into the current mount point.
	 *
	 * Re-mounts only when the node has actually been replaced, because a
	 * re-mount throws away whatever the shopper has typed into the card
	 * fields inside XPay's iframe.
	 *
	 * @return {boolean} True when a mount was performed.
	 */
	function mount() {
		var node = container();
		if ( ! node || ! xpaySelected() ) {
			return false;
		}

		var method = ( node.getAttribute && node.getAttribute( 'data-xpay-method' ) ) || '';
		var entry = rows[ method ];

		if ( entry && entry.node === node ) {
			// Same container, same element: switching back to a row keeps
			// what the shopper typed into it.
			handle = entry.handle;
			return false;
		}

		// The row's container was replaced (a redraw), or this row was
		// never mounted. Only THIS row's old element dies with its node.
		if ( entry ) {
			entry.handle.destroy();
		}
		handle = attach( node, method );
		rows[ method ] = { node: node, handle: handle };
		return true;
	}

	/**
	 * Put XPay's fields in the mount point, displaying the cart's total.
	 *
	 * No network call and no session: the element is created from an amount
	 * and a currency, which is the whole of deferred mode.
	 */
	function attach( node, method ) {
		// The row's method rides on its container: mount the fields
		// restricted to it, so this row renders exactly one method and
		// the page's radio list is the selector. '' (the single-row
		// fallback) mounts unfiltered, exactly as before.
		return window.XPayElements.mount( {
			node: ( node.querySelector && node.querySelector( '.xpay-el__mount' ) ) || node,
			paymentMethodTypes: method ? [ method ] : undefined,
			// This row already draws the method's logo and title, so the
			// fields render the form alone. The fallback single row keeps
			// the accordion: it is the whole selector there.
			layout: method ? 'tabs' : undefined,
			amount: cartAmount(),
			currency: params.currency,
			publishableKey: params.publishableKey,
			sdkUrl: params.sdkUrl,
			colorMode: params.colorMode,
			locale: params.locale,
			// The library holds no wording of its own, so every line it
			// may need to show a shopper is handed to it here.
			i18n: params.i18n,
			onReady: function () {
				showError( '' );
			},
			onError: function ( message ) {
				showError( message || ( params.i18n && params.i18n.unavailable ) );
			},
			onUnavailable: function () {
				showError( params.i18n && params.i18n.unavailable );
			},
		} );
	}

	/**
	 * Drop every element whose container a redraw has replaced. Their
	 * iframes went with their nodes; this closes the bridges so a message
	 * meant for a live element is never heard by a dead one.
	 */
	function pruneReplacedRows() {
		Object.keys( rows ).forEach( function ( method ) {
			var entry = rows[ method ];
			var alive = entry.node && false !== entry.node.isConnected;
			if ( ! alive ) {
				entry.handle.destroy();
				delete rows[ method ];
				if ( handle === entry.handle ) {
					handle = null;
				}
			}
		} );
	}

	/**
	 * The cart total this page currently shows, in minor units.
	 *
	 * Read from the DOM rather than re-asked of the server: WooCommerce has
	 * just rendered the authoritative number into the order-review table,
	 * and asking for it again would be a round trip to learn what is
	 * already on screen. The localised value is the fallback for the first
	 * render, before any recalculation has happened.
	 *
	 * @return {number} Minor units, or 0 when it cannot be read.
	 */
	function cartAmount() {
		var node = container();
		var shown = node ? node.getAttribute( 'data-xpay-amount' ) : null;
		var amount = shown ? parseInt( shown, 10 ) : NaN;
		if ( ! isNaN( amount ) && amount > 0 ) {
			return amount;
		}
		return parseInt( params.amount, 10 ) > 0 ? parseInt( params.amount, 10 ) : 0;
	}

	/**
	 * Bring the displayed amount in line with the cart.
	 *
	 * Zero API calls. The old flow asked the server to move a live
	 * session's amount on every recalculation, with a two-phase
	 * pending/applied bookkeeping to survive the races that created; none
	 * of that exists now, because there is no session to move.
	 *
	 * Refused while a payment is in flight, by the library: the shopper
	 * approved a number and it must not change under an open charge.
	 */
	function sync() {
		if ( ! handle ) {
			return;
		}
		handle.setAmount( cartAmount(), params.currency );
	}

	/**
	 * Take over Place Order.
	 *
	 * @param {Object} wcForm WooCommerce's own checkout-form controller,
	 *                        handed to us by core with the event. Using its
	 *                        blocking and error rendering keeps this looking
	 *                        and behaving like every other gateway.
	 */
	function pay( wcForm ) {
		if ( paying || ! handle ) {
			if ( ! handle ) {
				showError( ( params.i18n && params.i18n.notReady ) || '' );
			}
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
				// Refused before anything was created, so there is nothing
				// to release and nothing to undo.
				release( wcForm );
				showError( problem );
				return;
			}
			placeOrderThenConfirm( wcForm );
		} );
	}

	/**
	 * Unfreeze the checkout after a payment that did not go through.
	 *
	 * @param {Object} wcForm WooCommerce's checkout-form controller.
	 */
	function release( wcForm ) {
		paying = false;
		if ( wcForm && wcForm.$checkout_form ) {
			wcForm.$checkout_form.removeClass( 'processing' ).unblock();
		}
		if ( wcForm && wcForm.detachUnloadEventsOnSubmit ) {
			wcForm.detachUnloadEventsOnSubmit();
		}
	}

	/**
	 * Create the order, then charge for it.
	 *
	 * @param {Object} wcForm WooCommerce's checkout-form controller.
	 */
	function placeOrderThenConfirm( wcForm ) {
		var $form = wcForm && wcForm.$checkout_form ? wcForm.$checkout_form : window.jQuery( 'form.checkout' );

		/*
		 * WooCommerce's own blockOnSubmit film is the whole of the
		 * in-flight UI, exactly as it is for Stripe's plugin: it covers
		 * the form from here until the browser navigates, and release()
		 * lifts it on every path that stays on this page. The SDK draws
		 * its own overlay for the one stretch that needs more, a 3DS or
		 * redirect challenge.
		 */
		if ( wcForm && wcForm.blockOnSubmit ) {
			$form.addClass( 'processing' );
			wcForm.blockOnSubmit( $form );
			wcForm.attachUnloadEventsOnSubmit();
		}

		var strings = params.i18n || {};

		placeOrder( $form )
			.then( function ( result ) {
				// No secret came back: the order exists but the server did
				// not hand this page a session to confirm against (already
				// paid, or a fallback it owns). It named where to go; go
				// there.
				if ( 'yes' !== result.xpay_confirm || ! result.xpay_secret ) {
					navigate( result.redirect );
					return;
				}

				return handle.confirm( result.xpay_secret, customerDetails() ).then( function ( outcome ) {
					/*
					 * Charge = display, refused. The session the server made
					 * does not total what these fields showed, so NOTHING
					 * was charged. The cart moved under the shopper between
					 * mounting and paying; show them the real number and let
					 * them approve it. Never retried automatically: that
					 * would charge a total nobody agreed to.
					 */
					if ( outcome && 'amount_reconfirmation_required' === outcome.code ) {
						sync();
						release( wcForm );
						showError( window.XPayElements.refusalMessage( strings, outcome.code ) );
						return;
					}

					// A clean success settles itself; nothing else does.
					// "Not ok" from the SDK is a decline and a payment the
					// platform could not decide on in the same shape, so the
					// server is asked which.
					if ( window.XPayElements.confirmed( outcome ) ) {
						navigate( result.redirect );
						return;
					}

					// Asked twice when the first answer is "cannot say": an
					// unreachable API is usually a blip, and the fallback is
					// deliberately the cautious one, so it is worth one more
					// question.
					return window.XPayElements.settleVerdict( function () {
						return ask( 'outcome', {
							order: result.xpay_order_id || '',
							key: result.xpay_order_key || '',
						} ).then( function ( answer ) {
							return answer.ok && answer.json && answer.json.success
								? answer.json.data.verdict
								: 'unknown';
						} );
					} )
						.then( window.XPayElements.outcomeKind )
						.then( function ( kind ) {
							if ( 'paid' === kind ) {
								navigate( result.redirect );
								return;
							}

							// Undecided, not declined: the money may have
							// moved. Send the shopper to their order and let
							// the webhook settle it, rather than offering a
							// retry that could charge them twice.
							if ( 'pending' === kind ) {
								navigate( result.redirect );
								return;
							}

							// XPay is certain nothing moved. An ordinary
							// WooCommerce state the shopper can recover
							// from: the SAME order and the SAME session are
							// waiting, so a retry reuses both.
							release( wcForm );
							showError( ( outcome && outcome.message ) || strings.notCompleted );
						} );
				} );
			} )
			.catch( function ( error ) {
				release( wcForm );
				if ( error && error.wcMessages && wcForm && wcForm.submit_error ) {
					// WooCommerce's own validation messages, rendered by
					// WooCommerce's own renderer.
					wcForm.submit_error( error.wcMessages );
					return;
				}
				showError( error && error.message ? error.message : strings.notCompleted );
			} );
	}

	/**
	 * Post the checkout form to WooCommerce's own endpoint.
	 *
	 * The same URL and the same body core would have sent. Doing it here
	 * rather than letting core submit is what buys the gap between "the
	 * order now exists" and "navigate away" — the gap the confirm needs,
	 * because the card lives in an iframe on this page and a navigation
	 * destroys it.
	 *
	 * @param {Object} $form jQuery-wrapped checkout form.
	 * @return {Promise<Object>} WooCommerce's JSON result.
	 */
	function placeOrder( $form ) {
		return window
			.fetch( window.wc_checkout_params.checkout_url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: $form.serialize(),
			} )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( text ) {
				var result = parseCheckoutResponse( text );

				if ( ! result || 'success' !== result.result ) {
					var failure = new Error( ( params.i18n && params.i18n.unavailable ) || '' );
					if ( result && result.messages ) {
						failure.wcMessages = result.messages;
					}
					throw failure;
				}
				return result;
			} );
	}

	/**
	 * WooCommerce's checkout endpoint can return JSON with debug output in
	 * front of it, which is why core ships a repair step of its own. The
	 * same salvage is applied here rather than failing a placed order over
	 * a stray notice from another plugin.
	 *
	 * @param {string} text Raw response body.
	 * @return {Object|null} Parsed result, or null.
	 */
	function parseCheckoutResponse( text ) {
		try {
			return JSON.parse( text );
		} catch ( ignored ) {
			var salvaged = String( text ).match( /{"result.*}/ );
			if ( ! salvaged ) {
				return null;
			}
			try {
				return JSON.parse( salvaged[ 0 ] );
			} catch ( stillBad ) {
				return null;
			}
		}
	}

	/**
	 * @param {string} url Where WooCommerce wants the shopper to go next.
	 */
	function navigate( url ) {
		if ( ! url ) {
			window.location.reload();
			return;
		}
		window.location = -1 === url.indexOf( 'https://' ) || -1 === url.indexOf( 'http://' ) ? url : decodeURI( url );
	}

	/** What XPay is told about the shopper, gathered from the form. */
	function customerDetails() {
		var details = {
			name: value( '#billing_first_name' ) + ' ' + value( '#billing_last_name' ),
			email: value( '#billing_email' ),
			phone: value( '#billing_phone' ),
		};
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

	/* ── Wiring ──────────────────────────────────────────────────────── */

	/**
	 * Redraw handling.
	 *
	 * WooCommerce redraws the payment box on every recalculation but often
	 * reuses the same mount node, and mount() deliberately does nothing
	 * then — a re-mount would throw away the card the shopper has typed. On
	 * those redraws the sync is the only thing that moves the displayed
	 * amount, so it has to run. A fresh mount already displays the current
	 * total, so exactly one of the two runs each time.
	 */
	function onUpdatedCheckout() {
		pruneReplacedRows();
		if ( ! mount() ) {
			sync();
		}
		bindPlaceOrder();
	}

	/**
	 * Bind the Place Order takeover to the FORM ITSELF.
	 *
	 * This one detail is why the classic checkout has never worked. Core
	 * fires `checkout_place_order_{gateway}` on the form element with
	 * jQuery's triggerHandler (assets/js/frontend/checkout.js:909), and
	 * triggerHandler does not bubble — so the handler this plugin bound on
	 * `document` was never once called. Every classic shopper fell straight
	 * through to WooCommerce's normal submit and met the pay page, with the
	 * card fields they had just filled in discarded.
	 *
	 * For the same reason this cannot be a delegated binding either:
	 * delegation is bubbling, and there is none. It has to be on the form.
	 * Stripe binds the same event the same way.
	 *
	 * Namespaced and re-run after every redraw: WooCommerce replaces the
	 * form's contents constantly, and a theme that replaces the form element
	 * itself would otherwise silently take the binding with it. jQuery's
	 * `.off()` on our own namespace makes re-binding idempotent.
	 */
	function bindPlaceOrder() {
		if ( ! window.jQuery ) {
			return;
		}
		var $form = window.jQuery( 'form.checkout' );
		if ( ! $form.length ) {
			return;
		}
		// One takeover per row: core names the event after the CHOSEN
		// gateway id, and every method row is its own gateway now.
		( params.rows || [ params.gatewayId ] ).forEach( function ( rowId ) {
			$form
				.off( 'checkout_place_order_' + rowId + '.xpay' )
				.on( 'checkout_place_order_' + rowId + '.xpay', function ( event, wcForm ) {
					pay( wcForm );
					// We own the submit from here: the order is placed by
					// placeOrderThenConfirm(), and the page navigates only
					// once the payment has actually been confirmed.
					return false;
				} );
		} );
	}

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', onUpdatedCheckout );
		// Wrapped, not passed directly: mount() answers false when XPay is
		// not the selected row, and jQuery reads a handler returning false
		// as preventDefault() plus stopPropagation(). Bound straight on,
		// picking any other payment method would stop the event reaching
		// anything listening above document.body.
		window.jQuery( document.body ).on( 'payment_method_selected', function () {
			// A kept element may have missed recalculations while another
			// row was selected; re-reading the total costs nothing.
			if ( ! mount() ) {
				sync();
			}
		} );
		bindPlaceOrder();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', onUpdatedCheckout );
	} else {
		onUpdatedCheckout();
	}
} )( window, document );
