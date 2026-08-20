/**
 * Elements on the classic checkout — against a running test store.
 *
 * Covers the parts that are ours rather than the SDK's: that one XPay row
 * replaces the old three, that the mount point and the valU prompt are on
 * the page, that the driver is loaded and configured, and that the three
 * server endpoints behave — including the one that must refuse an amount
 * change while a payment is running.
 *
 * The SDK itself is not exercised here. It is remote, and this store's
 * keys are dummies, so the fields never mount. The endpoints do not depend
 * on the SDK, and they are where the money rules live.
 *
 * Usage: node tools/browser-tests/elements-test.mjs
 */
import { chromium } from 'playwright-core';

const BASE = process.env.STORE_URL || 'http://localhost:8080';
// The classic checkout, by page id. The store's own /checkout is the Blocks
// one, and these checks are about the classic path's markup.
const CLASSIC = process.env.CLASSIC_CHECKOUT || '/?page_id=29';
const EXECUTABLE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const results = [];
function check( name, pass, detail ) {
	results.push( { name, pass, detail } );
	console.log( `${ pass ? 'PASS' : 'FAIL' }  ${ name }${ detail ? `\n        ${ detail }` : '' }` );
}

const browser = await chromium.launch( { executablePath: EXECUTABLE, headless: true } );
const page = await browser.newPage();
const consoleErrors = [];
page.on( 'pageerror', ( e ) => consoleErrors.push( String( e ) ) );

