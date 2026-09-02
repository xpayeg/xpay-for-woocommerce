/**
 * XPay order-pay driver.
 *
 * The order-pay page serves pay links, retries from emails, and
 * admin-created orders: the ORDER already exists, its total is final, and
 * WooCommerce renders its own payment form around our fields. So this is
 * the checkout driver with everything about a moving cart removed — the
 * same deferred element, mounted at one fixed amount.
 *
 * The flow on Pay:
 *   1. check() — are the fields filled in and mounted?
 *   2. Ask the server for the order's session (get-or-create per ORDER,
 *      same discipline as the checkout: a retry reuses the same session
 *      and the same clientSecret).
 *   3. The server may answer "already paid" — a stale pay link — and then
 *      the only honest destination is the order-received page.
 *   4. confirm() against the secret; on success navigate, on a decline
 *      stay here with the reason, on "cannot say" ask the outcome
 *      endpoint before deciding, exactly as the checkout does.
 *
 * WooCommerce's own submit is intercepted, never followed: a plain form
 * POST navigates, and a navigation destroys the iframe holding the card
 * mid-payment.
 *
 * @package XPay_For_WooCommerce
 */
( function ( window, document ) {
	'use strict';

	var params = window.xpayPayPageParams;

	if ( ! params || ! window.XPayElements ) {
		return;
	}

	var handle = null;
	var paying = false;

	/*
	 * Set the moment confirm() is called, never unset on this page load.
	 * It is the pays-twice guard's fact: once a confirm has started,
	 * money may be moving, so every unclear ending goes to the order page
	 * for the webhook to settle instead of re-enabling the Pay button.
	 */
	var confirmStarted = false;

	/**
	 * Ask one of our endpoints. Always POST, always nonced.
	 *
	 * @param {string} action Endpoint suffix.
	 * @param {Object} body   Extra fields.
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
			.fetch( params.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, json: json };
				} );
			} );
	}

	function showError( message ) {
		var row = container();
		var node = ( row && row.querySelector && row.querySelector( '[data-xpay-elements-error]' ) )
			|| document.querySelector( '[data-xpay-elements-error]' );
		if ( node ) {
			node.textContent = message || '';
			node.hidden = ! message;
		}
	}

	/** Whether the shopper has the XPay row selected (or it is the only one). */
	function xpaySelected() {
		var input = document.querySelector( 'input[name="payment_method"]:checked' );
		return ! input || input.value === params.gatewayId || 0 === input.value.indexOf( params.gatewayId + '_' );
	}

	/** The selected row's container, or the page's only one. */
	function container() {
		var input = document.querySelector( 'input[name="payment_method"]:checked' );
		var id = input ? String( input.value || '' ) : '';
		var scoped = id ? document.querySelector( '.payment_method_' + id + ' [data-xpay-elements]' ) : null;
		return scoped || document.querySelector( '[data-xpay-elements]' );
	}

	var mountedNode = null;

	function mount() {
		var node = container();
		if ( ! node || node === mountedNode ) {
			return;
		}
		if ( handle ) {
			// The order-pay page re-renders nothing on its own, so a new
			// node here means the shopper switched rows. The total is
			// fixed, nothing typed carries between methods' fields on this
			// page, and one live element keeps the flow identical to the
			// single-row page it was.
			handle.destroy();
			handle = null;
		}
		mountedNode = node;
		var method = ( node.getAttribute && node.getAttribute( 'data-xpay-method' ) ) || '';
		handle = window.XPayElements.mount( {
			node: ( node.querySelector && node.querySelector( '.xpay-el__mount' ) ) || node,
			// One method per row, from the row's own container; '' (the
			// single-row fallback) mounts unfiltered.
			paymentMethodTypes: method ? [ method ] : undefined,
			// The row already draws the method's logo and title, so the
			// fields render the form alone; the fallback keeps the accordion.
			layout: method ? 'tabs' : undefined,
			// Fixed on purpose: the order's total is final. There is no
			// update path on this page, which is most of what makes this
			// driver smaller than the checkout's.
			amount: parseInt( params.amount, 10 ),
			currency: params.currency,
			publishableKey: params.publishableKey,
			sdkUrl: params.sdkUrl,
			colorMode: params.colorMode,
			locale: params.locale,
			i18n: params.i18n,
			onReady: function () {
				showError( '' );
			},
			onError: function ( message ) {
				showError( message || ( params.i18n && params.i18n.unavailable ) );
			},
			onUnavailable: function () {
				// The same tradeoff Stripe's plugin makes when its JS cannot
				// load on this page: tell the shopper to try again. The
				// hosted-page fallback died with the hosted session — a
				// custom session has no hosted URL to fall back to.
				showError( ( params.i18n && params.i18n.retry ) || ( params.i18n && params.i18n.unavailable ) || '' );
			},
		} );
	}

	function navigate( url ) {
		window.location = url || params.returnUrl;
	}

	/** @param {Object} outcome The confirm result, for the verdict machinery. */
	function settle( outcome ) {
		var strings = params.i18n || {};

		return window.XPayElements.settleVerdict( function () {
			return ask( 'outcome', { order: params.orderId, key: params.orderKey } ).then( function ( answer ) {
				return answer.ok && answer.json && answer.json.success ? answer.json.data.verdict : 'unknown';
			} );
		} )
			.then( window.XPayElements.outcomeKind )
			.then( function ( kind ) {
				if ( 'paid' === kind ) {
					navigate();
					return;
				}
				if ( 'pending' === kind ) {
					// The money may have moved. The order page and the
					// webhook settle it; offering a retry could charge
					// twice.
					navigate();
					return;
				}
				releasePay();
				showError( ( outcome && outcome.message ) || strings.notCompleted );
			} );
	}

	/** The Pay controls, wherever this page drew them. */
	function payButtons() {
		var buttons = document.querySelectorAll( '#order_review button[type="submit"], #order_review #place_order, [data-xpay-pay]' );
		return Array.prototype.slice.call( buttons );
	}

	/** Freeze the Pay controls while an attempt runs. */
	function holdPay() {
		paying = true;
		payButtons().forEach( function ( button ) {
			button.disabled = true;
		} );
	}

	/** Hand them back after an attempt that provably charged nothing. */
	function releasePay() {
		paying = false;
		payButtons().forEach( function ( button ) {
			button.disabled = false;
		} );
	}

	function pay() {
		if ( paying || ! handle ) {
			if ( ! handle ) {
				showError( ( params.i18n && params.i18n.notReady ) || '' );
			}
			return;
		}
		holdPay();
		showError( '' );

		var strings = params.i18n || {};

		handle.check().then( function ( problem ) {
			if ( problem ) {
				releasePay();
				showError( problem );
				return;
			}

			return ask( 'order_session', { order: params.orderId, key: params.orderKey } ).then( function ( result ) {
				var data = result.ok && result.json && result.json.success ? result.json.data : null;
				if ( ! data ) {
					releasePay();
					showError( ( params.i18n && params.i18n.unavailable ) || '' );
					return;
				}

				// A stale pay link: the order was already paid, possibly in
				// another tab or by a webhook still in flight when the page
				// rendered. The only honest destination is the receipt.
				if ( data.paid ) {
					navigate( data.redirect );
					return;
				}

				if ( ! data.clientSecret ) {
					releasePay();
					showError( ( params.i18n && params.i18n.unavailable ) || '' );
					return;
				}

				confirmStarted = true;

				return handle.confirm( data.clientSecret, params.customer || {} ).then( function ( outcome ) {
					/*
					 * Charge = display, refused: the order's total changed
					 * after this page rendered (an admin edit), the server
					 * repriced the session to the real total, the fields
					 * still display the old one, and NOTHING was charged.
					 * Unlike the checkout, this page cannot move its
					 * displayed amount honestly — the order table around the
					 * fields still shows the stale total — so the reload IS
					 * the re-read: the page comes back showing the real
					 * total for the shopper to approve. Without this, every
					 * retry met the same refusal forever.
					 */
					if ( outcome && 'amount_reconfirmation_required' === outcome.code ) {
						window.location.reload();
						return;
					}
					if ( window.XPayElements.confirmed( outcome ) ) {
						navigate();
						return;
					}
					return settle( outcome );
				} );
			} );
		} ).catch( function () {
			/*
			 * Nothing in this chain reports a failure by rejecting: the
			 * library's confirm() and settleVerdict() both resolve their own
			 * failures. What DOES reject is ask() — fetch on a dropped
			 * connection, or response.json() on an admin-ajax reply that was
			 * not JSON (a PHP fatal, a host error page). Without this the
			 * rejection went nowhere: `paying` stayed true, no message
			 * appeared, and the Pay button was dead until the shopper
			 * reloaded.
			 *
			 * confirmStarted decides what is safe to offer. False means
			 * nothing was charged and a retry is safe. True means money may
			 * be moving, so this answers it the way every undecided outcome
			 * is answered: the order page, and the webhook settles it.
			 * Re-enabling the button there is how a shopper pays twice.
			 */
			if ( confirmStarted ) {
				navigate();
				return;
			}
			releasePay();
			showError( strings.unavailable || '' );
		} );
	}

	/**
	 * Take over the page's Pay control.
	 *
	 * Two shapes serve this page: WooCommerce's own order-pay form
	 * (form#order_review with its Pay button), and the plain button the
	 * receipt endpoint renders when there is no form. Either way the
	 * submit is ours: a form POST would navigate and destroy the card
	 * fields mid-payment.
	 */
	function bind() {
		var form = document.getElementById( 'order_review' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! xpaySelected() ) {
					return;
				}
				event.preventDefault();
				pay();
			} );
			// Each method row has fields of its own now; switching rows
			// mounts the newly selected one. Delegated on the form because
			// the radios are core's markup, not ours.
			form.addEventListener( 'change', function ( event ) {
				if ( event.target && 'payment_method' === event.target.name && xpaySelected() && ! paying ) {
					mount();
				}
			} );
		}
		var button = document.querySelector( '[data-xpay-pay]' );
		if ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				pay();
			} );
		}
	}

	function boot() {
		mount();
		bind();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( window, document );
