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
 *   4b. Close request the SDK refuses to honor -> we honor it (see
 *      releaseWindow). Same calm state, so a declined card can never
 *      leave the shopper sealed inside the window.
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

	var container = document.getElementById( 'xpay-payment' );
	var statusEl = document.getElementById( 'xpay-payment-status' );
	var payButton = document.getElementById( 'xpay-pay-button' );

	function setStatus( text ) {
		if ( statusEl ) {
			statusEl.textContent = text;
		}
	}

	// The pay page draws two states from this one class: paused stops the
	// opening ring and reveals the "Awaiting payment" stamp. Button/link
	// visibility stays inline-display-driven below — the class is purely
	// presentational, so a theme stripping it breaks nothing functional.
	function setPaused( paused ) {
		if ( container ) {
			container.classList.toggle( 'xpay-paused', paused );
		}
	}

	// While the XPay window is open, the page is backdrop, not content:
	// this class paints a scrim one layer under the SDK sheet (pay-page.css)
	// so anything the window hosts — 3DS challenges especially — never
	// fights the receipt behind it. The SDK's own glass is calibrated for
	// light pages; dark themes keep too much contrast without this.
	function setWindowOpen( open ) {
		// The SDK's scroll lock fixes the <body>, which drops the document
		// scrollbar — the page silently re-centers a scrollbar-width wider
		// and everything behind the sheet jumps sideways. Only when the
		// page actually had a scrollbar: keep an inert track on <html>
		// (the xpay-keep-gutter rule in pay-page.css) so the viewport
		// width never changes while the window is open.
		var doc = document.documentElement;
		if ( open ) {
			doc.classList.toggle( 'xpay-keep-gutter', doc.scrollHeight > doc.clientHeight );
		} else {
			doc.classList.remove( 'xpay-keep-gutter' );
		}
		doc.classList.toggle( 'xpay-window-open', open );
	}

	function goHosted() {
		if ( params.hostedUrl ) {
			setStatus( params.i18n.fallback );
			window.location.href = params.hostedUrl;
			return;
		}
		// No hosted URL to fall back to: stop claiming to open — a stuck
		// "Opening…" spinner is a dead end. The paused receipt is the honest
		// state: the order is saved and payable later (account, pay link).
		setWindowOpen( false );
		setPaused( true );
		setStatus( params.i18n.closed );
	}

	/*
	 * Escape hatch for a payment window the SDK will not close.
	 *
	 * The SDK stops honoring close requests the moment the shopper
	 * submits a payment (XPAY_EMBED_CONFIRMED sets an internal
	 * not-closable flag) and clears that flag only on success. After a
	 * declined card the payment window hands the shopper back to its form
	 * and re-enables its own X, but the close message that X sends is
	 * dropped, and Escape and backdrop clicks are gated on the same flag:
	 * the shopper is sealed in until they reload the page. Reported to the
	 * platform; until it is fixed at the source, the merchant page is the
	 * only place that can let them out.
	 *
	 * The window's X is disabled while a payment is processing, so a close
	 * message arriving at all is proof the shopper is NOT mid-payment and
	 * asked to leave. That makes it safe to honor. Escape deliberately is
	 * NOT honored here: it fires during processing too, and tearing the
	 * window down mid-3DS would abandon a live payment.
	 */
	var xpayInstance = null;
	var modal = null;
	var confirmed = false; // Payment submitted: the SDK has locked closing.
	var finished = false;  // Payment completed: navigation is already underway.
	var destroyed = false; // We tore the window down; re-opening needs a fresh one.
	var listening = false;

	/** Origin the payment window is served from, for message validation. */
	function sdkOrigin() {
		try {
			return new URL( params.sdkUrl, window.location.href ).origin;
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Tear down a window the SDK refuses to close, and return the page to
	 * the same paused state a normal close produces. destroy() bypasses the
	 * not-closable flag; it also makes the instance unusable, so Pay now
	 * builds a fresh one.
	 */
	function releaseWindow() {
		confirmed = false;
		destroyed = true;
		try {
			if ( modal ) {
				modal.destroy();
			}
		} catch ( e ) {} // Already gone: the paused state below is still correct.
		setWindowOpen( false );
		// This path is only reached after a payment was submitted and did
		// not succeed, so the shopper gets the truer line rather than the
		// one for closing an untouched window. Falls back if an older
		// cached script meets a newer page.
		setStatus( params.i18n.failed || params.i18n.closed );
		setPaused( true );
		if ( payButton ) {
			payButton.style.display = '';
		}
	}

	/** Listen once for the close request the SDK drops. */
	function listenForRefusedClose() {
		if ( listening ) {
			return;
		}
		listening = true;
		var origin = sdkOrigin();
		window.addEventListener( 'message', function ( event ) {
			if ( ! confirmed || finished || ! origin || event.origin !== origin ) {
				return;
			}
			var data = event.data;
			if ( data && 'XPAY_EMBED_CLOSE' === data.type ) {
				releaseWindow();
			}
		} );
	}

	function openModal( xpay ) {
		// Synchronous SDK throws (bad key shape, internal setup error) never
		// reach onError — it was never registered. Without the try/catch the
		// shopper is stranded on "Opening secure payment…", breaking the
		// never-a-dead-end rule this file is built around.
		xpayInstance = xpay;
		confirmed = false;
		destroyed = false;
		try {
			modal = xpay.checkout( {
				clientSecret: params.clientSecret,
				mode: 'modal',
				locale: params.locale || 'en',
				onConfirmed: function () {
					// The SDK has locked closing from here until success.
					confirmed = true;
				},
				onComplete: function ( result ) {
					finished = true;
					// The thank-you page re-verifies server-side; this redirect
					// carries no authority of its own. Prefer the payload's
					// redirectUrl: it is the session's afterCompletion URL with
					// the REAL session id substituted in, so support's
					// xpay_session_id breadcrumb reaches the thank-you page on
					// the modal path too — previously only the hosted fallback
					// carried it.
					window.location.href = ( result && result.redirectUrl ) || params.returnUrl;
				},
				onClose: function () {
					confirmed = false;
					setWindowOpen( false );
					setStatus( params.i18n.closed );
					setPaused( true );
					if ( payButton ) {
						payButton.style.display = '';
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
					// A destroyed instance ignores open() forever, so a
					// window we tore down is replaced rather than reopened.
					if ( destroyed ) {
						payButton.style.display = 'none';
						openModal( xpayInstance );
						return;
					}
					setStatus( params.i18n.preparing );
					setPaused( false );
					payButton.style.display = 'none';
					setWindowOpen( true );
					try {
						modal.open();
					} catch ( e ) {
						goHosted();
					}
				};
			}

			listenForRefusedClose();

			setStatus( params.i18n.preparing );
			setPaused( false );
			setWindowOpen( true );
			modal.open();
		} catch ( e ) {
			goHosted();
		}
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
