import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = ( name ) => readFileSync( new URL( '../../assets/js/' + name, import.meta.url ), 'utf8' );

const EGP_BILLING = { cartTotal: { value: 29000 }, currency: { code: 'EGP', minorUnit: 2 } };
const EGP_CART = { extensions: { xpayeg: { amount: 29000, currency: 'EGP' } } };

function boot( {
	submitResult = { selectedPaymentMethod: 'apple_pay' },
	confirmResult = { type: 'success' },
	billing = EGP_BILLING,
	cartData = EGP_CART,
} = {} ) {
	// A small hook runtime: slots persist across renders, and an effect
	// re-runs only when its dependencies change, as React's do.
	const slots = [];
	let slot = 0;
	let pending = [];
	const callbacks = {};
	const instances = [];
	const confirms = [];
	const errors = [];
	let row;
	const element = {
		useRef: ( current ) => {
			const i = slot++;
			slots[ i ] = slots[ i ] || { current };
			return slots[ i ];
		},
		useState: ( initial ) => {
			const i = slot++;
			slots[ i ] = slots[ i ] || [ initial, ( value ) => errors.push( value ) ];
			return slots[ i ];
		},
		useEffect: ( effect, deps ) => {
			const i = slot++;
			const prev = slots[ i ];
			if ( ! prev || ! deps || deps.some( ( dep, k ) => dep !== prev.deps[ k ] ) ) {
				pending.push( { i, effect } );
			}
			slots[ i ] = { deps, cleanup: prev && prev.cleanup };
		},
		createElement: ( type, props, ...children ) => {
			if ( props && props.ref ) { props.ref.current = {}; }
			return { type, props, children };
		},
	};
	const mounts = [];
	const updates = [];
	const xpay = {
		elements: ( options ) => {
			mounts.push( options );
			const handlers = {};
			const state = { destroyed: false, submitted: 0 };
			instances.push( state );
			return {
				on() {}, off() {}, update: ( options ) => { updates.push( options ); },
				submit: async () => { state.submitted++; return submitResult; },
				destroy: () => { state.destroyed = true; },
				create: () => ( {
					on: ( event, callback ) => { handlers[ event ] = callback; },
					mount: () => handlers.ready(),
				} ),
			};
		},
		confirmPayment: async ( args ) => { confirms.push( args ); return confirmResult; },
	};
	const window = {
		wp: { element, htmlEntities: { decodeEntities: ( text ) => text } },
		wc: {
			wcBlocksRegistry: { registerPaymentMethod: ( registration ) => { row = registration; } },
			wcSettings: { getSetting: () => ( { method: 'apple_pay', title: 'Apple Pay' } ) },
		},
		xpayegElementsParams: {
			rows: [ 'xpayeg_apple_pay' ], amount: 29000, currency: 'EGP', walletTypes: [ 'apple_pay' ],
			publishableKey: 'pk_test_example', i18n: { unavailable: 'unavailable', notReady: 'not ready' },
		},
		XPay: () => xpay,
		setTimeout, clearTimeout,
	};
	const box = { window, document: {}, Promise, setTimeout, clearTimeout };
	vm.createContext( box );
	vm.runInContext( source( 'checkout-elements.js' ), box );
	vm.runInContext( source( 'blocks-integration.js' ), box );
	const eventRegistration = Object.fromEntries(
		[ 'onPaymentSetup', 'onCheckoutSuccess', 'onCheckoutFail' ].map( ( event ) => [
			event, ( callback ) => { callbacks[ event ] = callback; return () => { delete callbacks[ event ]; }; },
		] )
	);
	const base = {
		...row.content.props, eventRegistration,
		emitResponse: { responseTypes: { SUCCESS: 'success', ERROR: 'error' } },
	};
	const render = ( props ) => {
		slot = 0;
		pending = [];
		row.content.type( { ...base, ...props } );
		pending.forEach( ( { i, effect } ) => {
			if ( slots[ i ].cleanup ) { slots[ i ].cleanup(); }
			slots[ i ].cleanup = effect();
		} );
	};
	render( { billing, cartData } );
	return {
		callbacks, instances, confirms, errors, mounts, updates,
		rerender: render,
		unmount: () => slots.forEach( ( s ) => s && s.cleanup && s.cleanup() ),
	};
}

const success = { processingResponse: { paymentDetails: { xpayeg_confirm: 'yes', xpayeg_secret: 'cs_test_secret' } } };

