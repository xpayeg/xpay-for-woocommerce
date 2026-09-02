/**
 * The classic checkout driver, exercised as a whole file.
 *
 * This exists because the single worst defect in this plugin was invisible
 * to every test it had: the Place Order takeover was bound to `document`,
 * core fires it on the form with jQuery's triggerHandler, triggerHandler
 * does not bubble, and so the classic checkout never once ran XPay's
 * payment. Nothing failed. The suite was green the entire time.
 *
 * So the fakes below are built to be able to reproduce that. The jQuery
 * stand-in implements `triggerHandler` with its real semantics — first
 * matched element only, no bubbling — which means a driver that binds on
 * `document` or uses delegation fails these tests, exactly as it would fail
 * on a real checkout.
 *
 * Run: node tools/js-tests/checkout-driver-test.mjs
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const here = dirname( fileURLToPath( import.meta.url ) );
const driverSource = readFileSync( join( here, '../../assets/js/checkout-driver.js' ), 'utf8' );

/*
 * The REAL library, loaded once so the driver's fake XPayElements can expose
 * the real refusalMessage. Hand-writing a copy of it here would mean the
 * test asserts against wording the shipped code does not use — the exact
 * failure mode that let a completely broken classic checkout sit behind a
 * green suite.
 */
const elementsSource = readFileSync( join( here, '../../assets/js/checkout-elements.js' ), 'utf8' );
const realLibrary = ( () => {
	// setTimeout because settleVerdict schedules its second ask through the
	// library's OWN window. Without it that call throws, the catch turns it
	// into 'unknown', and every test below passes for the wrong reason —
	// never once exercising the retry.
	const box = { window: { setTimeout }, module: { exports: {} } };
	box.globalThis = box;
	vm.createContext( box );
	vm.runInContext( elementsSource, box );
	return box.window.XPayElements;
} )();

/* ── A DOM small enough to reason about ──────────────────────────────── */

class FakeNode {
	constructor( tag = 'div', attrs = {} ) {
		this.tag = tag;
		this.attrs = attrs;
		this.value = attrs.value || '';
		this.textContent = '';
		this.hidden = false;
		this.children = [];
		// Real DOM nodes report isConnected true while attached; the
		// driver's prune reads it, so the fake must not answer "detached"
		// by accident of a missing property.
		this.isConnected = true;
	}
	setAttribute( name, value ) {
		this.attrs[ name ] = value;
	}
	getAttribute( name ) {
		return this.attrs[ name ];
	}
	querySelector() {
		return null;
	}
	appendChild( child ) {
		this.children.push( child );
		return child;
	}
}

/**
 * A document that answers only the selectors the driver actually asks for.
 * Anything else returns null, which is what a real checkout does for a
 * selector that is not there.
 */
function makeDocument( nodes ) {
	return {
		readyState: 'complete',
		body: new FakeNode( 'body' ),
		querySelector: ( selector ) => nodes[ selector ] || null,
		createElement: ( tag ) => new FakeNode( tag ),
		addEventListener: () => {},
	};
}

/* ── A jQuery stand-in with triggerHandler's real semantics ──────────── */

function makeJQuery( registry ) {
	function wrap( target, selector ) {
		const key = selector || target;
		const api = {
			length: target ? 1 : 0,
			selector,
			_target: target,
			on( event, handler ) {
				( registry.get( key ) || registry.set( key, new Map() ).get( key ) )
					.set( event, handler );
				return api;
			},
			off( event ) {
				const events = registry.get( key );
				if ( events ) {
					// jQuery removes by namespace; both the namespaced and
					// bare forms name the same binding here.
					for ( const bound of [ ...events.keys() ] ) {
						if ( bound === event ) {
							events.delete( bound );
						}
					}
				}
				return api;
			},
			/**
			 * The real thing: fires on this element only, does not bubble.
			 */
			triggerHandler( event, data ) {
				const events = registry.get( key );
				const handler = events && events.get( event );
				if ( ! handler ) {
					return undefined;
				}
				return handler( { type: event }, ...( data || [] ) );
			},
			trigger() {
				return api;
			},
			serialize: () => 'billing_email=a%40b.test&payment_method=xpay',
			addClass: () => api,
			removeClass: () => api,
			block: () => api,
			unblock: () => api,
		};
		return api;
	}

	const jq = ( thing ) => {
		if ( typeof thing === 'string' ) {
			return wrap( thing, thing );
		}
		return wrap( thing, thing && thing.tag ? thing.tag : 'unknown' );
	};
	return jq;
}

