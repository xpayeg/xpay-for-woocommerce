/**
 * Cart & Checkout Blocks registration for the XPay checkout rows.
 *
 * Deliberately build-less plain JS (no JSX/webpack): each row is a label,
 * an optional logo, and a description — Blocks' standard redirect flow
 * does the rest, and a build step would be tooling overhead with no
 * shopper benefit. WP.org review also favors reviewable, unminified source.
 *
 * The PHP side registers one payment method type per active row (combined
 * XPay, or Card/valU/Fawry in split mode); each publishes its data under
 * '<gateway id>_data'. The candidate id list arrives from PHP too
 * (xpayBlocksRowIds, built from the method registry), so adding a method
 * server-side reaches Blocks without touching this file.
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp ) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var createElement = window.wp.element.createElement;

	var ROW_IDS = window.xpayBlocksRowIds || [ 'xpay' ];

	function labelElement( title, iconUrl ) {
		if ( ! iconUrl ) {
			return title;
		}
		return createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
			title,
			createElement( 'img', {
				src: iconUrl,
				alt: '',
				style: { height: '20px', width: 'auto' },
			} )
		);
	}

	ROW_IDS.forEach( function ( id ) {
		var settings = getSetting( id + '_data', null );
		if ( ! settings ) {
			return;
		}

		var title = decodeEntities( settings.title || 'XPay' );
		var description = decodeEntities( settings.description || '' );

		registerPaymentMethod( {
			name: id,
			label: labelElement( title, settings.icon ),
			ariaLabel: title,
			content: createElement( 'p', { style: { margin: 0 } }, description ),
			edit: createElement( 'p', { style: { margin: 0 } }, description ),
			// Blocks swaps the Place Order label for this while the row is
			// selected — same string classic checkout shows via the
			// gateway's order_button_text.
			placeOrderButtonLabel:
				decodeEntities( settings.buttonLabel || '' ) || undefined,
			canMakePayment: function () {
				return true;
			},
			supports: {
				features: ( settings.supports || [ 'products' ] ),
			},
		} );
	} );
} )();
