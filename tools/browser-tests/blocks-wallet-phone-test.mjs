/**
 * The valU wallet-number prompt on the Cart & Checkout Blocks checkout.
 *
 * Blocks decides nothing about phone numbers itself. The server publishes
 * its verdict on the Store API cart response and Blocks refetches that
 * whenever the address changes, so this test is really asking whether that
 * round trip works: edit the phone, and does the prompt follow.
 *
 * Run against a store whose checkout page is the Blocks checkout, which is
 * the test store's default. No probe page needed, unlike the classic tests.
 *
 *   npm install playwright-core
 *   node tools/browser-tests/blocks-wallet-phone-test.mjs
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

const BASE = process.env.XPAY_STORE_URL || 'http://localhost:8080';
const OUT = process.env.XPAY_SHOT_DIR || 'tools/browser-tests/screenshots';

// The gate's own error code. Asserted on the Store API response rather
// than on page text: the prompt's label contains the words "valU wallet"
// too, so a text match would call the prompt an error and pass whatever
// happened.
const GATE_CODE = 'xpay_wallet_phone_required';

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

// Every checkout response, so an assertion can look at what the server
// actually said rather than at what the page happens to be showing.
const checkoutResponses = [];
page.on( 'response', async ( response ) => {
	if ( ! response.url().includes( '/wc/store/v1/checkout' ) ) {
		return;
	}
	const body = await response.text().catch( () => '' );
	checkoutResponses.push( { status: response.status(), body } );
	if ( process.env.XPAY_DEBUG ) { console.log( 'RESP', response.status(), body.slice( 0, 260 ) ); }
} );
const sinceHere = () => checkoutResponses.length;
const gateFiredSince = ( mark ) =>
	checkoutResponses.slice( mark ).some( ( r ) => r.body.includes( GATE_CODE ) );
// Reaching the payment attempt, not completing one. The test store's keys
// are dummies, so a real payment can never succeed here; what proves the
// gate let the order through is the plugin's own failure message, which is
// produced inside process_payment and therefore only after every check
// before it has passed.
const REACHED_PROCESS_PAYMENT = 'payment could not be started';
const reachedPaymentSince = ( mark ) =>
	checkoutResponses
		.slice( mark )
		.some( ( r ) => r.body.includes( REACHED_PROCESS_PAYMENT ) || r.body.includes( '"payment_result"' ) );

await page.goto( `${ BASE }/?p=5`, { waitUntil: 'domcontentloaded' } );
await page.click( 'button[name="add-to-cart"], .single_add_to_cart_button' );
await page.waitForTimeout( 1500 );
await page.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
await page.waitForSelector( '#billing-phone', { timeout: 30000 } );
await page.waitForSelector( '#radio-control-wc-payment-method-options-xpay_valu', { timeout: 30000 } );

/** Blocks pushes the address to the Store API on blur, then refetches. */
async function settle() {
	await page.waitForTimeout( 3500 );
}

async function fillField( selector, value ) {
	await page.fill( selector, value );
	await page.dispatchEvent( selector, 'change' );
	await page.dispatchEvent( selector, 'blur' );
}

async function selectMethod( id ) {
	await page.check( `#radio-control-wc-payment-method-options-${ id }`, { force: true } );
	await page.waitForTimeout( 1500 );
}

async function promptVisible() {
	return page.isVisible( '#xpay_wallet_phone' ).catch( () => false );
}

async function placeOrder() {
	await page.click( '.wc-block-components-checkout-place-order-button' );
	await page.waitForTimeout( 9000 );
}

async function fillEgyptianShopper( phone ) {
	await page.selectOption( '#billing-country', 'EG' );
	await settle();
	await fillField( '#email', 'shopper@example.com' );
	await fillField( '#billing-first_name', 'Nour' );
	await fillField( '#billing-last_name', 'Hassan' );
	await fillField( '#billing-address_1', '12 Road 9, Maadi' );
	await fillField( '#billing-city', 'Cairo' );
	// Required for Egypt in Blocks; without it the Place Order button never
	// reaches the network and every gate assertion below silently passes.
	await fillField( '#billing-postcode', '11728' );
	await page.selectOption( '#billing-state', { index: 1 } ).catch( () => {} );
	await fillField( '#billing-phone', phone );
	await settle();
}

// An Emirati mobile typed by a shopper billed in Egypt: completes to a
// well-formed +20 number that reaches nobody.
await fillEgyptianShopper( '563333431' );

// Card first. The rule must not reach it.
await selectMethod( 'xpay_card' );
check( 'card row: no wallet prompt', await promptVisible(), false );

// valU: the server says ask, so the prompt appears.
await selectMethod( 'xpay_valu' );
check( 'valU row, unusable number: prompt shown', await promptVisible(), true );
await page.screenshot( { path: `${ OUT }/blocks-valu-prompt.png` } );

// Correct the billing phone itself and the prompt must withdraw, which is
// the whole point of publishing the verdict instead of copying the rule.
await fillField( '#billing-phone', '01012345678' );
await settle();
check( 'valU row, billing phone fixed: prompt withdrawn', await promptVisible(), false );

// Back to the unusable number, and answer the prompt instead.
await fillField( '#billing-phone', '563333431' );
await settle();
check( 'valU row: prompt returns', await promptVisible(), true );

// Selecting a row makes Blocks sync its draft order against the same
// endpoint the gate listens on. Nothing may be refused at that point: the
// shopper has not been asked yet.
check( 'selecting valU does not refuse the draft', gateFiredSince( 0 ), false );

// Ignoring the prompt must be refused.
let mark = sinceHere();
await placeOrder();
check( 'unanswered prompt: order refused by the gate', gateFiredSince( mark ), true );

// Answering it must be accepted, and reach the payment attempt.
await page.fill( '#xpay_wallet_phone', '01112345678' );
await page.waitForTimeout( 500 );
mark = sinceHere();
await placeOrder();
check( 'answered prompt: not refused by the gate', gateFiredSince( mark ), false );
check( 'answered prompt: reached the payment attempt', reachedPaymentSince( mark ), true );
await page.screenshot( { path: `${ OUT }/blocks-valu-accepted.png` } );

await browser.close();

const failed = results.filter( ( r ) => ! r.pass );
console.log( `\n${ results.length - failed.length }/${ results.length } checks passed` );
process.exit( failed.length === 0 ? 0 : 1 );
