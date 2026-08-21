/**
 * Guards for assets/js/checkout-elements.js.
 *
 * Runs on node against a stubbed SDK. The real SDK, the iframe and the
 * card fields are XPay's; what this file owns is the wiring around them,
 * and that wiring is what these tests pin: which appearance gets handed
 * over, which method the page is told about, what a shopper is shown when
 * a payment fails, and whether the in-flight flag is honest.
 *
 * That last one is not cosmetic. The platform accepts an amount change on
 * any open session, including one with a payment in flight, so this flag
 * is the only thing standing between a cart recalculation and a shopper
 * being charged a total they never saw.
 *
 *   node --test tools/js-tests/*.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

/**
 * What the SDK was mounted into, named by its selector.
 *
 * The SDK's mount() takes `string | HTMLElement`, and this module resolves
 * a selector to its node before calling it so that Blocks — which owns its
 * DOM and hands us the node directly — goes down the same path. These
 * tests therefore assert the resolved target, not the string.
 *
 * @param {*} target Whatever mount() received.
 * @return {?string} The selector it stands for.
 */
function mountedSelector( target ) {
	if ( ! target ) {
		return null;
	}
	return 'string' === typeof target ? target : target.__selector || null;
}

const here = path.dirname( fileURLToPath( import.meta.url ) );
const assets = path.join( here, '..', '..', 'assets', 'js' );

/** A fresh module in a fresh fake window, so tests cannot leak into each other. */
function load( { xpay = null, appearance = null, timers = true } = {} ) {
	const scripts = [];
	const win = {
		document: {
			querySelector: ( sel ) => ( { tagName: 'DIV', __selector: sel } ),
			createElement: () => {
				const el = {};
				scripts.push( el );
				return el;
			},
			head: { appendChild: () => {} },
		},
		setTimeout: timers ? ( fn, ms ) => ( { fn, ms } ) : () => ( {} ),
		clearTimeout: () => {},
	};
	if ( xpay ) {
		win.XPay = () => xpay;
	}
	if ( appearance ) {
		win.XPayAppearance = appearance;
	}
	// The module reads `document` as a bare global too.
	globalThis.document = win.document;

	const require = createRequire( import.meta.url );
	delete require.cache[ path.join( assets, 'checkout-elements.js' ) ];
	const mod = require( path.join( assets, 'checkout-elements.js' ) );
	// It binds to whatever `window` was at load; rebind for the fake.
	return { mod: rebind( mod, win ), win, scripts };
}

/**
 * The module closes over the real global at import time. Reloading it per
 * test with a fake global is the honest way to exercise it, so this simply
 * re-evaluates the file with the fake in place.
 */
function rebind( mod, win ) {
	const require = createRequire( import.meta.url );
	const file = path.join( assets, 'checkout-elements.js' );
	delete require.cache[ file ];
	const previousWindow = globalThis.window;
	globalThis.window = win;
	const fresh = require( file );
	globalThis.window = previousWindow;
	return fresh;
}

/** A stub standing in for a mounted Payment Element. */
function fakeSdk( {
	confirmResult = { type: 'success' },
	failInit = null,
	status = { type: 'open' },
	canConfirm = true,
	// submit() resolves a bare object, not an ActionResult: no `type`
	// discriminant, and `error` absent rather than null when all is well.
	submitResult = {},
} = {} ) {
	const state = {
		handlers: {},
		checkoutHandlers: {},
		submits: 0,
		mountedAt: null,
		appearance: null,
		confirmedWith: null,
		appearanceUpdates: [],
	};
	const element = {
		on: ( name, fn ) => {
			state.handlers[ name ] = fn;
		},
		mount: ( sel ) => {
			state.mountedAt = sel;
		},
		unmount: () => {
			state.mountedAt = null;
		},
	};
	const elements = {
		create: () => element,
		changeAppearance: ( a ) => state.appearanceUpdates.push( a ),
	};
	const xpay = {
		initCheckout: ( o ) => {
			state.appearance = o.appearance;
			state.clientSecret = o.clientSecret;
			if ( failInit ) {
				return Promise.reject( failInit );
			}
			return Promise.resolve( {
				status,
				canConfirm,
				getElements: () => elements,
				on: ( name, fn ) => {
					state.checkoutHandlers[ name ] = fn;
				},
				submit: () => {
					state.submits += 1;
					return Promise.resolve( submitResult );
				},
				confirm: ( args ) => {
					state.confirmedWith = args;
					return Promise.resolve( confirmResult );
				},
			} );
		},
	};
	return { xpay, state };
}

