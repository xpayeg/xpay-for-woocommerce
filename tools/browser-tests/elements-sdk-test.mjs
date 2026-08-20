/**
 * Elements behaviour that needs an SDK — against a running test store.
 *
 * The real SDK is remote and this store has dummy keys, so these checks
 * run against a fake one served locally and pointed at with
 * XPAY_WC_SDK_URL. The fake models only what checkout-elements.js actually
 * calls, taken from that module: initCheckout -> getElements -> create
 * -> on('change'|'ready') -> mount, and checkout.confirm.
 *
 * What is being tested is ours, not the fake's: that the valU prompt
 * follows the method the shopper picks inside the fields, that the store's
 * own theme reaches the SDK, and that the number the shopper types is the
 * one sent to be charged.
 *
 * Setup: see tools/browser-tests/README.md — the stub API and fake SDK
 * both need to be running, and wp-config must point at them.
 *
 * Usage: node tools/browser-tests/elements-sdk-test.mjs
 */
import { chromium } from 'playwright-core';

const BASE = process.env.STORE_URL || 'http://localhost:8080';
const CLASSIC = process.env.CLASSIC_CHECKOUT || '/?page_id=29';
const BLOCKS = process.env.BLOCKS_CHECKOUT || '/?page_id=12';
const SHOP = process.env.SHOP_PAGE || '/?page_id=10';
const EXECUTABLE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const results = [];
function check( name, pass, detail ) {
	results.push( { name, pass, detail } );
	console.log( `${ pass ? 'PASS' : 'FAIL' }  ${ name }${ detail ? `\n        ${ detail }` : '' }` );
}

const browser = await chromium.launch( { executablePath: EXECUTABLE, headless: true } );

/**
 * A page with something in the cart, parked on the given checkout.
 *
 * @param {string} checkoutPath Which checkout to land on.
 * @return {Promise<Object>} The page, and any errors it threw.
 */
async function shopperAt( checkoutPath ) {
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( String( e ) ) );
	await page.goto( `${ BASE }${ SHOP }`, { waitUntil: 'domcontentloaded' } );
	await page.locator( 'a.add_to_cart_button, button.add_to_cart_button' ).first().click();
	await page.waitForTimeout( 1800 );
	await page.goto( `${ BASE }${ checkoutPath }`, { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 2500 );
	return { page, errors };
}

try {
	/* ── Classic: the prompt follows the method ──────────────────────── */

	const { page, errors } = await shopperAt( CLASSIC );

	check(
		'the payment fields mount',
		( await page.evaluate( () => window.__xpayFake && window.__xpayFake.mounted ) ) === true
	);

	const prompt = page.locator( '[data-xpay-bnpl-phone]' );
	check( 'the valU prompt starts hidden', await prompt.isHidden() );

	await page.locator( '[data-fake-method="card"]' ).click();
	await page.waitForTimeout( 300 );
	check( 'picking card leaves the prompt hidden', await prompt.isHidden() );

	await page.locator( '[data-fake-method="valu"]' ).click();
	await page.waitForTimeout( 300 );
	check( 'picking valU reveals the prompt', await prompt.isVisible() );
	check(
		'the prompt says why it is asking',
		( await page.locator( '[data-xpay-bnpl-hint]' ).innerText() ).toLowerCase().includes( 'valu' )
	);

	// Fawry is not a card either, and must NOT ask: the rule is a method
	// list, not a not-card test.
	await page.locator( '[data-fake-method="fawry"]' ).click();
	await page.waitForTimeout( 300 );
	check( 'picking Fawry hides the prompt again', await prompt.isHidden() );

	/* ── The store's theme reaches the SDK ───────────────────────────── */

	const appearance = await page.evaluate( () => window.__xpayFake && window.__xpayFake.appearance );
	check(
		'the store\'s own theme is measured and sent',
		!! appearance && !! appearance.colors && !! appearance.fontFamily,
		`fontFamily: ${ appearance && appearance.fontFamily ? appearance.fontFamily.slice( 0, 40 ) : 'none' }`
	);
	check(
		'the shopper\'s device decides light or dark by default',
		!! appearance && appearance.colorMode === 'system',
		`colorMode: ${ appearance && appearance.colorMode }`
	);

	/* ── The number the shopper gives is the one charged ─────────────── */

	await page.locator( '[data-fake-method="valu"]' ).click();
	await page.waitForTimeout( 300 );
	await page.locator( '[data-xpay-bnpl-input]' ).fill( '01012345678' );
	await page.evaluate( () => {
		const form = document.querySelector( 'form.checkout' );
		if ( form && window.jQuery ) {
			window.jQuery( document ).trigger( 'checkout_place_order_xpay' );
		}
	} );
	await page.waitForTimeout( 1500 );

	const confirms = await page.evaluate( () => window.__xpayFake && window.__xpayFake.confirms );
	const sent = confirms && confirms.length ? confirms[ confirms.length - 1 ] : null;
	check(
		'confirm is called when the shopper pays',
		!! sent,
		sent ? '' : 'no confirm reached the SDK'
	);
	if ( sent ) {
		check(
			'the valU number the shopper typed is the one sent',
			sent.customerDetails && sent.customerDetails.phone === '01012345678',
			`phone sent: ${ sent.customerDetails && sent.customerDetails.phone }`
		);
	}

	check( 'the classic page threw nothing', errors.length === 0, errors.join( ' | ' ) );
	await page.close();

	/* ── Blocks gets the same fields ─────────────────────────────────── */

	const blocks = await shopperAt( BLOCKS );
	const radio = blocks.page.locator( '#radio-control-wc-payment-method-options-xpay' );
	if ( await radio.count() ) {
		await radio.check();
		await blocks.page.waitForTimeout( 2500 );
	}
	check(
		'the fields mount on the Blocks checkout too',
		( await blocks.page.evaluate( () => window.__xpayFake && window.__xpayFake.mounted ) ) === true
	);
	const blocksPrompt = blocks.page.locator( '[data-xpay-bnpl-phone], .xpay-bnpl-phone' );
	if ( await blocks.page.locator( '[data-fake-method="valu"]' ).count() ) {
		await blocks.page.locator( '[data-fake-method="valu"]' ).click();
		await blocks.page.waitForTimeout( 400 );
		check(
			'the valU prompt appears on Blocks as well',
			( await blocksPrompt.count() ) > 0 && ( await blocksPrompt.first().isVisible() )
		);
	} else {
		check( 'the valU prompt appears on Blocks as well', false, 'the fake method buttons never rendered' );
	}
	check( 'the Blocks page threw nothing', blocks.errors.length === 0, blocks.errors.join( ' | ' ) );
	await blocks.page.close();
} catch ( error ) {
	check( 'the test itself ran', false, String( error && error.stack ? error.stack : error ) );
} finally {
	await browser.close();
}

const failed = results.filter( ( r ) => ! r.pass );
console.log( `\n${ results.length - failed.length }/${ results.length } checks passed` );
process.exit( failed.length ? 1 : 0 );
