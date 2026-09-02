<?php
/**
 * The payment-state notice on the order-received page.
 *
 * WooCommerce's own page renders untouched; what the plugin adds is the
 * state of the MONEY, and only when that state needs saying. Two of those
 * states carry obligations pinned here. A pending order must offer a way
 * to pay: the checkout sends shoppers here whenever it cannot see what
 * happened, and a page that only promises an email is a lie when nothing
 * was ever charged. A paid order must offer nothing at all: core's page
 * already says everything true, and rendering more is how a plugin ends
 * up redrawing a page that was never its to draw.
 *
 * @package XPay_For_WooCommerce
 */

class ThankyouPayLinkTest extends XPay_Integration_Test_Case {

	/**
	 * An order with something to pay for. A zero-total order needs no
	 * payment and correctly offers no link, which is not what these are
	 * about.
	 *
	 * @param string $status Order status.
	 */
	private function order( string $status ): WC_Order {
		$order = $this->make_xpay_order();
		$order->set_total( '249.99' );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	private function rendered( WC_Order $order ): string {
		ob_start();
		XPay_Thankyou_Notice::render( $order->get_id() );
		return (string) ob_get_clean();
	}

	public function test_an_unpaid_order_offers_a_way_to_pay(): void {
		$order = $this->order( 'pending' );

		$html = $this->rendered( $order );

		// The URL's ampersands are entity-encoded differently by esc_url
		// and by the notice's kses pass; the parts that make it THIS
		// order's pay link are what matters.
		$this->assertStringContainsString(
			'pay_for_order',
			$html,
			'A shopper whose payment could not be confirmed was left with an email promise and no way to pay.'
		);
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $html );
	}

	public function test_an_unpaid_order_still_says_an_email_may_arrive(): void {
		$order = $this->order( 'pending' );

		// Both halves matter: the payment may yet land, AND it may not.
		$this->assertStringContainsString( 'We will email you', $this->rendered( $order ) );
	}

	/**
	 * The link is safe because it is deliberate rather than blind: once the
	 * webhook marks the order paid there is nothing to charge, and the page
	 * stops rendering anything at all — WooCommerce's own confirmation is
	 * the whole page, the way it is for Stripe's plugin.
	 */
	public function test_a_paid_order_renders_nothing(): void {
		$this->assertSame( '', $this->rendered( $this->order( 'processing' ) ) );
	}

	public function test_a_fawry_reference_order_explains_the_wait(): void {
		$order = $this->order( 'on-hold' );
		$order->update_meta_data( XPay_Constants::META_AWAITING_PAYMENT, (string) time() );
		$order->save();

		$html = $this->rendered( $order );

		$this->assertStringContainsString( 'waiting for your payment reference', $html );
		$this->assertStringContainsString( 'Nothing ships before that confirmation.', $html );
	}

	/**
	 * on-hold WITHOUT the awaiting marker is a park with money behind it
	 * (an amount mismatch, a superseded payment): a human's conversation,
	 * not the shopper's. The page says nothing rather than guessing.
	 */
	public function test_a_parked_order_gets_no_notice(): void {
		$this->assertSame( '', $this->rendered( $this->order( 'on-hold' ) ) );
	}

	public function test_the_notice_uses_woocommerce_own_markup(): void {
		$this->assertStringContainsString(
			'class="woocommerce-info',
			$this->rendered( $this->order( 'pending' ) ),
			'Core\'s notice class is what lets every theme style this the way it styles core\'s own notices, with no plugin CSS.'
		);
	}
}
