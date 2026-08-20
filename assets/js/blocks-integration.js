/**
 * Cart & Checkout Blocks registration for the XPay checkout row.
 *
 * Deliberately build-less plain JS (no JSX/webpack): a build step would be
 * tooling overhead with no shopper benefit, and WP.org review favors
 * reviewable, unminified source.
 *
 * One row now. There used to be an optional row per payment method so a
 * shopper could pick Card or valU before a window opened; Elements makes
 * that redundant, because XPay's own fields render the method accordion
 * inside this row, listing exactly what the merchant's XPay account has
 * enabled.
 *
 * WHAT IS DIFFERENT ABOUT BLOCKS
 *
 * Blocks owns the DOM and re-renders freely, and the payment fields live
 * in an iframe that cannot survive being moved. So the mount point is a
 * ref'd node that React is told never to touch, and the fields are mounted
 * into it once and torn down only when the row genuinely unmounts.
 *
 * Blocks also owns the Place Order button, so the payment runs inside
 * onPaymentSetup: the money is arranged first, and the order is only
 * created once it has been.
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
	 * Ask one of the plugin's endpoints. Same three as classic checkout.
	 *
	 * @param {string} action Endpoint suffix.
	 * @return {Promise<Object>} { ok, json }
	 */
	function ask( action ) {
		var form = new window.FormData();
		form.append( 'action', 'xpay_elements_' + action );
		form.append( 'nonce', params.nonce );
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
	function labelElement( title, iconUrl ) {
		if ( ! iconUrl ) {
			return title;
		}
		return createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
			title,
			createElement( 'img', {
				src: iconUrl,
				alt: '',
				style: { height: '20px', width: 'auto' },
			} )
		);
	}

	/**
	 * The row's body: XPay's fields, and the valU number prompt when the
	 * shopper picks a method that charges a registered mobile.
	 *
	 * @param {Object} props Blocks passes eventRegistration and
	 *                       emitResponse in; settings is bound per row.
	 */
	function RowContent( props ) {
		var settings = props.settings;
		var copy = params.bnplPhone || {};

		var mountRef = element.useRef( null );
		var handleRef = element.useRef( null );

		var methodState = element.useState( '' );
		var method = methodState[ 0 ];
		var setMethod = methodState[ 1 ];

		var numberState = element.useState( '' );
		var number = numberState[ 0 ];
		var setNumber = numberState[ 1 ];

		var errorState = element.useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		// Mount once. The dependency list is deliberately empty: the fields
		// live in an iframe, and re-running this would throw away whatever
		// the shopper has typed into it.
		element.useEffect( function () {
			var cancelled = false;

			ask( 'session' ).then( function ( result ) {
				if ( cancelled || ! result.ok || ! result.json || ! result.json.success ) {
					setError( ( params.i18n && params.i18n.unavailable ) || '' );
					return;
				}
				if ( ! window.XPayElements || ! mountRef.current ) {
					return;
				}
				handleRef.current = window.XPayElements.mount( {
					node: mountRef.current,
					clientSecret: result.json.data.clientSecret,
					publishableKey: params.publishableKey,
					sdkUrl: params.sdkUrl,
					colorMode: params.colorMode,
					onMethodChange: function ( picked ) {
						setMethod( picked || '' );
					},
					onReady: function () {
						setError( '' );
					},
					onError: function ( message ) {
						setError( message || '' );
					},
					onUnavailable: function () {
						setError( ( params.i18n && params.i18n.unavailable ) || '' );
					},
				} );
			} );

			return function () {
				cancelled = true;
				if ( handleRef.current ) {
					handleRef.current.destroy();
					handleRef.current = null;
				}
			};
		}, [] );

		// Run the payment before Blocks creates the order. Success here
		// means the money is arranged; a failure keeps the shopper on the
		// page with a reason.
		element.useEffect(
			function () {
				var registration = props.eventRegistration;
				var emit = props.emitResponse;
				if ( ! registration || ! registration.onPaymentSetup || ! emit ) {
					return undefined;
				}

				return registration.onPaymentSetup( function () {
					if ( ! handleRef.current ) {
						return {
							type: emit.responseTypes.ERROR,
							message: ( params.i18n && params.i18n.unavailable ) || '',
						};
					}

					return ask( 'paying' )
						.then( function ( locked ) {
							if ( ! locked.ok || ! locked.json || ! locked.json.success ) {
								var reason = locked.json && locked.json.data && locked.json.data.reason;
								throw new Error(
									'stale-amount' === reason
										? ( params.i18n && params.i18n.totalChanged ) || ''
										: ( params.i18n && params.i18n.unavailable ) || ''
								);
							}
							return handleRef.current.confirm( { phone: number || undefined } );
						} )
						.then( function ( outcome ) {
							return ask( 'paid' ).then( function () {
								return outcome;
							} );
						} )
						.then( function ( outcome ) {
							if ( outcome && outcome.ok ) {
								return { type: emit.responseTypes.SUCCESS };
							}
							return {
								type: emit.responseTypes.ERROR,
								message:
									( outcome && outcome.message ) ||
									( params.i18n && params.i18n.notCompleted ) ||
									'',
							};
						} )
						.catch( function ( thrown ) {
							return ask( 'paid' ).then( function () {
								return {
									type: emit.responseTypes.ERROR,
									message:
										( thrown && thrown.message ) ||
										( params.i18n && params.i18n.notCompleted ) ||
										'',
								};
							} );
						} );
				} );
			},
			[ props.eventRegistration, props.emitResponse, number ]
		);

		var needsNumber = !! ( copy.methods && copy.methods.indexOf( method ) !== -1 );

		var children = [
			createElement( 'div', {
				key: 'mount',
				ref: mountRef,
				className: 'xpay-el__mount',
			} ),
		];

		if ( error ) {
			children.push(
				createElement(
					'p',
					{ key: 'error', role: 'alert', style: { margin: '8px 0 0' } },
					error
				)
			);
		}

		if ( needsNumber ) {
			children.push(
				createElement(
					'div',
					{ key: 'bnpl', className: 'xpay-bnpl-phone', style: { marginTop: '12px' } },
					createElement(
						'label',
						{
							htmlFor: copy.field || 'xpay_bnpl_phone',
							style: { display: 'block', fontWeight: 600, marginBottom: '4px' },
						},
						decodeEntities( copy.label || '' )
					),
					createElement(
						'p',
						{ style: { margin: '0 0 8px' } },
						decodeEntities( ( number || copy.prefill ) ? copy.whyKnown || '' : copy.whyMissing || '' )
					),
					createElement( 'input', {
						type: 'tel',
						id: copy.field || 'xpay_bnpl_phone',
						name: copy.field || 'xpay_bnpl_phone',
						inputMode: 'tel',
						autoComplete: 'tel',
						placeholder: copy.placeholder || '',
						value: number,
						onChange: function ( event ) {
							setNumber( event.target.value );
						},
						style: { width: '100%' },
					} )
				)
			);
		}

		return createElement( element.Fragment, null, children );
	}

	var settings = getSetting( ( params.gatewayId || 'xpay' ) + '_data', null );
	if ( ! settings ) {
		return;
	}

	var title = decodeEntities( settings.title || 'XPay' );
	var description = decodeEntities( settings.description || '' );

	registerPaymentMethod( {
		name: params.gatewayId || 'xpay',
		label: labelElement( title, settings.icon ),
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
} )();