/* ── Booting the driver under a controlled world ─────────────────────── */

function bootDriver( {
	confirmResult = { ok: true },
	setAmountResult = { ok: true },
	outcomeVerdict = 'unpaid',
	checkProblem = '',
	checkoutResponse,
	ajax,
	rows,
} = {} ) {
	const calls = [];
	/** Every amount move the driver asked the element for, in order. */
	const amountMoves = [];
	/** Every confirm, with the secret it was given. */
	const confirms = [];
	const registry = new Map();

	const mountNode = new FakeNode( 'div', { 'data-xpay-elements': '' } );
	const errorNode = new FakeNode( 'p', { 'data-xpay-elements-error': '' } );
	const radio = new FakeNode( 'input', { name: 'payment_method', value: 'xpay' } );
	radio.value = 'xpay';

	const nodes = {
		'[data-xpay-elements]': mountNode,
		'[data-xpay-elements-error]': errorNode,
		'input[name="payment_method"]:checked': radio,
		'form.checkout': new FakeNode( 'form' ),
		'#billing_first_name': Object.assign( new FakeNode( 'input' ), { value: 'Mo' } ),
		'#billing_last_name': Object.assign( new FakeNode( 'input' ), { value: 'S' } ),
		'#billing_email': Object.assign( new FakeNode( 'input' ), { value: 'a@b.test' } ),
		'#billing_phone': Object.assign( new FakeNode( 'input' ), { value: '01000000000' } ),
	};

	const document = makeDocument( nodes );

	const handle = {
		amount: 29000,
		check: () => {
			calls.push( 'check' );
			return Promise.resolve( checkProblem );
		},
		// Deferred: the secret arrives HERE, from the checkout response,
		// not at mount. Recorded so tests can pin which session was
		// confirmed against — the whole of the retry discipline.
		confirm: ( clientSecret, details ) => {
			calls.push( 'confirm' );
			confirms.push( { clientSecret, details } );
			return Promise.resolve( confirmResult );
		},
		destroy: () => calls.push( 'destroy' ),
		setAmount: ( amount, currency ) => {
			calls.push( 'setAmount' );
			amountMoves.push( { amount, currency } );
			handle.amount = amount;
			return Promise.resolve( setAmountResult );
		},
	};

	const navigations = [];
	/** Every element mount, with the exact options the driver passed. */
	const mounts = [];

	const window = {
		xpayElementsParams: {
			ajaxUrl: '/admin-ajax.php',
			nonce: 'n',
			publishableKey: 'pk_test_x',
			sdkUrl: 'https://checkout.xpay.app/v1/sdk.js',
			gatewayId: 'xpay',
			rows: rows || undefined,
			amount: 29000,
			currency: 'EGP',
			locale: 'en',
			i18n: {
				unavailable: 'unavailable',
				totalChanged: 'total changed',
				emptyCart: 'empty cart',
				notCompleted: 'not completed',
				confirmSlow: 'taking longer',
				notReady: 'not ready',
			},
		},
		// The real library returns the handle synchronously. refusalMessage,
		// confirmed and outcomeKind are the shipped ones, not copies —
		// outcomeKind in particular decides whether a shopper is offered a
		// retry, and a hand-written copy here would be asserting against a
		// rule the shipped code does not follow.
		XPayElements: {
			// The real element becomes usable a moment after mount, and
			// announces it through onReady. The gap is the point: setAmount
			// answers false before it, so a driver that moves the amount too
			// early reads a false and trades the session in.
			mount: ( opts ) => {
				mounts.push( opts );
				if ( opts && opts.onReady ) {
					Promise.resolve().then( () => opts.onReady() );
				}
				handle.ready = true;
				return handle;
			},
			refusalMessage: realLibrary.refusalMessage,
			outcomeKind: realLibrary.outcomeKind,
			confirmed: realLibrary.confirmed,
			settleVerdict: realLibrary.settleVerdict,
		},
		jQuery: makeJQuery( registry ),
		Promise,
		setTimeout,
		clearTimeout,
		FormData: class {
			constructor() {
				this.entries = [];
			}
			append( k, v ) {
				this.entries.push( [ k, v ] );
			}
		},
		wc_checkout_params: { checkout_url: '/?wc-ajax=checkout' },
		location: {
			set href( url ) {
				navigations.push( url );
			},
			reload: () => navigations.push( 'RELOAD' ),
		},
		fetch: ( url, options ) => {
			if ( String( url ).includes( 'wc-ajax=checkout' ) ) {
				calls.push( 'place-order' );
				return Promise.resolve( {
					ok: true,
					status: 200,
					text: () =>
						Promise.resolve(
							JSON.stringify(
								checkoutResponse || {
									result: 'success',
									redirect: 'https://store.test/order-received/9/',
									xpay_confirm: 'yes',
									xpay_secret: 'cs_test_1_secret_x',
									xpay_order_id: '9',
									xpay_order_key: 'wc_order_k',
								}
							)
						),
				} );
			}
			const action = ( options.body.entries.find( ( e ) => e[ 0 ] === 'action' ) || [] )[ 1 ];
			calls.push( action );
			const answer = ajax && ajax[ action ];
			// A confirm that did not come back clean asks the server whether
			// a retry is safe. Default to 'unpaid' — XPay certain nothing
			// moved — so a test about a DECLINE stays a test about a
			// decline; the undecided case scripts its own answer.
			// `applied` is now a VERDICT, not an acknowledgement: the server
			// asks the API whether the platform really took the quantity,
			// because the SDK's session object cannot tell the page. Default
			// to confirmed, so a test about something else is not silently
			// turned into a test about a rejected move.
			let fallback = { success: true, data: { clientSecret: 'cs_secret' } };
			if ( 'xpay_elements_outcome' === action ) {
				fallback = { success: true, data: { verdict: outcomeVerdict } };
			} else if ( 'xpay_elements_applied' === action ) {
				fallback = { success: true, data: { confirmed: true } };
			}
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( answer || fallback ),
			} );
		},
	};

	// `window.location = url` is an assignment to the property on window.
	Object.defineProperty( window, 'location', {
		get: () => ( { reload: () => navigations.push( 'RELOAD' ) } ),
		set: ( url ) => navigations.push( url ),
		configurable: true,
	} );

	// No real wait in tests. The delay is a courtesy to a shopper watching a
	// spinner; here it would only make the suite slow and racy.
	realLibrary.RECHECK_DELAY_MS = 0;

	const sandbox = { window, document, console };
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( driverSource, sandbox );

	return {
		calls,
		amountMoves,
		confirms,
		registry,
		navigations,
		nodes,
		errorNode,
		window,
		handle,
		mounts,
		/**
		 * Stand in for the shopper picking another payment row: point the
		 * checked radio at it and give the row its own container, the way
		 * each method gateway's payment_fields renders one.
		 *
		 * @param {string} id     Gateway id (e.g. 'xpay_valu').
		 * @param {string} method The row's data-xpay-method value.
		 */
		selectRow: ( id, method ) => {
			const row = new FakeNode( 'div', { 'data-xpay-elements': '', 'data-xpay-method': method } );
			nodes[ '.payment_method_' + id + ' [data-xpay-elements]' ] = row;
			nodes[ 'input[name="payment_method"]:checked' ].value = id;
			return row;
		},
		/** Stand in for WooCommerce replacing the whole payment box. */
		replaceMountNode: () => {
			nodes[ '[data-xpay-elements]' ] = new FakeNode( 'div', { 'data-xpay-elements': '' } );
		},
		/** Stand in for WooCommerce recalculating the cart in place. */
		setDisplayedTotal: ( minor ) => {
			nodes[ '[data-xpay-elements]' ].attrs[ 'data-xpay-amount' ] = String( minor );
		},
	};
}

