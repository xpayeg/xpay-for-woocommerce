/**
 * Cart & Checkout Blocks registration for the XPay checkout row.
 *
 * Deliberately build-less plain JS (no JSX/webpack): a build step would be
 * tooling overhead with no shopper benefit, and WP.org review favors
 * reviewable, unminified source.
 *
 * One row per payment method the account can charge: Card, ValU, Fawry
 * each register as their own Blocks payment method, and each mounts the
 * fields restricted to its method — Blocks' own radio list is the
 * selector, the same structure Stripe's plugin uses. Before the account
 * map is cached, a single "XPay" fallback row renders every method
 * inside the fields.
 *
 * WHAT IS DIFFERENT ABOUT BLOCKS
 *
 * Blocks owns the DOM and re-renders freely, and the payment fields live
 * in an iframe that cannot survive being moved. So the mount point is a
 * ref'd node that React is told never to touch, and the fields are mounted
 * into it once and torn down only when the row genuinely unmounts.
 *
 * Blocks also owns the Place Order button, and it emits twice. The split
 * between those two emits is what keeps a card from being charged before
 * there is an order to charge it for:
 *
 *   onPaymentSetup    — lock the amount. No money moves.
 *   onCheckoutSuccess — the order exists and process_payment has stamped
 *                       its id onto the session. Confirm here.
 *
 * Charging in onPaymentSetup is what produced the double-charge, the
 * webhook that could not find its order, and the missing order number.
 * The full reasoning, and why onCheckoutSuccess can abort the redirect,
 * is at the handlers themselves.
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp ) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var element = window.wp.element;
	var createElement = element.createElement;

	var params = window.xpayElementsParams || {};

	/**
	 * Ask one of the plugin's endpoints. The same set classic checkout uses.
	 *
	 * @param {string} action  Endpoint suffix.
	 * @param {Object} [extra] Extra fields to post.
	 * @return {Promise<Object>} { ok, json }
	 */
	function ask( action, extra ) {
		var form = new window.FormData();
		form.append( 'action', 'xpay_elements_' + action );
		form.append( 'nonce', params.nonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			form.append( key, extra[ key ] );
		} );
		return window
			.fetch( params.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: form,
			} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, json: json };
				} );
			} );
	}

	/**
	 * @param {string} title   Row title.
	 * @param {string} iconUrl Optional logo.
	 * @return {Object|string} The label Blocks renders.
	 */

	function labelElement( title, iconUrl, method ) {
		if ( ! iconUrl ) {
			return title;
		}
		// Stripe's label shape: the label spans the whole row and the brand
		// mark floats to its far end, so every row reads name-left,
		// mark-right (their blocks stylesheet does the same to their span).
		// Fawry's roundel gets optical compensation because a disc carries
		// less visual mass than the wide sibling marks at the same height.
		var iconStyle = { float: 'right', maxHeight: '24px', width: 'auto' };
		if ( 'fawry' === method ) {
			iconStyle.transform = 'scale(1.25)';
			iconStyle.transformOrigin = 'right center';
		}
		return createElement(
			'span',
			{ style: { width: '100%', display: 'block' } },
			title,
			createElement( 'img', {
				src: iconUrl,
				alt: '',
				style: iconStyle,
			} )
		);
	}

	/**
	 * Fail the checkout, with a notice only when we have something to add.
	 *
	 * An ERROR carrying an empty message is NOT silent: Blocks fills the gap
	 * with its own "Something went wrong. Please contact us to get
	 * assistance." So a shopper whose card XPay had already declined — in
	 * the fields, in its own words — got that generic banner above it as
	 * well. One accurate message and one useless one.
	 *
	 * Omitting the property entirely IS silent, and still fails the payment.
	 * Verified in a browser against Blocks: with `message` present the text
	 * renders at the top of the checkout; with the key absent, no banner
	 * appears at all.
	 *
	 * So: say something, or say nothing. Never say nothing loudly.
	 *
	 * @param {Object} emit    Blocks' emitResponse.
	 * @param {string} message Reason to show, or empty to leave it to XPay.
	 * @return {Object} Blocks error response.
	 */
	function fail( emit, message ) {
		return message
			? { type: emit.responseTypes.ERROR, message: message }
			: { type: emit.responseTypes.ERROR };
	}

	/**
	 * The row's body: XPay's fields.
	 *
	 * @param {Object} props Blocks passes eventRegistration and
	 *                       emitResponse in; settings is bound per row.
	 */
	function RowContent( props ) {
		var settings = props.settings;

		var mountRef = element.useRef( null );
		var handleRef = element.useRef( null );

		/**
		 * The cart total Blocks is currently showing, in minor units.
		 *
		 * Blocks hands the row its own billing data, so this needs no
		 * server round trip and no DOM scraping: `cartTotal.value` is
		 * already minor units, which is the unit the element wants.
		 *
		 * @return {number} Minor units, or 0 when it cannot be read.
		 */
		function cartAmount() {
			var total = props.billing && props.billing.cartTotal ? props.billing.cartTotal.value : null;
			var amount = parseInt( total, 10 );
			if ( ! isNaN( amount ) && amount > 0 ) {
				return amount;
			}
			amount = parseInt( params.amount, 10 );
			return amount > 0 ? amount : 0;
		}

		/** The currency Blocks is pricing in, falling back to the store's. */
		function cartCurrency() {
			var currency = props.billing && props.billing.currency ? props.billing.currency.code : null;
			return String( currency || params.currency || '' ).toUpperCase();
		}

		var errorState = element.useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		// Mount once. The dependency list is deliberately empty: the fields
		// live in an iframe, and re-running this would throw away whatever
		// the shopper has typed into it.
		element.useEffect( function () {
			var cancelled = false;

			if ( ! window.XPayElements || ! mountRef.current ) {
				setError( ( params.i18n && params.i18n.unavailable ) || '' );
				return undefined;
			}

			/*
			 * Deferred: an amount and a currency, no session and no server
			 * call at all. The session is created by process_payment once
			 * the order exists, and its secret arrives on the checkout
			 * response.
			 */
			handleRef.current = window.XPayElements.mount( {
				node: mountRef.current,
				// One method per row: the row's own type restricts what
				// the fields render, and Blocks' radio list is the
				// selector. '' (the single-row fallback) mounts unfiltered.
				paymentMethodTypes: settings.method ? [ settings.method ] : undefined,
				// The row already draws the method's logo and title, so the
				// fields render the form alone; the fallback row keeps the
				// accordion, which is the whole selector there.
				layout: settings.method ? 'tabs' : undefined,
				amount: cartAmount(),
				currency: cartCurrency(),
				publishableKey: params.publishableKey,
				sdkUrl: params.sdkUrl,
				colorMode: params.colorMode,
				locale: params.locale,
				i18n: params.i18n,
				onReady: function () {
					if ( ! cancelled ) {
						setError( '' );
					}
				},
				onError: function ( message ) {
					if ( ! cancelled ) {
						setError( message || ( params.i18n && params.i18n.unavailable ) || '' );
					}
				},
				onUnavailable: function () {
					if ( ! cancelled ) {
						setError( ( params.i18n && params.i18n.unavailable ) || '' );
					}
				},
			} );

			return function () {
				cancelled = true;
				if ( handleRef.current ) {
					handleRef.current.destroy();
					handleRef.current = null;
				}
			};
		}, [] );

		/*
		 * Blocks recalculates the cart without rebuilding this row, so the
		 * amount the element displays has to follow. This costs NO API
		 * call: there is no session, so it is one message to the iframe,
		 * and whatever the shopper has typed survives it.
		 *
		 * The library refuses the move once a payment is in flight, which
		 * is what the old server-side payment lock existed to do.
		 */
		var cartTotal = props.billing && props.billing.cartTotal ? props.billing.cartTotal.value : null;
		element.useEffect(
			function () {
				if ( handleRef.current ) {
					handleRef.current.setAmount( cartAmount(), cartCurrency() );
				}
			},
			[ cartTotal ]
		);

		/*
		 * THE ORDER THIS BLOCK EXISTS TO GET RIGHT
		 *
		 * The card is charged AFTER the order exists, not before.
		 *
		 * So the work is split across the two events Blocks gives us:
		 *
		 *   onPaymentSetup   — lock the amount and say the payment is ready.
		 *                      No money moves.
		 *   onCheckoutSuccess — the order now exists and process_payment has
		 *                      stamped its id onto the open session. Confirm.
		 *
		 * onCheckoutSuccess is awaited by Blocks and can abort the redirect
		 * by returning an error response (wc-blocks-data.js — the
		 * CHECKOUT_SUCCESS emit is `emitWithAbort(...).then(...)` and only
		 * then does it complete), which is exactly the control a confirm
		 * that fails needs.
		 */


		/*
		 * SUBSCRIBE ONCE. THE DEPENDENCY LIST IS EMPTY ON PURPOSE.
		 *
		 * `props.eventRegistration` and `props.emitResponse` are NEW OBJECTS
		 * on every render. Listing them as dependencies means the effect
		 * re-runs on every single render,
		 * tearing down and re-registering the payment callbacks each time.
		 *
		 * With only onPaymentSetup registered that was merely wasteful.
		 * Adding onCheckoutSuccess made it fatal: registering it triggers a
		 * render, which re-runs the effect, which registers again — React
		 * gives up with "Maximum update depth exceeded" and the checkout
		 * freezes with no payment fields.
		 *
		 * Both subscription functions come off the same module-level emitter
		 * singleton (blocks-checkout-events.js), so the copy captured on the
		 * first render is as good as any later one. Anything the callbacks
		 * need that CAN change is read through a ref instead.
		 */
		element.useEffect(
			function () {
				var registration = props.eventRegistration;
				var emit = props.emitResponse;
				if ( ! registration || ! registration.onPaymentSetup || ! registration.onCheckoutSuccess || ! emit ) {
					return undefined;
				}

				var unsubscribeSetup = registration.onPaymentSetup( function () {
					if ( ! handleRef.current ) {
						return fail( emit, ( params.i18n && params.i18n.unavailable ) || '' );
					}

					/*
					 * Validation only. Nothing is created, locked or
					 * charged here: no session exists yet, so there is no
					 * amount on the platform for a moving cart to
					 * invalidate. The old server-side payment lock existed
					 * to freeze one, and its job now belongs to the
					 * library, which refuses to move the displayed amount
					 * once a payment is in flight.
					 *
					 * Asked rather than remembered: the fields report their
					 * state when something changes and say nothing at all
					 * about the method they select for themselves, so what
					 * they last announced is not an answer.
					 */
					return handleRef.current.check().then( function ( problem ) {
						if ( problem ) {
							return fail( emit, problem );
						}
						return { type: emit.responseTypes.SUCCESS };
					} );
				} );

				var unsubscribeSuccess = registration.onCheckoutSuccess( function ( data ) {
					var details =
						data && data.processingResponse && data.processingResponse.paymentDetails
							? data.processingResponse.paymentDetails
							: {};
					var strings = params.i18n || {};

					/**
					 * Fail the checkout with a reason, or with XPay's own.
					 *
					 * @param {string} message Reason to show.
					 * @return {Object} Blocks error response.
					 */
					function stop( message ) {
						// No fallback text on purpose. When XPay declined the
						// payment it has already said so in its own fields,
						// and a second sentence from us adds nothing. Only a
						// reason XPay did not give is worth a banner.
						return fail( emit, message );
					}

					// No secret came back: the order exists but this page was
					// given nothing to confirm against (already paid, or a
					// fallback the server owns). Let Blocks redirect.
					if ( 'yes' !== details.xpay_confirm || ! details.xpay_secret ) {
						return { type: emit.responseTypes.SUCCESS };
					}

					if ( ! handleRef.current ) {
						return stop( strings.unavailable );
					}

					// Blocks' own processing state covers the page from here
					// until it redirects, the same in-flight UI every other
					// gateway gets; the SDK draws its own overlay for a 3DS
					// or redirect challenge.

					return handleRef.current.confirm( details.xpay_secret, customerDetails() )
						.then( function ( outcome ) {
							/*
							 * Charge = display, refused. The session the
							 * server made does not total what these fields
							 * showed, so NOTHING was charged. The cart moved
							 * between mounting and paying; show the real
							 * number and let the shopper approve it. Never
							 * retried automatically: that would charge a
							 * total nobody agreed to.
							 */
							if ( outcome && 'amount_reconfirmation_required' === outcome.code ) {
								if ( handleRef.current ) {
									handleRef.current.setAmount( cartAmount(), cartCurrency() );
								}
								return stop( window.XPayElements.refusalMessage( strings, outcome.code ) );
							}

							return Promise.resolve()
								.then( function () {
									// A clean success settles itself; nothing
									// else does. "Not ok" from the SDK is a
									// decline and a payment the platform could
									// not decide on in the same shape, so the
									// server is asked which.
									if ( window.XPayElements.confirmed( outcome ) ) {
										return 'paid';
									}
									// Asked twice when the first answer is
									// "cannot say": an unreachable API is
									// usually a blip, and the fallback is
									// deliberately the cautious one.
									return window.XPayElements.settleVerdict( function () {
										return ask( 'outcome', {
											order: details.xpay_order_id || '',
											key: details.xpay_order_key || '',
										} ).then( function ( answer ) {
											return answer.ok && answer.json && answer.json.success
												? answer.json.data.verdict
												: 'unknown';
										} );
									} ).then( window.XPayElements.outcomeKind );
								} )
								.then( function ( kind ) {
									if ( 'paid' === kind ) {
										return { type: emit.responseTypes.SUCCESS };
									}

									// Undecided, not declined. The money may
									// well have moved, so the shopper goes to
									// their order and the webhook settles it.
									// Keeping them here with a red notice is
									// how somebody pays twice.
									if ( 'pending' === kind ) {
										return { type: emit.responseTypes.SUCCESS };
									}

									// XPay is certain nothing moved. An
									// ordinary WooCommerce state the shopper
									// can recover from: the SAME order and
									// the SAME session are waiting, so a
									// retry reuses both.
									return stop( outcome && outcome.message );
								} );
						} )
						.catch( function ( thrown ) {
							return stop( thrown && thrown.message );
						} );
				} );

				return function () {
					if ( unsubscribeSetup ) {
						unsubscribeSetup();
					}
					if ( unsubscribeSuccess ) {
						unsubscribeSuccess();
					}
				};
			},
			[]
		);

		/**
		 * What confirm() is told about the shopper.
		 *
		 * Nothing. Who the shopper is reaches XPay from the SERVER: the
		 * session is created carrying the order's name, email and phone
		 * (class-xpay-checkout-service.php, apply_customer_fields), so this
		 * checkout adds nothing at confirm time. The classic driver and the
		 * pay page do pass details with their confirms — the same values the
		 * order already carries, merged over the session's at collect — so
		 * the identity the payment records still starts server-side.
		 *
		 * @return {Object} Empty: confirm() takes the details from the order.
		 */
		function customerDetails() {
			return {};
		}

		var children = [
			createElement( 'div', {
				key: 'mount',
				ref: mountRef,
				className: 'xpay-el__mount',
			} ),
		];

		/*
		 * No pass-through fee line. It promised "you will see the exact
		 * amount before you pay", and the deferred flow cannot keep that:
		 * the platform prices the fee onto a SESSION, and none exists while
		 * the shopper fills the form. Charge = display keeps it from
		 * reappearing silently — a session inflated with a fee would no
		 * longer total what these fields displayed, so the confirmation
		 * would be refused rather than quietly charging more. The rest of
		 * the fee machinery goes in its own commit.
		 */

		if ( error ) {
			children.push(
				createElement(
					'p',
					{ key: 'error', role: 'alert', style: { margin: '8px 0 0' } },
					error
				)
			);
		}


		return createElement( element.Fragment, null, children );
	}

	/*
	 * One registration per checkout row: the Card row plus one per other
	 * method the account can charge, mirroring the classic per-method
	 * gateways. The row list rides on xpayElementsParams (built with the
	 * classic list, so the two can never disagree); each row's own data
	 * comes from its server-side registration. A row the server marked
	 * inactive for this currency has no data here and is skipped.
	 */
	( params.rows || [ params.gatewayId || 'xpay' ] ).forEach( function ( rowName ) {
		var settings = getSetting( rowName + '_data', null );
		if ( ! settings ) {
			return;
		}

		var title = decodeEntities( settings.title || 'XPay' );
		var description = decodeEntities( settings.description || '' );

		registerPaymentMethod( {
			name: rowName,
			label: labelElement( title, settings.icon, settings.method ),
			ariaLabel: title,
			content: createElement( RowContent, { settings: settings } ),
			// The editor preview has no cart and no shopper, so it shows the
			// row's description rather than trying to mount a payment form.
			edit: createElement( 'p', { style: { margin: 0 } }, description ),
			placeOrderButtonLabel: decodeEntities( settings.buttonLabel || '' ) || undefined,
			canMakePayment: function () {
				return true;
			},
			supports: {
				features: settings.supports || [ 'products' ],
			},
		} );
	} );
} )();
