/**
 * Proof that the valU wallet-number prompt behaves on the real checkout:
 * absent for card, absent for a good Egyptian mobile, present for the
 * Emirati-mobile trap, and gone again once corrected.
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

const results = [];
function check( name, actual, expected ) {
	const pass = actual === expected;
	results.push( { name, actual, expected, pass } );
	console.log( `${ pass ? 'PASS' : 'FAIL' }  ${ name } (got ${ actual }, want ${ expected } )` );
}

const browser = await chromium.launch( {
	executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
	args: [ '--no-sandbox' ],
} );
const page = await browser.newPage( { viewport: { width: 1280, height: 1400 } } );
page.on( 'pageerror', ( e ) => console.log( 'PAGE ERROR:', e.message ) );

// Put something in the cart, then go to checkout.
await page.goto( `${ BASE }/?p=5`, { waitUntil: 'domcontentloaded' } );
await page.click( 'button[name="add-to-cart"], .single_add_to_cart_button' );
await page.waitForTimeout( 1500 );
await page.goto( `${ BASE }/classic-checkout-probe/`, { waitUntil: 'domcontentloaded' } );
await page.waitForSelector( '#billing_phone', { state: 'attached', timeout: 20000 } );

async function fillPhone( value ) {
	await page.fill( '#billing_phone', value );
	await page.dispatchEvent( '#billing_phone', 'change' );
	// Debounce is 600ms, then WooCommerce's own AJAX refresh.
	await page.waitForTimeout( 2500 );
	await page.waitForFunction(
		() => ! document.querySelector( '.blockUI.blockOverlay' ),
		{ timeout: 20000 }
	).catch( () => {} );
}

async function selectMethod( id ) {
	// The theme hides the radio and paints a label, so drive it the way
	// WooCommerce's own script does rather than clicking pixels.
	await page.evaluate( ( gid ) => {
		const el = document.getElementById( 'payment_method_' + gid );
		el.checked = true;
		window.jQuery( el ).trigger( 'click' ).trigger( 'change' );
	}, id );
	await page.waitForTimeout( 1500 );
}

async function promptVisible() {
	return page.isVisible( '#xpay_wallet_phone' ).catch( () => false );
}

// Which XPay rows exist at all. The payment box arrives with the first
// checkout refresh, so wait for the row rather than reading on load.
await page.waitForSelector( '#payment_method_xpay_valu', { state: 'attached', timeout: 30000 } );
const rows = await page.$$eval( 'input[name="payment_method"]', ( els ) => els.map( ( e ) => e.value ) );
console.log( 'payment rows:', rows.join( ', ' ) );

// 1. Card row with a number that fails: no prompt, because a card payment
//    never spends a wallet.
await fillPhone( '563333431' );
await selectMethod( 'xpay_card' );
check( 'card row, bad phone: no prompt', await promptVisible(), false );

// 2. valU row, same bad number: the prompt appears.
await selectMethod( 'xpay_valu' );
check( 'valU row, Emirati-mobile trap: prompt shown', await promptVisible(), true );
await page.screenshot( { path: `${ OUT }/valu-prompt-shown.png`, fullPage: false } );

// 3. valU row, a real Egyptian mobile: no prompt.
await fillPhone( '01012345678' );
await selectMethod( 'xpay_valu' );
check( 'valU row, good Egyptian mobile: no prompt', await promptVisible(), false );
await page.screenshot( { path: `${ OUT }/valu-prompt-hidden.png`, fullPage: false } );

// 4. Back to the bad number, type a correction, and confirm it survives a
//    checkout refresh triggered by something else.
await fillPhone( '563333431' );
await selectMethod( 'xpay_valu' );
if ( await promptVisible() ) {
	await page.fill( '#xpay_wallet_phone', '01112345678' );
	await page.evaluate( () => window.jQuery( document.body ).trigger( 'update_checkout' ) );
	await page.waitForTimeout( 2500 );
	const carried = await page.inputValue( '#xpay_wallet_phone' ).catch( () => '' );
	check( 'typed correction survives a refresh', carried, '01112345678' );
}

await browser.close();

const failed = results.filter( ( r ) => ! r.pass );
console.log( `\n${ results.length - failed.length }/${ results.length } checks passed` );
process.exit( failed.length === 0 ? 0 : 1 );
