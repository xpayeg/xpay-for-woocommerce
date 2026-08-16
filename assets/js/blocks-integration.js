/**
 * Cart & Checkout Blocks registration for the XPay gateway.
 *
 * Deliberately build-less plain JS (no JSX/webpack): the surface is a
 * label and description — Blocks' standard redirect flow does the rest,
 * and a build step would be tooling overhead with no shopper benefit.
 * WP.org review also favors reviewable, unminified source.
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

	var settings = getSetting( 'xpay_data', {} );
	var label = decodeEntities( settings.title || 'XPay' );
	var description = decodeEntities( settings.description || '' );

	registerPaymentMethod( {
		name: 'xpay',
		label: label,
		ariaLabel: label,
		content: createElement( 'p', { style: { margin: 0 } }, description ),
		edit: createElement( 'p', { style: { margin: 0 } }, description ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: ( settings.supports || [ 'products' ] ),
		},
	} );
} )();