try {
	// A cart with something in it, or checkout has nothing to show.
	await page.goto( `${ BASE }/?page_id=10`, { waitUntil: 'domcontentloaded' } );
	const addBtn = page.locator( 'a.add_to_cart_button, button.add_to_cart_button' ).first();
	await addBtn.click();
	await page.waitForTimeout( 1500 );

	await page.goto( `${ BASE }${ CLASSIC }`, { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 1200 );

	/* ── One row, not three ──────────────────────────────────────────── */

	const rows = await page.locator( 'input[name="payment_method"]' ).evaluateAll( ( els ) =>
		els.map( ( el ) => el.value )
	);
	const xpayRows = rows.filter( ( r ) => r.startsWith( 'xpay' ) );
	check(
		'one XPay row at checkout',
		xpayRows.length === 1 && xpayRows[ 0 ] === 'xpay',
		`rows: ${ JSON.stringify( rows ) }`
	);
	check(
		'the old per-method rows are gone',
		! rows.some( ( r ) => /^xpay_(card|valu|fawry)$/.test( r ) ),
		'a store upgraded from split mode must not keep showing them'
	);

	// Select XPay so its body renders.
	const xpayRadio = page.locator( 'input[value="xpay"]' );
	if ( await xpayRadio.count() ) {
		await xpayRadio.check();
		await page.waitForTimeout( 1200 );
	}

	/* ── The mount point and the prompt ──────────────────────────────── */

	check(
		'the Elements mount point is on the page',
		( await page.locator( '#xpay-elements-mount' ).count() ) === 1
	);
	const promptCount = await page.locator( '[data-xpay-bnpl-phone]' ).count();
	check( 'the valU prompt is present in the markup', promptCount === 1 );
	check(
		'the valU prompt starts hidden',
		promptCount === 1 && ( await page.locator( '[data-xpay-bnpl-phone]' ).isHidden() ),
		'it must only appear once the shopper picks valU inside XPay'
	);

	/* ── The driver ──────────────────────────────────────────────────── */

	let params = await page.evaluate( () => window.xpayElementsParams || null );
	check( 'the driver is configured', !! params, params ? '' : 'xpayElementsParams missing' );
	if ( params ) {
		check( 'a nonce is issued', !! params.nonce );
		check(
			'the methods needing a number come from the server',
			Array.isArray( params.bnplPhone?.methods ) && params.bnplPhone.methods.length > 0,
			`methods: ${ JSON.stringify( params.bnplPhone?.methods ) }`
		);
		check(
			'the theme is passed through',
			[ 'system', 'light', 'dark' ].includes( params.colorMode ),
			`colorMode: ${ params.colorMode }`
		);
	}
	check(
		'no page errors from our scripts',
		consoleErrors.length === 0,
		consoleErrors.join( '\n        ' )
	);

	/* ── The endpoints ───────────────────────────────────────────────── */

	/**
	 * Call one of our AJAX endpoints from inside the page, so it carries
	 * the real cookies and the real nonce.
	 */
	async function ask( action, extra = {} ) {
		return page.evaluate(
			async ( { action, extra, ajaxUrl, nonce } ) => {
				const form = new FormData();
				form.append( 'action', `xpay_elements_${ action }` );
				form.append( 'nonce', nonce );
				Object.entries( extra ).forEach( ( [ k, v ] ) => form.append( k, v ) );
				const res = await fetch( ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: form,
				} );
				let json = null;
				try {
					json = await res.json();
				} catch ( e ) {
					json = null;
				}
				return { status: res.status, json };
			},
			{ action, extra, ajaxUrl: params.ajaxUrl, nonce: params.nonce }
		);
	}

	// A bad nonce must be refused outright: these move money-shaped state.
	const badNonce = await page.evaluate( async ( ajaxUrl ) => {
		const form = new FormData();
		form.append( 'action', 'xpay_elements_paying' );
		form.append( 'nonce', 'not-a-real-nonce' );
		const res = await fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } );
		return res.status;
	}, params.ajaxUrl );
	check( 'a forged nonce is refused', badNonce === 403, `status ${ badNonce }` );

	const created = await ask( 'session' );
	const startAmount = created.json?.data?.amount;
	check(
		'a session is created for the cart',
		created.status === 200 && !! created.json?.data?.clientSecret,
		`amount: ${ startAmount }`
	);

	const lock = await ask( 'paying' );
	check( 'a payment can be announced', lock.json?.success === true );

	/* ── The guard, with a cart that genuinely moves ─────────────────── */

	// Add a second product WHILE the payment is running. This is the exact
	// shape of the platform gap in xpayeg/woocommerce#2: nothing on the
	// server side refuses an amount change mid-payment, so the plugin must.
	await page.goto( `${ BASE }/?page_id=10`, { waitUntil: 'domcontentloaded' } );
	await page.locator( 'a.add_to_cart_button, button.add_to_cart_button' ).nth( 1 ).click();
	await page.waitForTimeout( 1800 );
	await page.goto( `${ BASE }${ CLASSIC }`, { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 1000 );
	params = await page.evaluate( () => window.xpayElementsParams );

	const syncLocked = await ask( 'sync' );
	check(
		'an amount change is refused while a payment is running',
		syncLocked.json?.data?.outcome === 'locked',
		`outcome: ${ syncLocked.json?.data?.outcome }`
	);
	check(
		'the session keeps the amount the shopper agreed to',
		syncLocked.json?.data?.amount === startAmount,
		`still ${ syncLocked.json?.data?.amount }, not the new cart total`
	);

	// Second line of defence: even asked to pay, a drifted cart is refused
	// rather than charged at a number the shopper never saw.
	const payStale = await ask( 'paying' );
	check(
		'paying with a drifted cart is refused',
		payStale.status === 409 && payStale.json?.data?.reason === 'stale-amount',
		`status ${ payStale.status }, reason ${ payStale.json?.data?.reason }`
	);

	const release = await ask( 'paid' );
	check( 'the lock is released when the payment ends', release.json?.success === true );

	const syncAfter = await ask( 'sync' );
	check(
		'the amount is brought in line once the payment is over',
		syncAfter.json?.data?.outcome === 'updated' && syncAfter.json?.data?.amount !== startAmount,
		`outcome: ${ syncAfter.json?.data?.outcome }, amount: ${ syncAfter.json?.data?.amount }`
	);

} catch ( error ) {
	check( 'the test itself ran', false, String( error && error.stack ? error.stack : error ) );
} finally {
	await browser.close();
}

const failed = results.filter( ( r ) => ! r.pass );
console.log(
	`\n${ results.length - failed.length }/${ results.length } checks passed`
);
process.exit( failed.length ? 1 : 0 );
