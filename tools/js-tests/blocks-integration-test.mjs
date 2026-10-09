import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = ( name ) => readFileSync( new URL( '../../assets/js/' + name, import.meta.url ), 'utf8' );

function boot( { submitResult = { selectedPaymentMethod: 'apple_pay' }, confirmResult = { type: 'success' } } = {} ) {
	const effects = [];
	const callbacks = {};
	const instances = [];
	const confirms = [];
	const errors = [];
	let row;
	const element = {
		useRef: ( current ) => ( { current } ),
		useState: ( initial ) => [ initial, ( value ) => errors.push( value ) ],
		useEffect: ( effect ) => effects.push( effect ),
		createElement: ( type, props, ...children ) => {
			if ( props && props.ref ) { props.ref.current = {}; }
			return { type, props, children };
		},
	};
	const xpay = {
		elements: () => {
			const handlers = {};
			const state = { destroyed: false, submitted: 0 };
			instances.push( state );
			return {
				on() {}, off() {}, update() {},
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
	row.content.type( {
		...row.content.props, eventRegistration,
		emitResponse: { responseTypes: { SUCCESS: 'success', ERROR: 'error' } },
		billing: { cartTotal: { value: 29000 }, currency: { code: 'EGP' } },
	} );
	const cleanups = effects.map( ( effect ) => effect() );
	return { callbacks, instances, confirms, errors, unmount: () => cleanups.forEach( ( fn ) => fn && fn() ) };
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
