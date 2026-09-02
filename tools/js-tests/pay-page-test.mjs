/**
 * The order-pay driver (assets/js/pay-page.js), exercised as a whole file.
 *
 * What it must get right: the session comes from the server at Pay (the
 * one-per-order discipline lives there), a stale already-paid link goes to
 * the receipt and never near a charge, a decline keeps the shopper here
 * with the reason, and WooCommerce's own form submit is intercepted — a
 * plain POST would navigate and destroy the card iframe mid-payment.
 *
 * Run: node --test tools/js-tests/pay-page-test.mjs
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const here = dirname( fileURLToPath( import.meta.url ) );
const driverSource = readFileSync( join( here, '../../assets/js/pay-page.js' ), 'utf8' );

/*
 * The REAL library's verdict helpers, so the tests assert against the
 * shipped rules rather than a copy of them.
 */
const elementsSource = readFileSync( join( here, '../../assets/js/checkout-elements.js' ), 'utf8' );
const realLibrary = ( () => {
	const box = { window: { setTimeout }, module: { exports: {} } };
	box.globalThis = box;
	vm.createContext( box );
	vm.runInContext( elementsSource, box );
	return box.window.XPayElements;
} )();

/** A node just deep enough for the driver's queries. */
function node() {
	return {
		textContent: '',
		hidden: false,
		listeners: {},
		addEventListener( name, fn ) {
			this.listeners[ name ] = fn;
		},
	};
}

function boot( { confirmResult = { ok: true }, checkProblem = '', ajax = {} } = {} ) {
	const calls = [];
	const confirms = [];
	const navigations = [];
	const reloads = [];

	const mountNode = node();
	const errorNode = node();
	const form = node();
	const payButton = node();
	const radio = { value: 'xpay' };

	const nodes = {
		'[data-xpay-elements]': mountNode,
		'[data-xpay-elements-error]': errorNode,
		'[data-xpay-pay]': payButton,
		'input[name="payment_method"]:checked': radio,
	};

	const handle = {
		amount: 29000,
		check: () => Promise.resolve( checkProblem ),
		confirm: ( clientSecret, details ) => {
			confirms.push( { clientSecret, details } );
			return Promise.resolve( confirmResult );
		},
		destroy: () => {},
	};

	const window = {
		xpayPayPageParams: {
			ajaxUrl: '/admin-ajax.php',
			nonce: 'n',
			publishableKey: 'pk_test_x',
			sdkUrl: 'https://checkout.xpay.app/v1/sdk.js',
			gatewayId: 'xpay',
			amount: '29000',
			currency: 'EGP',
			orderId: '9',
			orderKey: 'wc_order_k',
			returnUrl: 'https://store.test/order-received/9/',
			customer: { name: 'Mo S', email: 'a@b.test' },
			i18n: {
				unavailable: 'unavailable',
				notCompleted: 'not completed',
				notReady: 'not ready',
				retry: 'reload to retry',
			},
		},
		XPayElements: {
			mount: ( opts ) => {
				calls.push( [ 'mount', opts.amount, opts.currency ] );
				return handle;
			},
			confirmed: realLibrary.confirmed,
			outcomeKind: realLibrary.outcomeKind,
			settleVerdict: realLibrary.settleVerdict,
			refusalMessage: realLibrary.refusalMessage,
		},
		FormData: class {
			constructor() {
				this.entries = [];
			}
			append( k, v ) {
				this.entries.push( [ k, v ] );
			}
		},
		fetch: ( url, options ) => {
			const action = ( options.body.entries.find( ( e ) => 'action' === e[ 0 ] ) || [] )[ 1 ];
			calls.push( action );
			const scripted = ajax[ action ];
			const body = scripted || { success: true, data: { verdict: 'unpaid' } };
			return Promise.resolve( { ok: true, json: () => Promise.resolve( body ) } );
		},
		setTimeout,
		clearTimeout,
	};
	Object.defineProperty( window, 'location', {
		set: ( url ) => navigations.push( url ),
		// reload is real on the browser's location; a stub without it would
		// turn the driver's reload into a TypeError the catch reads as an
		// undecided outcome — a fake more agreeable than the real thing.
		get: () => ( { reload: () => reloads.push( true ) } ),
	} );

	const document = {
		readyState: 'complete',
		querySelector: ( sel ) => nodes[ sel ] || null,
		// The driver freezes the Pay controls through this while an
		// attempt runs; the harness's one button is the whole page's.
		querySelectorAll: ( sel ) => ( -1 !== sel.indexOf( 'data-xpay-pay' ) ? [ payButton ] : [] ),
		getElementById: ( id ) => ( 'order_review' === id ? form : null ),
		addEventListener: () => {},
	};

	const sandbox = { window, document, Promise, setTimeout, clearTimeout, console };
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( driverSource, sandbox );

	return { calls, confirms, navigations, reloads, errorNode, form, payButton, radio, nodes };
}

