/**
 * The valU wallet-number prompt on the classic checkout.
 *
 * Two jobs, both small, both about the fact that the decision to show this
 * prompt is made in PHP and never here. Whether a number is acceptable is
 * one rule living in XPay_Phone; duplicating it in JavaScript would give
 * the shopper a second opinion that drifts from the one that actually
 * gates the payment.
 *
 * 1. Ask WooCommerce to refresh the payment box when the billing phone
 *    changes. WooCommerce refreshes on country, state and postcode out of
 *    the box, but not on the phone, so without this the prompt would only
 *    appear after some other field happened to trigger a refresh.
 *
 * 2. Carry the shopper's typed number across those refreshes. WooCommerce
 *    replaces the whole payment box on every update, which would otherwise
 *    empty a field they had just filled in.
 */
( function () {
	'use strict';

	var FIELD = 'xpay_wallet_phone';
	var REFRESH_DELAY_MS = 600;

	if ( typeof window.jQuery === 'undefined' ) {
		return;
	}

	var $ = window.jQuery;
	var body = $( document.body );
	var carried = '';
	var timer = null;

	/** The prompt's input, if it is on the page right now. */
	function field() {
		return document.getElementById( FIELD );
	}

	/**
	 * Remember what the shopper typed before WooCommerce throws the payment
	 * box away.
	 */
	function remember() {
		var input = field();
		if ( input ) {
			carried = input.value;
		}
	}

	/**
	 * Put it back once the new payment box is in place, but never over
	 * something already there: a value rendered by the server is fresher
	 * than one held from before the refresh.
	 */
	function restore() {
		var input = field();
		if ( input && '' === input.value && '' !== carried ) {
			input.value = carried;
		}
	}

	/**
	 * A phone number is typed a digit at a time, and each keystroke would
	 * otherwise be its own round trip. Wait for a pause instead.
	 */
	function refreshSoon() {
		if ( null !== timer ) {
			window.clearTimeout( timer );
		}
		timer = window.setTimeout( function () {
			timer = null;
			remember();
			body.trigger( 'update_checkout' );
		}, REFRESH_DELAY_MS );
	}

	body.on( 'keyup change', '#billing_phone', refreshSoon );

	// Any other refresh — a changed country, an applied coupon — would also
	// wipe the field, so the value is carried across all of them.
	body.on( 'update_checkout', remember );
	body.on( 'updated_checkout', restore );
} )();
