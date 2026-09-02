<?php
/**
 * How much of a payment XPay will still refund.
 *
 * WooCommerce keeps its own refund ledger and that is what the order screen
 * renders, but it is a copy: refunds can also be issued from the XPay
 * dashboard, and the plugin only learns about those when the webhook
 * mirroring them arrives. A lost delivery leaves WooCommerce believing
 * money is still refundable that XPay has already paid back.
 *
 * This derives the balance from the current XPay session payload. It is a
 * pure function; the caller decides when to fetch that payload.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Refundable {

	/**
	 * Read the refundable balance out of a checkout-session payload.
	 *
	 * `GET /checkout/sessions/:id` expands `paymentIntent.charges[]`, and
	 * each charge carries `amount` and `amountRefunded` in minor units.
	 *
	 * Answers null for "cannot tell" rather than guessing a number. Every
	 * caller shows an honest "could not check" for null; a wrong figure on
	 * a money screen is worse than no figure.
	 *
	 * The top-level figures are the settlement ones, per
	 * XPay_Money::session_charge(). Where the charge carries a
	 * `presentmentDetails` mirror, `presentment` holds the same three figures
	 * in the customer's currency: charged, gone back, and left.
	 *
	 * Refund presentment amounts are summed because the charge carries no
	 * presentment equivalent of amountRefunded.
	 *
	 * @param array $session Session payload from the API.
	 * @return array{captured:int,refunded:int,refundable:int,currency:string,presentment:?array}|null
	 * @see https://docs.xpay.app/en/api-reference/objects/refund
	 */
	public static function from_session( array $session ): ?array {
		$intent = isset( $session['paymentIntent'] ) && is_array( $session['paymentIntent'] )
			? $session['paymentIntent']
			: array();

		// Absent is not empty. A response that did not expand its charges
		// says nothing about how much is left.
		if ( ! isset( $intent['charges'] ) || ! is_array( $intent['charges'] ) ) {
			return null;
		}

		$captured    = 0;
		$refunded    = 0;
		$currency    = '';
		$presentment = null;

		foreach ( $intent['charges'] as $charge ) {
			if ( ! is_array( $charge ) ) {
				continue;
			}

			$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';
			if ( ! in_array( $status, XPay_Charge_Status::CAPTURED, true ) ) {
				// A declined or cancelled attempt took no money, so it has
				// none to give back. Counting it would report a shopper's
				// failed retry as refundable.
				continue;
			}

			if ( ! isset( $charge['amount'] ) || ! is_numeric( $charge['amount'] ) ) {
				return null;
			}

			$charge_currency = isset( $charge['currency'] ) && is_string( $charge['currency'] )
				? strtoupper( $charge['currency'] )
				: '';
			if ( '' === $charge_currency ) {
				return null;
			}
			if ( '' === $currency ) {
				$currency = $charge_currency;
			} elseif ( $currency !== $charge_currency ) {
				// Two captured charges in different currencies cannot be
				// added up. Not reachable today, and not worth being wrong
				// about if it ever is.
				return null;
			}

			$captured += (int) $charge['amount'];
			$refunded += isset( $charge['amountRefunded'] ) && is_numeric( $charge['amountRefunded'] )
				? (int) $charge['amountRefunded']
				: 0;

			$mirror = self::presentment_of( $charge );
			if ( null === $mirror ) {
				continue;
			}

			// Each refund carries the platform's own projection of itself
			// into the customer's currency, at the rate locked on the
			// charge. Summing them is how much has gone back in that
			// currency — platform-authored figures throughout, so nothing
			// here derives money from a rate. Null means the refunds on
			// this payload do not account for what the charge says went
			// back, and no figure may be built on that.
			$mirror['refunded'] = self::presentment_refunded( $charge );

			if ( null === $presentment ) {
				$presentment = $mirror;
				continue;
			}
			if ( $presentment['currency'] !== $mirror['currency'] ) {
				// Two captured charges shown to the customer in different
				// currencies. Say nothing rather than pick one.
				$presentment = null;
				break;
			}
			$presentment['amount'] += $mirror['amount'];
			if ( null === $mirror['refunded'] || null === $presentment['refunded'] ) {
				$presentment['refunded'] = null;
			} else {
				$presentment['refunded'] += $mirror['refunded'];
			}
		}

		if ( '' === $currency ) {
			return null;
		}

		if ( null !== $presentment && null !== $presentment['refunded'] ) {
			// Clamped for the same reason the settlement figure is: an
			// over-refund recorded upstream is not money this store may
			// claim back.
			$presentment['refundable'] = max( 0, $presentment['amount'] - $presentment['refunded'] );
		}

		return array(
			'captured'    => $captured,
			'refunded'    => $refunded,
			// Clamped: an over-refund recorded upstream is not a negative
			// amount this store may claim back.
			'refundable'  => max( 0, $captured - $refunded ),
			'currency'    => $currency,
			'presentment' => $presentment,
		);
	}

	/**
	 * How much of one charge has already gone back, in the customer's
	 * currency.
	 *
	 * The charge has no presentment equivalent of amountRefunded, so the
	 * successful refunds' presentment amounts form the accumulator.
	 *
	 * Only SUCCEEDED refunds count. A failed or pending one has given
	 * nothing back and must not reduce what is still refundable.
	 *
	 * @param array $charge One charge payload, refunds embedded.
	 * @return int Minor units in the customer's currency.
	 */
	private static function presentment_refunded( array $charge ): ?int {
		$settled = isset( $charge['amountRefunded'] ) && is_numeric( $charge['amountRefunded'] )
			? (int) $charge['amountRefunded']
			: 0;

		if ( ! isset( $charge['refunds'] ) || ! is_array( $charge['refunds'] ) ) {
			// Nothing refunded and nothing to reconcile is a clean zero. A
			// charge that HAS given money back but did not expand its
			// refunds cannot be accounted for, and reporting the full
			// charge as refundable there would offer back money that has
			// already gone.
			return 0 === $settled ? 0 : null;
		}

		$presentment = 0;
		$reconciled  = 0;
		foreach ( $charge['refunds'] as $refund ) {
			if ( ! is_array( $refund ) ) {
				continue;
			}
			// Only SUCCEEDED counts. A failed or pending refund has given
			// nothing back and must not reduce what is still refundable.
			$status = isset( $refund['status'] ) ? (string) $refund['status'] : '';
			if ( XPay_Refund_Status::SUCCEEDED !== $status ) {
				continue;
			}
			if ( ! isset( $refund['amount'], $refund['presentmentDetails']['amount'] )
				|| ! is_numeric( $refund['amount'] )
				|| ! is_numeric( $refund['presentmentDetails']['amount'] )
			) {
				// One refund we cannot read makes the whole sum a guess.
				return null;
			}
			$reconciled  += (int) $refund['amount'];
			$presentment += (int) $refund['presentmentDetails']['amount'];
		}

		// The check that makes the sum trustworthy: the refunds we counted
		// have to add up to what the charge says went back. If they do not,
		// something is missing from this payload and any figure derived
		// from it would overstate what is left.
		return $reconciled === $settled ? $presentment : null;
	}

	/**
	 * The customer-facing mirror on one charge, when there is one.
	 *
	 * Null when absent or when either half of the amount/currency pair is missing —
	 * an amount without its currency is not a figure anyone may render.
	 *
	 * @param array $charge One charge payload.
	 * @return array{amount:int,currency:string,rate:?string}|null Charged
	 *         amount; the caller adds `refunded` and `refundable`.
	 */
	private static function presentment_of( array $charge ): ?array {
		if ( ! isset( $charge['presentmentDetails'] ) || ! is_array( $charge['presentmentDetails'] ) ) {
			return null;
		}
		$details = $charge['presentmentDetails'];

		if ( ! isset( $details['amount'], $details['currency'] ) || ! is_numeric( $details['amount'] ) ) {
			return null;
		}
		$currency = is_string( $details['currency'] ) ? strtoupper( $details['currency'] ) : '';
		if ( '' === $currency ) {
			return null;
		}

		return array(
			'amount'   => (int) $details['amount'],
			'currency' => $currency,
			// Kept as the string the API sent, and used as one: this is the
			// rate a partial refund is converted at, and parsing it into a
			// float would give back the precision the string exists to
			// preserve.
			'rate'     => isset( $details['exchangeRate'] ) && is_scalar( $details['exchangeRate'] )
				? (string) $details['exchangeRate']
				: null,
		);
	}
}