const settle = () => new Promise( ( resolve ) => setImmediate( resolve ) );

async function press( boot ) {
	let prevented = false;
	boot.form.listeners.submit( { preventDefault: () => ( prevented = true ) } );
	for ( let i = 0; i < 10; i++ ) {
		await settle();
	}
	return prevented;
}

const SESSION_ANSWER = { success: true, data: { paid: false, clientSecret: 'cs_ops_secret' } };

test( 'the form submit is intercepted and the session comes from the server at Pay', async () => {
	const b = boot( { ajax: { xpay_elements_order_session: SESSION_ANSWER } } );

	const prevented = await press( b );

	assert.ok( prevented, 'A plain form POST would navigate and destroy the card iframe mid-payment.' );
	assert.ok( b.calls.includes( 'xpay_elements_order_session' ), 'The session must be asked for at Pay, never mounted with one.' );
	assert.equal( b.confirms[ 0 ].clientSecret, 'cs_ops_secret' );
	assert.deepEqual( b.navigations, [ 'https://store.test/order-received/9/' ] );
} );

test( 'the element mounts at the order total with no server call', async () => {
	const b = boot();
	await settle();

	assert.deepEqual( b.calls, [ [ 'mount', 29000, 'EGP' ] ], 'Rendering the page must cost zero API calls.' );
} );

test( 'a stale already-paid link goes to the receipt and never near a charge', async () => {
	const b = boot( {
		ajax: {
			xpay_elements_order_session: {
				success: true,
				data: { paid: true, redirect: 'https://store.test/order-received/9/?key=k' },
			},
		},
	} );

	await press( b );

	assert.equal( b.confirms.length, 0, 'A paid order must never be charged again.' );
	assert.deepEqual( b.navigations, [ 'https://store.test/order-received/9/?key=k' ] );
} );

test( 'a decline keeps the shopper here with the reason', async () => {
	const b = boot( {
		confirmResult: { ok: false, message: 'card declined' },
		ajax: {
			xpay_elements_order_session: SESSION_ANSWER,
			xpay_elements_outcome: { success: true, data: { verdict: 'unpaid' } },
		},
	} );

	await press( b );

	assert.deepEqual( b.navigations, [], 'A declined shopper was sent to a thank-you page.' );
	assert.equal( b.errorNode.textContent, 'card declined' );
	assert.equal( b.payButton.disabled, false, 'XPay is certain nothing moved, so the Pay button comes back.' );
} );

test( 'a total that changed under the page reloads it instead of refusing forever', async () => {
	const b = boot( {
		confirmResult: { ok: false, code: 'amount_reconfirmation_required', message: 'amount changed' },
		ajax: { xpay_elements_order_session: SESSION_ANSWER },
	} );

	await press( b );

	assert.equal( b.reloads.length, 1, 'The reload is this page\'s re-read of the total; without it every retry meets the same refusal.' );
	assert.deepEqual( b.navigations, [], 'Nothing was charged, so the shopper must not be sent to the order page.' );
	assert.ok( ! b.calls.includes( 'xpay_elements_outcome' ), 'A refusal the SDK names needs no server verdict.' );
} );

test( 'an undecided outcome goes to the order page instead of offering a retry', async () => {
	const b = boot( {
		confirmResult: { ok: false, message: 'network wobble' },
		ajax: {
			xpay_elements_order_session: SESSION_ANSWER,
			xpay_elements_outcome: { success: true, data: { verdict: 'unknown' } },
		},
	} );

	await press( b );
	// 'unknown' is asked a second time after the library's recheck delay
	// before anything is decided; wait it out.
	for ( let waited = 0; 0 === b.navigations.length && waited < 4000; waited += 50 ) {
		await new Promise( ( r ) => setTimeout( r, 50 ) );
	}

	assert.deepEqual(
		b.navigations,
		[ 'https://store.test/order-received/9/' ],
		'When the money may have moved, a retry could charge twice; the webhook settles it.'
	);
} );

test( 'a refused check never asks for a session', async () => {
	const b = boot( { checkProblem: 'Finish the payment details.' } );

	await press( b );

	assert.ok( ! b.calls.includes( 'xpay_elements_order_session' ) );
	assert.equal( b.errorNode.textContent, 'Finish the payment details.' );
} );

test( 'the customer details from the order reach the confirm', async () => {
	const b = boot( { ajax: { xpay_elements_order_session: SESSION_ANSWER } } );

	await press( b );

	assert.deepEqual( b.confirms[ 0 ].details, { name: 'Mo S', email: 'a@b.test' } );
} );

test( 'another gateway selected leaves the submit to WooCommerce', async () => {
	const b = boot();
	b.radio.value = 'cod';

	let prevented = false;
	b.form.listeners.submit( { preventDefault: () => ( prevented = true ) } );
	await settle();

	assert.ok( ! prevented, 'A COD order must submit the way core intends.' );
} );
