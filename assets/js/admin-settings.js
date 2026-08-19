/**
 * The Manage screen's behavior layer. Small on purpose: every control is
 * a real form field the WooCommerce settings API saves — this file only
 * handles reveal/replace on secrets, copy buttons, the segmented
 * controls' visual state, tile highlighting, and the go-live shortcut.
 *
 * No jQuery, readyState-safe, same as every other script in the plugin.
 */
( function () {
	'use strict';

	function copyText( text, button ) {
		var done = function () {
			var previous = button.textContent;
			button.textContent = button.getAttribute( 'data-xpay-copied-label' ) || 'Copied';
			window.setTimeout( function () {
				button.textContent = previous;
			}, 1500 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function () {} );
			return;
		}
		var scratch = document.createElement( 'textarea' );
		scratch.value = text;
		document.body.appendChild( scratch );
		scratch.select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {
			// Clipboard unavailable: leave the value visible for manual copy.
		}
		document.body.removeChild( scratch );
	}

	function openSecret( row, replacing ) {
		var masked = row.querySelector( '[data-xpay-masked]' );
		var input = row.querySelector( 'input' );
		if ( ! input ) {
			return;
		}
		if ( masked ) {
			masked.hidden = true;
		}
		input.hidden = false;
		input.type = 'text';
		if ( replacing ) {
			input.focus();
			input.select();
		}
	}

	function boot() {
		var root = document.querySelector( '.xpay-adm' );
		if ( ! root ) {
			return;
		}

		root.addEventListener( 'click', function ( event ) {
			var target = event.target;

			var helpButton = target.closest( '[data-xpay-help]' );
			if ( helpButton ) {
				var helpDialog = document.getElementById( 'xpay-help-' + helpButton.getAttribute( 'data-xpay-help' ) );
				if ( helpDialog ) {
					helpDialog.hidden = false;
					var closer = helpDialog.querySelector( '.xpay-adm__dialog-close' );
					if ( closer ) {
						closer.focus();
					}
				}
				return;
			}

			if ( target.classList.contains( 'xpay-adm__dialog-close' ) ) {
				target.closest( '.xpay-adm__dialog-backdrop' ).hidden = true;
				return;
			}

			// A click on the dimmed backdrop itself (not the card) closes.
			if ( target.classList.contains( 'xpay-adm__dialog-backdrop' ) ) {
				target.hidden = true;
				return;
			}

			if ( target.hasAttribute( 'data-xpay-reveal' ) || target.hasAttribute( 'data-xpay-replace' ) ) {
				openSecret( target.closest( '[data-xpay-secret]' ), target.hasAttribute( 'data-xpay-replace' ) );
				target.remove();
				return;
			}

			if ( target.hasAttribute( 'data-xpay-copy' ) ) {
				copyText( target.getAttribute( 'data-xpay-copy' ), target );
				return;
			}

			if ( target.hasAttribute( 'data-xpay-copy-input' ) ) {
				var row = target.closest( '[data-xpay-secret]' );
				var input = row ? row.querySelector( 'input' ) : null;
				if ( input ) {
					copyText( input.value, target );
				}
				return;
			}

			if ( target.hasAttribute( 'data-xpay-golive' ) ) {
				var liveRadio = root.querySelector( '[data-xpay-segment="mode"] input[value="live"]' );
				if ( liveRadio ) {
					liveRadio.checked = true;
					liveRadio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					liveRadio.closest( '.xpay-adm__segment' ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			}
		} );

		root.addEventListener( 'change', function ( event ) {
			var target = event.target;

			var segment = target.closest( '.xpay-adm__segment' );
			if ( segment ) {
				segment.querySelectorAll( '.xpay-adm__seg' ).forEach( function ( seg ) {
					var radio = seg.querySelector( 'input' );
					seg.classList.toggle( 'is-active', !! ( radio && radio.checked ) );
				} );
				if ( 'mode' === segment.getAttribute( 'data-xpay-segment' ) ) {
					root.classList.toggle( 'xpay-adm--live', 'live' === target.value );
				}
			}

			var tile = target.closest( '.xpay-adm__tile' );
			if ( tile ) {
				tile.classList.toggle( 'is-on', target.checked );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key ) {
				return;
			}
			var open = document.querySelector( '.xpay-adm__help-dialog:not([hidden])' );
			if ( open ) {
				open.hidden = true;
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