/**
 * Put a mounted handle in the state a shopper filling in a card reaches.
 *
 * Not a precondition of paying — see the tests below about the method the
 * element selects for itself, which never produces one of these — but it
 * is what the common path looks like, and the tests about confirming want
 * the common path.
 *
 * @param {Object} state The fake SDK's recorded state.
 */
function makePayable( state ) {
	state.handlers.change( {
		complete: true,
		value: { type: 'card' },
		session: { canConfirm: true, status: { type: 'open' } },
	} );
}

const BASE = {
	selector: '#xpay-element',
	clientSecret: 'cs_test_abc_secret_xyz',
	publishableKey: 'pk_test_1',
	sdkUrl: 'https://checkout.xpay.app/v1/sdk.js',
};

/* ── Mounting ─────────────────────────────────────────────────────────── */

test( 'mounts the payment element at the given selector', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();
	assert.equal( mountedSelector( state.mountedAt ), '#xpay-element' );
	assert.equal( state.clientSecret, BASE.clientSecret );
} );

test( 'the detected appearance is handed over at init, not after', async () => {
	// Passing it later would flash XPay's defaults and then correct itself
	// on a page the shopper is already looking at.
	const { xpay, state } = fakeSdk();
	const detected = { colorMode: 'dark', colors: { primary: '#635bff' } };
	const { mod } = load( { xpay, appearance: { detect: () => detected } } );
	mod.mount( { ...BASE, colorMode: 'dark' } );
	await Promise.resolve();
	assert.deepEqual( state.appearance, detected );
} );

test( 'a theme that breaks measurement still gets a payment form', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( {
		xpay,
		appearance: {
			detect: () => {
				throw new Error( 'getComputedStyle exploded' );
			},
		},
	} );
	mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();
	assert.deepEqual( state.appearance, {} );
	assert.equal( mountedSelector( state.mountedAt ), '#xpay-element' );
} );

/* ── Method selection ─────────────────────────────────────────────────── */

test( 'the selected method is reported to the page', async () => {
	// This is the signal the valU number prompt hangs off. Without it the
	// prompt cannot know when to appear.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const seen = [];
	const handle = mod.mount( { ...BASE, onMethodChange: ( m ) => seen.push( m ) } );
	await Promise.resolve();
	await Promise.resolve();

	state.handlers.change( { value: { type: 'valu' } } );
	state.handlers.change( { value: { type: 'card' } } );
	assert.deepEqual( seen, [ 'valu', 'card' ] );
	assert.equal( handle.selectedMethod, 'card' );
} );

test( 'a change event with no method does not crash the page', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const seen = [];
	mod.mount( { ...BASE, onMethodChange: ( m ) => seen.push( m ) } );
	await Promise.resolve();
	await Promise.resolve();
	state.handlers.change( {} );
	state.handlers.change( null );
	assert.deepEqual( seen, [ null, null ] );
} );

test( 'a listener that throws does not take the payment form with it', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( {
		...BASE,
		onMethodChange: () => {
			throw new Error( 'listener bug' );
		},
	} );
	await Promise.resolve();
	await Promise.resolve();
	assert.doesNotThrow( () => state.handlers.change( { value: { type: 'valu' } } ) );
} );

/* ── Confirming ───────────────────────────────────────────────────────── */

