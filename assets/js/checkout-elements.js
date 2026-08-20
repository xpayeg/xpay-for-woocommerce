/**
 * XPay Elements — payment fields on the store's own page.
 *
 * Replaces the drop-in window with XPay's Payment Element mounted inline.
 * The shopper never sees a window open: the method rows and the card
 * fields are part of the page, and the store's own button submits them.
 *
 * Deliberately page-agnostic. It is handed a container and a session and
 * knows nothing about whether it is running on the pay page or the
 * checkout, so the same file serves both as each one is moved over.
 *
 * What it owns:
 *   1. Loading the SDK, with the same hard timeout the modal used — on a
 *      slow Egyptian mobile network the hosted page beats waiting.
 *   2. Measuring the store's appearance and handing it to the SDK, so the
 *      form arrives already looking like the theme rather than flashing
 *      XPay's defaults and then correcting itself.
 *   3. Telling the page which method the shopper selected, which is what
 *      the valU number prompt listens to.
 *   4. Confirming, and turning every ending into something the shopper
 *      can act on.
 *
 * What it deliberately does NOT own: the card data. The fields live in
 * XPay's iframe, and inside that a second vault iframe, so the PAN never
 * touches this page or this plugin.
 */
( function ( window ) {
	'use strict';

	var SDK_TIMEOUT_MS = 6000;

	var XPayElements = {};

	/**
	 * Mount the Payment Element.
	 *
	 * @param {Object}   options                 Mount options.
	 * @param {string}   [options.selector]      CSS selector for the mount point.
	 * @param {Object}   [options.node]          The mount point itself. Blocks
	 *                                           owns its DOM and hands us the
	 *                                           node rather than a selector
	 *                                           that may match the wrong copy
	 *                                           when two carts render at once.
	 * @param {string}   options.clientSecret    Session client secret.
	 * @param {string}   options.publishableKey  Merchant publishable key.
	 * @param {string}   options.sdkUrl          SDK script URL.
	 * @param {string}   options.colorMode       "system", "light" or "dark".
	 * @param {Function} options.onMethodChange  Called with the selected method.
	 * @param {Function} options.onReady         Called once fields can be filled.
	 * @param {Function} options.onError         Called with a shopper-facing message.
	 * @param {Function} options.onUnavailable   Called when the SDK cannot load.
	 * @return {Object} A handle with confirm() and destroy().
	 */
	/**
	 * The element to mount into, from either shape of the option.
	 *
	 * @param {Object} opts Mount options.
	 * @return {?Object} The node, or null when it is not on the page.
	 */
	function mountNode( opts ) {
		if ( opts.node ) {
			return opts.node;
		}
		if ( opts.selector && typeof document !== 'undefined' ) {
			return document.querySelector( opts.selector );
		}
		return null;
	}

	XPayElements.mount = function ( options ) {
		var opts = options || {};
		var handle = {
			checkout: null,
			elements: null,
			element: null,
			selectedMethod: null,
			// Set the moment a payment is submitted. Anything that would
			// disturb the session must consult this first: the platform
			// accepts an amount change on any open session, including one
			// with a payment in flight, so refusing while in flight is
			// this plugin's job and nobody else's.
			paying: false,
			ready: false,
		};

		var fired = false;
		function giveUp( reason ) {
			if ( fired ) {
				return;
			}
			fired = true;
			call( opts.onUnavailable, reason );
		}

		function start( xpay ) {
			if ( fired ) {
				return;
			}
			fired = true;

			var appearance = {};
			try {
				if ( window.XPayAppearance ) {
					appearance = window.XPayAppearance.detect( {
						anchor: mountNode( opts ) || undefined,
						colorMode: opts.colorMode || 'system',
					} );
				}
			} catch ( e ) {
				// A theme that breaks measurement must not cost the payment
				// form. The SDK falls back to the merchant's dashboard
				// branding when no appearance arrives.
				appearance = {};
			}

			xpay
				.initCheckout( {
					clientSecret: opts.clientSecret,
					appearance: appearance,
				} )
				.then( function ( checkout ) {
					handle.checkout = checkout;
					handle.elements = checkout.getElements();
					handle.element = handle.elements.create( 'payment' );

					handle.element.on( 'change', function ( event ) {
						// Stripe-compatible shape: the selected method rides
						// on value.type. This is the signal the valU number
						// prompt hangs off, so it is reported even when it
						// has not changed, letting listeners stay stateless.
						var method = event && event.value ? event.value.type : null;
						handle.selectedMethod = method;
						call( opts.onMethodChange, method, event );
					} );

					handle.element.on( 'ready', function () {
						handle.ready = true;
						call( opts.onReady );
					} );

					handle.element.mount( mountNode( opts ) || opts.selector );
				} )
				.catch( function ( error ) {
					call( opts.onError, messageFrom( error ) );
					giveUpLate( error );
				} );
		}

		function giveUpLate( reason ) {
			// initCheckout failing after the script loaded is a different
			// failure from the script never arriving, but the shopper's way
			// out is the same one.
			call( opts.onUnavailable, reason );
		}

		/**
		 * Confirm the payment.
		 *
		 * @param {Object} customerDetails Name, email, phone as collected.
		 * @return {Promise<Object>} Resolves with { ok: true } or { ok: false, message }.
		 */
		handle.confirm = function ( customerDetails ) {
			if ( ! handle.checkout ) {
				return Promise.resolve( {
					ok: false,
					message: opts.i18n && opts.i18n.notReady,
				} );
			}
			handle.paying = true;
			return handle.checkout
				.confirm( { customerDetails: customerDetails || {} } )
				.then( function ( result ) {
					if ( result && 'error' === result.type ) {
						handle.paying = false;
						return { ok: false, message: messageFrom( result.error ) };
					}
					// Success keeps `paying` set: the browser is on its way
					// to the return URL and nothing should touch the session
					// in the meantime.
					return { ok: true, result: result };
				} )
				.catch( function ( error ) {
					handle.paying = false;
					return { ok: false, message: messageFrom( error ) };
				} );
		};

		/**
		 * Re-measure the page and restyle the form in place.
		 *
		 * Cheap and non-destructive: appearance travels as its own message
		 * and never recreates the element, so a theme that repaints on a
		 * dark-mode toggle is followed without disturbing typed fields.
		 */
		handle.restyle = function ( colorMode ) {
			if ( ! handle.elements || ! window.XPayAppearance ) {
				return;
			}
			try {
				handle.elements.changeAppearance(
					window.XPayAppearance.detect( {
						anchor: mountNode( opts ) || undefined,
						colorMode: colorMode || opts.colorMode || 'system',
					} )
				);
			} catch ( e ) {
				// Restyling is cosmetic; never let it break a live form.
			}
		};

		handle.destroy = function () {
			try {
				if ( handle.element && handle.element.unmount ) {
					handle.element.unmount();
				}
			} catch ( e ) {
				// Already gone.
			}
			handle.checkout = null;
			handle.elements = null;
			handle.element = null;
			handle.ready = false;
		};

		loadSdk( opts, start, giveUp );
		return handle;
	};

	/* ── SDK loading ──────────────────────────────────────────────────── */

	function loadSdk( opts, onLoaded, onFailed ) {
		if ( window.XPay ) {
			onLoaded( window.XPay( opts.publishableKey ) );
			return;
		}

		var settled = false;
		var timer = window.setTimeout( function () {
			if ( ! settled ) {
				settled = true;
				onFailed( 'timeout' );
			}
		}, SDK_TIMEOUT_MS );

		var script = document.createElement( 'script' );
		script.src = opts.sdkUrl;
		script.async = true;
		script.onload = function () {
			if ( settled ) {
				return;
			}
			settled = true;
			window.clearTimeout( timer );
			if ( window.XPay ) {
				onLoaded( window.XPay( opts.publishableKey ) );
			} else {
				onFailed( 'no-global' );
			}
		};
		script.onerror = function () {
			if ( settled ) {
				return;
			}
			settled = true;
			window.clearTimeout( timer );
			onFailed( 'network' );
		};
		document.head.appendChild( script );
	}

	/* ── Helpers ──────────────────────────────────────────────────────── */

	function call( fn ) {
		if ( 'function' !== typeof fn ) {
			return;
		}
		try {
			fn.apply( null, Array.prototype.slice.call( arguments, 1 ) );
		} catch ( e ) {
			// A listener that throws is the listener's problem, never the
			// payment form's.
		}
	}

	/**
	 * A shopper-facing line from whatever the SDK threw or returned.
	 *
	 * @param {*} error An SDK error, an Error, or nothing useful.
	 * @return {?string} A message, or null to let the caller choose one.
	 */
	function messageFrom( error ) {
		if ( ! error ) {
			return null;
		}
		if ( 'string' === typeof error ) {
			return error;
		}
		if ( error.message && 'string' === typeof error.message ) {
			return error.message;
		}
		return null;
	}

	window.XPayElements = XPayElements;

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = XPayElements;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