const settle = () => new Promise( ( resolve ) => setImmediate( resolve ) );

/**
 * Press Place Order the way core does, and let the flow run out.
 *
 * Core fires the event on the FORM with triggerHandler, which does not
 * bubble — the fake jQuery models that, so this helper cannot accidentally
 * paper over a driver that binds in the wrong place.
 *
 * @param {Object} boot A booted driver.
 */
async function placeOrder( boot ) {
	await settle();
	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )(
		{ type: 'checkout_place_order_xpay' },
		null
	);
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}
}

/**
 * Let both microtasks AND timers run. settleVerdict schedules its second
 * ask on a timer, which setImmediate alone never reaches.
 */
const settleWithTimers = async ( rounds = 20 ) => {
	for ( let i = 0; i < rounds; i++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}
};

/* ── The binding ─────────────────────────────────────────────────────── */

test( 'Place Order is bound to the form, which is where core fires it', () => {
	const { registry } = bootDriver();

	const formEvents = registry.get( 'form.checkout' );
	assert.ok( formEvents, 'Nothing was bound to form.checkout at all.' );
	assert.ok(
		[ ...formEvents.keys() ].some( ( e ) => e.startsWith( 'checkout_place_order_xpay' ) ),
		'The Place Order takeover is not bound to the form.'
	);
} );