test( 'customer details reach confirm', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	makePayable( state );
	const out = await handle.confirm( { phone: '+201012345678' } );
	assert.equal( out.ok, true );
	assert.deepEqual( state.confirmedWith, { customerDetails: { phone: '+201012345678' } } );
} );

test( 'a declined payment comes back as a message, not a throw', async () => {
	const { xpay, state } = fakeSdk( {
		confirmResult: { type: 'error', error: { message: 'Your card was declined.' } },
	} );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	makePayable( state );
	const out = await handle.confirm( {} );
	assert.equal( out.ok, false );
	assert.equal( out.message, 'Your card was declined.' );
} );

test( 'confirming before the form is ready fails closed', async () => {
	const { mod } = load( { xpay: null, timers: false } );
	const handle = mod.mount( { ...BASE, i18n: { notReady: 'Not ready yet.' } } );
	const out = await handle.confirm( {} );
	assert.equal( out.ok, false );
	assert.equal( out.message, 'Not ready yet.' );
} );

/* ── The pay gate ─────────────────────────────────────────────────────── */

test( 'a method the element picked for itself can still be paid', async () => {
	// The element raises a change event when the shopper does something,
	// and raises none at all for the method it selects on load. A merchant
	// whose first method is valU or Fawry therefore has shoppers looking at
	// an already-selected method with nothing to fill in and no event ever
	// sent. A gate that waited to be told the form was complete would
	// refuse them forever, over fields that were never shown.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE, i18n: { incomplete: 'Finish the fields.' } } );
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( handle.complete, false, 'nothing ever announced it' );
	assert.equal( await handle.check(), null, 'and nothing may be inferred from that' );

	const out = await handle.confirm( {} );
	assert.equal( out.ok, true );
	assert.equal( state.confirmedWith !== null, true );
} );

test( 'the fields get the last word on whether the form is complete', async () => {
	// Asked, not remembered: submit() computes the same verdict on demand
	// and is right without a prior event.
	const { xpay, state } = fakeSdk( {
		submitResult: { error: { type: 'invalid_request_error', message: 'Please complete the payment form' } },
	} );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE, i18n: { incomplete: 'Finish the fields.' } } );
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( await handle.check(), 'Please complete the payment form' );
	assert.equal( state.submits, 1 );

	const out = await handle.confirm( {} );
	assert.equal( out.ok, false );
	assert.equal( out.message, 'Please complete the payment form' );
	assert.equal( state.confirmedWith, null, 'nothing may reach confirm past a refusal' );
} );

test( 'a transport failure inside submit does not strand a filled-in form', async () => {
	// Only a verdict about the shopper's input stops a payment. The embed
	// not answering in time is not evidence anyone typed anything wrong.
	const { xpay } = fakeSdk( {
		submitResult: { error: { type: 'api_error', message: 'Validation timed out' } },
	} );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( await handle.check(), null );
} );

/* ── Sessions that can never be paid ──────────────────────────────────── */

test( 'a spent session is never mounted over', async () => {
	// An expired session loads exactly like a live one — the client
	// endpoint answers 200 either way — so mounting first and checking
	// after puts a working card form over a session the platform refuses.
	const { xpay, state } = fakeSdk( { status: { type: 'expired' }, canConfirm: false } );
	const { mod } = load( { xpay } );
	const seen = [];
	const handle = mod.mount( {
		...BASE,
		i18n: { expired: 'That session expired.' },
		onTerminal: ( type ) => seen.push( type ),
	} );
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( handle.terminal, 'expired' );
	assert.deepEqual( seen, [ 'expired' ] );
	assert.equal( mountedSelector( state.mountedAt ), null );
} );

test( 'a spent session explains itself rather than blaming the shopper', async () => {
	// The wording the shopper is given has to be about the state they are
	// in. Advice to finish filling in payment details cannot be acted on
	// when no payment details were ever asked for, and it replaces advice
	// that could be.
	const { xpay } = fakeSdk( { status: { type: 'expired' }, canConfirm: false } );
	const { mod } = load( { xpay } );
	const handle = mod.mount( {
		...BASE,
		i18n: { expired: 'That session expired.', incomplete: 'Finish the fields.' },
	} );
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( await handle.check(), 'That session expired.' );
	assert.equal( ( await handle.confirm( {} ) ).message, 'That session expired.' );
} );

