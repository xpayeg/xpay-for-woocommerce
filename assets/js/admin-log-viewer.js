/**
 * XPay Log screen behaviors: the copy-debug-report button and the
 * full-entry dialog the truncated details cells open.
 *
 * navigator.clipboard needs a secure context (which wp-admin over https
 * always is); the execCommand path covers the rare http-only staging box.
 */
( function () {
	'use strict';

	/**
	 * Copy text and confirm on the button (label swaps to data-copied).
	 *
	 * @param {string}            text   What to copy.
	 * @param {HTMLButtonElement} button Whose label confirms the copy.
	 */
	function copyText( text, button ) {
		var done = function () {
			var original = button.textContent;
			button.textContent = button.dataset.copied;
			window.setTimeout( function () {
				button.textContent = original;
			}, 2500 );
		};

		var fallback = function () {
			// A throwaway textarea keeps the fallback selection off the
			// visible page (the entry dialog has no textarea to select).
			var scratch = document.createElement( 'textarea' );
			scratch.value = text;
			scratch.setAttribute( 'readonly', '' );
			scratch.style.position = 'fixed';
			scratch.style.left = '-9999px';
			document.body.appendChild( scratch );
			scratch.select();
			if ( document.execCommand( 'copy' ) ) {
				done();
			}
			document.body.removeChild( scratch );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			// writeText rejects when the browser denies clipboard permission
			// — fall through to execCommand instead of failing silently.
			navigator.clipboard.writeText( text ).then( done ).catch( fallback );
			return;
		}
		fallback();
	}

	// ── Copy debug report ────────────────────────────────────────────

	var reportButton = document.getElementById( 'xpay-copy-report' );
	var report = document.getElementById( 'xpay-debug-report' );
	if ( reportButton && report ) {
		reportButton.addEventListener( 'click', function () {
			copyText( report.value, reportButton );
		} );
	}

	// ── Full-entry dialog ────────────────────────────────────────────

	var dialog = document.getElementById( 'xpay-log-dialog' );
	if ( ! dialog ) {
		return;
	}

	var closeButton = dialog.querySelector( '.xpay-adm__dialog-close' );
	var copyEntryButton = document.getElementById( 'xpay-log-copy-entry' );
	var opener = null;
	var current = null;

	function field( name ) {
		return dialog.querySelector( '[data-xpay-dlg="' + name + '"]' );
	}

	/**
	 * Context rows are JSON at rest; show them indented when they parse,
	 * verbatim when they don't (truncated rows can be cut mid-JSON).
	 *
	 * @param {string} raw Stored context string.
	 */
	function pretty( raw ) {
		if ( ! raw ) {
			return '';
		}
		try {
			return JSON.stringify( JSON.parse( raw ), null, 2 );
		} catch ( e ) {
			return raw;
		}
	}

	/** The same one-line format the debug report uses for its tail. */
	function entryText( d ) {
		return '[' + d.time + '] [' + d.request + '] ' + d.stage +
			( d.order ? ' order=' + d.order : '' ) +
			( d.message ? ' ' + d.message : '' ) +
			( d.context ? ' ' + d.context : '' );
	}

	function openDialog( row ) {
		current = row.dataset;
		field( 'time' ).textContent = current.time || '—';
		field( 'request' ).textContent = current.request || '—';
		field( 'stage' ).textContent = current.stage || '—';
		field( 'order' ).textContent = current.order ? '#' + current.order : '—';
		var message = field( 'message' );
		message.textContent = current.message || '';
		message.hidden = ! current.message;
		var context = field( 'context' );
		context.textContent = pretty( current.context );
		context.hidden = ! current.context;
		opener = document.activeElement;
		dialog.hidden = false;
		closeButton.focus();
	}

	function closeDialog() {
		dialog.hidden = true;
		current = null;
		if ( opener && opener.focus ) {
			opener.focus();
		}
		opener = null;
	}

	document.addEventListener( 'click', function ( event ) {
		var more = event.target.closest ? event.target.closest( '.xpay-adm__cell-more' ) : null;
		if ( more ) {
			openDialog( more.closest( 'tr' ) );
		}
	} );

	closeButton.addEventListener( 'click', closeDialog );

	// Clicking the dimmed backdrop (not the card) closes.
	dialog.addEventListener( 'click', function ( event ) {
		if ( event.target === dialog ) {
			closeDialog();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && ! dialog.hidden ) {
			closeDialog();
		}
	} );

	if ( copyEntryButton ) {
		copyEntryButton.addEventListener( 'click', function () {
			if ( current ) {
				copyText( entryText( current ), copyEntryButton );
			}
		} );
	}
} )();