test( 'binding on document alone would not have been reached', () => {
	const { registry } = bootDriver();

	// The original bug, stated so it cannot come back: core uses
	// triggerHandler on the form, and nothing bound elsewhere hears it.
	const documentEvents = registry.get( 'unknown' ) || new Map();
	assert.ok(
		! [ ...documentEvents.keys() ].some( ( e ) => e.startsWith( 'checkout_place_order_' ) ),
		'The takeover is bound somewhere triggerHandler will never reach.'
	);
} );

/* ── The order of events ─────────────────────────────────────────────── */

test( 'the order is created BEFORE the card is charged', async () => {
	const boot = bootDriver();
	await settle();

	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )(
		{ type: 'checkout_place_order_xpay' },
		null
	);

	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	const placedAt = boot.calls.indexOf( 'place-order' );
	const chargedAt = boot.calls.indexOf( 'confirm' );

	assert.ok( placedAt !== -1, 'The order was never placed.' );
	assert.ok( chargedAt !== -1, 'The payment was never confirmed.' );
	assert.ok(
		placedAt < chargedAt,
		`The card was charged before the order existed: ${ boot.calls.join( ' → ' ) }`
	);
} );

test( 'the session is created by the server, not before the order', async () => {
	// The whole flip: nothing exists on the platform while the shopper
	// fills the form. The order is placed first and the secret to confirm
	// against comes back with it.
	const boot = bootDriver();
	await placeOrder( boot );

	assert.deepEqual(
		boot.calls.filter( ( c ) => 'place-order' === c || 'confirm' === c ),
		[ 'place-order', 'confirm' ],
		'The order must exist before anything is charged.'
	);
	assert.equal(
		boot.confirms[ 0 ].clientSecret,
		'cs_test_1_secret_x',
		'The confirm must use the secret the checkout response carried.'
	);
} );

test( 'the shopper is sent on after a good payment', async () => {
	const boot = bootDriver();
	await placeOrder( boot );

	assert.deepEqual( boot.navigations, [ 'https://store.test/order-received/9/' ] );
} );

test( 'nothing is confirmed when the server sent no secret', async () => {
	// An order that exists with no session to confirm against is the
	// already-paid case. Navigating is right; charging is not possible.
	const boot = bootDriver( {
		checkoutResponse: {
			result: 'success',
			redirect: 'https://store.test/order-received/9/',
			xpay_confirm: 'yes',
		},
	} );
	await placeOrder( boot );

	assert.ok( ! boot.calls.includes( 'confirm' ), 'A missing secret must never reach confirm.' );
	assert.deepEqual( boot.navigations, [ 'https://store.test/order-received/9/' ] );
} );

/* ── When things go wrong ────────────────────────────────────────────── */

test( 'a failed confirm keeps the shopper here with a reason', async () => {
	const boot = bootDriver( { confirmResult: { ok: false, message: 'card declined' } } );
	await settle();

	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.deepEqual( boot.navigations, [], 'The shopper was sent to a thank-you page for a payment that failed.' );
	assert.equal( boot.errorNode.textContent, 'card declined' );
	// Nothing to release: there is no server-side lock any more. The retry
	// reuses the SAME order and the SAME session, which is what keeps one
	// purchase on one Payment Intent.
	assert.ok( ! boot.calls.some( ( c ) => 0 === String( c ).indexOf( 'xpay_elements_pa' ) ) );
} );

test( 'a refused check never places an order', async () => {
	// The gate runs before anything is created, so a shopper who has not
	// finished the fields never gets an order they did not pay for.
	const boot = bootDriver( { checkProblem: 'Finish the payment details.' } );
	await placeOrder( boot );

	assert.ok( ! boot.calls.includes( 'place-order' ), 'An order was created for a payment that was refused up front.' );
	assert.ok( ! boot.calls.includes( 'confirm' ) );
	assert.equal( boot.errorNode.textContent, 'Finish the payment details.' );
} );

