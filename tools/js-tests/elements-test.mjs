/**
 * Guards for assets/js/checkout-elements.js.
 *
 * Runs on node against a stubbed SDK. The real SDK, the iframe and the
 * card fields are XPay's; what this file owns is the wiring around them,
 * and that wiring is what these tests pin: which colorMode gets handed
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
function load( { xpay = null, timers = true, pageBackground = null } = {} ) {
	// Collected on append rather than on create: a script built and never
	// put on the page can never load, fail, or settle the mount, and a test
	// that watched createElement could not tell the two apart.
	const scripts = [];
	const win = {
		document: {
			querySelector: ( sel ) => ( { tagName: 'DIV', __selector: sel } ),
			createElement: () => ( {} ),
			head: {
				appendChild: ( el ) => {
					scripts.push( el );
					return el;
				},
			},
		},
		setTimeout: timers ? ( fn, ms ) => ( { fn, ms } ) : () => ( {} ),
		clearTimeout: () => {},
	};
	if ( xpay ) {
		win.XPay = () => xpay;
	}
	// What the page's computed styles answer. Null models a page the
	// resolver cannot read at all (the sandbox default).
	if ( null !== pageBackground ) {
		win.getComputedStyle = () => ( { backgroundColor: pageBackground } );
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

/**
 * A stub standing in for the DEFERRED SDK surface.
 *
 * Faithful where it matters:
 *
 *   - `elements()` VALIDATES and THROWS, as the real one does, on a
 *     missing currency or a non-positive / non-integer amount. A fake that
 *     accepted anything would hide the guard the mount path depends on.
 *   - The element announces 'ready' only when its iframe has actually
 *     rendered. `neverReady` models a form that never becomes payable.
 *   - `confirmPayment` lives on the SDK INSTANCE, not on a checkout
 *     object, and requires a clientSecret string.
 */
function fakeSdk( {
	confirmResult = { type: 'success' },
	failElements = null,
	// submit() resolves a bare object, not an ActionResult: no `type`
	// discriminant, and `error` absent rather than null when all is well.
	submitResult = {},
	neverReady = false,
	updateThrows = null,
} = {} ) {
	const state = {
		handlers: {},
		elementsHandlers: {},
		submits: 0,
		mountedAt: null,
		appearance: null,
		confirmedWith: null,
		createdWith: null,
		updates: [],
		destroyed: 0,
	};
	const element = {
		on: ( name, fn ) => {
			state.handlers[ name ] = fn;
		},
		mount: ( sel ) => {
			state.mountedAt = sel;
			if ( ! neverReady && state.handlers.ready ) {
				state.handlers.ready();
			}
		},
		unmount: () => {
			state.mountedAt = null;
		},
	};
	const elements = {
		create: ( type, options ) => {
			// The real SDK validates layout at create and THROWS on a bad
			// value — the fake must be no more
			// agreeable than that.
			if ( options && undefined !== options.layout && 'accordion' !== options.layout && 'tabs' !== options.layout ) {
				throw new Error( "Invalid layout: must be 'accordion' or 'tabs'." );
			}
			state.elementCreatedWith = options;
			return element;
		},
		on: ( name, fn ) => {
			state.elementsHandlers[ name ] = fn;
		},
		off: () => {},
		submit: () => {
			state.submits += 1;
			return Promise.resolve( submitResult );
		},
		update: ( opts ) => {
			if ( updateThrows ) {
				throw updateThrows;
			}
			state.updates.push( opts );
			return Promise.resolve();
		},
		destroy: () => {
			state.destroyed += 1;
			state.mountedAt = null;
		},
	};
	const xpay = {
		elements: ( o ) => {
			if ( failElements ) {
				throw failElements;
			}
			// The real SDK's own validation, modelled rather than assumed.
			if ( 'payment' !== o.mode ) {
				throw new Error( "mode must be 'payment'" );
			}
			if ( 'string' !== typeof o.currency || 0 === o.currency.length ) {
				throw new Error( '`currency` is required when `mode` is payment.' );
			}
			if ( 'number' !== typeof o.amount || ! Number.isInteger( o.amount ) || o.amount <= 0 ) {
				throw new Error( '`amount` must be a positive integer.' );
			}
			// The real SDK's strict filter validation: a wrong
			// value throws at creation, never a confusing empty frame. A fake
			// looser than the real thing hides shipped breakage.
			if ( undefined !== o.paymentMethodTypes ) {
				if (
					! Array.isArray( o.paymentMethodTypes ) ||
					0 === o.paymentMethodTypes.length ||
					o.paymentMethodTypes.some( ( t ) => 'string' !== typeof t || 0 === t.length )
				) {
					throw new Error( '`paymentMethodTypes` must be a non-empty array of payment method type strings.' );
				}
			}
			state.createdWith = o;
			state.appearance = o.appearance;
			return elements;
		},
		confirmPayment: ( args ) => {
			state.confirmedWith = args;
			return Promise.resolve( confirmResult );
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
	} );
}

