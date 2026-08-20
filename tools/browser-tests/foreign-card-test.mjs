/**
 * End-to-end proof that the valU account rule never reaches a card payment.
 *
 * A shopper in the United Kingdom, with a British mobile that the Egyptian
 * mobile plan would reject outright, fills the checkout and clicks Place
 * Order paying by card. The plugin must let them through.
 *
 * "Let them through" is asserted precisely rather than loosely. The test
 * store's XPay keys are dummies, so the session call fails and the shopper
 * sees the plugin's payment-could-not-be-started message. That message is
 * itself the proof: it is produced inside process_payment, which
 * WooCommerce only reaches once validate_fields() has passed. Seeing it
 * means validation let the card shopper by.
 *
 * The mirror case runs too: the same British number on the valU row must
 * be stopped, which is what shows the gate is real and method-scoped
 * rather than switched off everywhere.
 */
// playwright-core is not a plugin dependency (the shipped plugin has no
// node_modules). Install it wherever you run this from: npm i playwright-core
let chromium;
try {
	( { chromium } = await import( 'playwright-core' ) );
} catch ( e ) {
	console.error( 'playwright-core not found. Run: npm install playwright-core' );
	process.exit( 2 );
}

const BASE = 'http://localhost:8080';
const OUT = process.env.XPAY_SHOT_DIR || 'tools/browser-tests/screenshots';

// Read from the error notice, not from the page: the prompt's own label
// contains the words "valU account" too, so a whole-page text match would
// call the prompt an error and pass whatever happened.
const WALLET_ERROR = 'valU account';
const REACHED_PROCESS_PAYMENT = 'payment could not be started';

const results = [];
function check( name, actual, expected ) {
	const pass = actual === expected;
	results.push( { name, pass } );
	console.log( `${ pass ? 'PASS' : 'FAIL' }  ${ name }  (got ${ actual }, want ${ expected })` );
}

const browser = await chromium.launch( {
	executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
	args: [ '--no-sandbox' ],
} );
const page = await browser.newPage( { viewport: { width: 1280, height: 1600 } } );
page.on( 'pageerror', ( e ) => console.log( 'PAGE ERROR:', e.message ) );

await page.goto( `${ BASE }/?p=5`, { waitUntil: 'domcontentloaded' } );
await page.click( 'button[name="add-to-cart"], .single_add_to_cart_button' );
await page.waitForTimeout( 1500 );
await page.goto( `${ BASE }/classic-checkout-probe/`, { waitUntil: 'domcontentloaded' } );
await page.waitForSelector( '#billing_phone', { state: 'attached', timeout: 30000 } );
await page.waitForSelector( '#payment_method_xpay_valu', { state: 'attached', timeout: 30000 } );

async function settle() {
	await page.waitForTimeout( 2500 );
	await page
		.waitForFunction( () => ! document.querySelector( '.blockUI.blockOverlay' ), { timeout: 30000 } )
		.catch( () => {} );
}

/** A complete British billing address with a British mobile. */
async function fillBritishShopper() {
	await page.selectOption( '#billing_country', 'GB' );
	await settle();
	await page.fill( '#billing_first_name', 'Alice' );
	await page.fill( '#billing_last_name', 'Bennett' );
	await page.fill( '#billing_address_1', '18 Bramble Lane' );
	await page.fill( '#billing_city', 'Manchester' );
	await page.fill( '#billing_postcode', 'M1 4WU' );
	await page.fill( '#billing_email', 'alice@example.co.uk' );
	await page.fill( '#billing_phone', '07700900123' );
	await page.dispatchEvent( '#billing_phone', 'change' );
	await settle();
}

async function selectMethod( id ) {
	await page.evaluate( ( gid ) => {
		const el = document.getElementById( 'payment_method_' + gid );
		el.checked = true;
		window.jQuery( el ).trigger( 'click' ).trigger( 'change' );
	}, id );
	await page.waitForTimeout( 1500 );
}