test( 'a checkout the server rejected never charges', async () => {
	const boot = bootDriver( {
		checkoutResponse: { result: 'failure', messages: '<ul><li>Billing email is required.</li></ul>' },
	} );
	await settle();

	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.ok(
		! boot.calls.includes( 'confirm' ),
		'The card was charged for a checkout WooCommerce refused to accept.'
	);
	assert.deepEqual( boot.navigations, [] );
} );

test( 'a pay-page fallback navigates without charging in the browser', async () => {
	const boot = bootDriver( {
		checkoutResponse: { result: 'success', redirect: 'https://store.test/checkout/order-pay/9/' },
	} );
	await settle();

	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.ok(
		! boot.calls.includes( 'confirm' ),
		'The browser confirmed against a session the server did not adopt.'
	);
	assert.deepEqual( boot.navigations, [ 'https://store.test/checkout/order-pay/9/' ] );
} );

test( 'a second click while paying does nothing', async () => {
	const boot = bootDriver();
	await settle();

	const handler = boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' );
	handler( {}, null );
	handler( {}, null );
	handler( {}, null );

	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.equal(
		boot.calls.filter( ( c ) => c === 'place-order' ).length,
		1,
		'A double click created more than one order.'
	);
	assert.equal(
		boot.calls.filter( ( c ) => c === 'confirm' ).length,
		1,
		'A double click charged the card more than once.'
	);
} );

test( 'the handler always stops WooCommerce from submitting on its own', () => {
	const boot = bootDriver();
	const handler = boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' );

	assert.equal( handler( {}, null ), false );
} );

/* ── Redraws ─────────────────────────────────────────────────────────── */

test( 'a redraw either re-mounts or moves the amount, never both', async () => {
	const boot = bootDriver();
	await settle();

	const onUpdated = boot.registry.get( 'body' ).get( 'updated_checkout' );
	assert.ok( onUpdated, 'Nothing listens for updated_checkout.' );

	// A redraw that reuses the mount node. mount() stands aside on purpose
	// — re-mounting would throw away the card the shopper has typed —
	// which makes the amount move the only thing that can follow the cart.
	boot.calls.length = 0;
	boot.setDisplayedTotal( 31000 );
	onUpdated( {} );
	for ( let i = 0; i < 6; i++ ) {
		await settle();
	}
	assert.ok( ! boot.calls.includes( 'destroy' ), 'The fields were re-mounted for nothing, discarding the card.' );
	assert.deepEqual(
		boot.amountMoves,
		[ { amount: 31000, currency: 'EGP' } ],
		'A redraw that reused the mount node left the fields quoting a stale total.'
	);

	// A redraw that genuinely replaced the payment box: a fresh mount
	// already displays the current total, so moving it again would be a
	// second write for no reason.
	boot.calls.length = 0;
	boot.amountMoves.length = 0;
	boot.replaceMountNode();
	boot.setDisplayedTotal( 33000 );
	onUpdated( {} );
	for ( let i = 0; i < 6; i++ ) {
		await settle();
	}
	assert.deepEqual( boot.amountMoves, [], 'A fresh mount already shows the current total.' );
} );

test( 'moving the displayed amount costs no server call at all', async () => {
	// The old flow asked the server on every recalculation, with two-phase
	// bookkeeping to survive the races that created. There is no session
	// to move now, so a cart change is one message to the iframe.
	const boot = bootDriver();
	await settle();

	boot.calls.length = 0;
	boot.setDisplayedTotal( 31000 );
	boot.registry.get( 'body' ).get( 'updated_checkout' )( {} );
	for ( let i = 0; i < 6; i++ ) {
		await settle();
	}

	assert.ok(
		! boot.calls.some( ( c ) => 0 === String( c ).indexOf( 'xpay_elements_' ) ),
		`A cart recalculation reached the server: ${ boot.calls.join( ' → ' ) }`
	);
} );

/* ── What each ending looks like ──────────────────────────────────────── */

test( 'a confirmed payment navigates straight to the order page', async () => {
	const { registry, navigations } = bootDriver( { confirmResult: { ok: true } } );

	await settle();

	registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.deepEqual( navigations, [ 'https://store.test/order-received/9/' ] );
} );

