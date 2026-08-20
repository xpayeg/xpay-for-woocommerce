/**
 * Cart & Checkout Blocks registration for the XPay checkout rows.
 *
 * Deliberately build-less plain JS (no JSX/webpack): each row is a label,
 * an optional logo, and a description — Blocks' standard redirect flow
 * does the rest, and a build step would be tooling overhead with no
 * shopper benefit. WP.org review also favors reviewable, unminified source.
 *
 * The PHP side registers one payment method type per active row (combined
 * XPay, or Card/valU/Fawry in split mode); each publishes its data under
 * '<gateway id>_data'. The candidate id list arrives from PHP too
 * (xpayBlocksRowIds, built from the method registry), so adding a method
 * server-side reaches Blocks without touching this file.
 *
 * The valU row also asks for the wallet number when the order does not
 * carry one that can be sent. Nothing here judges a phone number: whether
 * to ask is decided by XPay_Phone in PHP and published on the Store API
 * cart response, which Blocks refetches whenever the address changes. A
 * second copy of that rule in JavaScript would drift from the one that
 * gates the payment, and tell the shopper something the server disagrees
 * with.
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

	var ROW_IDS = window.xpayBlocksRowIds || [ 'xpay' ];

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
	 * The server's answer to "must this shopper be asked for a wallet
	 * number", read off the cart rather than worked out here.
	 */
	function useVerdict() {
		var data = window.wp.data;
		if ( ! data || ! data.useSelect ) {
			return null;
		}
		return data.useSelect( function ( select ) {
			var store = select( 'wc/store/cart' );
			if ( ! store || ! store.getCartData ) {
				return null;
			}
			var cart = store.getCartData();
			return cart && cart.extensions ? cart.extensions.xpay || null : null;
		}, [] );
	}

	/**
	 * A row's checkout body: its description, plus the wallet-number prompt
	 * when the server says one is needed.
	 *
	 * @param {Object} props Blocks passes billing, eventRegistration and
	 *                       emitResponse in; settings is bound per row.
	 */
	function RowContent( props ) {
		var settings = props.settings;
		var description = props.description;
		var copy = settings.walletPhone || {};
		var verdict = useVerdict();
		var state = element.useState( '' );
		var value = state[ 0 ];
		var setValue = state[ 1 ];

		var needed = !! ( settings.spendsWallet && verdict && verdict.walletPhoneNeeded );

		// Hand the typed number to the checkout request. Registered even
		// when nothing is being asked, so that a shopper who types a number
		// and then fixes their billing phone does not silently send the
		// leftover: an empty field sends nothing at all.
		element.useEffect(
			function () {
				var registration = props.eventRegistration;
				var emit = props.emitResponse;
				if ( ! registration || ! registration.onPaymentSetup || ! emit ) {
					return undefined;
				}
				return registration.onPaymentSetup( function () {
					var data = {};
					if ( settings.spendsWallet && value ) {
						data[ copy.field ] = value;
					}
					return {
						type: emit.responseTypes.SUCCESS,
						meta: { paymentMethodData: data },
					};
				} );
			},
			[ props.eventRegistration, props.emitResponse, value, settings.spendsWallet, copy.field ]
		);

		var children = [
			createElement( 'p', { key: 'desc', style: { margin: 0 } }, description ),
		];

		if ( needed ) {
			children.push(
				createElement(
					'div',
					{ key: 'wallet', className: 'xpay-wallet-phone', style: { marginTop: '12px' } },
					createElement(
						'label',
						{
							htmlFor: copy.field,
							style: { display: 'block', fontWeight: 600, marginBottom: '4px' },
						},
						decodeEntities( copy.label || '' )
					),
					createElement(
						'p',
						{ style: { margin: '0 0 8px' } },
						decodeEntities(
							verdict.hasBillingPhone ? copy.whyKnown || '' : copy.whyMissing || ''
						)
					),
					createElement( 'input', {
						type: 'tel',
						id: copy.field,
						name: copy.field,
						inputMode: 'tel',
						autoComplete: 'tel',
						placeholder: '01012345678',
						value: value,
						onChange: function ( event ) {
							setValue( event.target.value );
						},
						style: { width: '100%' },
					} )
				)
			);
		}

		return createElement( element.Fragment, null, children );
	}

	ROW_IDS.forEach( function ( id ) {
		var settings = getSetting( id + '_data', null );
		if ( ! settings ) {
			return;
		}

		var title = decodeEntities( settings.title || 'XPay' );
		var description = decodeEntities( settings.description || '' );

		registerPaymentMethod( {
			name: id,
			label: labelElement( title, settings.icon ),
			ariaLabel: title,
			content: createElement( RowContent, {
				settings: settings,
				description: description,
			} ),
			// The editor preview has no cart and no shopper, so it shows the
			// row as a shopper with nothing to correct would see it.
			edit: createElement( 'p', { style: { margin: 0 } }, description ),
			// Blocks swaps the Place Order label for this while the row is
			// selected — same string classic checkout shows via the
			// gateway's order_button_text.
			placeOrderButtonLabel:
				decodeEntities( settings.buttonLabel || '' ) || undefined,
			canMakePayment: function () {
				return true;
			},
			supports: {
				features: ( settings.supports || [ 'products' ] ),
			},
		} );
	} );
} )();
