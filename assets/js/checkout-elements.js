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
 * DEFERRED MOUNTING: NO SESSION EXISTS HERE
 *
 * The element is created from an amount and a currency alone
 * (`xpay.elements({ mode: 'payment', amount, currency })`), which is
 * Stripe's deferred-intent shape. No checkout session is created while the
 * shopper fills the form, and none is needed: the merchant's server
 * creates one at Pay time and the browser confirms against its
 * clientSecret. The session never pre-dates the order.
 *
 * The rule that makes it safe: CHARGE = DISPLAY. The session the server
 * creates must total exactly what this element displayed, or the
 * confirmation fails with `amount_reconfirmation_required` and nothing is
 * charged. Enforced twice, by the embed before it binds and by the server
 * inside /collect. So a cart that moves is answered by moving the
 * DISPLAYED amount (`setAmount`), never by topping up a charge after the
 * shopper approved one.
 *
 * What it owns:
 *   1. Loading the SDK, with the same hard timeout the modal used — on a
 *      slow Egyptian mobile network the hosted page beats waiting.
 *   2. Handing the SDK the merchant's theme choice (colorMode); every
 *      other appearance decision is the merchant's XPay dashboard
 *      branding, merged server-side.
 *   3. Telling the page which method the shopper selected, which is what
 *      the ValU number prompt listens to.
 *   4. Holding the gate that decides whether a payment may be attempted
 *      at all, and confirming once it may.
 *   5. Keeping the displayed amount in step with the cart.
 *
 * What it deliberately does NOT own: the card data. The fields live in
 * XPay's iframe, and inside that a second vault iframe, so the PAN never
 * touches this page or this plugin.
 *
 * THE ORDER THE SDK IMPOSES
 *
 * Every action method — submit, confirmPayment — talks over a bridge to
 * the element's iframe, and that bridge is not built until the element
 * mounts. Calling any of them earlier does not throw; it quietly resolves
 * an error nobody asked for. So the sequence below is not a style choice:
 *
 *   elements({mode})  ->  subscribe  ->  create  ->  mount  ->  ready
 *
 * Subscriptions are registered before mounting because neither 'change'
 * nor 'error' replays for a listener that arrives late.
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
	 * How long to wait for a confirm before telling the shopper something.
	 *
	 * This is only a UI backstop for a confirm promise that never settles. It
	 * does not declare the payment failed or change the order.
	 */
	var CONFIRM_DEADLINE_MS = ( 15 * 60 * 1000 ) + 60000;

	/**
	 * Give a promise a deadline, resolving rather than rejecting.
	 *
	 * Lives here because this file owns confirm().
	 *
	 * The timeout message deliberately does NOT say the card was not
	 * charged, because we do not know that: a ValU or kiosk payment can
	 * still be live at the processor long after the browser gave up.
	 *
	 * @param {Promise} promise What to bound.
	 * @param {string}  message What to answer if the deadline passes.
	 * @param {number}  ms      Override for the deadline, in milliseconds.
	 * @return {Promise} Resolves the promise's value, or a timeout answer.
	 */
	function withDeadline( promise, message, ms ) {
		return new Promise( function ( resolve ) {
			var settled = false;
			var timer = setTimeout( function () {
				if ( settled ) {
					return;
				}
				settled = true;
				resolve( { type: 'error', error: { message: message } } );
			}, ms || CONFIRM_DEADLINE_MS );

			promise.then(
				function ( value ) {
					if ( ! settled ) {
						settled = true;
						clearTimeout( timer );
						resolve( value );
					}
				},
				function ( error ) {
					if ( ! settled ) {
						settled = true;
						clearTimeout( timer );
						resolve( { type: 'error', error: error } );
					}
				}
			);
		} );
	}

	/**
	 * The colorMode the fields should render in.
	 *
	 * A fixed choice passes through. 'system' (the Automatic setting) is
	 * resolved HERE, from the page: the fields sit inside the store's own
	 * layout, so light-or-dark is the PAGE's fact, not the shopper's
	 * device — a light store must not turn its card fields dark because
	 * the shopper's laptop is. The check is the one Stripe's plugin also
	 * takes from the page (theme: isColorLight(background) ? light :
	 * night, client/styles/upe/index.js:505), and nothing more: the first
	 * opaque background above the fields, judged by tinycolor's
	 * brightness formula, their isColorLight. Anything unreadable
	 * answers light, the way most stores are.
	 *
	 * @param {string} mode 'light', 'dark', or 'system'.
	 * @param {?Object} node The mount point, where the walk starts.
	 * @return {string} 'light' or 'dark'.
	 */
	function resolveColorMode( mode, node ) {
		if ( 'light' === mode || 'dark' === mode ) {
			return mode;
		}
		try {
			var el = node;
			while ( el ) {
				var rgb = opaqueColor( window.getComputedStyle( el ).backgroundColor );
				if ( rgb ) {
					return isDark( rgb ) ? 'dark' : 'light';
				}
				el = el.parentElement;
			}
			var body = opaqueColor( window.getComputedStyle( window.document.body ).backgroundColor );
			return body && isDark( body ) ? 'dark' : 'light';
		} catch ( e ) {
			return 'light';
		}
	}

	/**
	 * An rgb triple when the CSS color is opaque, null when it is
	 * transparent (keep walking) or unreadable.
	 *
	 * @param {string} css Computed background-color.
	 * @return {?number[]} [r, g, b] or null.
	 */
	function opaqueColor( css ) {
		var match = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([0-9.]+)\s*)?\)$/.exec( String( css || '' ) );
		if ( ! match ) {
			return null;
		}
		if ( undefined !== match[ 4 ] && parseFloat( match[ 4 ] ) < 0.5 ) {
			return null; // Mostly see-through: whatever is behind it decides.
		}
		return [ parseInt( match[ 1 ], 10 ), parseInt( match[ 2 ], 10 ), parseInt( match[ 3 ], 10 ) ];
	}

	/**
	 * tinycolor's brightness test, the same one behind Stripe's
	 * isColorLight: perceived brightness below 128 of 255 is dark.
	 *
	 * @param {number[]} rgb [r, g, b].
	 * @return {boolean} True when the color reads as dark.
	 */
	function isDark( rgb ) {
		return ( rgb[ 0 ] * 299 + rgb[ 1 ] * 587 + rgb[ 2 ] * 114 ) / 1000 < 128;
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
	 * @param {number}   options.amount           Amount to DISPLAY and charge,
	 *                                            in minor units.
	 * @param {string}   options.currency         Currency of that amount.
	 * @param {string[]} [options.paymentMethodTypes] Restrict the fields to
	 *                                            these method types (one per
	 *                                            checkout row). Absent =
	 *                                            every enabled method.
	 * @param {string}   [options.layout]         'accordion' or 'tabs'. A row
	 *                                            that already draws the
	 *                                            method's logo and title
	 *                                            passes 'tabs' with one
	 *                                            method type, and the fields
	 *                                            render the form alone.
	 * @param {string}   options.publishableKey   Merchant publishable key.
	 * @param {string}   options.sdkUrl           SDK script URL.
	 * @param {string}   options.colorMode        "system", "light" or "dark".
	 * @param {string}   [options.locale]         "en" or "ar".
	 * @param {Object}   [options.i18n]           Shopper-facing wording.
	 * @param {Function} options.onMethodChange   Called with the selected method.
	 * @param {Function} options.onReady          Called once fields can be filled.
	 * @param {Function} options.onError          Called with a shopper-facing message.
	 * @param {Function} options.onUnavailable    Called when the SDK cannot load.
	 * @return {Object} A handle with confirm(), check(), canPay(),
	 *                  setAmount() and destroy().
	 */
	XPayElements.mount = function ( options ) {
		var opts = options || {};
		var handle = {
			xpay: null,
			elements: null,
			element: null,
			selectedMethod: null,
			// Set the moment a payment is submitted, so a cart recalculation
			// racing a shopper who has already pressed Pay cannot move the
			// displayed amount out from under the charge they approved.
			paying: false,
			ready: false,
			// The last thing the element said about the shopper's progress
			// through the fields. Informational only: the element announces
			// it when something changes and says nothing at all about the
			// method it picks for itself, so its absence proves nothing and
			// it is never the reason a payment is refused. check() asks.
			complete: false,
			// The amount currently DISPLAYED, in minor units. The charge is
			// held to this: the server's session must total exactly it, or
			// nothing is charged. Moved only through setAmount().
			amount: 0,
			currency: '',
		};

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
		 * Bring up the payment form once the SDK is on the page.
		 *
		 * @param {Object} xpay The SDK instance.
		 */
		function start( xpay ) {
			if ( fired ) {
				return;
			}
			fired = true;
			handle.xpay = xpay;

			/*
			 * The merchant's theme choice, and nothing else. Everything
			 * beyond colorMode (colors, fonts, corners, spacing) comes
			 * from the merchant's XPay dashboard branding. The one
			 * decision Stripe's plugin also takes from the page (their
			 * theme pick, client/styles/upe/index.js:505): is the page
			 * behind the fields light or dark.
			 */
			var appearance = { colorMode: resolveColorMode( opts.colorMode, mountNode( opts ) ) };

			try {
				/*
				 * Deferred: an amount and a currency, no session. The SDK
				 * validates both up front and THROWS on a bad one (a
				 * non-integer amount, a zero, a missing currency) rather
				 * than resolving an error later, so this is wrapped.
				 *
				 * paymentMethodTypes restricts what the fields RENDER --
				 * one method per checkout row, so WooCommerce's own radio
				 * list is the selector. Narrow-only: the SDK intersects it
				 * with the account's enabled methods and fails with a
				 * loaderror (surfaced through onError) when nothing
				 * survives, never an empty frame. Absent = every enabled
				 * method, the single-row fallback.
				 */
				handle.elements = xpay.elements( {
					mode: 'payment',
					amount: handle.amount,
					currency: handle.currency,
					paymentMethodTypes: opts.paymentMethodTypes || undefined,
					appearance: appearance,
					locale: opts.locale || undefined,
				} );
				subscribe( handle.elements );

				// layout: 'tabs' with exactly one method type renders the form
				// CONTENT alone — no logo, no title, no chooser chrome — because
				// the page that chose it (a per-method checkout row) already
				// draws both. Absent = the SDK's accordion default.
				handle.element = handle.elements.create(
					'payment',
					opts.layout ? { layout: opts.layout } : undefined
				);

				handle.element.on( 'change', function ( event ) {
					// Stripe-compatible shape: the selected method rides on
					// value.type. This is the signal the ValU number prompt
					// hangs off, so it is reported even when it has not
					// changed, letting listeners stay stateless.
					var method = event && event.value ? event.value.type : null;
					handle.selectedMethod = method;
					// `complete` is the one flag the SDK forwards without a
					// default, so it is read for truthiness rather than
					// compared against true.
					handle.complete = !! ( event && event.complete );
					call( opts.onMethodChange, method, event );
				} );

				handle.element.on( 'ready', function () {
					handle.ready = true;
					call( opts.onReady );
				} );

				handle.element.on( 'loaderror', function ( event ) {
					call( opts.onError, messageFrom( event ) );
				} );

				handle.element.mount( mountNode( opts ) || opts.selector );
			} catch ( error ) {
				// The whole build, not only the constructor: create() and
				// mount() are SDK calls too, and a throw escaping this
				// callback would leave the row silently empty — no message,
				// no way for the page to fall back. Every failure ends at
				// the same two surfaces the page already handles.
				call( opts.onError, messageFrom( error ) );
				giveUpLate( error );
			}
		}

		/**
		 * Listen for failures nobody was awaiting.
		 *
		 * Only 'ready' is replayed to a listener that registers late, so a
		 * subscription made after mount() would miss whatever the element
		 * announced while it was loading.
		 *
		 * There is no session to subscribe to in deferred mode, so the
		 * session-change listener the session-first flow needed is gone:
		 * the only amount that exists is the one this page set, and it
		 * cannot move underneath the page.
		 *
		 * @param {Object} elements The Elements instance.
		 */
		function subscribe( elements ) {
			if ( 'function' !== typeof elements.on ) {
				return;
			}

			errorListener = function ( error ) {
				// A merchant config that will not load, a BIN lookup that
				// failed. There is no return value for these to arrive on,
				// so this is the only way the shopper hears about them.
				call( opts.onError, messageFrom( error ) );
			};

			elements.on( 'error', errorListener );
			elements.on( 'loaderror', errorListener );
		}

		/**
		 * Constructing the Elements instance failing after the script
		 * loaded is a different failure from the script never arriving,
		 * but the shopper's way out is the same one.
		 *
		 * @param {*} reason Whatever went wrong.
		 */
		function giveUpLate( reason ) {
			call( opts.onUnavailable, reason );
		}

		/**
		 * Whether a payment could be attempted at all.
		 *
		 * Deliberately only the element's half. Whether the shopper has
		 * filled in enough is not asked here, because the element only
		 * volunteers that on 'change' and it raises no change for the
		 * method it selects by itself when it loads. A shopper who accepts
		 * a default that needs nothing typed into it — ValU, Fawry,
		 * anything that is not a card — would otherwise be refused forever
		 * over a form that never asked them for anything. That half is
		 * settled by asking, in check().
		 *
		 * @return {boolean} True when a payment may be attempted.
		 */
		handle.canPay = function () {
			return !! handle.elements && handle.ready && handle.amount > 0;
		};

		/**
		 * Ask whether a payment may be attempted, and why not when it may not.
		 *
		 * What a page should call before it commits a shopper to anything.
		 * It costs one message to the element and no network at all, and
		 * unlike canPay() it puts the completeness question to the fields
		 * rather than repeating whatever they last happened to announce.
		 *
		 * @return {Promise<?string>} A reason to stop, or null to go ahead.
		 */
		handle.check = function () {
			/*
			 * `elements` exists as soon as the SDK loaded. `ready` is only
			 * set when the payment ELEMENT has actually mounted and
			 * announced itself. Between the two there is a real window — an
			 * instance that constructs but whose iframe never renders — in
			 * which an elements-only guard would say everything was fine
			 * with nothing on screen to type into.
			 */
			if ( ! handle.elements || ! handle.ready ) {
				return Promise.resolve( text( opts, 'notReady' ) );
			}
			if ( ! ( handle.amount > 0 ) ) {
				// Nothing to pay for. The page knows why (an empty cart, a
				// total covered by a coupon); this only refuses.
				return Promise.resolve( text( opts, 'unavailable' ) );
			}
			return preflight();
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
			if ( 'function' !== typeof handle.elements.submit ) {
				return Promise.resolve( null );
			}
			return handle.elements
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
		 * Confirm the payment against a session the server just created.
		 *
		 * The clientSecret arrives HERE rather than at mount: that is the
		 * whole of deferred mode. It is bound on submit, inside the same
		 * message that triggers the charge, so there is no window in which
		 * a session is bound but unconfirmed.
		 *
		 * CHARGE = DISPLAY is enforced on the way through. The embed
		 * refuses to bind a session whose customer-facing total differs
		 * from the amount displayed here, and the server refuses again
		 * inside /collect. Both answer `amount_reconfirmation_required`,
		 * which the caller surfaces by re-reading the total and letting the
		 * shopper approve the real one — never by charging the difference.
		 *
		 * @param {string} clientSecret    Session secret from the server.
		 * @param {Object} customerDetails Name, email, phone as collected.
		 * @return {Promise<Object>} Resolves { ok: true } or { ok: false, message }.
		 */
		handle.confirm = function ( clientSecret, customerDetails ) {
			if ( ! handle.elements || ! handle.ready ) {
				// A constructed instance is not a rendered form, and
				// confirming against an element that never mounted is how a
				// payment hangs with nothing on screen to explain it.
				return Promise.resolve( {
					ok: false,
					message: text( opts, 'notReady' ),
				} );
			}

			if ( ! clientSecret ) {
				// The server did not hand one back. Never confirm without
				// it: the SDK would refuse anyway, and the page's own
				// message is the one worth showing.
				return Promise.resolve( {
					ok: false,
					message: text( opts, 'unavailable' ),
				} );
			}

			handle.paying = true;

			// Validated here even when the caller already asked through
			// check(). That answer was true before the order was placed,
			// and this one is true immediately before the money moves,
			// which is the one that has to be right.
			return preflight()
				.then( function ( problem ) {
					if ( problem ) {
						handle.paying = false;
						return { ok: false, message: problem };
					}

					return withDeadline(
						handle.xpay.confirmPayment( {
							elements: handle.elements,
							clientSecret: clientSecret,
							customerDetails: customerDetails || {},
						} ),
						text( opts, 'confirmSlow' ) || text( opts, 'notCompleted' ),
						opts.confirmDeadlineMs || CONFIRM_DEADLINE_MS
					)
						.then( function ( result ) {
							if ( result && 'error' === result.type ) {
								handle.paying = false;
								// The code rides along for the log and for
								// the message. Nothing branches on it here:
								// a decline and a payment the platform could
								// not decide on arrive in the same shape, so
								// what happens next is the server's to say —
								// except amount_reconfirmation_required,
								// which the caller acts on directly.
								return {
									ok: false,
									code: result.error && result.error.code ? result.error.code : '',
									message: messageFrom( result.error )
								};
							}
							// Success keeps `paying` set: the browser is on its way
							// to the return URL and nothing should touch the amount
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
		 * Move the amount the element DISPLAYS.
		 *
		 * Zero API calls: no session exists yet, so this is a message to
		 * the iframe and nothing more. That is the whole reason the cart
		 * can move freely while the fields stay mounted and whatever the
		 * shopper typed survives.
		 *
		 * Refused while a payment is in flight. The shopper approved a
		 * number; moving it under an open charge is the failure this whole
		 * flow is built to prevent, and the server would refuse the
		 * mismatch anyway.
		 *
		 * @param {number} amount   Target amount in minor units.
		 * @param {string} currency Its currency.
		 * @return {Promise<Object>} Resolves { ok }.
		 */
		handle.setAmount = function ( amount, currency ) {
			if ( handle.paying ) {
				return Promise.resolve( { ok: false } );
			}
			if ( ! ( amount > 0 ) ) {
				return Promise.resolve( { ok: false } );
			}
			var next = String( currency || handle.currency ).toUpperCase();
			if ( amount === handle.amount && next === handle.currency ) {
				return Promise.resolve( { ok: true } );
			}

			// The SDK is still on its way: buffer the move instead of
			// dropping it. start() reads handle.amount at creation time, so
			// a cart that changed while the script loaded still mounts at
			// the current total rather than the one the page opened with.
			if ( ! handle.elements ) {
				handle.amount   = Math.round( amount );
				handle.currency = next;
				return Promise.resolve( { ok: true } );
			}

			try {
				// Synchronous in practice (the SDK resolves immediately and
				// posts the update), but it THROWS on a bad amount rather
				// than rejecting, so both are caught.
				return Promise.resolve( handle.elements.update( { amount: amount, currency: next } ) )
					.then( function () {
						handle.amount   = amount;
						handle.currency = next;
						return { ok: true };
					} )
					.catch( function ( error ) {
						return { ok: false, message: messageFrom( error ) };
					} );
			} catch ( error ) {
				return Promise.resolve( { ok: false, message: messageFrom( error ) } );
			}
		};

		handle.destroy = function () {
			try {
				if ( errorListener && handle.elements && 'function' === typeof handle.elements.off ) {
					handle.elements.off( 'error', errorListener );
					handle.elements.off( 'loaderror', errorListener );
				}
			} catch ( e ) {
				// Already gone.
			}
			errorListener = null;

			try {
				// destroy(), not unmount(): the whole instance goes, iframe
				// and bridge included. A remount builds a fresh one, and
				// leaving the old iframe alive would leave a second bridge
				// listening for messages meant for the new element.
				if ( handle.elements && 'function' === typeof handle.elements.destroy ) {
					handle.elements.destroy();
				} else if ( handle.element && handle.element.unmount ) {
					handle.element.unmount();
				}
			} catch ( e ) {
				// Already gone.
			}
			handle.xpay = null;
			handle.elements = null;
			handle.element = null;
			handle.ready = false;
			handle.complete = false;
			handle.paying = false;
		};

		handle.amount   = opts.amount > 0 ? Math.round( opts.amount ) : 0;
		handle.currency = String( opts.currency || '' ).toUpperCase();

		if ( ! handle.amount || ! handle.currency ) {
			// Nothing to display, so nothing to mount. Reported the same
			// way an SDK failure is: the page shows its own wording and
			// leaves the row unusable rather than mounting an empty form.
			giveUp( 'no-amount' );
			return handle;
		}

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

	/**
	 * What to tell the shopper when the server refuses to start a payment.
	 *
	 * Shared by both checkout drivers. It lived in each of them, identically,
	 * with a comment in both saying "keep this in step with the other one" —
	 * which is how the two checkouts end up telling a shopper different
	 * things about the same refusal.
	 *
	 * @param {Object} i18n   Localised strings from the page.
	 * @param {string} reason Refusal code from the endpoint.
	 * @return {string} Message to show.
	 */
	XPayElements.refusalMessage = function ( i18n, reason ) {
		var strings = i18n || {};
		if ( 'no-cart' === reason ) {
			return strings.emptyCart || strings.unavailable || '';
		}
		if ( 'amount_reconfirmation_required' === reason ) {
			// The server's session did not total what the element showed,
			// so nothing was charged. The page re-reads the total and the
			// shopper approves the real one.
			return strings.totalChanged || '';
		}
		return strings.unavailable || '';
	};

	/**
	 * Was this confirm clearly a success?
	 *
	 * The ONLY question the SDK's answer can settle. "Not ok" covers a card
	 * refused and a payment the platform could not decide on, and the two
	 * do arrive differently: the undecided one carries type `api_error`
	 * with code `payment_still_confirming`.
	 *
	 * That code is deliberately not read. It reports how one attempt ended
	 * in this browser, and what the page has to decide is what becomes of
	 * the WooCommerce order, which turns on whether the session at XPay is
	 * paid: the webhook may have settled it while this browser was still
	 * waiting. So whether a retry may be offered is the server's to answer,
	 * from the session (XPay_Checkout_Elements::verdict_for).
	 *
	 * @param {Object} outcome Result of XPayElements confirm().
	 * @return {boolean} Whether the payment is known to have succeeded.
	 */
	XPayElements.confirmed = function ( outcome ) {
		return !! ( outcome && outcome.ok );
	};

	/**
	 * How long to wait before asking the server a second time.
	 *
	 * CHOSEN, not derived. An unreachable API is usually a blip, and one
	 * question asked twice a moment apart turns most of them into a real
	 * answer — which matters because the fallback is deliberately the
	 * cautious one, and the fewer shoppers who meet it the better. Long
	 * enough to outlast a dropped packet, short enough that nobody watching
	 * a spinner reads it as a hang.
	 */
	XPayElements.RECHECK_DELAY_MS = 2000;

	/**
	 * Ask again, once, when the first answer was "cannot say".
	 *
	 * Only for `unknown`. A verdict of paid or unpaid is XPay's word and
	 * asking twice would not improve it.
	 *
	 * @param {Function} ask   Returns a Promise of a verdict string.
	 * @param {Function} [wait] Scheduler, for tests. Defaults to setTimeout.
	 * @return {Promise<string>} The best verdict of the two.
	 */
	XPayElements.settleVerdict = function ( ask, wait ) {
		var sleep = wait || function ( done ) {
			window.setTimeout( done, XPayElements.RECHECK_DELAY_MS );
		};

		// Called through a resolved promise, not directly: an asker that
		// throws SYNCHRONOUSLY would otherwise escape past the catch below,
		// and the one thing this must never do is fail in a way the caller
		// reads as anything but "cannot say".
		return Promise.resolve()
			.then( ask )
			.then( function ( first ) {
				if ( 'unknown' !== first ) {
					return first;
				}
				return new Promise( function ( resolve ) {
					sleep( function () {
						resolve(
							Promise.resolve().then( ask ).catch( function () {
								return 'unknown';
							} )
						);
					} );
				} );
			} )
			.catch( function () {
				return 'unknown';
			} );
	};

	/**
	 * What to do about a confirm that was not clearly a success.
	 *
	 * @param {string} verdict The server's answer: paid, unpaid or unknown.
	 * @return {string} 'paid', 'failed' (a retry is safe) or 'pending'.
	 */
	XPayElements.outcomeKind = function ( verdict ) {
		if ( 'paid' === verdict ) {
			return 'paid';
		}
		// Only a verdict of 'unpaid' — XPay certain no money moved, on a
		// session that can still be paid — may offer the button back.
		// Everything else, an unreachable API included, goes to the order
		// page rather than inviting a second charge.
		return 'unpaid' === verdict ? 'failed' : 'pending';
	};

	window.XPayElements = XPayElements;

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = XPayElements;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
