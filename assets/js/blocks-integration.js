/**
 * XPay Cart/Checkout Blocks integration.
 *
 * Renders a radio-button payment-method picker inside WC's block-based
 * checkout. The selected value is forwarded as paymentMethodData, which
 * WC blocks injects into $_POST before calling the gateway's
 * process_payment() — so the existing PHP flow works without changes.
 */
( function () {
	if ( ! window.wp || ! window.wp.element || ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings ) {
		return;
	}

	var createElement = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decodeEntities = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;

	var settings = getSetting( 'xpay_gateway_data', {} );
	var methods = ( settings.methods && settings.methods.length ) ? settings.methods : [ 'CARD' ];

	// Display labels for the canonical XPay method codes.
	var LABELS = {
		CARD: 'Card',
		FAWRY: 'Fawry',
		VALU: 'valU',
		'MEEZA/DIGITAL': 'Wallets',
		APPLE: 'Apple Pay',
		Installment: 'NBE Installments'
	};

	// Maps XPay's upstream method codes to the internal keys process_payment
	// expects. Mirrors xpay_normalize_method_code() in utils.php — keep in
	// sync. Without this MEEZA/DIGITAL would post 'meeza/digital', which
	// sanitize_text_field accepts but no payment_config key matches.
	var METHOD_KEY_MAP = {
		CARD: 'card',
		FAWRY: 'fawry',
		APPLE: 'apple',
		VALU: 'valu',
		'MEEZA/DIGITAL': 'wallets',
		Installment: 'installment'
	};

	function methodKey( upstream ) {
		if ( METHOD_KEY_MAP[ upstream ] ) { return METHOD_KEY_MAP[ upstream ]; }
		return String( upstream || '' ).toLowerCase().replace( /[^a-z0-9_]/g, '' );
	}

	function Label() {
		return createElement( 'span', null, decodeEntities( settings.title || 'XPay Payment' ) );
	}

	function Content( props ) {
		var initial = methodKey( methods[0] );
		var selectedTuple = useState( initial );
		var selected = selectedTuple[0];
		var setSelected = selectedTuple[1];

		var installmentTuple = useState( '' );
		var installmentPlan = installmentTuple[0];
		var setInstallmentPlan = installmentTuple[1];

		// Forward the selection as paymentMethodData. WC blocks injects
		// these keys into $_POST before calling process_payment() on the
		// server, so the classic PHP flow works without changes.
		useEffect( function () {
			if ( ! props.eventRegistration || ! props.eventRegistration.onPaymentSetup ) {
				return;
			}
			var unsubscribe = props.eventRegistration.onPaymentSetup( function () {
				return {
					type: props.emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							xpay_payment_method: selected,
							xpay_selected_installment_plan: installmentPlan
						}
					}
				};
			} );
			return unsubscribe;
		}, [ selected, installmentPlan, props.eventRegistration, props.emitResponse ] );

		var children = [];
		if ( settings.description ) {
			children.push(
				createElement( 'p', { key: 'desc', style: { marginBottom: '12px' } },
					decodeEntities( settings.description )
				)
			);
		}
		methods.forEach( function ( m ) {
			var value = methodKey( m );
			children.push(
				createElement( 'label', {
					key: m,
					style: { display: 'block', marginBottom: '6px', cursor: 'pointer' }
				},
					createElement( 'input', {
						type: 'radio',
						name: 'xpay_payment_method_blocks',
						value: value,
						checked: selected === value,
						onChange: function () { setSelected( value ); },
						style: { marginRight: '8px' }
					} ),
					LABELS[ m ] || m
				)
			);
		} );

		// Installment-period picker only appears when "installment" is selected.
		if ( selected === 'installment' ) {
			children.push(
				createElement( 'div', { key: 'inst', style: { marginTop: '12px' } },
					createElement( 'label', { style: { display: 'block', marginBottom: '4px' } }, 'Installment period (months)' ),
					createElement( 'input', {
						type: 'number',
						min: '1',
						value: installmentPlan,
						onChange: function ( e ) { setInstallmentPlan( e.target.value ); },
						style: { width: '120px' }
					} )
				)
			);
		}

		return createElement( 'div', { className: 'xpay-blocks-payment' }, children );
	}

	registerPaymentMethod( {
		name: 'xpay_gateway',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () { return true; },
		ariaLabel: settings.title || 'XPay Payment',
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
} )();