const SECRET = 'cs_test_abc_secret_xyz';

const BASE = {
	selector: '#xpay-element',
	amount: 29000,
	currency: 'EGP',
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

test( 'the merchant colorMode is the whole appearance handed over', async () => {
	// Everything else comes from the merchant's XPay dashboard branding.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( { ...BASE, colorMode: 'dark' } );
	await Promise.resolve();
	assert.deepEqual( state.appearance, { colorMode: 'dark' } );
} );

test( 'Automatic reads light or dark off the page, not the device', async () => {
	// The fields sit inside the store's own layout, so a light store must
	// not turn its card fields dark because the shopper's laptop is. The
	// same one decision Stripe's plugin takes from the page.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay, pageBackground: 'rgb(17, 17, 17)' } );
	mod.mount( { ...BASE, colorMode: 'system' } );
	await Promise.resolve();
	assert.deepEqual( state.appearance, { colorMode: 'dark' } );
} );

test( 'a light page answers light whatever the device prefers', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay, pageBackground: 'rgb(255, 255, 255)' } );
	const opts = { ...BASE };
	delete opts.colorMode;
	mod.mount( opts );
	await Promise.resolve();
	assert.deepEqual( state.appearance, { colorMode: 'light' } );
} );

test( 'a page the resolver cannot read answers light, never a crash', async () => {
	// The sandbox has no getComputedStyle at all: the walk throws, and the
	// fields still mount, light, the way most stores are.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const opts = { ...BASE };
	delete opts.colorMode;
	mod.mount( opts );
	await Promise.resolve();
	assert.deepEqual( state.appearance, { colorMode: 'light' } );
	assert.equal( mountedSelector( state.mountedAt ), '#xpay-element' );
} );

test( 'a mostly transparent background is decided by what is behind it', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay, pageBackground: 'rgba(0, 0, 0, 0)' } );
	mod.mount( { ...BASE, colorMode: 'system' } );
	await Promise.resolve();
	// Nothing opaque anywhere up the walk: light, the way most stores are.
	assert.deepEqual( state.appearance, { colorMode: 'light' } );
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
	const out = await handle.confirm( SECRET, { phone: '+201012345678' } );
	assert.equal( out.ok, true );
	assert.equal( state.confirmedWith.clientSecret, SECRET );
	assert.deepEqual( state.confirmedWith.customerDetails, { phone: '+201012345678' } );
	assert.ok( state.confirmedWith.elements, 'confirmPayment binds the Elements instance, not a checkout object.' );
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
	const out = await handle.confirm( SECRET, {} );
	assert.equal( out.ok, false );
	assert.equal( out.message, 'Your card was declined.' );
} );

test( 'confirming before the form is ready fails closed', async () => {
	const { mod } = load( { xpay: null, timers: false } );
	const handle = mod.mount( { ...BASE, i18n: { notReady: 'Not ready yet.' } } );
	const out = await handle.confirm( SECRET, {} );
	assert.equal( out.ok, false );
	assert.equal( out.message, 'Not ready yet.' );
} );

test( 'a session that loads but never renders is not payable', async () => {
	// The window this closes: the SESSION has been fetched, so the old
	// guard was satisfied, but the payment ELEMENT never mounted, so there
	// is nothing on screen and nothing to confirm against. The payment used
	// to be attempted anyway and hang there.
	const { xpay, state } = fakeSdk( { neverReady: true } );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE, i18n: { notReady: 'Not ready yet.' } } );
	await Promise.resolve();
	await Promise.resolve();

	makePayable( state );

	assert.equal( handle.ready, false, 'Precondition: the element never announced itself.' );
	assert.equal( await handle.check(), 'Not ready yet.' );

	const out = await handle.confirm( SECRET, {} );
	assert.equal( out.ok, false );
	assert.equal( out.message, 'Not ready yet.' );
	assert.equal( state.confirmedWith, null, 'A payment was attempted against an element that never rendered.' );
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

	const out = await handle.confirm( SECRET, {} );
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

	const out = await handle.confirm( SECRET, {} );
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

