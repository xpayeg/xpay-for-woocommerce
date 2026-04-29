/**
 * XPay Cart/Checkout Blocks integration.
 *
 * Renders a radio-button payment-method picker and (when the merchant
 * community has it enabled) a promo-code input inside WC's block-based
 * checkout. The selected method is forwarded as paymentMethodData, which
 * WC blocks injects into $_POST before calling the gateway's
 * process_payment() — so the existing PHP flow works without changes.
 *
 * Promo flow:
 *  - Apply hits the existing xpay_validate_promo_code admin-ajax handler
 *    (shared with the classic checkout), which atomically writes the
 *    validated promocode_id + discount value into the WC session.
 *  - The cart-fee hook xpay_apply_promo_fee_to_cart then surfaces the
 *    discount as a negative WC cart fee on the next cart recalculation,
 *    so we just invalidate the wc/store/cart resolver and let the block
 *    UI re-fetch totals — no DOM manipulation needed.
 *  - Switching payment method (or unmounting) clears the session via
 *    xpay_clear_promo_details and refreshes the cart so the discount
 *    line goes away.
 */
( function () {
	if ( ! window.wp || ! window.wp.element || ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings ) {
		return;
	}

	var createElement = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var useCallback = window.wp.element.useCallback;
	var useRef = window.wp.element.useRef;
	var decodeEntities = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;

	var settings = getSetting( 'xpay_gateway_data', {} );
	var methods = ( settings.methods && settings.methods.length ) ? settings.methods : [ 'CARD' ];
	var promoStrings = settings.promo_strings || {};
	var allowPromoCode = ! ! settings.allow_promo_code;

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

	// Best-effort fire-and-forget log post. Silent on failure — diagnostics
	// must never break checkout. The PHP handler is a silent no-op when the
	// merchant has the diagnostic logger disabled.
	function logBlocksEvent( eventName, details ) {
		if ( ! settings.log_endpoint || ! settings.log_nonce || ! window.fetch ) {
			return;
		}
		try {
			var fd = new FormData();
			fd.append( 'action',  'xpay_log_blocks_event' );
			fd.append( 'nonce',   settings.log_nonce );
			fd.append( 'event',   String( eventName || 'unknown' ) );
			fd.append( 'href',    window.location ? window.location.href : '' );
			if ( details ) {
				fd.append( 'details', typeof details === 'string' ? details : JSON.stringify( details ) );
			}
			window.fetch( settings.log_endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				body: fd,
				keepalive: true
			} ).catch( function () {} );
		} catch ( e ) { /* noop */ }
	}

	// Read the cart total in major currency units. wc/store/cart returns
	// totals as integer strings in the currency's minor unit (e.g. piasters
	// for EGP, cents for USD); divide by 10^minor_unit to get the major-unit
	// number XPay's promo validate endpoint expects.
	function readCartTotalMajor() {
		try {
			var cart = window.wp.data && window.wp.data.select( 'wc/store/cart' );
			if ( ! cart ) { return 0; }
			var totals = cart.getCartTotals && cart.getCartTotals();
			if ( ! totals || typeof totals.total_price === 'undefined' ) { return 0; }
			var minor = parseInt( totals.currency_minor_unit, 10 );
			if ( isNaN( minor ) || minor < 0 ) { minor = 2; }
			var raw = parseInt( totals.total_price, 10 );
			if ( isNaN( raw ) ) { return 0; }
			return raw / Math.pow( 10, minor );
		} catch ( e ) {
			return 0;
		}
	}

	function readBillingPhone() {
		try {
			var cart = window.wp.data && window.wp.data.select( 'wc/store/cart' );
			if ( ! cart || ! cart.getCustomerData ) { return ''; }
			var customer = cart.getCustomerData();
			return ( customer && customer.billingAddress && customer.billingAddress.phone ) || '';
		} catch ( e ) {
			return '';
		}
	}

	// Force the block cart to re-fetch totals so the negative XPay promo fee
	// added server-side by xpay_apply_promo_fee_to_cart shows up (or the
	// removed fee disappears) without the customer having to nudge any field.
	function refreshCart() {
		try {
			var dispatch = window.wp.data && window.wp.data.dispatch( 'wc/store/cart' );
			if ( ! dispatch ) { return; }
			if ( typeof dispatch.invalidateResolutionForStoreSelector === 'function' ) {
				dispatch.invalidateResolutionForStoreSelector( 'getCartData' );
				return;
			}
			if ( typeof dispatch.invalidateResolution === 'function' ) {
				dispatch.invalidateResolution( 'getCartData' );
			}
		} catch ( e ) { /* noop */ }
	}

	// Posts to admin-ajax with the same form encoding the classic
	// checkout.js uses. Returns a promise resolving to the parsed JSON
	// or rejecting on transport failure.
	function postAjax( action, fields ) {
		if ( ! settings.ajax_url || ! settings.promo_nonce ) {
			return Promise.reject( new Error( 'xpay-blocks: ajax wiring missing' ) );
		}
		var body = new URLSearchParams();
		body.set( 'action',   action );
		body.set( 'security', settings.promo_nonce );
		Object.keys( fields || {} ).forEach( function ( k ) {
			if ( fields[ k ] !== undefined && fields[ k ] !== null ) {
				body.set( k, String( fields[ k ] ) );
			}
		} );
		return window.fetch( settings.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( res ) { return res.json(); } );
	}

	function clearPromoOnServer() {
		return postAjax( 'xpay_clear_promo_details', {} ).catch( function () { /* swallow */ } );
	}

	function Label() {
		return createElement( 'span', null, decodeEntities( settings.title || 'XPay Payment' ) );
	}

	function PromoSection( props ) {
		var open = useState( false );
		var isOpen = open[0];
		var setOpen = open[1];

		var input = useState( '' );
		var promoCode = input[0];
		var setPromoCode = input[1];

		var applying = useState( false );
		var isApplying = applying[0];
		var setApplying = applying[1];

		var applied = useState( false );
		var isApplied = applied[0];
		var setApplied = applied[1];

		var msg = useState( null );
		var message = msg[0];
		var setMessage = msg[1];

		var apply = useCallback( function () {
			var trimmed = ( promoCode || '' ).trim();
			if ( ! trimmed ) {
				setMessage( { text: promoStrings.empty || 'Please enter a promo code', isError: true } );
				return;
			}
			var phone = readBillingPhone();
			if ( ! phone ) {
				setMessage( { text: promoStrings.phone_first || 'Enter your billing phone number first.', isError: true } );
				return;
			}

			setApplying( true );
			setMessage( null );

			var fields = {
				name:               trimmed,
				amount:             readCartTotalMajor(),
				currency:           ( props.requestData && props.requestData.currency ) || '',
				phone_number:       phone,
				payment_for:        'API_PAYMENT',
				community_id:       ( props.requestData && props.requestData.community_id ) || '',
				variable_amount_id: ( props.requestData && props.requestData.variable_amount_id ) || ''
			};

			postAjax( 'xpay_validate_promo_code', fields ).then( function ( response ) {
				setApplying( false );
				if ( response && response.success ) {
					setApplied( true );
					setMessage( { text: promoStrings.applied || 'Promocode applied successfully', isError: false } );
					refreshCart();
					logBlocksEvent( 'promo.apply.success', {
						code_len: trimmed.length,
						value: response.data && response.data.value
					} );
				} else {
					var errText = ( response && response.data && response.data.message )
						? response.data.message
						: ( promoStrings.invalid || 'Invalid promo code' );
					setMessage( { text: errText, isError: true } );
					logBlocksEvent( 'promo.apply.error', { reason: errText } );
				}
			} ).catch( function ( err ) {
				setApplying( false );
				setMessage( { text: promoStrings.invalid || 'Invalid promo code', isError: true } );
				logBlocksEvent( 'promo.apply.transport_error', { err: ( err && err.message ) || 'unknown' } );
			} );
		}, [ promoCode, props.requestData ] );

		// External resets — fired when the parent payment-method radio changes
		// or when the customer re-types in the input. resetSignal increments
		// in the parent so this effect re-runs even with the same value.
		useEffect( function () {
			if ( ! props.resetSignal ) { return; }
			setPromoCode( '' );
			setMessage( null );
			setApplied( false );
			setOpen( false );
			clearPromoOnServer().then( refreshCart );
		}, [ props.resetSignal ] );

		// Clean up on unmount: drop any session promo so a customer who
		// switches gateway radios after applying a discount doesn't leave
		// the discount fee active on the cart. Using a ref so the cleanup
		// reads the latest isApplied without re-running on every change
		// (which would double-clear alongside the resetSignal effect).
		var appliedRef = useRef( false );
		useEffect( function () { appliedRef.current = isApplied; }, [ isApplied ] );
		useEffect( function () {
			return function () {
				if ( appliedRef.current ) {
					clearPromoOnServer().then( refreshCart );
				}
			};
		}, [] );

		var children = [];
		children.push(
			createElement( 'button', {
				key: 'toggle',
				type: 'button',
				onClick: function () { setOpen( ! isOpen ); },
				style: {
					backgroundColor: '#f8f9fa',
					color: '#272265',
					border: '2px dashed #272265',
					padding: '8px 15px',
					borderRadius: '4px',
					cursor: 'pointer',
					width: '100%',
					textAlign: 'center',
					fontWeight: 500
				}
			},
				isOpen ? ( promoStrings.hide || 'Hide Promo Code' ) : ( promoStrings.show || 'Have Xpay Promo Code?' )
			)
		);

		if ( isOpen ) {
			children.push(
				createElement( 'div', {
					key: 'fields',
					style: { marginTop: '10px', display: 'flex', gap: '8px', alignItems: 'center' }
				},
					createElement( 'input', {
						type: 'text',
						value: promoCode,
						placeholder: promoStrings.placeholder || 'Enter promo code',
						onChange: function ( e ) { setPromoCode( e.target.value ); },
						disabled: isApplying,
						style: { flex: 1, minWidth: 0, padding: '6px 8px' }
					} ),
					createElement( 'button', {
						type: 'button',
						onClick: apply,
						disabled: isApplying,
						style: {
							backgroundColor: '#272265',
							color: 'white',
							border: 'none',
							padding: '8px 15px',
							borderRadius: '4px',
							cursor: isApplying ? 'wait' : 'pointer'
						}
					},
						isApplying ? ( promoStrings.applying || 'Validating...' ) : ( promoStrings.apply || 'Apply' )
					)
				)
			);
			if ( message ) {
				children.push(
					createElement( 'p', {
						key: 'msg',
						style: { color: message.isError ? '#b00020' : '#1b873b', marginTop: '8px' }
					}, message.text )
				);
			}
		}

		return createElement( 'div', {
			className: 'xpay-promo-code-container',
			style: { marginBottom: '12px' }
		}, children );
	}

	function Content( props ) {
		var initial = methodKey( methods[0] );
		var selectedTuple = useState( initial );
		var selected = selectedTuple[0];
		var setSelected = selectedTuple[1];

		var installmentTuple = useState( '' );
		var installmentPlan = installmentTuple[0];
		var setInstallmentPlan = installmentTuple[1];

		// Bumped whenever the selected payment method changes, to signal
		// PromoSection to clear its local state + the server session.
		var resetTuple = useState( 0 );
		var resetSignal = resetTuple[0];
		var bumpReset = resetTuple[1];

		var changeMethod = useCallback( function ( value ) {
			setSelected( value );
			bumpReset( function ( n ) { return n + 1; } );
		}, [] );

		// Forward the selection as paymentMethodData. WC blocks injects
		// these keys into $_POST before calling process_payment() on the
		// server, so the classic PHP flow works without changes.
		useEffect( function () {
			if ( ! props.eventRegistration || ! props.eventRegistration.onPaymentSetup ) {
				return;
			}
			var unsubscribe = props.eventRegistration.onPaymentSetup( function () {
				// Block submission when installment is selected without a
				// period — otherwise an empty xpay_selected_installment_plan
				// reaches process_payment, the period match in the
				// installment_fees loop fails silently, and the customer
				// is charged with no installment plan applied.
				if ( selected === 'installment' && ! installmentPlan ) {
					return {
						type: props.emitResponse.responseTypes.ERROR,
						message: 'Please select an installment period before continuing.'
					};
				}
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

		// Promo section renders above the radio list, mirroring the classic
		// payment_fields() ordering.
		if ( allowPromoCode ) {
			children.push( createElement( PromoSection, {
				key: 'promo',
				requestData: settings.promo_request_data || {},
				resetSignal: resetSignal
			} ) );
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
						onChange: function () { changeMethod( value ); },
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
