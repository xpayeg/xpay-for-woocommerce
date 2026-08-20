/**
 * XPay checkout appearance — read the store's own look off the page.
 *
 * Under Elements the payment fields sit on the merchant's checkout page
 * instead of inside XPay's window, so a form styled to XPay's defaults
 * reads as a foreign object dropped into someone else's theme. XPay's
 * Appearance API can be told colours, fonts, radius and density; this file
 * works out what to tell it by measuring what the theme already does.
 *
 * The approach is WooPayments', not Stripe's. Stripe's own WooCommerce
 * plugin exposes styling as a PHP filter, which asks a merchant to write
 * code; WooPayments detects the store's styles and feeds them in as rules.
 * Detection wins here for the same reason: the merchants this plugin is
 * for will not be writing filters.
 *
 * Everything below the DOM reader is pure and unit tested from node in
 * tools/js-tests/appearance-test.mjs. The DOM reader itself is exercised
 * in the browser harness, because only a browser has computed styles.
 *
 * The API accepts hex only (#RGB, #RGBA, #RRGGBB, #RRGGBBAA) and rejects
 * the session outright otherwise, while browsers always answer in rgb().
 * Converting is therefore not a nicety: an unconverted colour is a failed
 * checkout, which is why toHex refuses anything it cannot represent rather
 * than passing a best guess through.
 */
