/**
 * Pure-function guards for assets/js/checkout-appearance.js.
 *
 * Runs on node with no browser: everything tested here is arithmetic and
 * mapping, which is exactly the part that must not drift. The DOM reader
 * sits in the same file and is exercised by the browser harness instead,
 * because only a browser has computed styles.
 *
 *   node --test tools/js-tests/
 *
 * The colour cases matter more than they look. XPay's API accepts hex only
 * and rejects a session outright otherwise, while browsers always answer in
 * rgb(). A conversion bug here is not a cosmetic slip, it is a checkout
 * that will not open.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire( import.meta.url );
const here = path.dirname( fileURLToPath( import.meta.url ) );
const A = require( path.join( here, '..', '..', 'assets', 'js', 'checkout-appearance.js' ) );

/* ── toHex ────────────────────────────────────────────────────────────── */

test( 'rgb() becomes six-digit hex', () => {
	assert.equal( A.toHex( 'rgb(99, 91, 255)' ), '#635bff' );
	assert.equal( A.toHex( 'rgb(0, 0, 0)' ), '#000000' );
	assert.equal( A.toHex( 'rgb(255, 255, 255)' ), '#ffffff' );
} );

test( 'partial alpha becomes eight-digit hex, which the API accepts', () => {
	assert.equal( A.toHex( 'rgba(99, 91, 255, 0.5)' ), '#635bff80' );
	assert.equal( A.toHex( 'rgba(0, 0, 0, 1)' ), '#000000' );
} );

test( 'invisible is absent, not black', () => {
	// The whole reason backgroundOf walks up the tree: a literal reading
	// of a transparent container paints the payment form black.
	assert.equal( A.toHex( 'rgba(0, 0, 0, 0)' ), null );
	assert.equal( A.toHex( 'transparent' ), null );
} );

test( 'modern space and slash syntax is understood', () => {
	assert.equal( A.toHex( 'rgb(99 91 255)' ), '#635bff' );
	assert.equal( A.toHex( 'rgb(99 91 255 / 50%)' ), '#635bff80' );
} );

test( 'hex passes through, normalised', () => {
	assert.equal( A.toHex( '#635BFF' ), '#635bff' );
	assert.equal( A.toHex( '#abc' ), '#abc' );
	assert.equal( A.toHex( '#635bff80' ), '#635bff80' );
} );

test( 'anything the API would reject comes back null', () => {
	// Never pass a guess through: a rejected colour fails the session.
	for ( const bad of [
		'oklch(0.51 0.23 277)',
		'color(display-p3 1 0 0)',
		'rebeccapurple',
		'#12345',
		'',
		'   ',
		null,
		undefined,
		42,
	] ) {
		assert.equal( A.toHex( bad ), null, `expected null for ${ String( bad ) }` );
	}
} );

test( 'out-of-range channels are clamped rather than wrapped', () => {
	assert.equal( A.toHex( 'rgb(300, -20, 128)' ), '#ff0080' );
} );

/* ── isDark ───────────────────────────────────────────────────────────── */

test( 'obvious grounds are classified correctly', () => {
	assert.equal( A.isDark( 'rgb(0, 0, 0)' ), true );
	assert.equal( A.isDark( '#0e0d14' ), true );
	assert.equal( A.isDark( 'rgb(255, 255, 255)' ), false );
	assert.equal( A.isDark( '#fbfbfd' ), false );
} );

test( 'luminance is weighted, not averaged', () => {
	// Identical channel averages, opposite verdicts. A plain average calls
	// both the same and puts white fields on a mid-green page.
	assert.equal( A.isDark( 'rgb(0, 200, 0)' ), false );
	assert.equal( A.isDark( 'rgb(0, 0, 200)' ), true );
} );

test( 'an unreadable colour is not called dark', () => {
	// Guessing dark would flip a light store to a dark form on one bad read.
	assert.equal( A.isDark( 'oklch(0.2 0 0)' ), false );
	assert.equal( A.isDark( null ), false );
} );

/* ── mix ──────────────────────────────────────────────────────────────── */

