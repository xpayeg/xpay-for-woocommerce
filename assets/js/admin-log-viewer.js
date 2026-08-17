/**
 * Copy-debug-report button on the XPay Log admin screen.
 *
 * navigator.clipboard needs a secure context (which wp-admin over https
 * always is); the execCommand path covers the rare http-only staging box.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'xpay-copy-report' );
	var report = document.getElementById( 'xpay-debug-report' );
	if ( ! button || ! report ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var done = function () {
			var original = button.textContent;
			button.textContent = button.dataset.copied;
			window.setTimeout( function () {
				button.textContent = original;
			}, 2500 );
		};

		var fallback = function () {
			report.select();
			if ( document.execCommand( 'copy' ) ) {
				done();
			}
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			// writeText rejects when the browser denies clipboard permission
			// — fall through to execCommand instead of failing silently.
			navigator.clipboard.writeText( report.value ).then( done ).catch( fallback );
			return;
		}
		fallback();
	} );
} )();