test( 'a declined card stays on the checkout with the reason', async () => {
	const { registry, navigations, errorNode } = bootDriver( {
		confirmResult: { ok: false, code: 'card_declined', message: 'Your card was declined.' },
	} );

	await settle();

	registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.deepEqual( navigations, [], 'A declined payment must not send the shopper to an order page.' );
	assert.equal( errorNode.textContent, 'Your card was declined.' );
} );

test( 'an undecided outcome goes to the order page instead of offering a retry', async () => {
	const { registry, navigations, errorNode } = bootDriver( {
		// The real shape of an undecided payment: an api_error with NO code,
		// indistinguishable from a decline that fell back. Only the server
		// can tell them apart, and here it cannot either.
		confirmResult: {
			ok: false,
			code: '',
			message: "Your payment is still being confirmed. Don't pay again.",
		},
		outcomeVerdict: 'unknown',
	} );

	await settle();

	registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	await settleWithTimers();

	// The platform could not decide and left the charge pending. Money may
	// have moved, so the one thing that must not happen is an invitation to
	// pay again.
	assert.deepEqual( navigations, [ 'https://store.test/order-received/9/' ] );
	assert.equal( errorNode.textContent, '', 'A payment that may still land was shown as a failure.' );
} );

test( 'an unknown verdict is asked a second time before anyone gives up', async () => {
	const boot = bootDriver( {
		confirmResult: { ok: false, code: '', message: 'Cannot say.' },
		outcomeVerdict: 'unknown',
	} );
	await settle();

	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	await settleWithTimers();

	assert.equal(
		boot.calls.filter( ( c ) => 'xpay_elements_outcome' === c ).length,
		2,
		'A blip was accepted as the final word.'
	);
} );

test( 'a fallback with no secret simply follows the redirect', async () => {
	const { registry, navigations } = bootDriver( {
		checkoutResponse: {
			result: 'success',
			redirect: 'https://store.test/checkout/order-pay/9/',
		},
	} );

	await settle();

	registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.deepEqual( navigations, [ 'https://store.test/checkout/order-pay/9/' ] );
} );

/* ── Charge = display ─────────────────────────────────────────────────── */

test( 'a session that does not match what was displayed charges nothing and re-reads the total', async () => {
	/*
	 * The cart moved between mounting and paying, so the session the
	 * server made totals something the shopper never approved. The
	 * platform refuses it — NOTHING is charged — and the page's job is to
	 * show the real number and let them approve it. Retrying
	 * automatically would charge a total nobody agreed to.
	 */
	const boot = bootDriver( {
		confirmResult: {
			ok: false,
			code: 'amount_reconfirmation_required',
			message: 'The session total does not match.',
		},
	} );
	boot.setDisplayedTotal( 31000 );
	await placeOrder( boot );

	assert.deepEqual( boot.navigations, [], 'A refused amount must never look like a completed payment.' );
	assert.equal(
		boot.errorNode.textContent,
		'total changed',
		"The shopper is told the total moved, in the page's own words."
	);
	assert.deepEqual(
		boot.amountMoves,
		[ { amount: 31000, currency: 'EGP' } ],
		'The displayed amount must be re-read so the shopper can approve the real one.'
	);
	assert.equal(
		boot.calls.filter( ( c ) => 'confirm' === c ).length,
		1,
		'A refused amount must never be retried on its own.'
	);
} );

test( 'a declined card can be retried against the same session', async () => {
	/*
	 * ONE SESSION PER CHECKOUT. A decline leaves the order and its session
	 * exactly where they were, so pressing Pay again places the same order
	 * and confirms the same secret. The whole transaction — every decline
	 * and the final charge — stays on one Payment Intent.
	 */
	const boot = bootDriver( { confirmResult: { ok: false, message: 'card declined' } } );
	await placeOrder( boot );

	assert.equal( boot.errorNode.textContent, 'card declined' );

	await placeOrder( boot );

	assert.equal( boot.confirms.length, 2, 'The shopper could not try again.' );
	assert.equal(
		boot.confirms[ 1 ].clientSecret,
		boot.confirms[ 0 ].clientSecret,
		'A retry minted a second session, splitting one purchase across two Payment Intents.'
	);
} );

test( 'a payment the browser could not confirm but XPay has is treated as paid', async () => {
	const boot = bootDriver( {
		confirmResult: { ok: false, code: '', message: 'Something went wrong.' },
		outcomeVerdict: 'paid',
	} );
	await settle();

	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.deepEqual( boot.navigations, [ 'https://store.test/order-received/9/' ] );
	assert.equal( boot.errorNode.textContent, '', 'A paid order was shown as a failure.' );
} );

