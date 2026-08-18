<?php
/**
 * XPay_Branding
 *
 * Merchant branding for the pay page, synced FROM the XPay platform — never
 * configured in the plugin. The checkout session response carries the
 * merchant's resolved branding settings (dashboard defaults merged with any
 * session overrides, resolved server-side), so the merchant's primary color
 * rides in on a response the plugin already fetches: no extra API call, no
 * extra key permission, and no second branding UI that could drift from the
 * XPay dashboard.
 *
 * The gradient mirrors the hosted checkout's own hero formula
 * (packages/ui/src/lib/checkout-colors.ts): each channel lifted 35% toward
 * white, so the plugin's stage and XPay's hosted page derive the identical
 * pair from the same primary.
 *
 * Two deliberate divergences from the hosted page:
 *   - A transparent primary (the platform's "Minimal" preset) collapses the
 *     hosted gradient so the page background shows through. This stage IS
 *     the page background, so transparent falls back to XPay indigo instead.
 *   - With no merchant color the hosted page paints its own default brand
 *     purple (#635bff). The plugin falls back to the checkout theme's
 *     --primary (#4f46e5) so the stage always matches the Pay button it
 *     frames.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Branding {

	/**
	 * The checkout theme's light-mode --primary (packages/ui theme.css),
	 * already the Pay button color — the stage falls back to it so an
	 * unbranded merchant gets one coherent XPay-indigo page.
	 */
	const FALLBACK_PRIMARY = '#4f46e5';

	/** Channel lift toward white for the gradient's end stop (platform value). */
	const GRADIENT_LIFT = 0.35;

	/**
	 * Canonical '#rrggbb' (lowercase) or '' when the value is not a usable
	 * solid color. Accepts the four forms the platform's validator accepts
	 * (#RGB / #RGBA / #RRGGBB / #RRGGBBAA); short forms expand, an alpha
	 * channel is stripped after a fully-transparent value is rejected.
	 *
	 * @param string $value Color value as received (API response or stored option).
	 */
	public static function normalize_hex( string $value ): string {
		if ( 1 !== preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value, $matches ) ) {
			return '';
		}
		$hex = strtolower( $matches[1] );
		if ( strlen( $hex ) <= 4 ) {
			$expanded = '';
			foreach ( str_split( $hex ) as $char ) {
				$expanded .= $char . $char;
			}
			$hex = $expanded;
		}
		if ( 8 === strlen( $hex ) ) {
			if ( '00' === substr( $hex, 6, 2 ) ) {
				return '';
			}
			$hex = substr( $hex, 0, 6 );
		}
		return '#' . $hex;
	}

	/**
	 * The gradient's end stop: each channel lifted GRADIENT_LIFT of its
	 * remaining headroom toward 255. Matches the platform's rounding
	 * (Math.round and PHP round() agree on positive halves).
	 *
	 * @param string $normalized A '#rrggbb' value from normalize_hex().
	 */
	public static function brighten( string $normalized ): string {
		$out = '#';
		for ( $i = 1; $i <= 5; $i += 2 ) {
			$channel = hexdec( substr( $normalized, $i, 2 ) );
			$lifted  = (int) round( min( 255, $channel + ( 255 - $channel ) * self::GRADIENT_LIFT ) );
			$out    .= str_pad( dechex( $lifted ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * Stage gradient stops for a stored primary. Re-normalized on every
	 * read: options are writable by any code on the site, and this value
	 * lands in a style attribute.
	 *
	 * @param string $stored The persisted primary ('' when never synced).
	 * @return array {from: '#rrggbb', to: '#rrggbb'}
	 */
	public static function stage_from_primary( string $stored ): array {
		$primary = self::normalize_hex( $stored );
		if ( '' === $primary ) {
			$primary = self::FALLBACK_PRIMARY;
		}
		return array(
			'from' => $primary,
			'to'   => self::brighten( $primary ),
		);
	}

	/**
	 * The merchant's primary color from a checkout session response, or ''
	 * when the merchant has no color customization (the response omits
	 * colors entirely for unbranded merchants).
	 *
	 * @param array $session Session object from the API.
	 */
	public static function primary_from_session( array $session ): string {
		if ( ! isset( $session['brandingSettings']['colors']['primary'] ) || ! is_string( $session['brandingSettings']['colors']['primary'] ) ) {
			return '';
		}
		return self::normalize_hex( $session['brandingSettings']['colors']['primary'] );
	}
}