/* ── The deferred contract ────────────────────────────────────────────── */

test( 'the element is created from an amount and a currency, with no session', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( state.createdWith.mode, 'payment' );
	assert.equal( state.createdWith.amount, 29000 );
	assert.equal( state.createdWith.currency, 'EGP' );
	assert.equal( state.createdWith.clientSecret, undefined, 'A session must not exist at mount.' );
} );

test( 'no amount means nothing is mounted at all', async () => {
	// An element showing nothing is worse than no element: the shopper
	// would type a card into a form that can never charge.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	let reason = null;
	const handle = mod.mount( { ...BASE, amount: 0, onUnavailable: ( r ) => ( reason = r ) } );
	await Promise.resolve();

	assert.equal( state.createdWith, null );
	assert.equal( mountedSelector( state.mountedAt ), null );
	assert.ok( reason );
	assert.equal( handle.canPay(), false );
} );

test( 'confirming without a clientSecret is refused before anything is sent', async () => {
	// The session is the server's to create. Confirming without one would
	// reach the SDK and come back as an error nobody could act on; the
	// page's own wording is the one worth showing.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE, i18n: { unavailable: 'Not available.' } } );
	await Promise.resolve();
	await Promise.resolve();
	makePayable( state );

	const out = await handle.confirm( '', {} );

	assert.equal( out.ok, false );
	assert.equal( out.message, 'Not available.' );
	assert.equal( state.confirmedWith, null, 'Nothing may reach the SDK without a secret.' );
} );

test( 'the charge-equals-display refusal comes back with its code intact', async () => {
	// The page branches on this code: it re-reads the total and lets the
	// shopper approve the real one. Folding it into a generic message
	// would leave them stuck on a number that can never be charged.
	const { xpay, state } = fakeSdk( {
		confirmResult: {
			type: 'error',
			error: {
				code: 'amount_reconfirmation_required',
				message: 'The session total does not match the amount shown.',
			},
		},
	} );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();
	makePayable( state );

	const out = await handle.confirm( SECRET, {} );

	assert.equal( out.ok, false );
	assert.equal( out.code, 'amount_reconfirmation_required' );
} );

/* ── Moving the displayed amount ──────────────────────────────────────── */

test( 'moving the amount costs no API call and updates what is displayed', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	const out = await handle.setAmount( 31000, 'EGP' );

	assert.equal( out.ok, true );
	assert.deepEqual( state.updates, [ { amount: 31000, currency: 'EGP' } ] );
	assert.equal( handle.amount, 31000 );
} );

test( 'an unchanged amount is not re-sent', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	await handle.setAmount( 29000, 'EGP' );

	assert.deepEqual( state.updates, [], 'A redraw that changed nothing must not churn the iframe.' );
} );

test( 'the amount cannot move once a payment is in flight', async () => {
	// The shopper approved a number. Moving it under an open charge is the
	// failure the whole flow is built to prevent, and the server would
	// refuse the mismatch anyway.
	const { xpay, state } = fakeSdk();
	// A confirm still in flight: it never settles, so the deadline is the
	// only thing that would end it, and this test finishes long before.
	xpay.confirmPayment = () => new Promise( () => {} );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE, confirmDeadlineMs: 50 } );
	await Promise.resolve();
	await Promise.resolve();
	makePayable( state );

	handle.confirm( SECRET, {} );
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();

	const out = await handle.setAmount( 99000, 'EGP' );

	assert.equal( out.ok, false );
	assert.deepEqual( state.updates, [] );
	assert.equal( handle.amount, 29000 );
} );

test( 'a zero amount never reaches the SDK', async () => {
	// Nothing to pay for is not an amount. Salvaged from set-amount-test,
	// which guarded the session-first setAmount( lineItem, quantity ):
	// a zero fell through to a clamp that minted a live one-piaster
	// session against a local record of nothing.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	const out = await handle.setAmount( 0, 'EGP' );

	assert.equal( out.ok, false );
	assert.deepEqual( state.updates, [] );
	assert.equal( handle.amount, 29000 );
} );