( function ( window ) {
	'use strict';

	var XPayAppearance = {};

	/* ── Colour ───────────────────────────────────────────────────────── */

	/**
	 * A CSS colour as the API's hex, or null when it cannot be represented.
	 *
	 * Browsers answer getComputedStyle in rgb()/rgba(), so that is the only
	 * input shape worth handling; a hex value is passed through when it is
	 * already one of the four accepted widths. Fully transparent returns
	 * null rather than black, because "no colour here" must keep walking up
	 * the tree instead of painting the form black.
	 *
	 * @param {string} css A computed CSS colour.
	 * @return {?string} Hex string, or null.
	 */
	XPayAppearance.toHex = function ( css ) {
		if ( typeof css !== 'string' ) {
			return null;
		}
		var value = css.trim();
		if ( value === '' || value === 'transparent' ) {
			return null;
		}
		if ( value.charAt( 0 ) === '#' ) {
			return /^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test( value )
				? value.toLowerCase()
				: null;
		}
		var m = value.match( /^rgba?\(([^)]+)\)$/i );
		if ( ! m ) {
			return null;
		}
		var parts = m[ 1 ].split( /[\s,\/]+/ ).filter( function ( p ) {
			return p !== '';
		} );
		if ( parts.length < 3 ) {
			return null;
		}
		var rgb = parts.slice( 0, 3 ).map( function ( p ) {
			return clampByte( parseFloat( p ) );
		} );
		if ( rgb.some( isNaN ) ) {
			return null;
		}
		var hex = '#' + rgb.map( byteToHex ).join( '' );

		if ( parts.length > 3 ) {
			var alpha = parseAlpha( parts[ 3 ] );
			if ( alpha === 0 ) {
				return null; // Invisible: treat as absent, not as black.
			}
			if ( alpha < 1 ) {
				hex += byteToHex( clampByte( Math.round( alpha * 255 ) ) );
			}
		}
		return hex;
	};

	/**
	 * Whether a colour is dark enough that a form drawn on it needs the
	 * dark palette.
	 *
	 * Uses WCAG relative luminance rather than a plain average, so mid
	 * greens do not read as light while mid blues read as dark. The 0.4
	 * cut sits above WCAG's 0.179 contrast pivot on purpose: a form is a
	 * large surface, and erring toward the dark palette on an ambiguous
	 * mid-tone looks better than white fields on a dusk background.
	 *
	 * @param {string} css A computed CSS colour.
	 * @return {boolean} True when the colour is dark.
	 */
	XPayAppearance.isDark = function ( css ) {
		var hex = XPayAppearance.toHex( css );
		if ( ! hex ) {
			return false;
		}
		var rgb = hexToRgb( hex );
		if ( ! rgb ) {
			return false;
		}
		var channels = rgb.map( function ( c ) {
			var s = c / 255;
			return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
		} );
		var luminance =
			0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
		return luminance < 0.4;
	};

	/**
	 * Blend two colours, used to invent the muted tones a theme rarely
	 * declares outright.
	 *
	 * @param {string} fromCss Base colour.
	 * @param {string} toCss   Colour to move toward.
	 * @param {number} amount  0 keeps the base, 1 reaches the target.
	 * @return {?string} Hex string, or null when either side is unusable.
	 */
	XPayAppearance.mix = function ( fromCss, toCss, amount ) {
		var a = hexToRgb( XPayAppearance.toHex( fromCss ) );
		var b = hexToRgb( XPayAppearance.toHex( toCss ) );
		if ( ! a || ! b ) {
			return null;
		}
		var t = Math.min( 1, Math.max( 0, amount ) );
		return (
			'#' +
			a
				.map( function ( channel, i ) {
					return byteToHex( clampByte( Math.round( channel + ( b[ i ] - channel ) * t ) ) );
				} )
				.join( '' )
		);
	};

	/* ── Shape and density ────────────────────────────────────────────── */

	/**
	 * The API's borderStyle for a measured corner radius.
	 *
	 * Compared against the element's own height so a 20px radius reads as
	 * a pill on a 40px input and as a rounded corner on a 200px card.
	 *
	 * @param {number} radius Corner radius in pixels.
	 * @param {number} height Element height in pixels.
	 * @return {string} "sharp", "rounded" or "pill".
	 */
	XPayAppearance.borderStyle = function ( radius, height ) {
		if ( ! isFinite( radius ) || radius <= 2 ) {
			return 'sharp';
		}
		if ( isFinite( height ) && height > 0 && radius >= height / 2 ) {
			return 'pill';
		}
		return radius >= 999 ? 'pill' : 'rounded';
	};

	/**
	 * The API's inputSize for a measured input height.
	 *
	 * @param {number} height Input height in pixels.
	 * @return {string} "small", "medium" or "large".
	 */
	XPayAppearance.inputSize = function ( height ) {
		if ( ! isFinite( height ) || height <= 0 ) {
			return 'medium';
		}
		if ( height < 38 ) {
			return 'small';
		}
		return height > 52 ? 'large' : 'medium';
	};

	/**
	 * The API's inputStyle from how the theme draws its fields.
	 *
	 * @param {number}  borderWidth   Input border width in pixels.
	 * @param {?string} inputBg       Input background, hex or null.
	 * @param {?string} pageBg        Page background, hex or null.
	 * @return {string} "flat", "filled" or "outlined".
	 */
	XPayAppearance.inputStyle = function ( borderWidth, inputBg, pageBg ) {
		var hasBorder = isFinite( borderWidth ) && borderWidth > 0;
		var filled = !! inputBg && !! pageBg && inputBg !== pageBg;
		if ( filled ) {
			return 'filled';
		}
		return hasBorder ? 'outlined' : 'flat';
	};

	/**
	 * Density from the vertical padding a theme gives its inputs.
	 *
	 * @param {number} paddingY Top plus bottom padding, in pixels.
	 * @return {{spacing: string, formLayout: string}} Density choices.
	 */
	XPayAppearance.density = function ( paddingY ) {
		if ( ! isFinite( paddingY ) || paddingY <= 0 ) {
			return { spacing: 'normal', formLayout: 'spacious' };
		}
		if ( paddingY < 16 ) {
			return { spacing: 'condensed', formLayout: 'compact' };
		}
		return paddingY > 28
			? { spacing: 'spacious', formLayout: 'spacious' }
			: { spacing: 'normal', formLayout: 'spacious' };
	};

	/**
	 * Drop keys the API would reject, so one unreadable colour cannot fail
	 * the whole session. Every colour is hex-checked; blank strings and
	 * nulls are removed rather than sent.
	 *
	 * @param {Object} appearance A candidate appearance object.
	 * @return {Object} The same object with unusable entries removed.
	 */
	XPayAppearance.clean = function ( appearance ) {
		var out = {};
		var hexRe = /^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;
		Object.keys( appearance || {} ).forEach( function ( key ) {
			var value = appearance[ key ];
			if ( value === null || value === undefined || value === '' ) {
				return;
			}
			if ( key === 'colors' ) {
				var colors = {};
				Object.keys( value ).forEach( function ( name ) {
					if ( typeof value[ name ] === 'string' && hexRe.test( value[ name ] ) ) {
						colors[ name ] = value[ name ].toLowerCase();
					}
				} );
				if ( Object.keys( colors ).length ) {
					out.colors = colors;
				}
				return;
			}
			if ( key === 'fontFamily' ) {
				// The API bounds this at 512 characters.
				out.fontFamily = String( value ).slice( 0, 512 );
				return;
			}
			out[ key ] = value;
		} );
		return out;
	};

	/* ── Reading the live page ────────────────────────────────────────── */

	/**
	 * The nearest painted background walking up from an element.
	 *
	 * Themes leave checkout containers transparent constantly, and a
	 * transparent read taken literally paints the form black.
	 *
	 * @param {Element} el Starting element.
	 * @return {?string} Hex string, or null when nothing paints one.
	 */
	XPayAppearance.backgroundOf = function ( el ) {
		var node = el;
		while ( node && node.nodeType === 1 ) {
			var hex = XPayAppearance.toHex(
				window.getComputedStyle( node ).backgroundColor
			);
			if ( hex ) {
				return hex;
			}
			node = node.parentElement;
		}
		return null;
	};

	/**
	 * Measure the checkout and produce an Appearance object for the SDK.
	 *
	 * Every reading is optional: a theme that hides its place-order button
	 * behind a plugin, or renders no inputs yet, simply contributes fewer
	 * keys, and the SDK falls back to the merchant's dashboard branding for
	 * whatever is missing.
	 *
	 * @param {Object} options Optional overrides.
	 * @param {Element} options.anchor Element to measure around.
	 * @param {string}  options.colorMode "system", "light" or "dark".
	 * @return {Object} A cleaned Appearance object.
	 */
	XPayAppearance.detect = function ( options ) {
		var opts = options || {};
		var doc = window.document;
		var anchor = opts.anchor || doc.querySelector( 'form.checkout, .wc-block-checkout, body' ) || doc.body;

		var pageBg = XPayAppearance.backgroundOf( anchor ) || XPayAppearance.toHex(
			window.getComputedStyle( doc.body ).backgroundColor
		);
		var bodyStyle = window.getComputedStyle( doc.body );
		var foreground = XPayAppearance.toHex( bodyStyle.color );

		var input = anchor.querySelector( 'input[type="text"], input[type="email"], input[type="tel"], select' );
		var button = anchor.querySelector( 'button[type="submit"], #place_order, .wc-block-components-checkout-place-order-button' );

		var appearance = {
			colorMode: opts.colorMode || 'system',
			fontFamily: bodyStyle.fontFamily,
			colors: {},
		};

		if ( pageBg ) {
			appearance.colors.background = pageBg;
			if ( ! opts.colorMode || opts.colorMode === 'system' ) {
				// A theme that paints its own dark page should get the dark
				// form even when the device asks for light: the surrounding
				// page is the stronger signal about what the shopper sees.
				if ( XPayAppearance.isDark( pageBg ) ) {
					appearance.colorMode = 'dark';
				}
			}
		}
		if ( foreground ) {
			appearance.colors.foreground = foreground;
			if ( pageBg ) {
				appearance.colors.muted = XPayAppearance.mix( pageBg, foreground, 0.06 );
				appearance.colors.mutedForeground = XPayAppearance.mix( foreground, pageBg, 0.35 );
			}
		}

		if ( input ) {
			var inputStyle = window.getComputedStyle( input );
			var height = input.getBoundingClientRect().height;
			var borderColor = XPayAppearance.toHex( inputStyle.borderTopColor );
			var inputBg = XPayAppearance.toHex( inputStyle.backgroundColor );

			appearance.borderStyle = XPayAppearance.borderStyle(
				parseFloat( inputStyle.borderTopLeftRadius ),
				height
			);
			appearance.inputSize = XPayAppearance.inputSize( height );
			appearance.inputStyle = XPayAppearance.inputStyle(
				parseFloat( inputStyle.borderTopWidth ),
				inputBg,
				pageBg
			);
			var density = XPayAppearance.density(
				parseFloat( inputStyle.paddingTop ) + parseFloat( inputStyle.paddingBottom )
			);
			appearance.spacing = density.spacing;
			appearance.formLayout = density.formLayout;

			if ( borderColor ) {
				appearance.colors.border = borderColor;
				appearance.colors.input = borderColor;
			}
		}

		if ( button ) {
			var buttonStyle = window.getComputedStyle( button );
			var primary = XPayAppearance.toHex( buttonStyle.backgroundColor );
			var primaryForeground = XPayAppearance.toHex( buttonStyle.color );
			if ( primary ) {
				appearance.colors.primary = primary;
				appearance.colors.accent = primary;
				appearance.colors.ring = primary;
			}
			if ( primaryForeground ) {
				appearance.colors.primaryForeground = primaryForeground;
				appearance.colors.accentForeground = primaryForeground;
			}
		}

		return XPayAppearance.clean( appearance );
	};

	/* ── Helpers ──────────────────────────────────────────────────────── */

	function clampByte( n ) {
		if ( isNaN( n ) ) {
			return NaN;
		}
		return Math.min( 255, Math.max( 0, Math.round( n ) ) );
	}

	function byteToHex( n ) {
		var s = n.toString( 16 );
		return s.length === 1 ? '0' + s : s;
	}

	function parseAlpha( raw ) {
		var value = String( raw ).trim();
		if ( value.charAt( value.length - 1 ) === '%' ) {
			return parseFloat( value ) / 100;
		}
		return parseFloat( value );
	}

	function hexToRgb( hex ) {
		if ( ! hex ) {
			return null;
		}
		var body = hex.slice( 1 );
		if ( body.length === 3 || body.length === 4 ) {
			body = body
				.slice( 0, 3 )
				.split( '' )
				.map( function ( c ) {
					return c + c;
				} )
				.join( '' );
		}
		if ( body.length < 6 ) {
			return null;
		}
		return [
			parseInt( body.slice( 0, 2 ), 16 ),
			parseInt( body.slice( 2, 4 ), 16 ),
			parseInt( body.slice( 4, 6 ), 16 ),
		];
	}

	window.XPayAppearance = XPayAppearance;

	// Node reads the same file for the pure-function tests; a browser has
	// no module object and skips this.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = XPayAppearance;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