test( 'a clean confirm never asks the server', async () => {
	const boot = bootDriver();
	await settle();

	boot.registry.get( 'form.checkout' ).get( 'checkout_place_order_xpay.xpay' )( {}, null );
	for ( let i = 0; i < 12; i++ ) {
		await settle();
	}

	assert.ok(
		! boot.calls.includes( 'xpay_elements_outcome' ),
		'The ordinary payment paid for a round trip it did not need.'
	);
} );

/* -- One row per payment method --------------------------------------- */

test( 'every row gets its own Place Order takeover', () => {
	const { registry } = bootDriver( { rows: [ 'xpay', 'xpay_valu', 'xpay_fawry' ] } );

	const formEvents = [ ...registry.get( 'form.checkout' ).keys() ];
	for ( const rowId of [ 'xpay', 'xpay_valu', 'xpay_fawry' ] ) {
		assert.ok(
			formEvents.some( ( e ) => e.startsWith( 'checkout_place_order_' + rowId ) ),
			'Core names the event after the CHOSEN gateway, so an unbound row falls through to a plain submit: ' + rowId
		);
	}
} );

test( 'a method row mounts fields restricted to its method', async () => {
	const boot = bootDriver( { rows: [ 'xpay', 'xpay_valu' ] } );
	boot.selectRow( 'xpay_valu', 'valu' );
	boot.registry.get( 'body' ).get( 'updated_checkout' )( {} );
	await settle();

	const last = boot.mounts[ boot.mounts.length - 1 ];
	// Array.from: the options object was built inside the driver's own
	// realm, and a cross-realm array never deep-strict-equals a local one.
	assert.deepEqual(
		Array.from( last.paymentMethodTypes ),
		[ 'valu' ],
		'The valU row rendered more than valU, which puts a second selector inside a selected row.'
	);
	assert.equal(
		last.layout,
		'tabs',
		"tabs + one method renders the form alone; without it the iframe repeats the row's own logo and title."
	);
} );

test( 'the fallback single row still mounts unfiltered', async () => {
	const boot = bootDriver();
	await settle();

	assert.equal(
		boot.mounts[ 0 ].paymentMethodTypes,
		undefined,
		'With no account map there is one row, and it renders every enabled method.'
	);
	assert.equal(
		boot.mounts[ 0 ].layout,
		undefined,
		'The single fallback row keeps the accordion: it is the whole selector there.'
	);
} );

test( 'switching rows keeps what the shopper typed in the other row', async () => {
	const boot = bootDriver( { rows: [ 'xpay', 'xpay_valu' ] } );
	boot.selectRow( 'xpay', 'card' );
	boot.registry.get( 'body' ).get( 'updated_checkout' )( {} );
	await settle();
	const base = boot.mounts.length;

	boot.selectRow( 'xpay_valu', 'valu' );
	boot.registry.get( 'body' ).get( 'payment_method_selected' )( {} );
	await settle();

	assert.ok( ! boot.calls.includes( 'destroy' ), "Switching rows destroyed the other row's fields, losing the typed card." );
	assert.equal( boot.mounts.length, base + 1, 'The newly selected row mounts once.' );

	// Back to the first row: its element is still alive, so nothing new
	// mounts and nothing dies.
	boot.nodes[ 'input[name="payment_method"]:checked' ].value = 'xpay';
	boot.registry.get( 'body' ).get( 'payment_method_selected' )( {} );
	await settle();

	assert.equal( boot.mounts.length, base + 1, 'Returning to a row must reuse its element, not rebuild it.' );
	assert.ok( ! boot.calls.includes( 'destroy' ) );
} );

test( 'a redraw that replaces one row remounts it fresh', async () => {
	const boot = bootDriver( { rows: [ 'xpay', 'xpay_valu' ] } );
	const first = boot.selectRow( 'xpay', 'card' );
	boot.registry.get( 'body' ).get( 'updated_checkout' )( {} );
	await settle();
	const base = boot.mounts.length;

	// WooCommerce replaced the payment box: the old container is detached.
	first.isConnected = false;
	boot.selectRow( 'xpay', 'card' );
	boot.registry.get( 'body' ).get( 'updated_checkout' )( {} );
	await settle();

	assert.equal( boot.mounts.length, base + 1, 'A replaced container must get a fresh element.' );
} );
