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
 *   4. Holding the gate that decides whether a payment may be attempted
 *      at all, and confirming once it may.
 *   5. Re-reading the session when the server has moved it underneath us.
 *
 * What it deliberately does NOT own: the card data. The fields live in
 * XPay's iframe, and inside that a second vault iframe, so the PAN never
 * touches this page or this plugin.
 *
 * THE ORDER THE SDK IMPOSES
 *
 * Every action method — submit, fetchUpdates, confirm — talks over a
 * bridge to the element's iframe, and that bridge is not built until the
 * element mounts. Calling any of them earlier does not throw; it quietly
 * resolves an error nobody asked for. So the sequence below is not a
 * style choice:
 *
 *   initCheckout  ->  read status  ->  subscribe  ->  create  ->  mount
 *
 * Status is read before mounting because an expired session still loads
 * successfully; the only thing that distinguishes it from a live one is
 * the status field. Subscriptions are registered before mounting because
 * neither 'change' nor 'error' replays for a listener that arrives late.
 *
 * @package XPay_For_WooCommerce
 */
( function ( window ) {
	'use strict';

	var SDK_TIMEOUT_MS = 6000;

	var XPayElements = {};

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

	/**
	 * One of the caller's strings, or null to let the caller choose.
	 *
	 * Wording lives with the page, never here: this file is loaded on a
	 * translated store and has no business shipping English of its own.
	 *
	 * @param {Object} opts Mount options.
	 * @param {string} key  Which string is wanted.
	 * @return {?string} The string, or null when the page did not give one.
	 */
	function text( opts, key ) {
		var strings = opts.i18n || {};
		return strings[ key ] || null;
	}

	/**
	 * Mount the Payment Element.
	 *
	 * @param {Object}   options                  Mount options.
	 * @param {string}   [options.selector]       CSS selector for the mount point.
	 * @param {Object}   [options.node]           The mount point itself. Blocks
	 *                                            owns its DOM and hands us the
	 *                                            node rather than a selector
	 *                                            that may match the wrong copy
	 *                                            when two carts render at once.
	 * @param {string}   options.clientSecret     Session client secret.
	 * @param {string}   options.publishableKey   Merchant publishable key.
	 * @param {string}   options.sdkUrl           SDK script URL.
	 * @param {string}   options.colorMode        "system", "light" or "dark".
	 * @param {Object}   [options.i18n]           Shopper-facing wording.
	 * @param {Function} options.onMethodChange   Called with the selected method.
	 * @param {Function} options.onReady          Called once fields can be filled.
	 * @param {Function} options.onSessionChange  Called with the session whenever
	 *                                            totals move under the page.
	 * @param {Function} options.onTerminal       Called when the session is spent
	 *                                            or expired and no form was shown.
	 * @param {Function} options.onError          Called with a shopper-facing message.
	 * @param {Function} options.onUnavailable    Called when the SDK cannot load.
	 * @return {Object} A handle with confirm(), refresh(), check(), canPay()
	 *                  and destroy().
	 */
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
			// The last thing the element said about the shopper's progress
			// through the fields. Informational only: the element announces
			// it when something changes and says nothing at all about the
			// method it picks for itself, so its absence proves nothing and
			// it is never the reason a payment is refused. check() asks.
			complete: false,
			// Whether the session is still one that can be paid. Read off
			// every session the SDK hands over, never off the object
			// initCheckout returned, which is a snapshot that never moves.
			canConfirm: false,
			// The last session the SDK handed over, from any source.
			session: null,
			status: null,
			// 'expired' or 'complete' once the session can never be paid.
			terminal: null,
		};

		var sessionListener = null;
		var errorListener = null;

		var fired = false;

		/**
		 * Report that no payment form is going to appear.
		 *
		 * @param {*} reason Whatever went wrong.
		 */
		function giveUp( reason ) {
			if ( fired ) {
				return;
			}
			fired = true;
			call( opts.onUnavailable, reason );
		}

		/**
		 * Adopt a session the SDK just handed over.
		 *
		 * The object initCheckout returned is a snapshot spread once at
		 * ready time and never written back, so its canConfirm and status
		 * go stale the instant anything moves. Every live reading comes
		 * from a session delivered by an event or an action result, which
		 * is why they all funnel through here.
		 *
		 * @param {Object} session A freshly mapped session.
		 */
		function adopt( session ) {
			if ( ! session ) {
				return;
			}
			handle.session = session;
			handle.status = session.status || null;
			handle.canConfirm = !! session.canConfirm;

			var type = session.status && session.status.type;
			if ( 'expired' === type || 'complete' === type ) {
				enterTerminal( type );
			}
		}

		/**
		 * Record that the session is spent, and say so once.
		 *
		 * @param {string} type Either 'expired' or 'complete'.
		 */
		function enterTerminal( type ) {
			if ( handle.terminal === type ) {
				return;
			}
			handle.terminal = type;
			handle.canConfirm = false;

			var message = terminalText();
			call( opts.onTerminal, type, message );
			call( opts.onError, message );
		}

		/**
		 * The caller's wording for a session that can never be paid.
		 *
		 * Kept in one place because it is asked for twice: once when the
		 * session goes terminal, and again whenever somebody tries to pay
		 * afterwards and has to be told why they cannot. The second answer
		 * has to be the same as the first, or the shopper is handed two
		 * different explanations for one situation.
		 *
		 * @return {?string} The message, or null when the page gave none.
		 */
		function terminalText() {
			return text( opts, 'expired' === handle.terminal ? 'expired' : 'completed' );
		}

		/**
		 * Bring up the payment form once the SDK is on the page.
		 *
		 * @param {Object} xpay The SDK instance.
		 */
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
					handle.status = checkout.status || null;
					handle.canConfirm = !! checkout.canConfirm;

					var type = checkout.status && checkout.status.type;
					if ( 'expired' === type || 'complete' === type ) {
						// A spent session loads exactly like a live one: the
						// client endpoint answers 200 either way. Mounting
						// here would put a working card form over a session
						// the platform will refuse, so the shopper would
						// only find out after typing their number in.
						enterTerminal( type );
						return;
					}

					subscribe( checkout );

					handle.elements = checkout.getElements();
					// No options: the SDK's create() accepts them and then
					// ignores them, and one method shows at a time anyway.
					handle.element = handle.elements.create( 'payment' );

					handle.element.on( 'change', function ( event ) {
						// Stripe-compatible shape: the selected method rides
						// on value.type. This is the signal the valU number
						// prompt hangs off, so it is reported even when it
						// has not changed, letting listeners stay stateless.
						var method = event && event.value ? event.value.type : null;
						handle.selectedMethod = method;
						// `complete` is the one flag the SDK forwards without
						// a default, so it is read for truthiness rather than
						// compared against true.
						handle.complete = !! ( event && event.complete );
						if ( event && event.session ) {
							adopt( event.session );
						}
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

		/**
		 * Listen to the session itself, before anything mounts.
		 *
		 * Only 'ready' is replayed to a listener that registers late, so a
		 * subscription made after mount() would miss whatever the element
		 * announced while it was loading.
		 *
		 * @param {Object} checkout The initialised checkout.
		 */
		function subscribe( checkout ) {
			if ( 'function' !== typeof checkout.on ) {
				return;
			}

			sessionListener = function ( session ) {
				adopt( session );
				call( opts.onSessionChange, session );
			};

			errorListener = function ( error ) {
				// Errors nobody was awaiting: a session cancelled server
				// side, a fee recalculation that outlived its session, a
				// BIN lookup that failed. There is no return value for
				// these to arrive on, so this is the only way the shopper
				// hears about them.
				call( opts.onError, messageFrom( error ) );
			};

			checkout.on( 'change', sessionListener );
			checkout.on( 'error', errorListener );
		}

		/**
		 * initCheckout failing after the script loaded is a different
		 * failure from the script never arriving, but the shopper's way
		 * out is the same one.
		 *
		 * @param {*} reason Whatever went wrong.
		 */
		function giveUpLate( reason ) {
			call( opts.onUnavailable, reason );
		}

		/**
		 * Whether the session is still one a payment could be made against.
		 *
		 * This is the session's half of the gate, and deliberately only that
		 * half. Whether the shopper has filled in enough is not asked here,
		 * because the element only volunteers that on 'change' and it raises
		 * no change for the method it selects by itself when it loads. A
		 * shopper who accepts a default that needs nothing typed into it —
		 * valU, Fawry, anything that is not a card — would otherwise be
		 * refused forever over a form that never asked them for anything.
		 * That half is settled by asking, in check().
		 *
		 * @return {boolean} True when the session can still take a payment.
		 */
		handle.canPay = function () {
			return ! handle.terminal && !! handle.canConfirm;
		};

		/**
		 * Ask whether a payment may be attempted, and why not when it may not.
		 *
		 * What a page should call before it commits a shopper to anything.
		 * It costs one message to the element and no network at all, and
		 * unlike canPay() it puts the completeness question to the fields
		 * rather than repeating whatever they last happened to announce.
		 *
		 * The reason it hands back is the reason for the state the shopper
		 * is actually in: a spent session says so, rather than being folded
		 * into advice to finish filling in fields that were never shown.
		 *
		 * @return {Promise<?string>} A reason to stop, or null to go ahead.
		 */
		handle.check = function () {
			if ( ! handle.checkout ) {
				return Promise.resolve( text( opts, 'notReady' ) );
			}
			if ( handle.terminal ) {
				return Promise.resolve( terminalText() );
			}
			if ( ! handle.canConfirm ) {
				// Not open, and not for a reason the session named. There is
				// nothing for the shopper to do differently, so they are not
				// told to do anything differently.
				return Promise.resolve( text( opts, 'unavailable' ) );
			}
			return preflight();
		};

		/**
		 * Re-read the session after the server has changed it.
		 *
		 * This is what tells a mounted element that its total moved. The
		 * server patching the session is invisible to the iframe until
		 * somebody asks it to look again.
		 *
		 * A successful fetch does not raise 'change' by itself, so the
		 * fresh session is taken off the result and published from here
		 * rather than waited for.
		 *
		 * @return {Promise<Object>} Resolves { ok: true, session } or { ok: false, message }.
		 */
		handle.refresh = function () {
			if ( ! handle.checkout || 'function' !== typeof handle.checkout.fetchUpdates ) {
				return Promise.resolve( { ok: false } );
			}
			if ( ! handle.ready ) {
				// The bridge this travels over is built by mount() and the
				// element is only reachable once it reports ready. Asking
				// sooner returns an error about not being initialised, and
				// there is nothing on screen to be stale yet anyway.
				return Promise.resolve( { ok: false } );
			}

			return handle.checkout
				.fetchUpdates()
				.then( function ( result ) {
					if ( ! result || 'success' !== result.type ) {
						return { ok: false, message: messageFrom( result && result.error ) };
					}
					adopt( result.session );
					call( opts.onSessionChange, result.session );
					return { ok: true, session: result.session };
				} )
				.catch( function ( error ) {
					// Documented never to reject. Caught anyway so that a
					// future SDK that does cannot turn a cart update into
					// an unhandled rejection on a live checkout.
					return { ok: false, message: messageFrom( error ) };
				} );
		};

		/**
		 * Validate the fields before committing to a charge.
		 *
		 * Only a verdict about the shopper's input stops the payment. A
		 * transport failure here — the embed not answering inside its own
		 * ten second window, or an element that never mounted — is not
		 * evidence anyone typed anything wrong, and confirm() carries the
		 * same guards a moment later, so those fall through rather than
		 * stranding somebody who filled the form in correctly.
		 *
		 * @return {Promise<?string>} A reason to stop, or null to continue.
		 */
		function preflight() {
			if ( 'function' !== typeof handle.checkout.submit ) {
				return Promise.resolve( null );
			}
			return handle.checkout
				.submit()
				.then( function ( outcome ) {
					var error = outcome && outcome.error;
					if ( ! error ) {
						return null;
					}
					if ( 'invalid_request_error' === error.type ) {
						return messageFrom( error ) || text( opts, 'incomplete' );
					}
					return null;
				} )
				.catch( function () {
					return null;
				} );
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
					message: text( opts, 'notReady' ),
				} );
			}

			if ( handle.terminal ) {
				return Promise.resolve( {
					ok: false,
					message: terminalText(),
				} );
			}

			if ( ! handle.canPay() ) {
				// A session that is not open and has not said why. Pushing
				// past this gets an error out of the card form instead of an
				// answer out of the page.
				return Promise.resolve( {
					ok: false,
					message: text( opts, 'unavailable' ),
				} );
			}

			handle.paying = true;

			// Validated here even when the caller already asked through
			// check(). That answer was true before the server was told a
			// payment was starting, and this one is true immediately before
			// the money moves, which is the one that has to be right.
			return preflight()
				.then( function ( problem ) {
					if ( problem ) {
						handle.paying = false;
						return { ok: false, message: problem };
					}

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
						} );
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
		 *
		 * @param {string} colorMode The mode to restyle to.
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
				// Detaching goes through the Elements instance: the object
				// initCheckout returned can add listeners but has no way to
				// take them off again.
				if ( handle.elements && 'function' === typeof handle.elements.off ) {
					if ( sessionListener ) {
						handle.elements.off( 'change', sessionListener );
					}
					if ( errorListener ) {
						handle.elements.off( 'error', errorListener );
					}
				}
			} catch ( e ) {
				// Already gone.
			}
			sessionListener = null;
			errorListener = null;

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
			handle.complete = false;
			handle.canConfirm = false;
			handle.session = null;
			handle.status = null;
			handle.terminal = null;
		};

		loadSdk( opts, start, giveUp );
		return handle;
	};

	/* ── SDK loading ──────────────────────────────────────────────────── */

	/**
	 * Put the SDK on the page, or say why it is not coming.
	 *
	 * @param {Object}   opts     Mount options.
	 * @param {Function} onLoaded Called with the SDK instance.
	 * @param {Function} onFailed Called with a reason string.
	 */
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

	/**
	 * Call a listener the page gave us, if it gave us one.
	 *
	 * @param {Function} fn The listener.
	 */
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