test( 'a cart that moves while the SDK is still loading is not dropped', async () => {
	// The move is buffered into the handle, and element creation reads it
	// — so the fields mount at the current total, not the one the page
	// opened with, and no confirm meets amount_reconfirmation_required
	// over a total the driver already reported.
	const { mod, scripts } = load( { xpay: null, timers: false } );
	const handle = mod.mount( { ...BASE } );

	const out = await handle.setAmount( 31000, 'EGP' );

	assert.equal( out.ok, true, 'A move during SDK load was dropped.' );
	assert.equal( handle.amount, 31000 );
	assert.ok( scripts.length, 'The SDK load must still be in flight for this test to mean anything.' );
} );

test( 'an amount the SDK refuses leaves the displayed one untouched', async () => {
	const { xpay, state } = fakeSdk( { updateThrows: new Error( 'amount must be a positive integer' ) } );
	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE } );
	await Promise.resolve();
	await Promise.resolve();

	const out = await handle.setAmount( 31000, 'EGP' );

	assert.equal( out.ok, false );
	assert.equal( handle.amount, 29000, 'A refused move must not be recorded as applied.' );
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
	const pending = handle.confirm( SECRET, {} );
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
	await handle.confirm( SECRET, {} );
	assert.equal( handle.paying, false );
} );

/* ── When the SDK never arrives ───────────────────────────────────────── */

test( 'a failed elements() tells the page it is unavailable', async () => {
	const { xpay } = fakeSdk( { failElements: new Error( 'bad key' ) } );
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
	const { mod, scripts } = load( { xpay: null, timers: false } );
	let reason = null;
	mod.mount( { ...BASE, onUnavailable: ( r ) => ( reason = r ) } );

	// While the script is still in the air there is nothing to report: the
	// page keeps the empty box because the form may yet arrive.
	assert.equal( reason, null, 'reported before the script had a chance to load' );
	assert.equal( scripts.length, 1, 'No script was appended, so nothing can ever settle this mount.' );

	// It arrives and leaves no global behind. The page has to hear about it
	// so it can fall back to the hosted page instead of showing an empty box
	// for the rest of the shopper's visit.
	scripts[ 0 ].onload();

	assert.equal( reason, 'no-global' );
} );

test( 'a script that never arrives is reported too', () => {
	const { mod, scripts } = load( { xpay: null, timers: false } );
	let reason = null;
	mod.mount( { ...BASE, onUnavailable: ( r ) => ( reason = r ) } );

	scripts[ 0 ].onerror();

	assert.equal( reason, 'network' );
} );

/* ── The confirm deadline ─────────────────────────────────────────────── */

test( 'a confirm that never resolves is answered rather than left hanging', async () => {
	// The SDK's confirm path has no timeout anywhere. Without this the
	// shopper watches a frozen button for as long as they are willing to.
	const { xpay, state } = fakeSdk();
	// A confirm that never settles.
	xpay.confirmPayment = () => new Promise( () => {} );

	const { mod } = load( { xpay } );
	const handle = mod.mount( {
		...BASE,
		confirmDeadlineMs: 10,
		i18n: { confirmSlow: 'Taking longer than expected.' },
	} );
	await Promise.resolve();
	await Promise.resolve();
	makePayable( state );

	const out = await handle.confirm( SECRET, {} );

	assert.equal( out.ok, false );
	assert.equal( out.message, 'Taking longer than expected.' );
} );

test( 'the timeout never claims the card was not charged', async () => {
	// A valU or kiosk payment can still be live at the processor long after
	// the browser gave up. Saying "not charged" would be a guess, and the
	// shopper would act on it by paying again.
	const { xpay, state } = fakeSdk();
	// A confirm that never settles.
	xpay.confirmPayment = () => new Promise( () => {} );

	const { mod } = load( { xpay } );
	const handle = mod.mount( {
		...BASE,
		confirmDeadlineMs: 10,
		i18n: { confirmSlow: 'This payment is taking longer than expected. Do not pay again.' },
	} );
	await Promise.resolve();
	await Promise.resolve();
	makePayable( state );

	const out = await handle.confirm( SECRET, {} );

	assert.ok( ! /not (been )?charged/i.test( out.message ), out.message );
} );

/* ── The confirm backstop ─────────────────────────────────────────────── */

/**
 * Drive a confirm that never settles and report the deadline the library
 * armed, using a fake clock so the test does not wait for it.
 *
 * @param {string|null} method What the element reports as selected.
 * @return {Promise<number>} The deadline used, in ms.
 */
