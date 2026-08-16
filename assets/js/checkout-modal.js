/**
 * XPay drop-in modal on the order-pay page.
 *
 * Flow:
 *   1. Load the XPay SDK (script injection with a hard timeout).
 *   2. Open xpay.checkout({ clientSecret, mode: 'modal' }) immediately.
 *   3. onComplete -> the SDK auto-closes; we navigate to the thank-you
 *      page, where the server re-verifies before claiming success.
 *   4. onClose without payment -> calm "pay when ready" state with a
 *      re-open button. Never a dead end.
 *   5. SDK load failure -> automatic redirect to the hosted checkout page
 *      (same session). The shopper never sees a broken modal.
 *
 * No jQuery dependency: the pay page is ours, keep the surface tiny.
 */
( function () {
	'use strict';

	var params = window.xpayCheckoutParams;
	if ( ! params || ! params.clientSecret ) {
		return;
	}

	var SDK_TIMEOUT_MS = 6000; // Covers slow EG mobile networks; beyond this the hosted page is faster than waiting.

	var statusEl = document.getElementById( 'xpay-payment-status' );
	var payButton = document.getElementById( 'xpay-pay-button' );
	var hostedLink = document.getElementById( 'xpay-hosted-link' );

	function setStatus( text ) {
		if ( statusEl ) {
			statusEl.textContent = text;
		}
	}

	function goHosted() {
		if ( params.hostedUrl ) {
			setStatus( params.i18n.fallback );
			window.location.href = params.hostedUrl;
		} else if ( hostedLink ) {
			hostedLink.style.display = '';
		}
	}

	function openModal( xpay ) {
		var modal = xpay.checkout( {
			clientSecret: params.clientSecret,
			mode: 'modal',
			locale: params.locale || 'en',
			onComplete: function () {
				// The thank-you page re-verifies server-side; this redirect
				// carries no authority of its own.
				window.location.href = params.returnUrl;
			},
			onClose: function () {
				setStatus( params.i18n.closed );
				if ( payButton ) {
					payButton.style.display = '';
				}
				if ( hostedLink ) {
					hostedLink.style.display = '';
				}
			},
			onError: function () {
				// The modal shows its own error UI for payment failures;
				// this fires for setup-level errors — fall back rather
				// than strand the shopper.
				goHosted();
			},
		} );

		if ( payButton ) {
			payButton.onclick = function () {
				setStatus( params.i18n.preparing );
				payButton.style.display = 'none';
				modal.open();
			};
		}

		setStatus( params.i18n.preparing );
		modal.open();
	}

	function loadSdk() {
		if ( window.XPay ) {
			openModal( window.XPay( params.publishableKey ) );
			return;
		}

		var done = false;
		var timer = setTimeout( function () {
			if ( ! done ) {
				done = true;
				goHosted();
			}
		}, SDK_TIMEOUT_MS );

		var script = document.createElement( 'script' );
		script.src = params.sdkUrl;
		script.async = true;
		script.onload = function () {
			if ( done ) {
				return;
			}
			done = true;
			clearTimeout( timer );
			if ( window.XPay ) {
				openModal( window.XPay( params.publishableKey ) );
			} else {
				goHosted();
			}
		};
		script.onerror = function () {
			if ( ! done ) {
				done = true;
				clearTimeout( timer );
				goHosted();
			}
		};
		document.head.appendChild( script );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', loadSdk );
	} else {
		loadSdk();
	}
} )();