test( 'mix interpolates and clamps its amount', () => {
	assert.equal( A.mix( '#000000', '#ffffff', 0.5 ), '#808080' );
	assert.equal( A.mix( '#000000', '#ffffff', 0 ), '#000000' );
	assert.equal( A.mix( '#000000', '#ffffff', 1 ), '#ffffff' );
	assert.equal( A.mix( '#000000', '#ffffff', 5 ), '#ffffff' );
	assert.equal( A.mix( '#000000', '#ffffff', -5 ), '#000000' );
} );

test( 'mix refuses when either side is unreadable', () => {
	assert.equal( A.mix( 'oklch(0.5 0 0)', '#ffffff', 0.5 ), null );
	assert.equal( A.mix( '#ffffff', 'transparent', 0.5 ), null );
} );

/* ── shape and density ────────────────────────────────────────────────── */

test( 'radius is judged against the element it belongs to', () => {
	assert.equal( A.borderStyle( 0, 40 ), 'sharp' );
	assert.equal( A.borderStyle( 2, 40 ), 'sharp' );
	assert.equal( A.borderStyle( 8, 40 ), 'rounded' );
	// The same 20px reads differently on a field and on a card.
	assert.equal( A.borderStyle( 20, 40 ), 'pill' );
	assert.equal( A.borderStyle( 20, 200 ), 'rounded' );
	assert.equal( A.borderStyle( 9999, 40 ), 'pill' );
} );

test( 'input size follows measured height', () => {
	assert.equal( A.inputSize( 32 ), 'small' );
	assert.equal( A.inputSize( 44 ), 'medium' );
	assert.equal( A.inputSize( 60 ), 'large' );
	// Nothing measured is a default, never a guess.
	assert.equal( A.inputSize( 0 ), 'medium' );
	assert.equal( A.inputSize( NaN ), 'medium' );
} );

test( 'input style distinguishes filled from outlined from flat', () => {
	assert.equal( A.inputStyle( 1, '#eeeeee', '#ffffff' ), 'filled' );
	assert.equal( A.inputStyle( 1, '#ffffff', '#ffffff' ), 'outlined' );
	assert.equal( A.inputStyle( 0, '#ffffff', '#ffffff' ), 'flat' );
	assert.equal( A.inputStyle( 0, null, null ), 'flat' );
} );

test( 'density follows padding', () => {
	assert.deepEqual( A.density( 10 ), { spacing: 'condensed', formLayout: 'compact' } );
	assert.deepEqual( A.density( 20 ), { spacing: 'normal', formLayout: 'spacious' } );
	assert.deepEqual( A.density( 40 ), { spacing: 'spacious', formLayout: 'spacious' } );
	assert.deepEqual( A.density( NaN ), { spacing: 'normal', formLayout: 'spacious' } );
} );

/* ── clean ────────────────────────────────────────────────────────────── */

test( 'one unreadable colour does not cost the whole appearance', () => {
	const out = A.clean( {
		colorMode: 'dark',
		colors: { primary: '#635bff', background: null, foreground: 'oklch(0 0 0)' },
	} );
	assert.deepEqual( out, { colorMode: 'dark', colors: { primary: '#635bff' } } );
} );

test( 'an all-bad palette drops the colors key rather than sending {}', () => {
	const out = A.clean( { colorMode: 'light', colors: { primary: 'nope' } } );
	assert.deepEqual( out, { colorMode: 'light' } );
} );

test( 'font family is bounded to what the API stores', () => {
	const out = A.clean( { fontFamily: 'x'.repeat( 900 ) } );
	assert.equal( out.fontFamily.length, 512 );
} );

test( 'empty and missing values are dropped, not sent as blanks', () => {
	assert.deepEqual( A.clean( { colorMode: '', spacing: null, inputSize: undefined } ), {} );
	assert.deepEqual( A.clean( undefined ), {} );
} );

test( 'colours are normalised to lower case on the way out', () => {
	const out = A.clean( { colors: { primary: '#635BFF' } } );
	assert.equal( out.colors.primary, '#635bff' );
} );