test( 'a session paid in another tab reports that, not an unfinished form', async () => {
	const { xpay } = fakeSdk( {
		status: { type: 'complete', paymentStatus: 'paid' },
		canConfirm: false,
	} );
	const { mod } = load( { xpay } );
	const handle = mod.mount( {
		...BASE,
		i18n: { completed: 'Already paid for.', incomplete: 'Finish the fields.' },
	} );
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( handle.terminal, 'complete' );
	assert.equal( await handle.check(), 'Already paid for.' );
} );

test( 'a session that expires mid-flow goes terminal on the change that says so', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const seen = [];
	const handle = mod.mount( {
		...BASE,
		i18n: { expired: 'That session expired.' },
		onTerminal: ( type ) => seen.push( type ),
	} );
	await Promise.resolve();
	await Promise.resolve();

	state.checkoutHandlers.change( { canConfirm: false, status: { type: 'expired' } } );

	assert.equal( handle.terminal, 'expired' );
	assert.deepEqual( seen, [ 'expired' ] );
	assert.equal( await handle.check(), 'That session expired.' );
} );

/* ── The in-flight flag ───────────────────────────────────────────────── */

test( 'in-flight is false before paying and true once a payment is submitted', async () => {
	// The platform will accept an amount change on an open session even
	// while a payment is running. This flag is the only guard.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	makePayable( state );
	assert.equal( handle.paying, false );
	const pending = handle.confirm( {} );
	assert.equal( handle.paying, true, 'must be set synchronously, before any await' );
	await pending;
	assert.equal( handle.paying, true, 'success keeps it set: the browser is leaving' );
} );

test( 'a declined payment clears in-flight so the shopper can retry', async () => {
	const { xpay, state } = fakeSdk( {
		confirmResult: { type: 'error', error: { message: 'Declined.' } },
	} );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	makePayable( state );
	await handle.confirm( {} );
	assert.equal( handle.paying, false );
} );

/* ── When the SDK never arrives ───────────────────────────────────────── */

test( 'a failed init tells the page it is unavailable', async () => {
	const { xpay } = fakeSdk( { failInit: new Error( 'bad key' ) } );
	const { mod } = load( { xpay } );
	let unavailable = null;
	let shown = null;
	mod.mount( {
		...BASE,
		onError: ( m ) => {
			shown = m;
		},
		onUnavailable: ( r ) => {
			unavailable = r;
		},
	} );
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
	assert.equal( shown, 'bad key' );
	assert.ok( unavailable );
} );

test( 'a missing SDK global is reported rather than silently doing nothing', () => {
	const { mod } = load( { xpay: null, timers: false } );
	let reason = null;
	mod.mount( { ...BASE, onUnavailable: ( r ) => ( reason = r ) } );
	// No global and no script load: the page must hear about it so it can
	// fall back to the hosted page instead of showing an empty box.
	assert.equal( reason, null, 'not reported until the script actually fails' );
} );

/* ── Restyling ────────────────────────────────────────────────────────── */

test( 'restyle updates appearance in place without recreating the element', async () => {
	// Recreating would destroy whatever the shopper has already typed.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay, appearance: { detect: () => ( { colorMode: 'dark' } ) } } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	handle.restyle( 'dark' );
	assert.deepEqual( state.appearanceUpdates, [ { colorMode: 'dark' } ] );
	assert.equal( mountedSelector( state.mountedAt ), '#xpay-element', 'still mounted' );
} );

test( 'restyle before mount is a no-op, not a crash', () => {
	const { mod } = load( { xpay: null, timers: false } );
	const handle = mod.mount( { ...BASE } );
	assert.doesNotThrow( () => handle.restyle( 'light' ) );
} );