async function deadlineUsedFor( method ) {
	const { xpay, state } = fakeSdk();
	xpay.confirmPayment = () => new Promise( () => {} );

	const { mod } = load( { xpay } );
	const handle = mod.mount( { ...BASE, i18n: { confirmSlow: 'slow' } } );
	await Promise.resolve();
	await Promise.resolve();

	// No `session`: deferred elements have none, and the change handler
	// never read it.
	state.handlers.change( {
		complete: true,
		value: { type: method || 'card' },
	} );

	// The patch has to stay in place until confirm() has actually armed the
	// timer, which happens AFTER its preflight resolves.
	let armed = null;
	const realSetTimeout = globalThis.setTimeout;
	globalThis.setTimeout = ( fn, ms ) => {
		armed = ms;
		return realSetTimeout( fn, 0 );
	};
	try {
		await handle.confirm( SECRET, {} );
	} finally {
		globalThis.setTimeout = realSetTimeout;
	}
	return armed;
}

test( 'the backstop outlasts every bound the SDK itself enforces', async () => {
	const THREE_DS_TTL_MS = 15 * 60 * 1000;
	const RENDER_ALLOWANCE_MS = 20 * 1000;

	const used = await deadlineUsedFor( 'card' );

	assert.ok(
		used > THREE_DS_TTL_MS + RENDER_ALLOWANCE_MS,
		`backstop ${ used }ms would fire while a 3DS challenge can still complete`
	);
} );

test( 'the same backstop applies whatever the method is', async () => {
	const card = await deadlineUsedFor( 'card' );
	const valu = await deadlineUsedFor( 'valu' );
	const fawry = await deadlineUsedFor( 'fawry' );

	assert.equal( valu, card );
	assert.equal( fawry, card );
} );

/* -- Per-method rows: the render filter ------------------------------- */

test( 'the method filter reaches the SDK exactly as the row gave it', async () => {
	// One method per checkout row: the row mounts with its own type, and
	// what the SDK receives IS the row's restriction — no rewriting.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( { ...BASE, paymentMethodTypes: [ 'valu' ] } );
	await Promise.resolve();

	assert.deepEqual( state.createdWith.paymentMethodTypes, [ 'valu' ] );
} );

test( 'no filter means every enabled method, exactly as before', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( { ...BASE } );
	await Promise.resolve();

	assert.equal( state.createdWith.paymentMethodTypes, undefined );
} );

test( 'the layout choice reaches the SDK at element creation', async () => {
	// tabs + one method type renders the form content alone; the row that
	// chose it draws the logo and title itself.
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( { ...BASE, paymentMethodTypes: [ 'card' ], layout: 'tabs' } );
	await Promise.resolve();

	assert.equal( state.elementCreatedWith && state.elementCreatedWith.layout, 'tabs' );
} );

test( 'no layout means the SDK default, exactly as before', async () => {
	const { xpay, state } = fakeSdk();
	const { mod } = load( { xpay } );
	mod.mount( { ...BASE } );
	await Promise.resolve();

	assert.equal( state.elementCreatedWith, undefined );
} );

test( 'an SDK throw after construction still reaches the error surfaces', async () => {
	// create() and mount() are SDK calls too. A throw escaping the load
	// callback would leave the row silently empty: no message, no way for
	// the page to fall back. The fake's create throws on a bad layout the
	// way the shipped SDK does; the drivers never send one, so this drives
	// the guard, not the drivers.
	const { xpay } = fakeSdk();
	const { mod } = load( { xpay } );
	const errors = [];
	const unavailable = [];
	mod.mount( {
		...BASE,
		layout: 'sideways',
		onError: ( message ) => errors.push( message ),
		onUnavailable: ( reason ) => unavailable.push( reason ),
	} );
	await Promise.resolve();

	assert.equal( errors.length, 1, 'The page must hear about the failure in words.' );
	assert.match( String( errors[ 0 ] ), /layout/ );
	assert.equal( unavailable.length, 1, 'The page must be told no form is coming.' );
} );

test( 'a bad filter fails at mount, in words, never as an empty frame', async () => {
	// The real SDK throws on an empty list (nothing could ever render);
	// the page must hear about it through its own error surfaces.
	const { xpay } = fakeSdk();
	const { mod } = load( { xpay } );
	const errors = [];
	const unavailable = [];
	mod.mount( {
		...BASE,
		paymentMethodTypes: [],
		onError: ( m ) => errors.push( m ),
		onUnavailable: ( r ) => unavailable.push( r ),
	} );
	await Promise.resolve();

	assert.equal( unavailable.length, 1, 'The row must be told no form is coming.' );
	assert.ok( errors.length >= 1, 'The shopper-facing error surface heard nothing.' );
} );