test( 'Blocks closes prepared fields on checkout failure and retries using new fields', async () => {
	const b = boot();
	assert.equal( ( await b.callbacks.onPaymentSetup() ).type, 'success' );
	b.callbacks.onCheckoutFail();
	assert.equal( b.instances[ 0 ].destroyed, true );
	assert.equal( b.instances.length, 2 );
	assert.equal( b.confirms.length, 0 );
	b.callbacks.onCheckoutFail();
	assert.equal( b.instances.length, 2 );
	assert.equal( ( await b.callbacks.onPaymentSetup() ).type, 'success' );
	assert.equal( ( await b.callbacks.onCheckoutSuccess( success ) ).type, 'success' );
	assert.equal( b.confirms[ 0 ].clientSecret, 'cs_test_secret' );
	b.callbacks.onCheckoutFail();
	assert.equal( b.instances[ 1 ].destroyed, false, 'A confirmed payment must not be reset by a later failure event.' );
	b.unmount();
	assert.deepEqual( Object.keys( b.callbacks ), [] );
	assert.equal( b.instances[ 1 ].destroyed, true );
} );

test( 'Blocks refuses preparation errors without creating or confirming a session', async () => {
	const b = boot( { submitResult: { error: { type: 'api_error', message: 'Preparation failed' } } } );
	const result = await b.callbacks.onPaymentSetup();
	assert.equal( result.type, 'error' );
	assert.equal( result.message, 'Preparation failed' );
	b.callbacks.onCheckoutFail();
	assert.equal( b.instances.length, 1 );
	assert.equal( b.confirms.length, 0 );
} );

test( 'Blocks closes prepared fields before a server-owned redirect with no secret', async () => {
	const b = boot();
	await b.callbacks.onPaymentSetup();
	assert.equal( ( await b.callbacks.onCheckoutSuccess( {} ) ).type, 'success' );
	assert.equal( b.instances[ 0 ].destroyed, true );
	assert.equal( b.confirms.length, 0 );
} );

test( 'Blocks keeps the typed card when a card checkout fails', async () => {
	const b = boot( { submitResult: { selectedPaymentMethod: 'card' } } );
	assert.equal( ( await b.callbacks.onPaymentSetup() ).type, 'success' );
	b.callbacks.onCheckoutFail();
	assert.equal( b.instances.length, 1 );
	assert.equal( b.instances[ 0 ].destroyed, false );
} );

const mountedWith = ( billing, cartData ) => boot( { billing, cartData } ).mounts[ 0 ];

test( 'Blocks mounts with the amount the server converted, not Blocks\' own total', () => {
	// A store set to 0 decimals: Blocks reports EGP 349 as 349.
	const mount = mountedWith(
		{ cartTotal: { value: 349 }, currency: { code: 'EGP', minorUnit: 0 } },
		{ extensions: { xpayeg: { amount: 34900, currency: 'EGP' } } }
	);
	assert.equal( mount.amount, 34900 );
	assert.equal( mount.currency, 'EGP' );
} );

test( 'Blocks falls back to the page amount when the cart carries none', () => {
	const billing = { cartTotal: { value: 349 }, currency: { code: 'EGP', minorUnit: 0 } };
	assert.equal( mountedWith( billing, { extensions: { xpayeg: { amount: null, currency: 'EGP' } } } ).amount, 29000 );
	assert.equal( mountedWith( billing, { extensions: {} } ).amount, 29000, 'Never Blocks\' total in the store decimals.' );
	assert.equal( mountedWith( billing, null ).amount, 29000 );
} );

test( 'Blocks moves the mounted fields with each cart change, without mounting again', () => {
	const cart = ( amount, currency ) => ( { extensions: { xpayeg: { amount, currency } } } );
	const b = boot( { cartData: cart( 34900, 'EGP' ) } );
	assert.equal( b.mounts.length, 1 );

	b.rerender( { billing: EGP_BILLING, cartData: cart( 39900, 'EGP' ) } );
	b.rerender( { billing: EGP_BILLING, cartData: cart( 39900, 'USD' ) } );
	b.rerender( { billing: EGP_BILLING, cartData: cart( 39900, 'USD' ) } );

	assert.equal( b.mounts.length, 1, 'A cart change must keep what the shopper typed.' );
	assert.deepEqual(
		b.updates.map( ( u ) => [ u.amount, u.currency ] ),
		[ [ 39900, 'EGP' ], [ 39900, 'USD' ] ],
		'An amount-only and a currency-only change each move the fields once; an unchanged cart moves nothing.'
	);
} );