async function placeOrder() {
	await page.click( '#place_order' );
	await page.waitForTimeout( 6000 );
	await page
		.waitForFunction( () => ! document.querySelector( '.blockUI.blockOverlay' ), { timeout: 30000 } )
		.catch( () => {} );
	// Only what WooCommerce put in the notice area counts as the outcome.
	return (
		( await page
			.textContent( '.woocommerce-NoticeGroup, .woocommerce-error, .woocommerce-message' )
			.catch( () => '' ) ) || ''
	);
}

await fillBritishShopper();

// The prompt must not even appear for a card shopper.
await selectMethod( 'xpay_card' );
check( 'UK card shopper: no wallet prompt rendered', await page.isVisible( '#xpay_wallet_phone' ).catch( () => false ), false );
await page.screenshot( { path: `${ OUT }/foreign-card-checkout.png` } );

// And Place Order must get past validation.
let body = await placeOrder();
check( 'UK card shopper: not blocked by the wallet rule', body.includes( WALLET_ERROR ), false );
check( 'UK card shopper: reached process_payment', body.includes( REACHED_PROCESS_PAYMENT ), true );
await page.screenshot( { path: `${ OUT }/foreign-card-placed.png` } );

// The tightening, end to end. valU can only be charged on an Egyptian or
// Jordanian mobile, so this British shopper is now asked for one on the
// valU row. Before the plans moved into XPay_Bnpl_Phone their number was
// accepted, because only +20 was ever checked against a plan.
await selectMethod( 'xpay_valu' );
check( 'UK shopper on valU: prompt rendered', await page.isVisible( '#xpay_wallet_phone' ).catch( () => false ), true );
body = await placeOrder();
check( 'UK shopper on valU: blocked by the wallet rule', body.includes( WALLET_ERROR ), true );

// A Jordanian mobile in the prompt is accepted, so the rule is two
// countries wide rather than Egypt with extra steps.
//
// Written in international form on purpose. A number with no calling code
// is completed from the BILLING country, so this shopper typing the
// national "0791234567" would produce a British number, not a Jordanian
// one. That is the canonicaliser behaving correctly, and it is why the
// prompt is the right place to accept a full +962.
await page.fill( '#xpay_wallet_phone', '+962791234567' );
body = await placeOrder();
check( 'UK shopper, Jordanian wallet number: accepted', body.includes( WALLET_ERROR ), false );
check( 'UK shopper, Jordanian wallet number: reached process_payment', body.includes( REACHED_PROCESS_PAYMENT ), true );

// Mirror case: the gate is live and scoped to the method rather than
// switched off everywhere.
//
// Note what is NOT asserted here. A British mobile on the valU row is
// ACCEPTED, because the rule holds non-Egyptian numbers to E.164's bounds
// and only enforces Egypt's mobile plan on +20. So the mirror uses the
// case the rule actually exists for: an Egyptian billing country with an
// Emirati national number, which completes to a well-formed +20 number
// that reaches nobody.
// Clear the answer first: it is still holding the Jordanian number from
// the previous case, which is valid and would carry this case through.
await page.fill( '#xpay_wallet_phone', '' ).catch( () => {} );
await page.selectOption( '#billing_country', 'EG' );
await settle();
await page.fill( '#billing_phone', '563333431' );
await page.dispatchEvent( '#billing_phone', 'change' );
await settle();
await selectMethod( 'xpay_valu' );
check( 'EG shopper, Emirati number, valU: wallet prompt rendered', await page.isVisible( '#xpay_wallet_phone' ).catch( () => false ), true );
body = await placeOrder();
check( 'EG shopper, Emirati number, valU: blocked by the wallet rule', body.includes( WALLET_ERROR ), true );
await page.screenshot( { path: `${ OUT }/foreign-valu-blocked.png` } );

await browser.close();

const failed = results.filter( ( r ) => ! r.pass );
console.log( `\n${ results.length - failed.length }/${ results.length } checks passed` );
process.exit( failed.length === 0 ? 0 : 1 );
